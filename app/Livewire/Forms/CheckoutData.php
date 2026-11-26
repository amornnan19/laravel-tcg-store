<?php

namespace App\Livewire\Forms;

use App\Enums\Courier;
use App\Enums\PaymentMethod;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * What checkout asks for: the recipient, the address, the courier, the packing option, the payment method and whether to
 * spend store credit.
 * `validate()` checks the raw input; `normalized()` is what the order stores (trimmed, phone as digits).
 * The text properties are never rewritten server-side, so the response cannot overwrite what is being typed.
 */
class CheckoutData extends Form
{
    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $line1 = '';

    public string $subdistrict = '';

    public string $district = '';

    public string $province = '';

    public string $postcode = '';

    public string $courier = 'flash';

    public bool $protection = false;

    public string $method = '';

    /** Spend store credit on this order; honoured only for a signed-in customer with a balance. */
    public bool $useCredit = false;

    /** The credit the form showed as applied when it last rendered (0 when the box is unticked): PlaceOrder refuses a spend that differs. */
    public int $shownCreditSatang = 0;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (preg_match('/^0\d{8,9}$/', preg_replace('/[\s-]/', '', (string) $value) ?? '') !== 1) {
                    $fail(__('storefront.checkout.errors.phone'));
                }
            }],
            'email' => ['required', 'string', 'email', 'max:255'],
            'line1' => ['required', 'string', 'max:200'],
            'subdistrict' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'postcode' => ['required', 'regex:/^\d{5}$/'],
            'courier' => ['required', Rule::enum(Courier::class)],
            'protection' => ['boolean'],
            'useCredit' => ['boolean'],
            'shownCreditSatang' => ['integer', 'min:0'],
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $method): string => $method->value, PaymentMethod::offered()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'required' => __('storefront.checkout.errors.required'),
            'max' => __('storefront.checkout.errors.max'),
            'email' => __('storefront.checkout.errors.email'),
            'postcode.regex' => __('storefront.checkout.errors.postcode'),
            'courier.*' => __('storefront.checkout.errors.courier'),
            'method.in' => __('storefront.checkout.errors.method'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return collect(['name', 'phone', 'email', 'line1', 'subdistrict', 'district', 'province', 'postcode', 'courier', 'method'])
            ->mapWithKeys(fn (string $field): array => [$field => __('storefront.checkout.fields.'.$field)])
            ->all();
    }

    /**
     * The checkout choices the order is built from, after validation.
     *
     * @return array{name: string, phone: string, email: string, line1: string, subdistrict: string, district: string, province: string, postcode: string}
     */
    public function normalized(): array
    {
        return [
            'name' => trim($this->name),
            'phone' => preg_replace('/\D/', '', $this->phone) ?? '',
            'email' => trim($this->email),
            'line1' => trim($this->line1),
            'subdistrict' => trim($this->subdistrict),
            'district' => trim($this->district),
            'province' => trim($this->province),
            'postcode' => trim($this->postcode),
        ];
    }
}
