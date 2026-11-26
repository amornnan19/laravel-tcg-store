@props(['until', 'keptSatang' => null])

{{-- The refund rule of a pre-order. The closing clause never breaks across lines: a line break inside it splits a Thai word and reads as another. `keptSatang` set: a full-plan order, where the shop keeps that deposit portion. --}}
<span {{ $attributes }}>{{ __($keptSatang === null ? 'storefront.preorder.refund_until' : 'storefront.preorder.refund_until_full', ['date' => $until->copy()->setTimezone(config('shop.timezone'))->locale('th')->translatedFormat('j M Y')]) }} <span class="whitespace-nowrap">{{ $keptSatang === null ? __('storefront.preorder.refund_after') : __('storefront.preorder.refund_after_full', ['deposit' => \App\Support\Money::fromSatang($keptSatang)->format()]) }}</span></span>
