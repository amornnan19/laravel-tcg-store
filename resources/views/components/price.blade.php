@props(['satang'])

<span {{ $attributes->class('tabular-nums') }}>{{ \App\Support\Money::fromSatang($satang)->format() }}</span>
