<x-filament-panels::page>
    @if (count($tables = $this->getTables()))
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Table</span>
            <x-filament::input.wrapper class="w-64">
                <x-filament::input.select wire:model.live="source">
                    @foreach ($tables as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
