@props(['condition'])

@php
    $condition = $condition instanceof \App\Enums\CardCondition ? $condition : \App\Enums\CardCondition::from($condition);
    $colour = match ($condition) {
        \App\Enums\CardCondition::Nm => 'border-cond-nm/40 bg-cond-nm/10 text-cond-nm',
        \App\Enums\CardCondition::Lp => 'border-cond-lp/40 bg-cond-lp/10 text-cond-lp',
        \App\Enums\CardCondition::Mp => 'border-cond-mp/40 bg-cond-mp/10 text-cond-mp',
        \App\Enums\CardCondition::Hp => 'border-cond-hp/40 bg-cond-hp/10 text-cond-hp',
        \App\Enums\CardCondition::Dmg => 'border-cond-dmg/40 bg-cond-dmg/10 text-cond-dmg',
    };
@endphp

<span {{ $attributes->class([$colour, 'inline-flex h-6 min-w-9 items-center justify-center rounded-tag border px-2 text-label font-medium']) }}><span class="sr-only">{{ $condition->label() }}</span><span aria-hidden="true">{{ strtoupper($condition->value) }}</span></span>
