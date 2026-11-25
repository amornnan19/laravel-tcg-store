@props(['name', 'type' => 'text', 'label', 'autocomplete' => null, 'hint' => null, 'value' => null])

@php
    $invalid = $errors->has($name);
    $id = 'auth-'.$name;
    $describedBy = trim(($invalid ? $id.'-error ' : '').($hint !== null ? $id.'-hint' : ''));
@endphp

<div>
    <label for="{{ $id }}" class="mb-1 block text-small text-muted">{{ $label }}</label>
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($value !== null) value="{{ $value }}" @endif
        @if ($autocomplete !== null) autocomplete="{{ $autocomplete }}" @endif
        @if ($type === 'email') inputmode="email" autocapitalize="none" spellcheck="false" @endif
        @if ($invalid) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        @class([
            'block min-h-11 w-full rounded-control border bg-surface px-3 text-body text-ink placeholder:text-faint',
            'border-danger' => $invalid,
            'border-line-strong' => ! $invalid,
        ])
    >
    @if ($hint !== null)
        <p id="{{ $id }}-hint" class="mt-1 text-label text-muted">{{ $hint }}</p>
    @endif
    @if ($invalid)
        <p id="{{ $id }}-error" class="mt-1 text-small text-danger">{{ $errors->first($name) }}</p>
    @endif
</div>
