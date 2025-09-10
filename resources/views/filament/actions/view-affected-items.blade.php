<div class="p-2">
    @livewire(
        \App\Livewire\ViewAffectedItems::class,
        ['sourceIds' => $sourceIds, 'modelClass' => $modelClass, 'sourceKey' => $sourceKey],
        key('affected-items-' . md5(json_encode($sourceIds)))
    )
</div>
