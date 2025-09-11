<?php

use App\Models\{User, Playlist, Channel, Series, CustomPlaylist};
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

it('changes parent playlist for selected channels', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create();
    $playlistB = Playlist::factory()->for($user)->create();
    $channelA = Channel::factory()->for($user)->for($playlistA)->create(['source_id' => 1]);
    $channelB = Channel::factory()->for($user)->for($playlistB)->create(['source_id' => 1]);
    $custom = CustomPlaylist::factory()->for($user)->create();
    $custom->channels()->attach($channelA);

    $replacement = Channel::where('playlist_id', $playlistB->id)
        ->where('source_id', $channelA->source_id)
        ->first();

    $custom->channels()->detach($channelA->id);
    $custom->channels()->attach($replacement->id);

    expect($custom->channels()->whereKey($replacement->id)->exists())->toBeTrue();
    expect($custom->channels()->whereKey($channelA->id)->exists())->toBeFalse();
});

it('changes parent playlist for selected vod', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create();
    $playlistB = Playlist::factory()->for($user)->create();
    $vodA = Channel::factory()->for($user)->for($playlistA)->create(['source_id' => 2, 'is_vod' => true]);
    $vodB = Channel::factory()->for($user)->for($playlistB)->create(['source_id' => 2, 'is_vod' => true]);
    $custom = CustomPlaylist::factory()->for($user)->create();
    $custom->channels()->attach($vodA);

    $replacement = Channel::where('playlist_id', $playlistB->id)
        ->where('source_id', $vodA->source_id)
        ->first();

    $custom->channels()->detach($vodA->id);
    $custom->channels()->attach($replacement->id);

    expect($custom->channels()->whereKey($replacement->id)->exists())->toBeTrue();
    expect($custom->channels()->whereKey($vodA->id)->exists())->toBeFalse();
});

it('changes parent playlist for selected series', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create();
    $playlistB = Playlist::factory()->for($user)->create();
    $seriesA = Series::factory()->for($user)->for($playlistA)->create(['source_series_id' => 10]);
    $seriesB = Series::factory()->for($user)->for($playlistB)->create(['source_series_id' => 10]);
    $custom = CustomPlaylist::factory()->for($user)->create();
    $custom->series()->attach($seriesA);

    $replacement = Series::where('playlist_id', $playlistB->id)
        ->where('source_series_id', $seriesA->source_series_id)
        ->first();

    $custom->series()->detach($seriesA->id);
    $custom->series()->attach($replacement->id);

    expect($custom->series()->whereKey($replacement->id)->exists())->toBeTrue();
    expect($custom->series()->whereKey($seriesA->id)->exists())->toBeFalse();
});

it('lists parent playlist when both parent and child items selected', function () {
    $user = User::factory()->create();
    $parent = Playlist::factory()->for($user)->create();
    $child = Playlist::factory()->for($user)->create(['parent_id' => $parent->id]);

    $custom = CustomPlaylist::factory()->for($user)->create();

    Channel::withoutEvents(function () use ($user, $parent, $child, $custom, &$parentChannel, &$childChannel) {
        $parentChannel = Channel::factory()->for($user)->for($parent)->create(['source_id' => 5]);
        $childChannel = Channel::factory()->for($user)->for($child)->create(['source_id' => 5]);
        $custom->channels()->attach([$parentChannel->id, $childChannel->id]);
    });

    $manager = new class($custom) {
        use \App\Filament\BulkActions\HandlesSourcePlaylist;

        public function __construct(public CustomPlaylist $ownerRecord) {}

        public function playlistOptions(Channel $record): array
        {
            [$groups] = self::getSourcePlaylistData(collect([$record]), 'channels', 'source_id');

            if ($groups->isEmpty()) {
                return [$record->playlist_id => $record->playlist?->name];
            }

            $group = $groups->first();

            return self::availablePlaylistsForGroup($this->ownerRecord->id, $group, 'channels', 'source_id', false)
                ->put($record->playlist_id, $record->playlist?->name)
                ->toArray();
        }

        public function bulkOptions(EloquentCollection $records): array
        {
            $playlists = [];

            foreach ($records as $record) {
                $playlists = array_replace($playlists, $this->playlistOptions($record));
            }

            return $playlists;
        }
    };

    $options = collect($manager->bulkOptions(new EloquentCollection([$parentChannel, $childChannel])));

    expect($options->keys())->toContain($parent->id);

    foreach ([$parentChannel, $childChannel] as $record) {
        $exists = Channel::where('playlist_id', $parent->id)
            ->where('source_id', $record->source_id)
            ->exists();

        if ($exists && $record->playlist_id !== $parent->id) {
            $replacement = Channel::where('playlist_id', $parent->id)
                ->where('source_id', $record->source_id)
                ->first();

            Channel::withoutEvents(function () use ($custom, $record, $replacement) {
                $custom->channels()->detach($record->id);
                $custom->channels()->attach($replacement->id);
            });
        }
    }

    expect($custom->channels()->whereKey($childChannel->id)->exists())->toBeFalse();
    expect($custom->channels()->whereKey($parentChannel->id)->exists())->toBeTrue();
});

it('lists selected parent playlists even without duplicates', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create();
    $playlistB = Playlist::factory()->for($user)->create();

    $custom = CustomPlaylist::factory()->for($user)->create();

    Channel::withoutEvents(function () use ($user, $playlistA, $playlistB, $custom, &$channelA, &$channelB) {
        $channelA = Channel::factory()->for($user)->for($playlistA)->create(['source_id' => 6]);
        $channelB = Channel::factory()->for($user)->for($playlistB)->create(['source_id' => 7]);
        $custom->channels()->attach([$channelA->id, $channelB->id]);
    });

    $manager = new class($custom) {
        use \App\Filament\BulkActions\HandlesSourcePlaylist;

        public function __construct(public CustomPlaylist $ownerRecord) {}

        public function playlistOptions(Channel $record): array
        {
            [$groups] = self::getSourcePlaylistData(collect([$record]), 'channels', 'source_id');

            if ($groups->isEmpty()) {
                return [$record->playlist_id => $record->playlist?->name];
            }

            $group = $groups->first();

            return self::availablePlaylistsForGroup($this->ownerRecord->id, $group, 'channels', 'source_id', false)
                ->put($record->playlist_id, $record->playlist?->name)
                ->toArray();
        }

        public function bulkOptions(EloquentCollection $records): array
        {
            $playlists = [];

            foreach ($records as $record) {
                $playlists = array_replace($playlists, $this->playlistOptions($record));
            }

            return $playlists;
        }
    };

    $options = collect($manager->bulkOptions(new EloquentCollection([$channelA, $channelB])));

    expect($options->keys())->toContain($playlistA->id);
    expect($options->keys())->toContain($playlistB->id);
});
