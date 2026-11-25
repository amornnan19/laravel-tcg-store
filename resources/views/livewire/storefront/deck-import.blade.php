@php
    use App\Enums\DeckBucket;
    use App\Enums\DeckLineKind;

    $parse = $this->result;
    $checked = $parse !== null;
    $stale = $this->isStale();
    $primaryButton = 'bg-accent font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
    $secondaryButton = 'border border-line-strong bg-surface text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none';
    $buttonBase = 'inline-flex min-h-11 items-center justify-center rounded-control px-4 text-small';
    $input = 'block min-h-11 w-full rounded-control border border-line-strong bg-surface px-3 text-body text-ink placeholder:text-faint';
    $format = fn (int $satang): string => \App\Support\Money::fromSatang($satang)->format();
    $rawError = $errors->first('raw');
    $gameError = $errors->first('gameId');
    $saveNameError = $errors->first('saveName');
    $staleJs = $stale ? 'true' : 'false';
    // A result the text has moved on from: the controls on it stay in the tab order but do nothing.
    $disabledBinding = "dirty || {$staleJs} ? 'true' : null";
    $collapseFrom = 8;
@endphp

<div x-data="{ dirty: false }" x-on:deck-checked.window="dirty = false; $nextTick(() => { $refs.summary?.scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); $refs.summary?.focus({ preventScroll: true }) })" x-on:deck-alerted.window="$nextTick(() => document.getElementById('deck-missing-heading')?.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }))" @class(['flex flex-col gap-6', 'max-lg:pb-28' => $checked])>
    <h1 class="font-display text-h2 font-medium lg:text-h1">{{ __('storefront.deck.title') }}</h1>

    <section class="flex flex-col gap-4 rounded-card border border-line bg-sunken p-4">
        <div>
            <p id="deck-game-label" class="mb-1 block text-small text-muted">{{ __('storefront.deck.game_group') }}</p>
            <div role="group" aria-labelledby="deck-game-label" class="flex flex-wrap gap-2">
                @foreach ($this->games as $game)
                    <x-filter-chip wire:key="game-{{ $game->id }}" :active="$gameId === $game->id" wire:click="selectGame({{ $game->id }})">{{ $game->name_en }}</x-filter-chip>
                @endforeach
            </div>
            @if ($gameError !== '')
                <p class="mt-1 text-small text-danger" role="alert">{{ $gameError }}</p>
            @endif
        </div>

        <div>
            <p id="deck-language-label" class="mb-1 block text-small text-muted">{{ __('storefront.deck.language_group') }}</p>
            <div role="group" aria-labelledby="deck-language-label" class="flex flex-wrap gap-2">
                <x-filter-chip :active="$language === null" wire:click="selectLanguage(null)">{{ __('storefront.deck.language_all') }}</x-filter-chip>
                @foreach (\App\Enums\Language::cases() as $option)
                    <x-filter-chip wire:key="language-{{ $option->value }}" :active="$language === $option" wire:click="selectLanguage('{{ $option->value }}')">{{ $option->shortCode() }}</x-filter-chip>
                @endforeach
            </div>
        </div>

        <div>
            <label for="deck-raw" class="mb-1 block text-small text-muted">{{ __('storefront.deck.raw_label') }}</label>
            <textarea
                id="deck-raw"
                rows="10"
                wire:model="raw"
                x-on:input="dirty = true"
                x-on:paste="$nextTick(() => setTimeout(() => $wire.check(), 0))"
                placeholder="{{ __('storefront.deck.placeholder') }}"
                autocomplete="off"
                spellcheck="false"
                aria-describedby="deck-raw-hint{{ $rawError !== '' ? ' deck-raw-error' : '' }}"
                @if ($rawError !== '') aria-invalid="true" @endif
                @class([$input, 'py-2', 'resize-y'])
            ></textarea>
            <p id="deck-raw-hint" class="mt-1 text-label text-muted">{{ __('storefront.deck.hint') }}</p>
            @if ($rawError !== '')
                <p id="deck-raw-error" class="mt-1 text-small text-danger" role="alert">{{ $rawError }}</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($checked)
                {{-- Two buttons, not one with a swapped class: both colours on one element would fight by stylesheet order. Each has
                     its own wire:key, so the pre-check button is replaced rather than morphed into one: Alpine does not start an
                     x-show added to an element it already initialised. --}}
                <button type="button" wire:click="check" wire:loading.attr="aria-busy" wire:target="check" wire:key="deck-check-stale" x-show="dirty || {{ $staleJs }}" x-cloak class="{{ $buttonBase }} {{ $primaryButton }}">{{ __('storefront.deck.check') }}</button>
                <button type="button" wire:click="check" wire:loading.attr="aria-busy" wire:target="check" wire:key="deck-check-fresh" x-show="! (dirty || {{ $staleJs }})" x-cloak class="{{ $buttonBase }} {{ $secondaryButton }}">{{ __('storefront.deck.check') }}</button>
                <button type="button" wire:click="clear" class="ml-auto inline-flex min-h-11 items-center rounded-control px-3 text-small text-muted transition-[color] duration-160 ease-ui hover:text-ink motion-reduce:transition-none">{{ __('storefront.deck.clear') }}</button>
            @else
                <button type="button" wire:click="check" wire:loading.attr="aria-busy" wire:target="check" wire:key="deck-check-first" class="{{ $buttonBase }} {{ $primaryButton }}">{{ __('storefront.deck.check') }}</button>
            @endif
        </div>
        @if ($checked)
            @auth
                <div x-show="! (dirty || {{ $staleJs }})" x-cloak class="flex max-w-md items-center gap-2">
                    <label for="deck-save-name" class="sr-only">{{ __('storefront.deck.save_name_label') }}</label>
                    <input id="deck-save-name" type="text" wire:model="saveName" maxlength="100" autocomplete="off" placeholder="{{ __('storefront.deck.save_name_label') }}" @if ($saveNameError !== '') aria-invalid="true" aria-describedby="deck-save-name-error" @endif class="{{ $input }} min-w-0 flex-1 text-small">
                    <button type="button" wire:click="save" wire:loading.attr="aria-busy" wire:target="save" x-bind:aria-disabled="{{ $disabledBinding }}" class="{{ $buttonBase }} {{ $secondaryButton }} flex-none">{{ __('storefront.deck.save') }}</button>
                </div>
            @endauth
        @endif
        @if ($saveNameError !== '')
            <p id="deck-save-name-error" class="text-small text-danger" role="alert">{{ $saveNameError }}</p>
        @endif
        @if ($saveNotice !== null)
            <p role="status" class="text-small font-medium text-stock">{{ $saveNotice }}</p>
        @endif
    </section>

    @if ($checked)
        @php
            $counts = $parse->counts();
            $unread = $parse->unread();
            $matched = fn (DeckBucket $bucket): array => array_values(array_filter($parse->inBucket($bucket), fn ($line): bool => $line->kind === DeckLineKind::Matched));
            $unmatched = array_values(array_filter($parse->lines, fn ($line): bool => $line->kind === DeckLineKind::Unmatched));
            $missing = $matched(DeckBucket::Missing);
            $languageEmptied = fn ($line): bool => $line->kind === DeckLineKind::Matched
                && $language !== null
                && $line->otherLanguagesAvailable > 0
                && collect($line->variants)->every(fn ($variant): bool => $variant->availableQty < 1);
            $emptiedCount = count(array_filter($parse->lines, $languageEmptied));
            $alertableCount = count($this->alertableLines());
            $requested = $parse->totalRequested();
            $have = $parse->totalCards();
            $sections = [
                ['bucket' => DeckBucket::Partial, 'lines' => $matched(DeckBucket::Partial)],
                ['bucket' => DeckBucket::Missing, 'lines' => $missing],
                ['bucket' => DeckBucket::Full, 'lines' => $matched(DeckBucket::Full)],
            ];
        @endphp

        @if ($stale)
            <p role="status" class="text-small font-medium text-low">{{ __('storefront.deck.stale') }}</p>
        @else
            <p role="status" x-show="dirty" x-cloak class="text-small font-medium text-low">{{ __('storefront.deck.stale') }}</p>
        @endif

        <div class="flex flex-col gap-6" x-bind:class="dirty && 'opacity-60'" @class(['opacity-60' => $stale])>
            <section aria-labelledby="deck-summary-heading" x-ref="summary" tabindex="-1" class="scroll-mt-4 rounded-card border border-line-strong bg-surface p-4 focus:outline-none">
                <h2 id="deck-summary-heading" class="font-display text-card font-medium">{{ __('storefront.deck.summary_title') }}</h2>

                <div aria-live="polite" class="mt-3 grid grid-cols-3 gap-2">
                    <x-deck-count :label="DeckBucket::Full->label()" :count="$counts['full']" tone="text-stock" href="#deck-full-heading" x-on:click="$dispatch('deck-open-full')" />
                    <x-deck-count :label="DeckBucket::Partial->label()" :count="$counts['partial']" tone="text-low" href="#deck-partial-heading" />
                    <x-deck-count :label="DeckBucket::Missing->label()" :count="$counts['missing']" tone="text-danger" :href="$missing !== [] ? '#deck-missing-heading' : '#deck-unmatched-heading'" />
                </div>

                <p class="mt-3 text-small text-muted tabular-nums">{{ __('storefront.deck.cards_line', ['have' => $have, 'want' => $requested, 'short' => max(0, $requested - $have)]) }}</p>
                <p class="mt-1 flex items-baseline justify-between gap-4">
                    <span class="text-small text-muted">{{ __('storefront.deck.total_label') }}</span>
                    <x-price :satang="$parse->totalSatang()" class="text-h3 font-medium text-accent" />
                </p>

                @if ($addNotice !== null)
                    <p role="alert" @class(['mt-3 text-small font-medium', 'text-danger' => $addFailed, 'text-low' => ! $addFailed])>{{ $addNotice }}</p>
                @endif

                <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
                    {{-- The one gold button once the list is checked and fresh. On a phone the same action is the fixed bar below. --}}
                    @if ($parse->totalCards() > 0)
                        <button type="button" wire:click="addAll('{{ $parse->signature($gameId) }}')" wire:loading.attr="aria-busy" wire:target="addAll" wire:key="deck-add-all" x-show="! (dirty || {{ $staleJs }})" x-cloak class="{{ $buttonBase }} {{ $primaryButton }} max-lg:hidden">{{ __('storefront.deck.add_all', ['n' => $parse->totalCards(), 'total' => $format($parse->totalSatang())]) }}</button>
                    @endif
                    @if ($emptiedCount > 0)
                        <button type="button" wire:click="selectLanguage(null)" x-bind:aria-disabled="{{ $disabledBinding }}" class="{{ $buttonBase }} {{ $secondaryButton }}">{{ __('storefront.deck.all_languages') }}</button>
                    @endif
                </div>
            </section>

            @foreach ($sections as $section)
                @php
                    $bucket = $section['bucket'];
                    $bucketLines = $section['lines'];
                    $collapsible = $bucket === DeckBucket::Full && count($bucketLines) > $collapseFrom;
                @endphp
                @if ($bucketLines !== [])
                    <section
                        wire:key="bucket-{{ $bucket->value }}"
                        aria-labelledby="deck-{{ $bucket->value }}-heading"
                        class="flex flex-col gap-3"
                        @if ($collapsible) x-data="{ open: {{ array_filter($bucketLines, fn ($line): bool => array_intersect(array_keys($line->variants), $failedVariants) !== []) !== [] ? 'true' : 'false' }} }" x-on:deck-open-full.window="open = true" @endif
                    >
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h2 id="deck-{{ $bucket->value }}-heading" class="flex items-baseline gap-3 font-display text-card font-medium">
                                {{ $bucket->label() }}
                                <span class="text-small font-normal text-faint tabular-nums">{{ __('storefront.deck.section_count', ['n' => count($bucketLines)]) }}</span>
                            </h2>
                            @if ($collapsible)
                                <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-controls="deck-full-list" class="inline-flex min-h-11 items-center rounded-control px-3 text-small text-accent hover:text-ink">
                                    <span x-show="! open">{{ __('storefront.deck.show_all', ['n' => count($bucketLines)]) }}</span>
                                    <span x-show="open" x-cloak>{{ __('storefront.deck.hide_all') }}</span>
                                </button>
                            @endif
                            @if ($bucket === DeckBucket::Missing && $alertableCount > 0)
                                <button type="button" wire:click="alertMissing" wire:loading.attr="aria-busy" wire:target="alertMissing" x-bind:aria-disabled="{{ $disabledBinding }}" x-bind:class="(dirty || {{ $staleJs }}) && 'opacity-60'" class="{{ $buttonBase }} {{ $secondaryButton }}">{{ __('storefront.deck.alert_missing', ['n' => $alertableCount]) }}</button>
                            @endif
                            @if ($bucket === DeckBucket::Missing && $alertableCount > 0)
                                <p x-show="dirty || {{ $staleJs }}" x-cloak class="basis-full text-small font-medium text-low">{{ __('storefront.deck.stale') }}</p>
                            @endif
                            @if ($bucket === DeckBucket::Missing && $alertNotice !== null)
                                <p role="{{ $alertSet ? 'status' : 'alert' }}" @class(['basis-full text-small font-medium', 'text-stock' => $alertSet, 'text-danger' => ! $alertSet])>{{ $alertNotice }}</p>
                            @endif
                        </div>
                        <ul id="deck-{{ $bucket->value }}-list" @if ($collapsible) x-show="open" x-cloak @endif class="divide-y divide-line rounded-card border border-line bg-sunken">
                            @foreach ($bucketLines as $line)
                                @php
                                    $chosen = $line->chosenVariant();
                                    $offers = array_filter($line->variants, fn (\App\Support\Deck\DeckVariant $variant): bool => ($line->remaining[$variant->id] ?? 0) > 0);
                                    $name = $chosen?->name ?? $line->matchedName;
                                    $code = $chosen?->code ?? $line->matchedCode;
                                    $shownQty = $chosen !== null && $line->bucket === DeckBucket::Partial ? $line->allocatedQty : $line->qty;
                                    $skipped = $line->bucket === DeckBucket::Partial && ! $line->takePartial;
                                    $showSelect = count($offers) > 1 || ($offers !== [] && ! isset($offers[$line->defaultVariantId]));
                                    $spansPrintings = collect($offers)->pluck('printingId')->unique()->count() > 1;
                                @endphp
                                <li wire:key="line-{{ $line->number }}" class="flex flex-col gap-1 p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <p class="min-w-0 truncate text-card font-medium">{{ $name }}</p>
                                        @if ($chosen !== null)
                                            <x-price :satang="$chosen->priceSatang * $shownQty" class="flex-none text-card font-medium {{ $skipped ? 'opacity-60' : '' }}" />
                                        @endif
                                    </div>

                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-label text-faint">
                                        <span class="tabular-nums">{{ $code }}</span>
                                        @if ($chosen !== null)
                                            @unless ($showSelect)
                                                <x-language-badge :language="$chosen->language" />
                                                <x-condition-badge :condition="$chosen->condition" />
                                                <span>{{ $chosen->finish->label() }}</span>
                                            @endunless
                                            <span class="tabular-nums"><x-price :satang="$chosen->priceSatang" /> &times; {{ $shownQty }}</span>
                                            @if ($line->bucket === DeckBucket::Full)
                                                <span class="tabular-nums text-stock">{{ __('storefront.deck.have', ['n' => $line->remaining[$chosen->id] ?? 0]) }}</span>
                                            @endif
                                        @endif
                                    </p>

                                    @if ($showSelect)
                                        <div>
                                            <label for="deck-variant-{{ $line->number }}" class="sr-only">{{ __('storefront.deck.variant') }}: {{ $name }}</label>
                                            <select id="deck-variant-{{ $line->number }}" wire:change="chooseVariant({{ $line->number }}, $event.target.value)" x-bind:aria-disabled="{{ $disabledBinding }}" class="{{ $input }} text-small">
                                                @unless (isset($offers[$line->defaultVariantId]))
                                                    <option value="" disabled selected></option>
                                                @endunless
                                                @foreach ($offers as $offer)
                                                    <option value="{{ $offer->id }}" @selected($offer->id === $line->defaultVariantId)>{{ __($spansPrintings ? 'storefront.deck.option_coded' : 'storefront.deck.option', ['code' => $offer->code, 'language' => $offer->language->shortCode(), 'condition' => strtoupper($offer->condition->value), 'finish' => $offer->finish->label(), 'price' => $format($offer->priceSatang), 'n' => $line->remaining[$offer->id] ?? 0]) }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endif

                                    @if ($line->bucket === DeckBucket::Missing)
                                        <p class="text-small text-muted">
                                            @if ($languageEmptied($line))
                                                {{ __('storefront.deck.other_language', ['lang' => $language->shortCode(), 'n' => $line->otherLanguagesAvailable]) }}
                                            @else
                                                {{ $line->variants === [] ? __('storefront.deck.not_stocked') : ($line->sumCapped ? __('storefront.deck.sum_capped', ['max' => config('deck.max_qty')]) : ($line->takenByEarlierLines ? __('storefront.deck.taken_earlier') : __('storefront.deck.sold_out'))) }}
                                            @endif
                                        </p>
                                    @elseif ($line->bucket === DeckBucket::Partial)
                                        <p class="text-small text-low tabular-nums">{{ __('storefront.deck.want_have', ['want' => $line->qty, 'have' => $line->allocatedQty]) }}</p>
                                        <label class="-ml-1 inline-flex min-h-11 w-fit cursor-pointer items-center gap-3 rounded-tag px-1 text-small has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent">
                                            <x-checkbox :checked="$line->takePartial" wire:click="togglePartial({{ $line->number }})" x-bind:aria-disabled="{{ $disabledBinding }}" />
                                            {{ __('storefront.deck.take_available', ['n' => $line->allocatedQty]) }}
                                            @if ($skipped)
                                                <span class="text-muted">· {{ __('storefront.deck.not_added') }}</span>
                                            @endif
                                        </label>
                                    @endif

                                    @if (array_intersect(array_keys($line->variants), $failedVariants) !== [])
                                        <p role="alert" class="text-small font-medium text-danger">{{ __('storefront.deck.add_failed_row') }}</p>
                                    @endif
                                    @if ($line->sumCapped)
                                        <p class="text-label text-low">{{ __('storefront.deck.sum_capped', ['max' => config('deck.max_qty')]) }}</p>
                                    @endif
                                    @if ($line->ambiguous)
                                        <p class="text-label text-low">{{ __('storefront.deck.ambiguous') }}</p>
                                    @endif
                                    @if ($line->isFuzzy())
                                        <p class="text-label text-muted">{{ __('storefront.deck.fuzzy') }}</p>
                                    @endif
                                    @if ($line->qtyCapped)
                                        <p class="text-label text-low">{{ __('storefront.deck.qty_capped', ['max' => config('deck.max_qty')]) }}</p>
                                    @endif

                                    @if ($line->showsPastedText())
                                        <p class="break-words text-label text-muted">{{ $line->text }}</p>
                                    @else
                                        <p class="text-label text-faint tabular-nums">{{ __('storefront.deck.line_number', ['n' => $line->number]) }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endforeach

            @if ($unmatched !== [])
                <section aria-labelledby="deck-unmatched-heading" class="flex flex-col gap-3">
                    <h2 id="deck-unmatched-heading" class="flex items-baseline gap-3 font-display text-card font-medium">
                        {{ __('storefront.deck.unmatched_title') }}
                        <span class="text-small font-normal text-faint tabular-nums">{{ __('storefront.deck.section_count', ['n' => count($unmatched)]) }}</span>
                    </h2>
                    <ul class="divide-y divide-line rounded-card border border-line bg-sunken">
                        @foreach ($unmatched as $line)
                            <li wire:key="unmatched-{{ $line->number }}" class="flex flex-col gap-1 p-3">
                                <p class="break-words text-small text-muted">{{ $line->text }}</p>
                                <p class="text-small font-medium text-low">
                                    {{ $line->qtyZero ? __('storefront.deck.zero_qty') : __('storefront.deck.unreadable') }}
                                    <span class="font-normal text-faint tabular-nums">· {{ __('storefront.deck.line_number', ['n' => $line->number]) }}</span>
                                </p>
                                <a href="{{ route('search', ['q' => $line->body]) }}" class="inline-flex min-h-11 w-fit items-center text-small text-accent hover:text-ink">{{ __('storefront.deck.search_name') }}</a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($unread !== [])
                <section aria-labelledby="deck-unread-heading" class="flex flex-col gap-2">
                    <h2 id="deck-unread-heading" class="text-small font-medium text-muted">{{ __('storefront.deck.unread_title') }}</h2>
                    <ul class="flex flex-col gap-1 text-label text-muted">
                        @foreach ($unread as $line)
                            <li wire:key="unread-{{ $line->number }}" class="break-words">
                                <span class="tabular-nums text-faint">{{ $line->number }}</span> {{ $line->text }}
                                @if ($line->kind === DeckLineKind::Directive)
                                    <span class="text-faint">· {{ __('storefront.deck.directive_note') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        @if ($parse->totalCards() > 0)
        <div id="deck-add-bar" x-show="! (dirty || {{ $staleJs }})" x-cloak class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-sunken px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3 lg:hidden">
            <div class="flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-label text-faint tabular-nums">{{ __('storefront.deck.bar_cards', ['have' => $parse->totalCards(), 'want' => $requested]) }}</p>
                    <p class="mt-1 flex items-baseline gap-2">
                        <span class="text-label text-faint">{{ __('storefront.deck.total_label') }}</span>
                        <x-price :satang="$parse->totalSatang()" class="text-h3 font-medium text-accent" />
                    </p>
                </div>
                <button type="button" wire:click="addAll('{{ $parse->signature($gameId) }}')" wire:loading.attr="aria-busy" wire:target="addAll" wire:key="deck-add-all-bar" class="{{ $buttonBase }} {{ $primaryButton }} min-h-13 rounded-card px-5 text-card">{{ __('storefront.deck.add_all_short') }}</button>
            </div>
        </div>
        @endif
    @endif
</div>
