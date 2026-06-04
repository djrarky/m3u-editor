<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Category;
use App\Models\Playlist;
use App\Models\Series;
use App\Traits\ProviderRequestDelay;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonMachine\Items;

class ProcessM3uImportSeriesChunk implements ShouldQueue
{
    use ProviderRequestDelay;
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    // Giving a timeout of 30 minutes to the Job to process the file
    public $timeout = 60 * 30;

    /** Default user agent used when the playlist has none configured. */
    public string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36';

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $payload,
        public int $batchCount,
        public string $batchNo,
        public int $index,
        public bool $autoEnable = false,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $playlistId = $this->payload['playlistId'] ?? null;
        $sourceCategoryId = $this->payload['categoryId'] ?? null;
        $sourceCategoryName = $this->payload['categoryName'] ?? null;

        if (! $sourceCategoryId || ! $playlistId) {
            return;
        }

        $playlist = Playlist::find($playlistId);
        if (! $playlist) {
            return;
        }

        // If this is the first chunk, reset the series progress and notify the user
        // This is to ensure that the series progress is reset for each import
        if ($this->index === 0) {
            // Notify the user that series import is starting
            Notification::make()
                ->info()
                ->title('Syncing Series')
                ->body('Syncing series now. This may take a while depending on the number of series your provider offers.')
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
            $playlist->update([
                'processing' => [
                    ...$playlist->processing ?? [],
                    'series_processing' => true,
                ],
                'status' => Status::Processing,
                'errors' => null,
                'series_progress' => 0,
            ]);
        }

        // Setup the user agent and SSL verification
        $verify = ! $playlist->disable_ssl_verification;
        $userAgent = $playlist->user_agent ?: $this->userAgent;

        $xtreamConfig = $playlist->xtream_config;
        if (! $xtreamConfig) {
            return;
        }

        $baseUrl = $xtreamConfig['url'] ?? '';
        $user = $xtreamConfig['username'] ?? '';
        $password = $xtreamConfig['password'] ?? '';
        if (! $baseUrl || ! $user || ! $password) {
            return;
        }

        // Get the series streams for this category with provider throttling
        $seriesStreamsUrl = "$baseUrl/player_api.php?username=$user&password=$password&action=get_series&category_id={$sourceCategoryId}";
        $seriesStreamsResponse = $this->withProviderThrottling(fn () => Http::withUserAgent($userAgent)
            ->withOptions(['verify' => $verify])
            ->timeout(60) // set timeout to 1 minute
            ->throw()->get($seriesStreamsUrl));
        if (! $seriesStreamsResponse->ok()) {
            return; // skip this category if there's an error
        }

        // --- Phase 1: parse the HTTP response into plain PHP arrays (no DB writes) ---
        $rawItems = [];
        foreach (Items::fromString($seriesStreamsResponse->body()) as $item) {
            $itemName = trim((string) ($item->name ?? $item->title ?? ''));
            if ($itemName === '') {
                continue;
            }

            $lastModified = isset($item->last_modified) && $item->last_modified
                ? Carbon::createFromTimestamp((int) $item->last_modified)->toDateTimeString()
                : null;

            $rawItems[] = [
                'name' => $itemName,
                'source_series_id' => $item->series_id,
                'last_modified' => $lastModified,
                'sort' => $item->num ?? null,
                'cover' => $item->cover ?? null,
                'plot' => $item->plot ?? null,
                'genre' => $item->genre ?? null,
                'release_date' => $item->releaseDate ?? $item->release_date ?? null,
                'cast' => $item->cast ?? null,
                'director' => $item->director ?? null,
                'rating' => $item->rating ?? null,
                'rating_5based' => (float) ($item->rating_5based ?? 0),
                'backdrop_path' => json_encode($item->backdrop_path ?? []),
                'youtube_trailer' => $item->youtube_trailer ?? null,
            ];
        }

        // --- Phase 2: identify which series already exist (read-only, outside transaction) ---
        $existingSeriesIds = $playlist->series()
            ->where('source_category_id', $sourceCategoryId)
            ->pluck('last_modified', 'source_series_id');

        $toUpdate = [];
        $toInsert = [];
        foreach ($rawItems as $raw) {
            $existing = $existingSeriesIds->get($raw['source_series_id']);
            if ($existing !== null) {
                // Only queue an update if last_modified actually changed
                if ($raw['last_modified'] && $raw['last_modified'] !== $existing) {
                    $toUpdate[] = ['source_series_id' => $raw['source_series_id'], 'last_modified' => $raw['last_modified']];
                }
            } else {
                $toInsert[] = $raw;
            }
        }

        // Apply last_modified updates for existing series (simple row updates, no FK risk)
        foreach ($toUpdate as $upd) {
            $playlist->series()
                ->where('source_series_id', $upd['source_series_id'])
                ->where('source_category_id', $sourceCategoryId)
                ->update(['last_modified' => $upd['last_modified']]);
        }

        // Update progress: scale 0→99 across all chunks using index position
        $chunkProgress = (int) round(($this->index + 1) / max(1, $this->batchCount) * 99);
        $playlist->update([
            'series_progress' => min(99, $chunkProgress),
        ]);

        if (empty($toInsert)) {
            return;
        }

        // --- Phase 3: ensure category exists and bulk-insert new series atomically ---
        // Wrapped in a transaction with 5 deadlock-retry attempts.
        // The category is created (or fetched) inside the transaction with import_batch_no
        // so that a concurrent sync's seriesCleanup cannot delete it while we hold the
        // transaction lock — preventing the series_category_id_foreign FK violation.
        $playlistId = $playlist->id;
        $userId = $playlist->user_id;
        $batchNo = $this->batchNo;
        $autoEnable = $this->autoEnable;

        collect($toInsert)->chunk(100)->each(function ($chunk) use (
            $playlistId, $userId, $batchNo, $sourceCategoryId, $sourceCategoryName, $autoEnable
        ) {
            try {
                DB::transaction(function () use (
                    $chunk, $playlistId, $userId, $batchNo,
                    $sourceCategoryId, $sourceCategoryName, $autoEnable
                ) {
                    // Get or create the category inside the transaction so the row exists
                    // for the duration of the insert. Include import_batch_no so cleanup
                    // queries (import_batch_no != $batchNo) leave this category alone.
                    $category = Category::firstOrCreate(
                        ['playlist_id' => $playlistId, 'source_category_id' => $sourceCategoryId],
                        [
                            'name' => $sourceCategoryName,
                            'name_internal' => $sourceCategoryName,
                            'user_id' => $userId,
                            'import_batch_no' => $batchNo,
                        ]
                    );

                    $rows = $chunk->map(fn ($raw) => array_merge($raw, [
                        'enabled' => $autoEnable,
                        'source_category_id' => $sourceCategoryId,
                        'import_batch_no' => $batchNo,
                        'user_id' => $userId,
                        'playlist_id' => $playlistId,
                        'category_id' => $category->id,
                    ]))->toArray();

                    Series::insertOrIgnore($rows);
                }, 5);
            } catch (QueryException $e) {
                // SQLSTATE 23503 = foreign_key_violation (PostgreSQL).
                // This can occur when a concurrent sync's seriesCleanup deletes the
                // category between our transaction retries. Log and skip — the series
                // will be picked up on the next successful sync.
                if ($e->getCode() === '23503') {
                    Log::warning('Series bulk insert skipped: category deleted by concurrent sync', [
                        'source_category_id' => $sourceCategoryId,
                        'playlist_id' => $playlistId,
                        'error' => $e->getMessage(),
                    ]);

                    return;
                }

                Log::error('Series bulk insert failed', [
                    'exception' => $e->getMessage(),
                    'source_category_id' => $sourceCategoryId,
                ]);

                throw $e;
            }
        });
    }
}
