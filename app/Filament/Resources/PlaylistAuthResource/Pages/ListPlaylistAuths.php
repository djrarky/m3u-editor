<?php

namespace App\Filament\Resources\PlaylistAuthResource\Pages;

use App\Filament\Resources\PlaylistAuthResource;
use Filament\Actions;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListPlaylistAuths extends ListRecords
{
    protected static string $resource = PlaylistAuthResource::class;

    protected ?string $subheading = 'Create credentials and assign them to your Playlist for simple authentication. They can also be used to access the Xtream API for the assigned Playlists.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->using(function (array $data, string $model): Model {
                    $data['user_id'] = auth()->id();
                    return $model::create($data);
                })
                ->successNotification(
                    FilamentNotification::make()
                        ->success()
                        ->title('Playlist Auth created')
                        ->body('You can now assign Playlists to this Auth.'),
                ),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
