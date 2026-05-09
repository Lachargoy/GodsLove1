<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="app-shell">
        <div class="app-page">
            {{ $slot }}
        </div>
    </flux:main>
</x-layouts::app.sidebar>
