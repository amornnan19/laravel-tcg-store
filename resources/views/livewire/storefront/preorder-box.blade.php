@php
    $preorder = $this->preorder;
    $state = $preorder?->state() ?? 'closed';
    $cap = $this->cap;
    $quantity = $this->quantity();
    $atMin = $quantity <= 1;
    $atMax = $quantity >= $cap;
    $plan = $this->planChoice();
    $timezone = config('shop.timezone');
    $day = fn (\Carbon\CarbonInterface $at): string => $at->copy()->setTimezone($timezone)->locale('th')->translatedFormat('j M Y');
    $stepperButton = 'size-11 text-card transition-[background-color] duration-160 ease-ui motion-reduce:transition-none';
    $primaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control bg-accent px-6 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
    $secondaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-small text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none';
    $planCard = 'flex min-h-14 cursor-pointer items-start gap-3 rounded-control border border-line bg-surface px-4 py-3 transition-[border-color,background-color] duration-160 ease-ui has-checked:border-accent has-checked:bg-raised has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent motion-reduce:transition-none';
    $radio = 'mt-0.5 grid size-5 flex-none appearance-none place-items-center rounded-full border-2 border-line-strong bg-transparent before:size-2.5 before:scale-0 before:rounded-full before:bg-accent focus-visible:outline-none checked:border-accent checked:before:scale-100 motion-safe:before:transition-transform motion-safe:before:duration-160';
    $rest = $preorder === null ? 0 : max(0, $preorder->full_satang - $preorder->deposit_satang);
    $left = $preorder === null ? 0 : max(0, (int) now()->diffInSeconds($preorder->releaseAt(), false));
    $days = intdiv($left, 86400);
    $hours = intdiv($left % 86400, 3600);
    $minutes = intdiv($left % 3600, 60);
    $percent = $preorder === null || $preorder->quota < 1 ? 0 : min(100, (int) floor($preorder->reserved_count * 100 / $preorder->quota));
    $placesLeft = $preorder?->placesLeft() ?? 0;
    $limitBinds = $preorder?->limitBinds(auth()->user()) ?? false;
    $nearlyFull = $preorder !== null && $placesLeft > 0 && $placesLeft * 10 <= $preorder->quota;
    $product = $preorder?->sealedProduct;
    // "See other products": the product's set, else its game, else the plain search.
    $browseUrl = route('search', array_filter(['q' => $product?->set?->name_en ?? $product?->game?->name_en]));
@endphp

{{-- One root. A closed, full, not-yet-open or released pre-order says so in words, with a way out, and offers no button. --}}
<div class="flex flex-col gap-4">
    @if ($preorder !== null)
        @if ($state !== 'released')
            <section class="rounded-card border border-line bg-surface p-4">
                @if ($state === 'open')
                    <div
                        role="timer"
                        aria-label="{{ __('storefront.preorder.countdown', ['d' => $days, 'h' => $hours, 'm' => $minutes]) }}"
                        x-data="{
                            ends: Date.parse(@js($preorder->releaseAt()->toIso8601String())),
                            skew: Date.parse(@js(now()->toIso8601String())) - Date.now(),
                            left: {{ $left }},
                            timer: null,
                            init() { this.tick(); this.timer = setInterval(() => this.tick(), 15000); },
                            destroy() { clearInterval(this.timer); },
                            tick() {
                                this.left = Math.max(0, Math.floor((this.ends - (Date.now() + this.skew)) / 1000));
                                if (this.left === 0) { clearInterval(this.timer); $wire.$refresh(); }
                            },
                            get d() { return Math.floor(this.left / 86400); },
                            get h() { return Math.floor((this.left % 86400) / 3600); },
                            get m() { return Math.floor((this.left % 3600) / 60); },
                        }"
                    >
                        <p class="text-small text-muted">{{ __('storefront.preorder.countdown_label') }}</p>
                        <div class="mt-3 flex gap-2">
                            <div class="flex-1 rounded-control bg-sunken py-4 text-center">
                                <p class="text-h2 font-medium tabular-nums text-ink" x-text="d">{{ $days }}</p>
                                <p class="text-label text-faint">{{ __('storefront.preorder.unit_day') }}</p>
                            </div>
                            <div class="flex-1 rounded-control bg-sunken py-4 text-center">
                                <p class="text-h2 font-medium tabular-nums text-ink" x-text="h">{{ $hours }}</p>
                                <p class="text-label text-faint">{{ __('storefront.preorder.unit_hour') }}</p>
                            </div>
                            <div class="flex-1 rounded-control bg-sunken py-4 text-center">
                                <p class="text-h2 font-medium tabular-nums text-ink" x-text="m">{{ $minutes }}</p>
                                <p class="text-label text-faint">{{ __('storefront.preorder.unit_minute') }}</p>
                            </div>
                        </div>
                    </div>
                @endif

                <dl @class(['flex flex-col gap-2 text-small', 'mt-4 border-t border-line pt-4' => $state === 'open'])>
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-muted">{{ __('storefront.preorder.deposit_each') }}</dt>
                        <dd class="font-medium text-accent"><x-price :satang="$preorder->deposit_satang" /></dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-muted">{{ __('storefront.preorder.full_price') }}</dt>
                        <dd class="font-medium text-ink"><x-price :satang="$preorder->full_satang" /></dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="sr-only">{{ __('storefront.preorder.progress_label') }}</dt>
                        <dd class="font-medium text-stock">{{ $placesLeft < 1 ? __('storefront.preorder.full_of', ['n' => $preorder->reserved_count, 'quota' => $preorder->quota]) : __('storefront.preorder.reserved_of', ['n' => $preorder->reserved_count, 'quota' => $preorder->quota]) }}</dd>
                        @if ($nearlyFull)
                            <dd class="font-medium text-ink">{{ __('storefront.preorder.few_left', ['n' => $placesLeft]) }}</dd>
                        @endif
                    </div>
                </dl>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-sunken" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $preorder->quota }}" aria-valuenow="{{ $preorder->reserved_count }}" aria-label="{{ __('storefront.preorder.reserved_of', ['n' => $preorder->reserved_count, 'quota' => $preorder->quota]) }}">
                    <div class="h-full rounded-full bg-accent" style="width: {{ $percent }}%"></div>
                </div>
            </section>
        @endif

        @if ($state === 'open')
            <fieldset class="flex flex-col gap-2">
                <legend class="mb-1 text-small font-medium">{{ __('storefront.preorder.plan') }}</legend>
                <label class="{{ $planCard }}">
                    <input type="radio" name="plan" value="deposit" wire:model.live="plan" class="{{ $radio }}">
                    <span class="flex min-w-0 flex-col gap-1">
                        <span class="text-small font-medium">{{ __('storefront.preorder.plan_deposit', ['deposit' => \App\Support\Money::fromSatang($preorder->deposit_satang)->format()]) }}</span>
                        <span class="text-small text-muted">{{ __($preorder->isWithinBalanceWindow() ? 'storefront.preorder.plan_deposit_rest_soon' : 'storefront.preorder.plan_deposit_rest', ['rest' => \App\Support\Money::fromSatang($rest)->format()]) }}</span>
                    </span>
                </label>
                <label class="{{ $planCard }}">
                    <input type="radio" name="plan" value="full" wire:model.live="plan" class="{{ $radio }}">
                    <span class="flex min-w-0 flex-col gap-1">
                        <span class="text-small font-medium">{{ __('storefront.preorder.plan_full', ['price' => \App\Support\Money::fromSatang($preorder->fullPlanUnitSatang())->format(), 'pct' => rtrim(rtrim(number_format($preorder->full_discount_bp / 100, 2), '0'), '.')]) }}</span>
                        <span class="text-small text-muted">{{ __('storefront.preorder.plan_full_note') }}</span>
                    </span>
                </label>
            </fieldset>

            @if ($cap < 1)
                <p role="status" class="text-small font-medium text-low">{{ __('storefront.preorder.refused.limit_reached') }}</p>
                @auth
                    <a href="{{ route('account') }}" class="{{ $secondaryButton }} sm:self-start">{{ __('storefront.preorder.my_orders') }}</a>
                @endauth
            @else
                <div class="flex flex-wrap items-center gap-3">
                    <span class="inline-flex flex-none items-center rounded-control border border-line-strong bg-surface">
                        <button type="button" wire:click="decrement" @if ($atMin) aria-disabled="true" @endif aria-label="{{ __('storefront.card.decrease') }}" @class([$stepperButton, 'rounded-l-control', 'text-faint' => $atMin, 'text-ink hover:bg-raised' => ! $atMin])>&minus;</button>
                        <span class="min-w-9 text-center text-body font-medium tabular-nums" aria-live="polite">{{ $quantity }}</span>
                        <button type="button" wire:click="increment" @if ($atMax) aria-disabled="true" @endif aria-label="{{ __('storefront.card.increase') }}" @class([$stepperButton, 'rounded-r-control', 'text-faint' => $atMax, 'text-ink hover:bg-raised' => ! $atMax])>+</button>
                    </span>
                    <button type="button" wire:click="reserve" wire:loading.attr="aria-busy" wire:target="reserve" class="{{ $primaryButton }}">{{ __('storefront.preorder.reserve') }}</button>
                </div>
                @if ($atMax)
                    {{-- The account limit and the places left are different reasons: say which one stops the stepper. --}}
                    <p class="text-small text-muted">{{ $limitBinds ? __('storefront.preorder.at_max_limit', ['n' => $cap]) : __('storefront.preorder.at_max', ['n' => $cap]) }}</p>
                @endif
                @guest
                    <p class="text-small text-muted">{{ __('storefront.preorder.login_note') }}</p>
                @endguest
            @endif

            <x-preorder-refund :until="$preorder->cancelUntilEnd()" :kept-satang="$plan === \App\Enums\PreorderPlan::Full ? $preorder->deposit_satang * $quantity : null" class="text-small text-muted" />
        @else
            <p role="status" class="text-small font-medium text-muted">{{ __('storefront.preorder.refused.'.$state) }}</p>
            @if ($state === 'not_yet' && $preorder->opens_at !== null)
                <p class="text-small text-muted">{{ __('storefront.preorder.opens_at', ['date' => $day($preorder->opens_at), 'time' => $preorder->opens_at->copy()->setTimezone($timezone)->format('H:i')]) }}</p>
            @endif
            <a href="{{ $browseUrl }}" class="{{ $secondaryButton }} sm:self-start">{{ __('storefront.preorder.browse') }}</a>
        @endif
    @endif

    @if ($notice !== null)
        <p role="status" class="text-small text-low">{{ $notice }}</p>
    @endif
</div>
