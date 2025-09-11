<?php

namespace App\Filament\Resources\CustomPlaylistResource\RelationManagers;

use App\Filament\Resources\SeriesResource;
use App\Filament\BulkActions\HandlesSourcePlaylist;
use App\Models\Series;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

class SeriesRelationManager extends RelationManager
{
    use HandlesSourcePlaylist;
    protected static string $relationship = 'series';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return SeriesResource::infolist($infolist);
    }

    public function table(Table $table): Table
    {
        $ownerRecord = $this->ownerRecord;

        $groupColumn = SpatieTagsColumn::make('tags')
            ->label('Playlist Category')
            ->type($ownerRecord->uuid.'-category')
            ->toggleable()->searchable(query: function (Builder $query, string $search) use ($ownerRecord): Builder {
                return $query->whereHas('tags', function (Builder $query) use ($search, $ownerRecord) {
                    $query->where('tags.type', $ownerRecord->uuid.'-category');

                    // Cross-database compatible JSON search
                    $connection = $query->getConnection();
                    $driver = $connection->getDriverName();

                    switch ($driver) {
                        case 'pgsql':
                            // PostgreSQL uses ->> operator for JSON
                            $query->whereRaw('LOWER(tags.name->>\'$\') LIKE ?', ['%'.strtolower($search).'%']);
                            break;
                        case 'mysql':
                            // MySQL uses JSON_EXTRACT
                            $query->whereRaw('LOWER(JSON_EXTRACT(tags.name, "$")) LIKE ?', ['%'.strtolower($search).'%']);
                            break;
                        case 'sqlite':
                            // SQLite uses json_extract
                            $query->whereRaw('LOWER(json_extract(tags.name, "$")) LIKE ?', ['%'.strtolower($search).'%']);
                            break;
                        default:
                            // Fallback - try to search the JSON as text
                            $query->where(DB::raw('LOWER(CAST(tags.name AS TEXT))'), 'LIKE', '%'.strtolower($search).'%');
                            break;
                    }
                });
            })
            ->sortable(query: function (Builder $query, string $direction) use ($ownerRecord): Builder {
                $connection = $query->getConnection();
                $driver = $connection->getDriverName();

                // Build the ORDER BY clause based on database type
                $orderByClause = match ($driver) {
                    'pgsql' => 'tags.name->>\'$\'',
                    'mysql' => 'JSON_EXTRACT(tags.name, "$")',
                    'sqlite' => 'json_extract(tags.name, "$")',
                    default => 'CAST(tags.name AS TEXT)'
                };

                return $query
                    ->leftJoin('taggables', function ($join) {
                        $join->on('series.id', '=', 'taggables.taggable_id')
                            ->where('taggables.taggable_type', '=', Series::class);
                    })
                    ->leftJoin('tags', function ($join) use ($ownerRecord) {
                        $join->on('taggables.tag_id', '=', 'tags.id')
                            ->where('tags.type', '=', $ownerRecord->uuid.'-category');
                    })
                    ->orderByRaw("{$orderByClause} {$direction}")
                    ->select('series.*', DB::raw("{$orderByClause} as tag_name_sort"))
                    ->distinct();
            });
        $defaultColumns = SeriesResource::getTableColumns(showCategory: true, showPlaylist: false);

        // Inject the custom group column after the group column
        array_splice($defaultColumns, 6, 0, [$groupColumn]);

        $defaultColumns[] = SelectColumn::make('playlist_id')
            ->label('Parent Playlist')
            ->getStateUsing(fn (Series $record) => $record->playlist_id)
            ->options(fn (Series $record) => $this->playlistOptions($record))
            ->disabled(fn (Series $record) => count($this->playlistOptions($record)) <= 1)
            ->selectablePlaceholder(false)
            ->updateStateUsing(fn ($state) => $state)
            ->afterStateUpdated(fn ($state, Series $record) => $this->changeSourcePlaylist($record, (int) $state))
            ->toggleable()
            ->sortable();

        return $table->persistFiltersInSession()
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('name')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->modifyQueryUsing(fn (Builder $query) => $query->with('playlist'))
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns($defaultColumns)
            ->filters([
                ...SeriesResource::getTableFilters(showPlaylist: true),
                Tables\Filters\SelectFilter::make('playlist_category')
                    ->label('Custom Category')
                    ->options(function () use ($ownerRecord) {
                        return $ownerRecord->tags()
                            ->where('type', $ownerRecord->uuid.'-category')
                            ->get()
                            ->mapWithKeys(fn ($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                            ->toArray();
                    })
                    ->query(function (Builder $query, array $data) use ($ownerRecord): Builder {
                        if (empty($data['values'])) {
                            return $query;
                        }

                        return $query->where(function ($query) use ($data, $ownerRecord) {
                            foreach ($data['values'] as $categoryName) {
                                $query->orWhereHas('tags', function ($tagQuery) use ($categoryName, $ownerRecord) {
                                    $tagQuery->where('type', $ownerRecord->uuid.'-category')
                                        ->where('name->en', $categoryName);
                                });
                            }
                        });
                    })
                    ->multiple()
                    ->searchable(),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        $action
                            ->getRecordSelect()
                            ->getSearchResultsUsing(function (string $search) {
                                $searchLower = strtolower($search);
                                $series = Auth::user()->series()
                                    ->withoutEagerLoads()
                                    ->with('playlist')
                                    ->where(function ($query) use ($searchLower) {
                                        $query->whereRaw('LOWER(series.name) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(series.cast) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(series.plot) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(series.genre) LIKE ?', ["%{$searchLower}%"]);
                                    })
                                    ->limit(50)
                                    ->get();

                                // Create options array
                                $options = [];
                                foreach ($series as $seriesItem) {
                                    $displayTitle = $seriesItem->name;
                                    $playlistName = $seriesItem->playlist->name ?? 'Unknown';
                                    $options[$seriesItem->id] = "{$displayTitle} [{$playlistName}]";
                                }

                                return $options;
                            })
                            ->getOptionLabelFromRecordUsing(function ($record) {
                                $displayTitle = $record->name;
                                $playlistName = $record->getEffectivePlaylist()->name ?? 'Unknown';

                                return "{$displayTitle} [{$playlistName}]";
                            }),
                    ]),

                // Advanced attach when adding pivot values:
                // Tables\Actions\AttachAction::make()->form(fn(Tables\Actions\AttachAction $action): array => [
                //     $action->getRecordSelect(),
                //     Forms\Components\TextInput::make('title')
                //         ->label('Title')
                //         ->required(),
                // ]),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->color('danger')
                    ->button()->hiddenLabel()
                    ->icon('heroicon-o-x-circle')
                    ->size('sm'),
                ...SeriesResource::getTableActions(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                ...$this->getBulkActionsWithParentPlaylist($ownerRecord),
                  Tables\Actions\DetachBulkAction::make()->color('danger'),
                  Tables\Actions\BulkAction::make('add_to_category')
                      ->label('Add to custom category')
                      ->form([
                          Forms\Components\Select::make('category')
                            ->label('Select category')
                            ->options(
                                Tag::where('type', $ownerRecord->uuid.'-category')
                                    ->pluck('name', 'name')
                            )
                            ->required(),
                    ])
                    ->action(function (Collection $records, $data) use ($ownerRecord): void {
                        foreach ($records as $record) {
                            $record->syncTagsWithType([$data['category']], $ownerRecord->uuid.'-category');
                        }
                    })->after(function () {
                        FilamentNotification::make()
                            ->success()
                            ->title('Added to category')
                            ->body('The selected series have been added to the custom category.')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-squares-plus')
                    ->modalIcon('heroicon-o-squares-plus')
                    ->modalDescription('Add to category')
                    ->modalSubmitActionLabel('Yes, add to category'),
                Tables\Actions\BulkAction::make('change_parent_playlist')
                    ->label('Change parent playlist')
                    ->form(function (Collection $records) use ($ownerRecord): array {
                        $playlists = [];

                        foreach ($records as $record) {
                            $playlists = array_replace($playlists, $this->playlistOptions($record));
                        }

                        return [
                            Forms\Components\Select::make('playlist')
                                ->label('Parent Playlist')
                                ->options($playlists)
                                ->required(),
                        ];
                    })
                    ->action(function (Collection $records, array $data): void {
                        foreach ($records as $record) {
                            $exists = Series::where('playlist_id', (int) $data['playlist'])
                                ->where('source_series_id', $record->source_series_id)
                                ->exists();

    }

    protected function getBulkActionsWithParentPlaylist($ownerRecord): array
    {
        $bulkActions = SeriesResource::getTableBulkActions(addToCustom: false);
        $bulkActions[0]->actions([
            ...$bulkActions[0]->getActions(),
            Tables\Actions\BulkAction::make('change_parent_playlist')
                ->label('Change parent playlist')
                ->form(function (Collection $records) use ($ownerRecord): array {
                    [$groups] = self::getSourcePlaylistData($records, 'series', 'source_series_id');

                    $playlists = $groups->flatMap(fn ($group) => self::availablePlaylistsForGroup(
                        $ownerRecord->id,
                        $group,
                        'series',
                        'source_series_id',
                    ));

                    return [
                        Forms\Components\Select::make('playlist')
                            ->label('Parent Playlist')
                            ->options($playlists->unique()->toArray())
                            ->required(),
                    ];
                })
                ->action(function (Collection $records, array $data): void {
                    foreach ($records as $record) {
                        $exists = Series::where('playlist_id', (int) $data['playlist'])
                            ->where('source_series_id', $record->source_series_id)
                            ->exists();

                        if ($exists) {
                            $this->changeSourcePlaylist($record, (int) $data['playlist']);
                        }
                    }
                })->after(function () {
                    FilamentNotification::make()
                        ->success()
                        ->title('Parent playlist updated')
                        ->body('The parent playlist has been updated where applicable.')
                        ->send();
                })
                ->deselectRecordsAfterCompletion()
                ->requiresConfirmation()
                ->icon('heroicon-o-arrows-right-left')
                ->modalIcon('heroicon-o-arrows-right-left')
                ->modalDescription('Change the parent playlist for the selected series.')
                ->modalSubmitActionLabel('Yes, change parent playlist'),
        ]);

        return $bulkActions;
    }

    protected function playlistOptions(Series $record): array
    {
        [$groups] = self::getSourcePlaylistData(collect([$record]), 'series', 'source_series_id');

        if ($groups->isEmpty()) {
            return [$record->playlist_id => $record->playlist?->name];
        }

        $group = $groups->first();
        $options = self::availablePlaylistsForGroup($this->ownerRecord->id, $group, 'series', 'source_series_id', false);

        return $options->put($record->playlist_id, $record->playlist?->name)->toArray();
    }

    protected function changeSourcePlaylist(Series $record, int $playlistId): void
    {
        if ($playlistId === $record->playlist_id) {
            return;
        }

        $replacement = Series::where('playlist_id', $playlistId)
            ->where('source_series_id', $record->source_series_id)
            ->first();

        if (! $replacement) {
            FilamentNotification::make()
                ->title('Series not found in selected playlist')
                ->danger()
                ->send();

            return;
        }

        $this->ownerRecord->series()->detach($record->id);
        $this->ownerRecord->series()->attach($replacement->id);

        FilamentNotification::make()
            ->title('Parent playlist updated')
            ->success()
            ->send();

        $this->dispatch('refresh');
    }
}
