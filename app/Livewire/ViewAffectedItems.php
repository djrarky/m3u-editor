<?php

namespace App\Livewire;

use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ViewAffectedItems extends Component implements HasTable
{
    use InteractsWithTable;

    /**
     * @var array<int, int|string>
     */
    public array $sourceIds = [];

    public string $modelClass;

    public string $sourceKey;

    public function mount(array $sourceIds, string $modelClass, string $sourceKey): void
    {
        $this->sourceIds = $sourceIds;
        $this->modelClass = $modelClass;
        $this->sourceKey = $sourceKey;
    }

    public function table(Table $table): Table
    {
        $modelClass = $this->modelClass;

        return $table
            ->query($modelClass::query()->whereIn($this->sourceKey, $this->sourceIds))
            ->columns([
                TextColumn::make('item')
                    ->label('Item')
                    ->state(fn ($record) => $record->title_custom
                        ?? $record->title
                        ?? $record->name
                        ?? $record->{$this->sourceKey}),
            ])
            ->paginated(true)
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    public function render(): View
    {
        return view('livewire.view-affected-items');
    }
}
