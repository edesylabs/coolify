@props([
    'id' => null,
    'label' => null,
    'helper' => null,
    'placeholder' => 'https://example.com',
    'required' => false,
    'disabled' => false,
    'readonly' => false,
    'canGate' => null,
    'canResource' => null,
])

@php
    $modelBinding = $attributes->whereStartsWith('wire:model')->first();
    $canUpdate = true;

    // Check authorization if canGate and canResource are provided
    if ($canGate && $canResource) {
        try {
            $canUpdate = auth()->user()?->can($canGate, $canResource) ?? false;
        } catch (\Exception $e) {
            $canUpdate = false;
        }
        $disabled = $disabled || !$canUpdate;
    }
@endphp

<div class="w-full">
    @if ($label)
        <label class="flex gap-1 items-center mb-1 text-sm font-medium {{ $disabled ? 'text-neutral-600' : '' }}">
            {{ $label }}
            @if ($required)
                <x-highlighted text="*" />
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </label>
    @endif

    <div x-data="{
            domains: [],
            fqdnString: @entangle($modelBinding).live,
            nextId: 0,

            init() {
                // Parse comma-separated string into array with unique IDs
                this.parseDomains();

                // Watch for external changes to fqdnString
                this.$watch('fqdnString', value => {
                    this.parseDomains();
                });
            },

            parseDomains() {
                if (!this.fqdnString) {
                    this.domains = [{ id: this.nextId++, value: '' }];
                    return;
                }
                this.domains = this.fqdnString
                    .split(',')
                    .map(d => d.trim())
                    .filter(d => d.length > 0)
                    .map(d => ({ id: this.nextId++, value: d }));

                // Always have at least one empty field if no domains
                if (this.domains.length === 0) {
                    this.domains = [{ id: this.nextId++, value: '' }];
                }
            },

            syncToString() {
                // Filter out empty values and join
                const validDomains = this.domains
                    .map(d => d.value.trim())
                    .filter(d => d.length > 0);

                // Check for duplicates and warn user
                const uniqueDomains = [...new Set(validDomains)];
                if (uniqueDomains.length !== validDomains.length) {
                    // Duplicates found - remove them
                    this.fqdnString = uniqueDomains.join(',');
                } else {
                    this.fqdnString = validDomains.join(',');
                }
            },

            addDomain() {
                this.domains.push({ id: this.nextId++, value: '' });
                this.$nextTick(() => {
                    // Focus the newly added input
                    const inputs = this.$el.querySelectorAll('.domain-input');
                    if (inputs.length > 0) {
                        inputs[inputs.length - 1].focus();
                    }
                });
            },

            removeDomain(id) {
                this.domains = this.domains.filter(d => d.id !== id);
                // Ensure at least one empty field remains
                if (this.domains.length === 0) {
                    this.domains = [{ id: this.nextId++, value: '' }];
                }
                this.syncToString();
            },

            updateDomain(id, value) {
                const domain = this.domains.find(d => d.id === id);
                if (domain) {
                    domain.value = value;
                    this.syncToString();
                }
            },

            isDuplicate(id) {
                const domain = this.domains.find(d => d.id === id);
                if (!domain || !domain.value.trim()) return false;

                const trimmedValue = domain.value.trim();
                const count = this.domains.filter(d => d.value.trim() === trimmedValue).length;
                return count > 1;
            }
        }"
        class="w-full space-y-2">

        {{-- Domain Input Fields --}}
        <div class="space-y-2 max-h-96 overflow-y-auto scrollbar pr-1">
            <template x-for="domain in domains" :key="domain.id">
                <div class="flex items-center gap-2">
                    <input
                        type="text"
                        :value="domain.value"
                        @input="updateDomain(domain.id, $event.target.value)"
                        :placeholder="{{ json_encode($placeholder) }}"
                        @readonly($readonly)
                        @disabled($disabled)
                        class="domain-input flex-1 py-1.5 px-2 w-full text-sm rounded-sm border-0 ring-2 ring-inset ring-neutral-200 dark:ring-coolgray-300 bg-white dark:bg-coolgray-100 focus:border-l-4 focus:border-l-coollabs dark:focus:border-l-warning text-black dark:text-white placeholder:text-neutral-400 dark:placeholder:text-neutral-600"
                        :class="{
                            'opacity-50': {{ $disabled ? 'true' : 'false' }},
                            'ring-red-500 dark:ring-red-500': isDuplicate(domain.id)
                        }"
                        wire:loading.class="opacity-50"
                    />
                    <button
                        type="button"
                        @click="removeDomain(domain.id)"
                        :disabled="{{ $disabled ? 'true' : 'false' }}"
                        x-show="domains.length > 1 || domain.value.trim() !== ''"
                        class="shrink-0 p-2 text-neutral-400 hover:text-red-600 dark:hover:text-red-400 transition-colors {{ $disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer' }}"
                        title="Remove domain">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </template>
        </div>

        {{-- Add Domain Button --}}
        @if (!$disabled)
            <x-forms.button type="button" @click="addDomain()">
                + Add Domain
            </x-forms.button>
        @endif
    </div>

    @error($modelBinding)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>
