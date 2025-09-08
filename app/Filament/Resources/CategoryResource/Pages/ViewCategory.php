<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use App\Models\Category;
use App\Jobs\SyncPlaylistChildren;
use App\Filament\BulkActions\HandlesSourcePlaylist;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Pages\ViewRecord;

class ViewCategory extends ViewRecord
{
    use \App\Filament\BulkActions\HandlesSourcePlaylist;

    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                Actions\Action::make('add')
                    ->label('Add to Custom Playlist')
                    ->form([
                        Forms\Components\Select::make('playlist')
                            ->required()
                            ->live()
                            ->label('Custom Playlist')
                            ->helperText('Select the custom playlist you would like to add the category series to.')
                            ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->afterStateUpdated(function (Forms\Set $set, $state) {
                                if ($state) {
                                    $set('category', null);
                                }
                            })
                            ->searchable(),
                        Forms\Components\Select::make('category')
                            ->label('Custom Category')
                            ->disabled(fn(Get $get) => !$get('playlist'))
                            ->helperText(fn(Get $get) => !$get('playlist') ? 'Select a custom playlist first.' : 'Select the category you would like to assign to the series to.')
                            ->options(function ($get) {
                                $customList = CustomPlaylist::find($get('playlist'));
                                return $customList ? $customList->tags()
                                    ->where('type', $customList->uuid . '-category')
                                    ->get()
                                    ->mapWithKeys(fn($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                                    ->toArray() : [];
                            })
                            ->searchable(),
                    ])
                    ->action(function ($record, array $data): void {
                        $playlist = CustomPlaylist::findOrFail($data['playlist']);
                        $playlist->series()->syncWithoutDetaching($record->series()->pluck('id'));
                        SyncPlaylistChildrenJob::debounce($record->playlist, []);
                    })->after(function () {
                        FilamentNotification::make()
                            ->success()
                            ->title('Series added to custom playlist')
                            ->body('The selected series have been added to the chosen custom playlist.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-play')
                    ->modalIcon('heroicon-o-play')
                    ->modalDescription('Add the selected series to the chosen custom playlist.')
                    ->modalSubmitActionLabel('Add now'),
                Actions\Action::make('move')
                    ->label('Move Series to Category')
                    ->form([
                        Forms\Components\Select::make('category')
                            ->required()
                            ->live()
                            ->label('Category')
                            ->helperText('Select the category you would like to move the series to.')
                            ->options(fn(Get $get, $record) => Category::where(['user_id' => auth()->id(), 'playlist_id' => $record->playlist_id])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable(),
                    ])
                    ->action(function ($record, array $data): void {
                        $category = Category::findOrFail($data['category']);
                        $record->series()->update([
                            'category_id' => $category->id,
                        ]);
                        SyncPlaylistChildrenJob::debounce($record->playlist, []);
                    })->after(function () {
                        FilamentNotification::make()
                            ->success()
                            ->title('Series moved to category')
                            ->body('The series have been moved to the chosen category.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalIcon('heroicon-o-arrows-right-left')
                    ->modalDescription('Move the series to another category.')
                    ->modalSubmitActionLabel('Move now'),
                Actions\Action::make('process')
                    ->label('Fetch Series Metadata')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function ($record) {
                        foreach ($record->enabled_series as $series) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ProcessM3uImportSeriesEpisodes(
                                    playlistSeries: $series,
                                ));
                        }
                    })->after(function () {
                        FilamentNotification::make()
                            ->success()
                            ->title('Series are being processed')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription('Process series for this category now? Only enabled series will be processed. This will fetch all episodes and seasons for the category series. This may take a while depending on the number of series in the category.')
                    ->modalSubmitActionLabel('Yes, process now'),
                Actions\Action::make('sync')
                    ->label('Sync Series .strm files')
                    ->action(function ($record) {
                        foreach ($record->enabled_series as $series) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\SyncSeriesStrmFiles(
                                    series: $series,
                                ));
                        }
                    })->after(function () {
                        FilamentNotification::make()
                            ->success()
                            ->title('.strm files are being synced for current category series. Only enabled series will be synced.')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription('Sync category series .strm files now? This will generate .strm files for the enabled series at the path set for the series.')
                    ->modalSubmitActionLabel('Yes, sync now'),
                Actions\Action::make('enable')
                    ->label('Enable category series')
                    ->action(function ($record): void {
                        $record->series()->update(['enabled' => true]);
                        SyncPlaylistChildrenJob::debounce($record->playlist, []);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        FilamentNotification::make()
                            ->success()
                            ->title('Current category series enabled')
                            ->body('The current category series have been enabled.')
                            ->send();
                    })
                    ->color('success')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-check-circle')
                    ->modalIcon('heroicon-o-check-circle')
                    ->modalDescription('Enable the current category series now?')
                    ->modalSubmitActionLabel('Yes, enable now'),
                Actions\Action::make('disable')
                    ->label('Disable category series')
                    ->action(function ($record): void {
                        $record->series()->update(['enabled' => false]);
                        SyncPlaylistChildrenJob::debounce($record->playlist, []);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        FilamentNotification::make()
                            ->success()
                            ->title('Current category series disabled')
                            ->body('The current category series have been disabled.')
                            ->send();
                    })
                    ->color('warning')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->modalDescription('Disable the current category series now?')
                    ->modalSubmitActionLabel('Yes, disable now'),
            ])->button()->label('Actions'),
        ];
    }
}