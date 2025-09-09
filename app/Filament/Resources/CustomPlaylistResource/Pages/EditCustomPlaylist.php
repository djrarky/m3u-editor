<?php

namespace App\Filament\Resources\CustomPlaylistResource\Pages;

use App\Filament\Resources\CustomPlaylistResource;
use App\Services\EpgCacheService;
use Filament\Actions;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Pages\EditRecord;

class EditCustomPlaylist extends EditRecord
{
    protected static string $resource = CustomPlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    public function clearEpgFileCache()
    {
        $cleared = EpgCacheService::clearPlaylistEpgCacheFile($this->record);
        if ($cleared) {
            FilamentNotification::make()
                ->title('EPG File Cache Cleared')
                ->body('The EPG file cache has been successfully cleared.')
                ->success()
                ->send();
        } else {
            FilamentNotification::make()
                ->title('EPG File Cache Not Found')
                ->body('No EPG cache files found.')
                ->warning()
                ->send();
        }

        // Close the modal
        $this->dispatch('close-modal', id: 'epg-url-modal-' . $this->record->getKey());
    }
}
