@php
    use App\Enums\BuylistPayout;

    $format = fn (int $satang): string => \App\Support\Money::fromSatang($satang)->format();
    $primaryButton = 'inline-flex min-h-12 items-center justify-center rounded-control bg-accent px-5 text-body font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
    $secondaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none';
    $linkButton = 'inline-flex min-h-11 min-w-11 items-center justify-center rounded-tag px-2 text-small text-muted transition-[color] duration-160 ease-ui hover:text-danger motion-reduce:transition-none';
    $input = 'block min-h-11 w-full rounded-control border border-line-strong bg-surface px-3 text-body text-ink placeholder:text-faint';
    $stepperButton = 'size-11 text-card transition-[background-color] duration-160 ease-ui motion-reduce:transition-none';
    $radio = 'grid size-5 flex-none appearance-none place-items-center rounded-full border-2 border-line-strong bg-transparent before:size-2.5 before:scale-0 before:rounded-full before:bg-accent focus-visible:outline-none checked:border-accent checked:before:scale-100 motion-safe:before:transition-transform motion-safe:before:duration-160';
    $card = 'flex min-h-14 cursor-pointer items-center gap-3 rounded-control border border-line bg-surface px-4 py-3 transition-[border-color,background-color] duration-160 ease-ui has-checked:border-accent has-checked:bg-raised has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent motion-reduce:transition-none';
    $payoutChoice = $this->payoutChoice();
    $hasLines = $rows !== [];
    $rawError = $errors->first('raw');
    $gameError = $errors->first('gameId');
    $pasteStale = $this->isPasteStale();
    $isStaff = auth()->check() && auth()->user()->is_staff;
    // The mode control is not a primary action: its selected state is the accent border on a raised ground, never the gold fill.
    $modeButton = 'inline-flex min-h-11 flex-none items-center gap-2 rounded-full border px-4 text-small transition-[color,background-color,border-color] duration-160 ease-ui motion-reduce:transition-none';
    $nowrap = fn (int $satang): string => '<span class="whitespace-nowrap">'.e($format($satang)).'</span>';
@endphp

<div
    x-data="{ dirty: false }"
    x-on:buylist-checked.window="dirty = false"
    @class(['flex flex-col gap-6', 'max-lg:pb-28' => $hasLines])
>
    <h1 class="font-display text-h2 font-medium lg:text-h1">{{ __('storefront.buylist.title') }}</h1>

    <section class="flex flex-col gap-4 rounded-card border border-line bg-sunken p-4">
        <div role="group" aria-label="{{ __('storefront.buylist.mode_group') }}" class="flex flex-wrap gap-2">
            @foreach (['search', 'paste'] as $option)
                <button type="button" wire:key="mode-{{ $option }}" wire:click="setMode('{{ $option }}')" aria-pressed="{{ $mode === $option ? 'true' : 'false' }}" @class([$modeButton, 'border-accent bg-raised font-medium text-ink' => $mode === $option, 'border-line-strong bg-surface text-muted hover:text-ink' => $mode !== $option])>{{ __('storefront.buylist.mode_'.$option) }}</button>
            @endforeach
        </div>

        @if ($mode === 'search')
            <div>
                <label for="buylist-query" class="mb-1 block text-small text-muted">{{ __('storefront.buylist.search_label') }}</label>
                <input id="buylist-query" type="search" wire:model.live.debounce.300ms="query" autocomplete="off" placeholder="{{ __('storefront.buylist.search_placeholder') }}" aria-describedby="buylist-query-hint" class="{{ $input }}">
                <p id="buylist-query-hint" class="mt-1 text-label text-muted">{{ __('storefront.buylist.search_hint') }}</p>
            </div>

            @if (trim($query) !== '')
                @if ($results->isEmpty())
                    <p role="status" class="text-small text-muted">{{ __('storefront.buylist.no_results') }}</p>
                @else
                    <ul class="grid grid-cols-1 gap-2" aria-label="{{ __('storefront.buylist.search_label') }}">
                        @foreach ($results as $result)
                            @php
                                $printing = $result['printing'];
                                $best = $result['best'];
                                $rowKey = $printing->id.'-'.$result['finish']->value;
                                $resultFull = $best->remainingQuota() === 0;
                            @endphp
                            <li wire:key="result-{{ $rowKey }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-card border border-line bg-surface p-3">
                                <x-card-art :printing="$printing" :foil="$result['finish'] === \App\Enums\Finish::Foil" size="w-10" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-small font-medium">{{ $printing->display_name }}</span>
                                    <span class="block truncate text-label text-faint tabular-nums">{{ $printing->display_code }} · {{ $printing->language->shortCode() }}@if ($result['finish'] === \App\Enums\Finish::Foil) · {{ __('storefront.buylist.foil') }}@endif</span>
                                    @if ($resultFull)
                                        <span class="block text-label text-low">{{ __('storefront.buylist.quota_full_week') }}</span>
                                    @else
                                        <span class="block text-label tabular-nums text-muted">{!! __('storefront.buylist.result_max', ['cash' => $nowrap($best->cash_satang), 'credit' => $nowrap($best->credit_satang)]) !!}</span>
                                    @endif
                                </span>
                                <button type="button" wire:click="add({{ $printing->id }}, '{{ $result['finish']->value }}')" @if ($resultFull) aria-disabled="true" @endif aria-label="{{ __('storefront.buylist.add_label', ['card' => $printing->display_name]) }}" @class([$secondaryButton, 'flex-none', 'text-faint' => $resultFull])>{{ __('storefront.buylist.add') }}</button>
                                @if ($notice !== null && $noticeFor === $rowKey)
                                    <p role="alert" class="basis-full text-small font-medium text-low">{{ $notice }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        @else
            <div class="flex flex-col gap-4">
                <div>
                    <label for="buylist-game" class="mb-1 block text-small text-muted">{{ __('storefront.buylist.game_label') }}</label>
                    <select id="buylist-game" wire:model="gameId" @if ($gameError !== '') aria-invalid="true" aria-describedby="buylist-game-error" @endif class="{{ $input }}">
                        @foreach ($this->games as $game)
                            <option value="{{ $game->id }}" wire:key="game-{{ $game->id }}">{{ $game->name_en }}</option>
                        @endforeach
                    </select>
                    @if ($gameError !== '')
                        <p id="buylist-game-error" class="mt-1 text-small text-danger" role="alert">{{ $gameError }}</p>
                    @endif
                </div>

                <div>
                    <label for="buylist-raw" class="mb-1 block text-small text-muted">{{ __('storefront.buylist.raw_label') }}</label>
                    <textarea
                        id="buylist-raw"
                        rows="8"
                        wire:model="raw"
                        x-on:input="dirty = true"
                        placeholder="{{ __('storefront.buylist.raw_placeholder') }}"
                        autocomplete="off"
                        spellcheck="false"
                        aria-describedby="buylist-raw-hint{{ $rawError !== '' ? ' buylist-raw-error' : '' }}"
                        @if ($rawError !== '') aria-invalid="true" @endif
                        class="{{ $input }} resize-y py-2"
                    ></textarea>
                    <p id="buylist-raw-hint" class="mt-1 text-label text-muted">{{ __('storefront.buylist.raw_hint') }}</p>
                    @if ($rawError !== '')
                        <p id="buylist-raw-error" class="mt-1 text-small text-danger" role="alert">{{ $rawError }}</p>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="check" wire:loading.attr="aria-busy" wire:target="check" class="{{ $secondaryButton }}">{{ __('storefront.buylist.check') }}</button>
                    @if ($pasteStale)
                        <p role="status" class="text-small font-medium text-low">{{ __('storefront.buylist.stale') }}</p>
                    @elseif ($checked)
                        <p role="status" x-show="dirty" x-cloak class="text-small font-medium text-low">{{ __('storefront.buylist.stale') }}</p>
                    @endif
                </div>

                @if ($checked)
                    <div class="flex flex-col gap-2" x-bind:class="dirty && 'opacity-60'">
                        <p role="status" class="text-small font-medium text-stock tabular-nums">{{ __('storefront.buylist.pasted_added', ['n' => $pastedAdded]) }}</p>
                        @if ($ambiguous !== [])
                            <div class="rounded-control border border-line bg-surface p-3">
                                <h2 class="text-small font-medium">{{ __('storefront.buylist.ambiguous_title') }}</h2>
                                <p class="mt-1 text-label text-muted">{{ __('storefront.buylist.ambiguous_hint') }}</p>
                                <ul class="mt-2 flex flex-col gap-1">
                                    @foreach ($ambiguous as $index => $line)
                                        <li wire:key="ambiguous-{{ $index }}" class="flex items-center justify-between gap-3 text-small text-muted">
                                            <span class="min-w-0 break-words">{{ $line['text'] }}</span>
                                            <button type="button" wire:click="searchFor({{ $index }})" aria-label="{{ __('storefront.buylist.ambiguous_search_label', ['text' => $line['text']]) }}" class="{{ $secondaryButton }} flex-none">{{ __('storefront.buylist.ambiguous_search') }}</button>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($ambiguousMore > 0)
                                    <p class="mt-1 text-label text-faint tabular-nums">{{ __('storefront.buylist.unbought_more', ['n' => $ambiguousMore]) }}</p>
                                @endif
                            </div>
                        @endif
                        @if ($quotaFullLines !== [])
                            <div class="rounded-control border border-line bg-surface p-3">
                                <h2 class="text-small font-medium">{{ __('storefront.buylist.quota_full_title') }}</h2>
                                <ul class="mt-2 flex flex-col gap-1 text-small text-muted">
                                    @foreach ($quotaFullLines as $text)
                                        <li class="break-words">{{ $text }}</li>
                                    @endforeach
                                </ul>
                                @if ($quotaFullMore > 0)
                                    <p class="mt-1 text-label text-faint tabular-nums">{{ __('storefront.buylist.unbought_more', ['n' => $quotaFullMore]) }}</p>
                                @endif
                            </div>
                        @endif
                        @if ($unbought !== [])
                            <div class="rounded-control border border-line bg-surface p-3">
                                <h2 class="text-small font-medium">{{ __('storefront.buylist.unbought_title') }}</h2>
                                <ul class="mt-2 flex flex-col gap-1 text-small text-muted">
                                    @foreach ($unbought as $text)
                                        <li class="break-words">{{ $text }}</li>
                                    @endforeach
                                </ul>
                                @if ($unboughtMore > 0)
                                    <p class="mt-1 text-label text-faint tabular-nums">{{ __('storefront.buylist.unbought_more', ['n' => $unboughtMore]) }}</p>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </section>

    @if ($notice !== null && $noticeFor === null)
        <p role="alert" class="text-small font-medium text-danger">{{ $notice }}</p>
    @endif

    @if (! $hasLines)
        <x-empty-state :title="__('storefront.buylist.empty')" :hint="__('storefront.buylist.empty_hint')">
            @if ($mode !== 'paste')
                <button type="button" wire:click="setMode('paste')" class="{{ $secondaryButton }}">{{ __('storefront.buylist.empty_cta') }}</button>
            @endif
        </x-empty-state>
    @else
        <section aria-labelledby="buylist-lines-heading">
            <h2 id="buylist-lines-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.buylist.lines_title') }}</h2>

            <ul class="grid grid-cols-1 gap-2">
                @foreach ($rows as $line)
                    @php
                        $row = $line['row'];
                        $printing = $line['printing'];
                        $name = $printing->display_name;
                    @endphp
                    <li wire:key="line-{{ $printing->id }}-{{ $row->finish->value }}" class="flex flex-col gap-3 rounded-card border border-line bg-sunken p-3">
                        <div class="flex items-center gap-3">
                            <x-card-art :printing="$printing" :foil="$row->finish === \App\Enums\Finish::Foil" size="w-10" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-small font-medium">{{ $name }}</span>
                                <span class="block truncate text-label text-faint tabular-nums">{{ $printing->display_code }} · {{ $printing->language->shortCode() }}@if ($row->finish === \App\Enums\Finish::Foil) · {{ __('storefront.buylist.foil') }}@endif</span>
                            </span>
                            <button type="button" wire:click="remove({{ $row->getKey() }})" class="{{ $linkButton }}">{{ __('storefront.buylist.remove') }}<span class="sr-only"> {{ $name }}</span></button>
                        </div>

                        @if (! $line['buyable'])
                            <p role="status" class="text-small text-low">{{ __('storefront.buylist.no_longer') }}</p>
                        @else
                            <div class="flex flex-wrap items-center gap-3">
                                <div>
                                    <label for="condition-{{ $row->getKey() }}" class="sr-only">{{ __('storefront.buylist.condition') }} {{ $name }}</label>
                                    {{-- A condition whose quota is used up stays listed, marked and unselectable, so the seller can see why it is not offered. --}}
                                    <select id="condition-{{ $row->getKey() }}" wire:change="setCondition({{ $row->getKey() }}, $event.target.value)" class="{{ $input }} w-auto">
                                        @foreach ($line['options'] as $option)
                                            @php
                                                $optionFull = $option->remainingQuota() === 0;
                                                $optionLabel = __('storefront.buylist.condition_option', ['condition' => $option->condition->label(), 'payout' => $payoutChoice->label(), 'amount' => $format($option->unitFor($payoutChoice))]);
                                            @endphp
                                            <option value="{{ $option->getKey() }}" @selected($option->getKey() === $row->getKey()) @disabled($optionFull && $option->getKey() !== $row->getKey()) @if ($optionFull) aria-disabled="true" @endif>{{ $optionFull ? __('storefront.buylist.condition_full', ['option' => $optionLabel]) : $optionLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                @if ($line['quotaFull'])
                                    <p role="status" class="text-small text-low">{{ __('storefront.buylist.quota_full') }}</p>
                                @else

                                {{-- No overflow-hidden here: it would clip the focus ring. A button at its limit is aria-disabled, not disabled, so focus stays on it. --}}
                                <span class="inline-flex flex-none items-center rounded-control border border-line-strong bg-surface">
                                    @php
                                        $atMin = $line['qty'] <= 1;
                                        $atMax = $line['qty'] >= $line['allowed'];
                                    @endphp
                                    <button type="button" wire:click="decrement({{ $row->getKey() }})" @if ($atMin) aria-disabled="true" @endif aria-label="{{ __('storefront.card.decrease') }}" @class([$stepperButton, 'rounded-l-control', 'text-faint' => $atMin, 'text-ink hover:bg-raised' => ! $atMin])>&minus;</button>
                                    <span class="min-w-9 text-center text-body font-medium tabular-nums" aria-live="polite"><span class="sr-only">{{ __('storefront.buylist.qty') }} </span>{{ $line['qty'] }}</span>
                                    <button type="button" wire:click="increment({{ $row->getKey() }})" @if ($atMax) aria-disabled="true" @endif aria-label="{{ __('storefront.card.increase') }}" @class([$stepperButton, 'rounded-r-control', 'text-faint' => $atMax, 'text-ink hover:bg-raised' => ! $atMax])>+</button>
                                </span>

                                <p class="ml-auto flex flex-col items-end text-small tabular-nums">
                                    <span class="whitespace-nowrap text-ink">{{ __('storefront.buylist.line_cash', ['amount' => $format($line['cash'])]) }}</span>
                                    <span class="whitespace-nowrap text-muted">{{ __('storefront.buylist.line_credit', ['amount' => $format($line['credit'])]) }}</span>
                                </p>
                                @endif
                            </div>

                            @if ($line['quotaFull'])
                            @elseif ($line['quotaCapped'])
                                <p role="status" class="text-small text-low tabular-nums">{{ __('storefront.buylist.quota_left', ['n' => $line['remaining']]) }}</p>
                            @elseif ($line['atMaxPerLine'])
                                <p role="status" class="text-small text-muted tabular-nums">{{ __('storefront.buylist.max_qty', ['max' => config('buylist.max_qty_per_line')]) }}</p>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section aria-labelledby="buylist-summary-heading">
            <h2 id="buylist-summary-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.buylist.summary_title') }}</h2>

            <p class="text-small text-muted tabular-nums">{{ __('storefront.buylist.summary_count', ['n' => $cardCount, 'lines' => $submittableCount]) }}</p>

            {{-- The warning comes before the choice it is about: the customer reads it, then picks cash or credit. --}}
            <div class="mt-4 flex items-start gap-3 rounded-card border border-info/40 bg-info/10 p-4">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" class="mt-0.5 flex-none text-info"><circle cx="10" cy="10" r="7.4" stroke="currentColor" stroke-width="1.6"/><path d="M10 9v4.4M10 6.4v.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                <div class="min-w-0 text-small text-ink">
                    <p>{{ __('storefront.buylist.warning') }}</p>
                    <p class="mt-1 tabular-nums text-muted">{{ __('storefront.buylist.quote_days', ['days' => config('buylist.quote_days')]) }}</p>
                </div>
            </div>

            <fieldset class="mt-4">
                <legend class="mb-2 text-small text-muted">{{ __('storefront.buylist.payout_group') }}</legend>
                <div class="flex flex-col gap-2">
                    <label wire:key="payout-credit" class="{{ $card }}">
                        <input type="radio" name="payout" value="credit" wire:model.live="payout" class="{{ $radio }}">
                        <span class="text-small font-medium tabular-nums">{{ __('storefront.buylist.payout_credit', ['credit' => $format($creditTotal), 'diff' => $format(max(0, $creditTotal - $cashTotal))]) }}</span>
                    </label>
                    <label wire:key="payout-cash" class="{{ $card }}">
                        <input type="radio" name="payout" value="cash" wire:model.live="payout" class="{{ $radio }}">
                        <span class="text-small font-medium tabular-nums">{{ __('storefront.buylist.payout_cash', ['cash' => $format($cashTotal)]) }}</span>
                    </label>
                </div>
            </fieldset>
        </section>

        {{-- On a phone the bar is fixed to the bottom, so a refusal sits inside it, above the button, where it cannot be missed. --}}
        <div id="buylist-bar" class="fixed inset-x-0 bottom-0 z-30 flex items-center gap-3 border-t border-line bg-sunken px-4 pb-4 pt-3 lg:static lg:border-0 lg:bg-transparent lg:p-0">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                @if ($submitNotice !== null)
                    <p role="alert" class="text-small font-medium text-danger">{{ $submitNotice }}</p>
                @endif
                @if ($isStaff)
                    <p role="status" class="text-small text-low">{{ __('storefront.buylist.staff') }}</p>
                @elseif ($submittableCount === 0)
                    <p role="status" class="text-small text-low">{{ __('storefront.buylist.nothing_to_submit') }}</p>
                @endif
                @guest
                    <button type="button" wire:click="submit('{{ $signature }}', '{{ $pricesSignature }}')" class="{{ $primaryButton }} w-full">{{ __('storefront.buylist.login_to_submit') }}</button>
                @else
                    <button type="button" wire:click="submit('{{ $signature }}', '{{ $pricesSignature }}')" wire:loading.attr="aria-busy" wire:target="submit" @if ($isStaff || $submittableCount === 0) aria-disabled="true" @endif @class([$primaryButton, 'w-full', 'opacity-60' => $isStaff || $submittableCount === 0])>{{ __('storefront.buylist.submit', ['total' => $format($payoutTotal)]) }}</button>
                @endguest
            </div>
        </div>
    @endif
</div>
