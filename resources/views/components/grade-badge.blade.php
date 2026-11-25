@props(['label', 'large' => false])

{{-- Neutral on purpose: the grade is a fact, not an offer, so the only gold on a graded page is the button. --}}
<span {{ $attributes->class([
    'inline-flex items-center rounded-tag border border-line-strong font-medium text-ink',
    'h-6 px-2 text-label' => ! $large,
    'px-3 py-1 font-display text-h2' => $large,
]) }}>{{ $label }}</span>
