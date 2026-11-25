@props(['full' => true, 'variant' => 'primary'])

{{-- The design tokens have no LINE green, so the primary button is the accent one with a speech-bubble glyph. A screen has
     one gold primary: where another action already is, this one takes the secondary variant. --}}
<a
    href="{{ route('line.redirect') }}"
    {{ $attributes->class([
        'w-full' => $full,
        'inline-flex min-h-11 items-center justify-center gap-2 rounded-control px-4 text-small',
        'bg-accent font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none' => $variant === 'primary',
        'border border-line-strong bg-surface text-ink transition-[border-color] duration-160 ease-ui hover:border-accent motion-reduce:transition-none' => $variant === 'secondary',
    ]) }}
>
    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3C5.9 3 2.8 5.6 2.8 8.8c0 2.7 2.2 4.9 5.3 5.6.3.1.3.4.3.6l-.1.9c0 .3.2.4.5.3 1.2-.6 3.5-2 4.7-3.3 1.4-1.2 2.3-2.7 2.3-4.2C15.8 5.6 13.2 3 10 3Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
    {{ $slot }}
</a>
