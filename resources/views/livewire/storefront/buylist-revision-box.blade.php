@php
    $format = fn (int $satang): string => \App\Support\Money::fromSatang($satang)->format();
    $primaryButton = 'inline-flex min-h-12 items-center justify-center rounded-control bg-accent px-5 text-body font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
    $secondaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none';
    $withPhoto = $changed->filter(fn ($item) => filled($item->photo_path))->values();
    $photos = $withPhoto->map(fn ($item) => [
        'url' => \Illuminate\Support\Facades\Storage::disk('public')->url($item->photo_path),
        'label' => $item->card_name,
        'alt' => __('storefront.buylist.revision.photo_alt', ['card' => $item->card_name]),
    ]);
    $photoPosition = $withPhoto->pluck('id')->flip();
@endphp

<div>
    @if ($submission->status === \App\Enums\BuylistStatus::Revised)
        <section
            aria-labelledby="buylist-revision-heading"
            class="flex flex-col gap-4 rounded-card border border-low/40 bg-low/10 p-4"
            x-data="{ open: null, zoom: false, confirming: false, labels: @js($photos->pluck('label')->all()), show(index) { this.open = index; this.zoom = false; }, step(by) { if (this.open !== null) { this.show((this.open + by + this.labels.length) % this.labels.length); } } }"
            x-on:keydown.escape.window="open = null; confirming = false"
        >
            <div>
                <h2 id="buylist-revision-heading" class="font-display text-card font-medium">{{ __('storefront.buylist.revision.title') }}</h2>
                <p class="mt-1 text-small text-muted">{{ __('storefront.buylist.revision.intro') }}</p>
            </div>

            <ul class="flex flex-col gap-2">
                @foreach ($changed as $item)
                    <li class="flex flex-col gap-2 rounded-card border border-line bg-surface p-3">
                        <div class="flex items-start gap-3">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-small font-medium">{{ $item->card_name }}</span>
                                <span class="block truncate text-label text-faint tabular-nums">{{ $item->display_code }} · {{ $item->language->shortCode() }} · {{ __('storefront.buylist.show.qty', ['n' => $item->qty]) }}</span>
                            </span>
                            @if ($photoPosition->has($item->id))
                                <button type="button" x-on:click="show({{ $photoPosition[$item->id] }})" aria-label="{{ __('storefront.buylist.revision.zoom', ['card' => $item->card_name]) }}" class="size-14 flex-none overflow-hidden rounded-control border border-line transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none">
                                    <img src="{{ $photos[$photoPosition[$item->id]]['url'] }}" alt="" class="size-full object-cover">
                                </button>
                            @endif
                        </div>
                        <p class="flex flex-wrap items-center gap-2 text-label text-muted">
                            <span>{{ __('storefront.buylist.revision.claimed') }}</span>
                            <x-condition-badge :condition="$item->claimed_condition" />
                            <span>{{ __('storefront.buylist.revision.found') }}</span>
                            <x-condition-badge :condition="$item->assessed_condition" />
                        </p>
                        <p class="text-label text-muted tabular-nums">
                            {{ __('storefront.buylist.revision.old_unit', ['amount' => $format($item->quoted_unit_satang)]) }}
                            → {{ __('storefront.buylist.revision.new_unit', ['amount' => $format($item->assessed_unit_satang)]) }}
                        </p>
                        <p class="text-label font-medium text-ink tabular-nums">{{ __('storefront.buylist.revision.line_change', ['old' => $format($item->quoted_line_satang), 'new' => $format($item->assessed_line_satang)]) }}</p>
                        @if (filled($item->assessed_note))
                            <p class="text-small text-ink"><span class="text-faint">{{ __('storefront.buylist.revision.note') }}</span> {{ $item->assessed_note }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>

            <p class="flex flex-wrap items-baseline justify-between gap-x-4 text-small text-muted tabular-nums">
                <span>{{ __('storefront.buylist.revision.quoted_total', ['amount' => $format($submission->quoted_total_satang)]) }}</span>
                <span class="text-body font-medium text-ink">{{ __('storefront.buylist.revision.new_total', ['amount' => $format($submission->final_total_satang)]) }}</span>
            </p>

            @if ($error !== null)
                <p role="alert" class="text-small font-medium text-danger">{{ $error }}</p>
            @endif

            <div class="flex flex-col gap-3">
                @if ($submission->final_total_satang > 0)
                    <button type="button" x-show="! confirming" wire:click="confirmNewTotal" wire:loading.attr="aria-busy" wire:target="confirmNewTotal,askForCardsBack" class="{{ $primaryButton }} w-full sm:w-auto sm:self-start">{{ __('storefront.buylist.revision.confirm', ['final' => $format($submission->final_total_satang)]) }}</button>
                @endif

                <div x-show="! confirming">
                    <button type="button" x-on:click="confirming = true" class="{{ $secondaryButton }}">{{ __('storefront.buylist.revision.return') }}</button>
                </div>
                <div x-show="confirming" x-cloak class="flex flex-col gap-2 rounded-card border border-line bg-surface p-3">
                    <p class="text-small text-muted">{{ __('storefront.buylist.revision.return_confirm') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="askForCardsBack" wire:loading.attr="aria-busy" wire:target="confirmNewTotal,askForCardsBack" class="{{ $secondaryButton }}">{{ __('storefront.buylist.revision.return_yes') }}</button>
                        <button type="button" x-on:click="confirming = false" class="{{ $secondaryButton }}">{{ __('storefront.buylist.revision.return_cancel') }}</button>
                    </div>
                </div>
            </div>

            {{-- The same lightbox as the graded page: no transitions, x-trap keeps focus inside and gives it back on close. --}}
            <div x-show="open !== null" x-cloak x-trap.noscroll="open !== null" role="dialog" aria-modal="true" aria-label="{{ __('storefront.buylist.revision.photos') }}" class="fixed inset-0 z-50 flex flex-col bg-bg/95">
                <div class="flex flex-wrap items-center justify-between gap-3 p-3">
                    <p class="text-small font-medium text-ink" aria-live="polite" x-text="open === null ? '' : labels[open] + ' · ' + (open + 1) + '/' + labels.length"></p>
                    <div class="flex items-center gap-2">
                        <button type="button" x-on:click="zoom = ! zoom" class="inline-flex min-h-11 items-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink" x-text="zoom ? @js(__('storefront.graded.fit')) : @js(__('storefront.graded.full_size'))">{{ __('storefront.graded.full_size') }}</button>
                        <button type="button" x-on:click="open = null" class="inline-flex min-h-11 items-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink">{{ __('storefront.graded.close') }}</button>
                    </div>
                </div>
                <div class="relative flex min-h-0 flex-1 items-center justify-center">
                    <div class="h-full w-full overflow-auto">
                        <div class="flex min-h-full min-w-full items-center justify-center p-2">
                            @foreach ($photos as $index => $photo)
                                <button type="button" x-show="open === {{ $index }}" x-on:click="zoom = ! zoom" aria-label="{{ __('storefront.graded.toggle_size') }}" class="block">
                                    <img src="{{ $photo['url'] }}" alt="{{ $photo['alt'] }}" :class="zoom ? 'max-h-none max-w-none cursor-zoom-out' : 'max-h-[80vh] max-w-full cursor-zoom-in'" class="rounded-card object-contain">
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @elseif ($error !== null)
        <p role="alert" class="rounded-card border border-line bg-sunken p-4 text-small font-medium text-danger">
            {{ $error }}
            <a href="{{ route('buylist.show', $submission->number) }}" class="ms-1 text-accent underline hover:text-ink">{{ __('storefront.buylist.revision.reload') }}</a>
        </p>
    @endif
</div>
