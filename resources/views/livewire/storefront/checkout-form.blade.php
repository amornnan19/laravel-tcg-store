@php
    $held = $this->heldLines;
    $expiresAt = $this->expiresAt;
    $canPlace = $this->canPlace();
    $format = fn (int $satang): string => \App\Support\Money::fromSatang($satang)->format();
    $primaryButton = 'inline-flex min-h-11 items-center justify-center rounded-control bg-accent px-4 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none';
    $input = 'block min-h-11 w-full rounded-control border bg-surface px-3 text-body text-ink placeholder:text-faint';
    $radio = 'grid size-5 flex-none appearance-none place-items-center rounded-full border-2 border-line-strong bg-transparent before:size-2.5 before:scale-0 before:rounded-full before:bg-accent focus-visible:outline-none checked:border-accent checked:before:scale-100 motion-safe:before:transition-transform motion-safe:before:duration-160';
    $card = 'flex min-h-14 cursor-pointer items-center gap-3 rounded-control border border-line bg-surface px-4 py-3 transition-[border-color,background-color] duration-160 ease-ui has-checked:border-accent has-checked:bg-raised has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent motion-reduce:transition-none';
    $fieldError = fn (string $field): bool => $errors->has('form.'.$field);
    $fields = [
        ['name' => 'name', 'type' => 'text', 'autocomplete' => 'name', 'inputmode' => null, 'span' => 'sm:col-span-2'],
        ['name' => 'phone', 'type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'tel', 'span' => ''],
        ['name' => 'email', 'type' => 'email', 'autocomplete' => 'email', 'inputmode' => 'email', 'span' => ''],
        ['name' => 'line1', 'type' => 'text', 'autocomplete' => 'address-line1', 'inputmode' => null, 'span' => 'sm:col-span-2'],
        ['name' => 'subdistrict', 'type' => 'text', 'autocomplete' => 'address-level3', 'inputmode' => null, 'span' => ''],
        ['name' => 'district', 'type' => 'text', 'autocomplete' => 'address-level2', 'inputmode' => null, 'span' => ''],
        ['name' => 'province', 'type' => 'text', 'autocomplete' => 'address-level1', 'inputmode' => null, 'span' => ''],
        ['name' => 'postcode', 'type' => 'text', 'autocomplete' => 'postal-code', 'inputmode' => 'numeric', 'span' => ''],
    ];
@endphp

<div class="flex flex-col gap-6">
    <div class="flex items-baseline justify-between gap-4">
        <h1 class="font-display text-h2 font-medium lg:text-h1">{{ __('storefront.checkout.title') }}</h1>
        @if ($canPlace)
            <a href="{{ route('cart') }}" class="inline-flex min-h-11 items-center text-small text-accent hover:text-ink">{{ __('storefront.checkout.edit_cart') }}</a>
        @endif
    </div>

    @if (! $canPlace)
        <div class="flex flex-col items-start gap-3 rounded-card border border-line bg-sunken p-4" role="status">
            <h2 class="font-display text-card font-medium">{{ __('storefront.checkout.blocked_title') }}</h2>
            <p class="text-small text-muted">{{ __('storefront.checkout.blocked') }}</p>
            <a href="{{ route('cart') }}" class="{{ $primaryButton }}">{{ __('storefront.checkout.back_to_cart') }}</a>
        </div>
    @else
        @if ($expiresAt !== null)
            <x-lock-banner :expires-at="$expiresAt" />
        @endif

        <section aria-labelledby="checkout-lines-heading">
            <div class="mb-3 flex items-baseline justify-between gap-4">
                <h2 id="checkout-lines-heading" class="font-display text-card font-medium">{{ __('storefront.checkout.lines', ['n' => $held->count()]) }}</h2>
            </div>
            <ul class="flex flex-col gap-2">
                @foreach ($held as $line)
                    @php
                        $variant = $line->purchasable;
                        $printing = $variant->printing;
                    @endphp
                    <li wire:key="line-{{ $line->id }}" class="flex items-start gap-3 rounded-card border border-line bg-sunken p-3">
                        <x-card-art :printing="$printing" size="w-10" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-small font-medium">{{ $printing->display_name }}</p>
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-label text-faint">
                                <span class="tabular-nums">{{ $printing->display_code }}</span>
                                <x-language-badge :language="$printing->language" />
                                <x-condition-badge :condition="$variant->condition" />
                                <span>{{ $variant->finish->label() }}</span>
                            </p>
                        </div>
                        <div class="flex-none text-right">
                            <x-price :satang="$line->unit_price_satang * $line->qty" class="text-small font-medium" />
                            @if ($line->qty > 1)
                                <p class="mt-1 text-label text-faint tabular-nums">&times; {{ $line->qty }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        @if ($methods === [])
            <p class="rounded-card border border-line bg-sunken p-4 text-small text-muted" role="status">{{ __('storefront.checkout.no_methods') }}</p>
        @else
            <form
                wire:submit="placeOrder"
                novalidate
                x-data
                x-on:checkout-invalid.window="$nextTick(() => $el.querySelector('[aria-invalid=true]')?.focus())"
                class="group flex flex-col gap-6"
            >
                <section aria-labelledby="checkout-address-heading">
                    <h2 id="checkout-address-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.checkout.address') }}</h2>
                    <div class="grid gap-3 rounded-card border border-line bg-sunken p-4 sm:grid-cols-2">
                        @foreach ($fields as $field)
                            @php
                                $invalid = $fieldError($field['name']);
                                $id = 'checkout-'.$field['name'];
                            @endphp
                            <div class="{{ $field['span'] }}">
                                <label for="{{ $id }}" class="mb-1 block text-small text-muted">{{ __('storefront.checkout.fields.'.$field['name']) }}</label>
                                <input
                                    id="{{ $id }}"
                                    type="{{ $field['type'] }}"
                                    wire:model.live.blur="form.{{ $field['name'] }}"
                                    data-text-input
                                    autocomplete="{{ $field['autocomplete'] }}"
                                    enterkeyhint="next"
                                    @if ($field['name'] === 'postcode') maxlength="5" @endif
                                    @if ($field['inputmode'] !== null) inputmode="{{ $field['inputmode'] }}" @endif
                                    @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
                                    @class([$input, 'border-danger' => $invalid, 'border-line-strong' => ! $invalid])
                                >
                                @if ($invalid)
                                    <p id="{{ $id }}-error" class="mt-1 text-small text-danger">{{ $errors->first('form.'.$field['name']) }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>

                <section aria-labelledby="checkout-courier-heading">
                    <h2 id="checkout-courier-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.checkout.courier') }}</h2>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($couriers as $option)
                            <label wire:key="courier-{{ $option->value }}" class="{{ $card }}">
                                <input type="radio" name="courier" value="{{ $option->value }}" wire:model.live="form.courier" class="{{ $radio }}">
                                <span class="flex min-w-0 flex-col gap-1">
                                    <span class="text-small font-medium">{{ $option->label() }}</span>
                                    <span class="text-small tabular-nums text-muted">{{ $format($option->rateSatang()) }} · {{ $option->daysLabel() }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <label class="mt-3 flex cursor-pointer items-start gap-3 rounded-card border border-line bg-surface p-3 has-checked:border-accent has-checked:bg-accent/8 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent">
                        <input type="checkbox" wire:model.live="form.protection" class="mt-0.5 focus-visible:outline-none grid size-5 flex-none appearance-none place-items-center rounded-tag border-2 border-line-strong bg-transparent before:mb-0.5 before:h-2.5 before:w-1.5 before:rotate-45 before:scale-0 before:border-b-2 before:border-r-2 before:border-accent-ink checked:border-accent checked:bg-accent checked:before:scale-100 motion-safe:before:transition-transform motion-safe:before:duration-160">
                        <span class="min-w-0 flex-1">
                            <span class="block text-small font-medium">{{ __('storefront.checkout.protection') }} <span class="tabular-nums text-accent">+ <x-price :satang="(int) config('shipping.protection_satang')" /></span></span>
                            <span class="mt-1 block text-small text-muted">{{ __('storefront.checkout.protection_hint', ['threshold' => $format((int) config('shipping.protection_threshold_satang'))]) }}</span>
                        </span>
                    </label>
                </section>

                <section aria-labelledby="checkout-payment-heading">
                    <h2 id="checkout-payment-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.checkout.payment') }}</h2>
                    <div class="flex flex-col gap-2">
                        @foreach ($methods as $option)
                            <label wire:key="method-{{ $option->value }}" class="{{ $card }}">
                                <input type="radio" name="method" value="{{ $option->value }}" wire:model="form.method" class="{{ $radio }}">
                                <span class="flex min-w-0 flex-col gap-1">
                                    <span class="text-small font-medium">{{ $option->label() }}</span>
                                    <span class="text-small text-muted">{{ $option === \App\Enums\PaymentMethod::Promptpay ? __('storefront.checkout.method_promptpay_hint') : __('storefront.checkout.method_bank_hint') }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @if ($errors->has('form.method'))
                        <p class="mt-1 text-small text-danger">{{ $errors->first('form.method') }}</p>
                    @endif
                </section>

                <section aria-labelledby="checkout-summary-heading">
                    <h2 id="checkout-summary-heading" class="mb-3 font-display text-card font-medium">{{ __('storefront.checkout.summary') }}</h2>
                    <div class="flex flex-col gap-1 rounded-card border border-line bg-sunken p-4 text-small text-muted">
                        <p class="flex items-baseline justify-between gap-4"><span>{{ __('storefront.checkout.items_total', ['n' => $held->count()]) }}</span><x-price :satang="$this->subtotal" class="text-ink" /></p>
                        <p class="flex items-baseline justify-between gap-4"><span>{{ __('storefront.checkout.shipping', ['courier' => $courier->label()]) }}</span><x-price :satang="$shipping" class="text-ink" /></p>
                        @if ($protection > 0)
                            <p class="flex items-baseline justify-between gap-4"><span>{{ __('storefront.checkout.protection') }}</span><x-price :satang="$protection" class="text-ink" /></p>
                        @endif
                        <p class="mt-2 flex items-baseline justify-between gap-4 border-t border-line pt-3">
                            <span class="text-body font-medium text-ink">{{ __('storefront.checkout.total_due') }}</span>
                            <x-price :satang="$total" class="text-h3 font-medium text-ink" />
                        </p>
                    </div>
                    <p class="mt-3 text-small text-muted">{{ __('storefront.checkout.terms', ['minutes' => config('shop.payment_window_minutes')]) }}</p>
                </section>

                <div class="fixed inset-x-0 bottom-0 z-30 flex items-center gap-3 border-t border-line bg-sunken px-4 pb-4 pt-3 max-lg:group-has-[[data-text-input]:focus]:hidden lg:static lg:border-0 lg:bg-transparent lg:p-0">
                    <p class="min-w-0 flex-1 lg:hidden">
                        <span class="block text-label text-faint">{{ __('storefront.checkout.total') }}</span>
                        <x-price :satang="$total" class="mt-1 block text-h3 font-medium" />
                    </p>
                    <button type="submit" wire:loading.attr="aria-busy" wire:target="placeOrder" class="{{ $primaryButton }} min-h-12 flex-none px-5 text-body lg:w-full">{{ __('storefront.checkout.confirm') }}</button>
                </div>
            </form>
        @endif
    @endif
</div>
