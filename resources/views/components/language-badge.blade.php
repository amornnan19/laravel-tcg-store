@props(['language'])

@php
    $language = $language instanceof \App\Enums\Language ? $language : \App\Enums\Language::from($language);
@endphp

<span {{ $attributes->class('inline-flex h-6 items-center rounded-tag border border-line px-2 text-label font-medium text-muted') }}><span class="sr-only">{{ $language->label() }}</span><span aria-hidden="true">{{ $language->shortCode() }}</span></span>
