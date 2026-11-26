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
    ]);
    $isReturned = $submission->returned_at !== null || $submission->status === \App\Enums\BuylistStatus::Returned;
    // A returned list ends at the return: the payment step it will never reach is not shown.
    $steps = $isReturned ? $steps->except('paid')->put('returned', $submission->returned_at) : $steps;
    $isSubmitted = $submission->status === \App\Enums\BuylistStatus::Submitted;
    $unbought = \App\Support\BuylistAssessment::unboughtLines($submission);
    $unboughtCards = (int) $unbought->sum('qty');
    $address = config('buylist.receiving_address');
    $statusCopy = match ($submission->status) {
        \App\Enums\BuylistStatus::Received => [__('storefront.buylist.show.status_received')],
        \App\Enums\BuylistStatus::Accepted => [__('storefront.buylist.show.status_accepted')],
        \App\Enums\BuylistStatus::Paid => [
            __('storefront.buylist.show.status_paid'),
            $submission->payout_reference !== null
                ? __('storefront.buylist.show.status_paid_cash', ['ref' => $submission->payout_reference])
                : __('storefront.buylist.show.status_paid_credit', ['amount' => $format($submission->totalSatang())]),
        ],
        // Past tense once the parcel has a tracking number; until then the shop only promises it.
        \App\Enums\BuylistStatus::Returned => [
            $submission->return_tracking !== null
                ? __('storefront.buylist.show.status_returned_tracking', ['tracking' => $submission->return_tracking])
                : __('storefront.buylist.show.status_returned'),
        ],
        default => [],
    };
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

        @if ($submission->status === \App\Enums\BuylistStatus::Revised)
            <livewire:storefront.buylist-revision-box :submission="$submission" />
        @elseif ($statusCopy !== [])
            <div role="status" class="flex flex-col gap-1 rounded-card border border-line bg-sunken p-4 text-small">
                @foreach ($statusCopy as $line)
                    <p @class(['text-muted' => ! $loop->first, 'font-medium text-ink' => $loop->first])>{{ $line }}</p>
                @endforeach
                @if ($submission->status === \App\Enums\BuylistStatus::Paid && $submission->payout_reference === null)
                    <a href="{{ route('account') }}#account-credit-heading" class="mt-1 inline-flex min-h-11 items-center self-start text-small text-accent hover:text-ink">{{ __('storefront.buylist.show.view_credit') }}</a>
                @endif
            </div>
        @endif

        @if ($unbought->isNotEmpty() && in_array($submission->status, [\App\Enums\BuylistStatus::Accepted, \App\Enums\BuylistStatus::Paid], true))
            <div role="status" class="flex flex-col gap-2 rounded-card border border-line bg-sunken p-4 text-small">
                <p class="font-medium text-ink">{{ $submission->return_tracking !== null
                    ? __('storefront.buylist.show.unbought_sent', ['n' => $unboughtCards, 'tracking' => $submission->return_tracking])
                    : __('storefront.buylist.show.unbought', ['n' => $unboughtCards]) }}</p>
                <ul class="text-muted">
                    @foreach ($unbought as $item)
                        <li class="tabular-nums">{{ $item->card_name }} · {{ $item->display_code }} · {{ __('storefront.buylist.show.qty', ['n' => $item->qty]) }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

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

        @if ($isSubmitted)
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
                <p class="text-small text-muted">{{ __('storefront.buylist.show.address_missing'.(\App\Support\LineLogin::isConfigured() ? '' : '_email')) }}</p>
            @endif
        </section>
        @endif

        <section aria-labelledby="buylist-lines-heading">
            <h2 id="buylist-lines-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.buylist.show.lines_title') }}</h2>
            <ul class="flex flex-col gap-2">
                @foreach ($submission->items as $item)
                    @php($isChanged = $item->assessed_condition !== null && $item->assessed_condition !== $item->claimed_condition)
                    <li class="flex items-center gap-3 rounded-card border border-line bg-sunken p-3">
                        <x-card-art :printing="$item->printing" :foil="$item->finish === \App\Enums\Finish::Foil" size="w-10" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-small font-medium">{{ $item->card_name }}</span>
                            <span class="block truncate text-label text-faint tabular-nums">{{ $item->display_code }} · {{ $item->language->shortCode() }}@if ($item->finish === \App\Enums\Finish::Foil) · {{ __('storefront.buylist.foil') }}@endif</span>
                            <span class="mt-1 flex flex-wrap items-center gap-2 text-label text-muted tabular-nums">
                                <span class="sr-only">{{ __('storefront.buylist.show.claimed') }}</span>
                                <x-condition-badge :condition="$item->claimed_condition" />
                                {{ __('storefront.buylist.show.qty', ['n' => $item->qty]) }}
                                @if ($isChanged)
                                    <span class="text-faint">{{ __('storefront.buylist.show.found') }}</span>
                                    <x-condition-badge :condition="$item->assessed_condition" />
                                @endif
                            </span>
                            <span class="mt-1 block text-label text-muted tabular-nums">
                                @if ($isChanged)
                                    {{ __('storefront.buylist.show.unit', ['amount' => $format($item->quoted_unit_satang)]) }} → {{ __('storefront.buylist.show.unit_now', ['amount' => $format($item->assessed_unit_satang)]) }}
                                @else
                                    {{ __('storefront.buylist.show.unit', ['amount' => $format($item->quoted_unit_satang)]) }}
                                @endif
                            </span>
                        </span>
                        <span class="flex-none text-right text-small font-medium tabular-nums">
                            @if ($isChanged && $item->assessed_line_satang !== $item->quoted_line_satang)
                                <span class="block text-label font-normal text-faint line-through">{{ $format($item->quoted_line_satang) }}</span>
                                <span class="block">{{ __('storefront.buylist.show.line_total', ['amount' => $format($item->assessed_line_satang)]) }}</span>
                            @else
                                {{ __('storefront.buylist.show.line_total', ['amount' => $format($item->quoted_line_satang)]) }}
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>

            <div class="mt-3 flex flex-col gap-1 rounded-card border border-line bg-sunken p-4 text-small text-muted">
                <p>{{ __('storefront.buylist.show.payout', ['payout' => $submission->payout->label()]) }}</p>
                <p class="flex items-baseline justify-between gap-4">
                    <span class="text-body font-medium text-ink">{{ $submission->final_total_satang === null ? __('storefront.buylist.show.total_quoted') : __('storefront.buylist.show.total_final') }}</span>
                    <x-price :satang="$submission->totalSatang()" class="text-h3 font-medium {{ $isReturned ? 'text-muted' : 'text-accent' }}" />
                </p>
                @if ($submission->final_total_satang !== null && $submission->final_total_satang !== $submission->quoted_total_satang)
                    <p class="text-label tabular-nums">{{ __('storefront.buylist.show.total_was', ['amount' => $format($submission->quoted_total_satang)]) }}</p>
                @endif
            </div>
        </section>

        <a href="{{ route('account') }}" class="{{ $secondaryButton }} self-start">{{ __('storefront.buylist.show.all_in_account') }}</a>
    </div>
</x-layouts.storefront>
