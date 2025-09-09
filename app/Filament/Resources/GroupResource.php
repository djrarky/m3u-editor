<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GroupResource\Pages;
use App\Filament\Resources\GroupResource\RelationManagers;
use App\Models\Group;
use App\Models\Playlist;
use App\Filament\Concerns\DisplaysPlaylistMembership;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Resource as FilamentResource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GroupResource extends FilamentResource
{
    use \App\Filament\BulkActions\HandlesSourcePlaylist;
    use DisplaysPlaylistMembership;
    protected static ?string $model = Group::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'name_internal'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id())
            ->whereHas('playlist', fn (Builder $query) => $query->whereNull('parent_id'));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('playlist', fn (Builder $query) => $query->whereNull('parent_id'));
    }

    protected static ?string $navigationGroup = 'Channels & VOD';

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount('live_channels')
                    ->withCount('enabled_live_channels')
                    ->withCount('vod_channels')
                    ->withCount('enabled_vod_channels');
            })
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextInputColumn::make('name')
                    ->label('Name')
                    ->rules(['min:0', 'max:255'])
                    ->placeholder(fn($record) => $record->name_internal)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('sort_order')
                    ->label('Sort Order')
                    ->rules(['min:0'])
                    ->type('number')
                    ->placeholder('Sort Order')
                    ->sortable()
                    ->tooltip(fn($record) => $record->playlist->auto_sort ? 'Playlist auto-sort enabled; disable to change' : 'Group sort order')
                    ->disabled(fn($record) => $record->playlist->auto_sort)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('name_internal')
                    ->label('Default name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('live_channels_count')
                    ->label('Live Channels')
                    ->description(fn(Group $record): string => "Enabled: {$record->enabled_live_channels_count}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vod_channels_count')
                    ->label('VOD Channels')
                    ->description(fn(Group $record): string => "Enabled: {$record->enabled_vod_channels_count}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('playlist.name')
                    ->label('Playlist')
                    ->formatStateUsing(fn($state, Group $record) => self::playlistDisplay($record, 'name_internal'))
                    ->tooltip(fn(Group $record) => self::playlistTooltip($record, 'name_internal'))
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_custom')
                    ->label('Custom')
                    ->icon(fn(string $state): string => match ($state) {
                        '1' => 'heroicon-o-check-circle',
                        '0' => 'heroicon-o-minus-circle',
                        '' => 'heroicon-o-minus-circle',
                    })->color(fn(string $state): string => match ($state) {
                        '1' => 'success',
                        '0' => 'danger',
                        '' => 'danger',
                    })->toggleable()->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('playlist')
                    ->relationship('playlist', 'name', fn (Builder $query) => $query->whereNull('parent_id'))
                    ->multiple()
                    ->preload()
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    self::addToCustomPlaylistAction(
                        Channel::class,
                        'channels',
                        'source_id',
                        'channel',
                        '',
                        'Custom Group',
                        fn ($records) => $records->map(fn ($group) => [
                            'group'    => $group,
                            'channels' => $group->channels()
                                ->select('id', 'playlist_id', 'source_id', 'title')
                                ->get(),
                        ])
                        ->action(function ($record, array $data): void {
                            $playlist = CustomPlaylist::findOrFail($data['playlist']);
                            $playlist->channels()->syncWithoutDetaching($record->channels()->pluck('id'));
                            if ($data['category']) {
                                $playlist->syncTagsWithType([$data['category']], $playlist->uuid);
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Group channels added to custom playlist')
                                ->body('The groups channels have been added to the chosen custom playlist.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-play')
                        ->modalIcon('heroicon-o-play')
                        ->modalDescription('Add the group channels to the chosen custom playlist.')
                        ->modalSubmitActionLabel('Add now'),
                    Tables\Actions\Action::make('move')
                        ->label('Move Channels to Group')
                        ->form([
                            Forms\Components\Select::make('group')
                                ->required()
                                ->live()
                                ->label('Group')
                                ->helperText('Select the group you would like to move the channels to.')
                                ->options(fn(Get $get, $record) => Group::where(['user_id' => auth()->id(), 'playlist_id' => $record->playlist_id])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                        ])
                        ->action(function ($record, array $data): void {
                            $group = Group::findOrFail($data['group']);
                            $record->channels()->update([
                                'group' => $group->name,
                                'group_id' => $group->id,
                            ]);
                            \App\Jobs\SyncPlaylistChildren::debounce($record->playlist, []);
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Channels moved to group')
                                ->body('The group channels have been moved to the chosen group.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription('Move the group channels to the another group.')
                        ->modalSubmitActionLabel('Move now'),

                    Tables\Actions\Action::make('enable')
                        ->label('Enable group channels')
                        ->action(function ($record): void {
                            $record->channels()->update([
                                'enabled' => true,
                            ]);
                            \App\Jobs\SyncPlaylistChildren::debounce($record->playlist, []);
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Group channels enabled')
                                ->body('The group channels have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable group channels now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    Tables\Actions\Action::make('disable')
                        ->label('Disable group channels')
                        ->action(function ($record): void {
                            $record->channels()->update([
                                'enabled' => false,
                            ]);
                            \App\Jobs\SyncPlaylistChildren::debounce($record->playlist, []);
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Group channels disabled')
                                ->body('The groups channels have been disabled.')
                                ->send();
                        })
                        ->color('warning')
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable group channels now?')
                        ->modalSubmitActionLabel('Yes, disable now'),

                    Tables\Actions\DeleteAction::make()
                        ->hidden(fn($record) => !$record->is_custom),
                ])->button()->hiddenLabel()->size('sm'),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    self::addToCustomPlaylistBulkAction(
                        Channel::class,
                        'channels',
                        'source_id',
                        'channel',
                        '',
                        'Custom Group',
                        fn ($records) => $records->map(fn ($group) => [
                            'group'    => $group,
                            'channels' => $group->channels()
                                ->select('id', 'playlist_id', 'source_id', 'title')
                                ->get(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $playlist = CustomPlaylist::findOrFail($data['playlist']);
                            foreach ($records as $record) {
                                // Sync the channels to the custom playlist
                                // Prevents duplicates in the playlist
                                $playlist->channels()->syncWithoutDetaching($record->channels()->pluck('id'));
                                if ($data['category']) {
                                    $playlist->syncTagsWithType([$data['category']], $playlist->uuid);
                                }
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Group channels added to custom playlist')
                                ->body('The groups channels have been added to the chosen custom playlist.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-play')
                        ->modalIcon('heroicon-o-play')
                        ->modalDescription('Add the group channels to the chosen custom playlist.')
                        ->modalSubmitActionLabel('Add now'),
                    Tables\Actions\BulkAction::make('move')
                        ->label('Move Channels to Group')
                        ->form([
                            Forms\Components\Select::make('group')
                                ->required()
                                ->live()
                                ->label('Group')
                                ->helperText('Select the group you would like to move the channels to.')
                                ->options(
                                    fn() => Group::query()
                                        ->with(['playlist'])
                                        ->where(['user_id' => auth()->id()])
                                        ->get(['name', 'id', 'playlist_id'])
                                        ->transform(fn($group) => [
                                            'id' => $group->id,
                                            'name' => $group->name . ' (' . $group->playlist->name . ')',
                                        ])->pluck('name', 'id')
                                )->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $group = Group::findOrFail($data['group']);
                            foreach ($records as $record) {
                                // Update the channels to the new group
                                if ($group->playlist_id !== $record->playlist_id) {
                                    FilamentNotification::make()
                                        ->warning()
                                        ->title('Warning')
                                        ->body("Cannot move \"{$group->name}\" to \"{$record->name}\" as they belong to different playlists.")
                                        ->persistent()
                                        ->send();
                                    continue;
                                }
                                $record->channels()->update([
                                    'group' => $group->name,
                                    'group_id' => $group->id,
                                ]);
                                \App\Jobs\SyncPlaylistChildren::debounce($record->playlist, []);
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Channels moved to group')
                                ->body('The group channels have been moved to the chosen group.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription('Move the group channels to the another group.')
                        ->modalSubmitActionLabel('Move now'),
                    Tables\Actions\BulkAction::make('enable')
                        ->label('Enable group channels')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->channels()->update([
                                    'enabled' => true,
                                ]);
                                \App\Jobs\SyncPlaylistChildren::debounce($record->playlist, []);
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Selected group channels enabled')
                                ->body('The selected group channels have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected group(s) channels now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    Tables\Actions\BulkAction::make('disable')
                        ->label('Disable group channels')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->channels()->update([
                                    'enabled' => false,
                                ]);
                                \App\Jobs\SyncPlaylistChildren::debounce($record->playlist, []);
                            }
                        })->after(function () {
                            FilamentNotification::make()
                                ->success()
                                ->title('Selected group channels disabled')
                                ->body('The selected groups channels have been disabled.')
                                ->send();
                        })
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected group(s) channels now?')
                        ->modalSubmitActionLabel('Yes, disable now')
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ChannelsRelationManager::class,
            RelationManagers\VodRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroups::route('/'),
            // 'create' => Pages\CreateGroup::route('/create'),
            'view' => Pages\ViewGroup::route('/{record}'),
            // 'edit' => Pages\EditGroup::route('/{record}/edit'),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        // return parent::infolist($infolist);
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Group Details')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->badge(),
                        Infolists\Components\TextEntry::make('playlist.name')
                            ->label('Playlist')
                            //->badge(),
                            ->url(fn($record) => PlaylistResource::getUrl('edit', ['record' => $record->playlist_id])),
                    ])
            ]);
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('playlist_id')
                ->required()
                ->label('Playlist')
                ->relationship(name: 'playlist', titleAttribute: 'name')
                ->helperText('Select the playlist you would like to add the group to.')
                ->preload()
                ->hiddenOn(['edit'])
                ->searchable(),
            Forms\Components\TextInput::make('sort_order')
                ->label('Sort Order')
                ->numeric()
                ->default(9999)
                ->helperText('Enter a number to define the sort order (e.g., 1, 2, 3). Lower numbers appear first.')
                ->rules(['integer', 'min:0']),
        ];
    }
}