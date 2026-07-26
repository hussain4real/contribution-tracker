<x-filament-panels::page>
    <div class="space-y-4">
        @foreach ($this->getFeatures() as $feature)
            <x-filament::section>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="space-y-1">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ $feature['name'] }}
                        </h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $feature['description'] }}
                        </p>
                    </div>

                    <x-filament::badge
                        :color="match ($feature['status']) {
                            'active' => 'success',
                            'partial' => 'warning',
                            default => 'danger',
                        }"
                    >
                        {{ match ($feature['status']) {
                            'active' => 'Active for everyone',
                            'partial' => 'Partially rolled out',
                            default => 'Inactive',
                        } }}
                    </x-filament::badge>
                </div>

                <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Activated users</dt>
                        <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                            {{ $feature['status'] === 'active' ? 'All' : $feature['activated_count'] }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Resolved users</dt>
                        <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $feature['total_resolved'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Key</dt>
                        <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $feature['key'] }}</dd>
                    </div>
                </dl>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
