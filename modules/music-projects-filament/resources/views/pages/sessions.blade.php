<x-filament-panels::page>
    <p>Manage session metadata, review immutable revisions and recover a checkpoint. Audio execution requires a capable local host.</p>
    <livewire:music-session-workspace :workspace="$workspaceId" scope="filament" :key="'filament-music-workspace-'.$workspaceId" />
</x-filament-panels::page>
