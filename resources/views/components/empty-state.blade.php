@props(['title', 'hint' => null])

<div {{ $attributes->class('flex flex-col items-center gap-2 rounded-card border border-dashed border-line bg-sunken px-4 py-6 text-center') }}>
    <svg width="34" height="34" viewBox="0 0 34 34" fill="none" aria-hidden="true" class="text-line-strong"><circle cx="14.5" cy="14.5" r="9" stroke="currentColor" stroke-width="2"/><path d="M21.5 21.5 30 30" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    <p class="text-small font-medium text-ink">{{ $title }}</p>
    @if ($hint !== null)
        <p class="text-label text-muted">{{ $hint }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-1 flex flex-col items-center gap-1">{{ $slot }}</div>
    @endif
</div>
