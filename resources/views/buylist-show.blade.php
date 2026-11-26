@php
    $timezone = config('shop.timezone');
    $when = fn (\Carbon\CarbonInterface $at): string => $at->copy()->setTimezone($timezone)->locale('th')->translatedFormat('j M Y H:i');
    $day = fn (\Carbon\CarbonInterface $at): string => $at->copy()->setTimezone($timezone)->locale('th')->translatedFormat('j M Y');
    $format = fn (int $satang): string => \App\Support\Money::fromSatang($satang)->format();
    $secondaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none';
    // The whole road is shown, so the customer sees what comes next; steps not reached yet are muted. A returned list
    // adds its own last step.
    $steps = collect([
        'submitted' => $submission->created_at,
        'received' => $submission->received_at,
        'inspected' => $submission->revised_at ?? $submission->accepted_at,
        'paid' => $submission->paid_at,
    ])->when($submission->returned_at !== null || $submission->status === \App\Enums\BuylistStatus::Returned, fn ($steps) => $steps->put('returned', $submission->returned_at));
    $address = config('buylist.receiving_address');
@endphp

<x-layouts.storefront :title="__('storefront.buylist.show.title', ['number' => $submission->number])" :results="true" :bottom-nav="false">
    <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 pb-8 pt-4 lg:px-8 lg:pb-16 lg:pt-6">
        <div>
            <a href="{{ route('buylist') }}" class="inline-flex min-h-11 items-center text-small text-accent hover:text-ink">{{ __('storefront.buylist.show.back') }}</a>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <h1 class="font-display text-h2 font-medium tabular-nums lg:text-h1">{{ $submission->number }}</h1>
                <x-order-status-badge :status="$submission->status" />
            </div>
            <p class="mt-2 text-small text-muted tabular-nums">{{ __('storefront.buylist.show.quote_until', ['date' => $day($submission->quote_expires_at)]) }}</p>
        </div>

        <section aria-labelledby="buylist-timeline-heading">
            <h2 id="buylist-timeline-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.buylist.show.timeline') }}</h2>
            <ol class="flex flex-col gap-2 rounded-card border border-line bg-sunken p-4">
                @foreach ($steps as $step => $at)
                    <li @class(['flex items-baseline justify-between gap-4 text-small', 'text-faint' => $at === null])>
                        <span @class(['font-medium', 'text-ink' => $at !== null])>{{ __('storefront.buylist.show.step_'.$step) }}</span>
                        @if ($at !== null)
                            <span class="tabular-nums text-muted">{{ $when($at) }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>

        <section aria-labelledby="buylist-packing-heading" class="flex flex-col gap-3 rounded-card border border-line bg-sunken p-4">
            <h2 id="buylist-packing-heading" class="font-display text-card font-medium">{{ __('storefront.buylist.show.packing_title') }}</h2>
            <ol class="list-decimal ps-5 text-small text-muted">
                <li>{{ __('storefront.buylist.show.packing_1') }}</li>
                <li>{{ __('storefront.buylist.show.packing_2', ['number' => $submission->number]) }}</li>
                <li>{{ __('storefront.buylist.show.packing_3') }}</li>
            </ol>

            <h3 class="text-small font-medium">{{ __('storefront.buylist.show.address_title') }}</h3>
            @if (filled($address))
                <p class="whitespace-pre-line text-small text-ink">{{ $address }}</p>
            @else
                <p class="text-small text-muted">{{ __('storefront.buylist.show.address_missing') }}</p>
            @endif
        </section>

        <section aria-labelledby="buylist-lines-heading">
            <h2 id="buylist-lines-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.buylist.show.lines_title') }}</h2>
            <ul class="flex flex-col gap-2">
                @foreach ($submission->items as $item)
                    <li class="flex items-center gap-3 rounded-card border border-line bg-sunken p-3">
                        <x-card-art :printing="$item->printing" :foil="$item->finish === \App\Enums\Finish::Foil" size="w-10" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-small font-medium">{{ $item->card_name }}</span>
                            <span class="block truncate text-label text-faint tabular-nums">{{ $item->display_code }} · {{ $item->language->shortCode() }}@if ($item->finish === \App\Enums\Finish::Foil) · {{ __('storefront.buylist.foil') }}@endif</span>
                            <span class="mt-1 flex flex-wrap items-center gap-2 text-label text-muted tabular-nums">
                                <span class="sr-only">{{ __('storefront.buylist.show.claimed') }}</span>
                                <x-condition-badge :condition="$item->claimed_condition" />
                                {{ __('storefront.buylist.show.qty', ['n' => $item->qty]) }} · {{ __('storefront.buylist.show.unit', ['amount' => $format($item->quoted_unit_satang)]) }}
                            </span>
                        </span>
                        <span class="flex-none text-right text-small font-medium tabular-nums">{{ __('storefront.buylist.show.line_total', ['amount' => $format($item->quoted_line_satang)]) }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="mt-3 flex flex-col gap-1 rounded-card border border-line bg-sunken p-4 text-small text-muted">
                <p>{{ __('storefront.buylist.show.payout', ['payout' => $submission->payout->label()]) }}</p>
                <p class="flex items-baseline justify-between gap-4">
                    <span class="text-body font-medium text-ink">{{ $submission->final_total_satang === null ? __('storefront.buylist.show.total_quoted') : __('storefront.buylist.show.total_final') }}</span>
                    <x-price :satang="$submission->totalSatang()" class="text-h3 font-medium text-accent" />
                </p>
            </div>
        </section>

        <a href="{{ route('account') }}" class="{{ $secondaryButton }} self-start">{{ __('storefront.buylist.show.all_in_account') }}</a>
    </div>
</x-layouts.storefront>
