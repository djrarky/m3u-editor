<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelFailover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Filament\Notifications\Notification as FilamentNotification;

class UnmergeChannels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public $user,
        public $playlistId = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->playlistId) {
            // Get the playlist channels IDs
            $channelIds = Channel::where('playlist_id', $this->playlistId);

            // Need to efficiently work through potentially 100s of thousands of channels
            // so we use cursor() to avoid loading everything into memory at once
            $idsToDelete = [];
            foreach ($channelIds->cursor() as $channel) {
                // Bulk delete in chunks of 100
                $idsToDelete[] = $channel->id;
                if (count($idsToDelete) >= 100) {
                    ChannelFailover::whereIn('channel_id', $idsToDelete)->delete();
                    $idsToDelete = [];
                }
            }

            // Clean up any remaining IDs
            if (count($idsToDelete) > 0) {
                ChannelFailover::whereIn('channel_id', $idsToDelete)->delete();
            }
        } else {
            // Delete all user failovers if no playlist is specified
            ChannelFailover::where('user_id', $this->user->id)->delete();
        }

        $this->sendCompletionNotification();
    }

    protected function sendCompletionNotification()
    {
        FilamentNotification::make()
            ->title('Unmerge complete')
            ->body('All channels have been unmerged successfully.')
            ->success()
            ->broadcast($this->user)
            ->sendToDatabase($this->user);
    }
}
