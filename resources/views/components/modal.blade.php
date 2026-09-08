{{--
    Modal built on the native <dialog> element.

    Breeze's Alpine version hand-rolled a focus trap, tab cycling, Escape
    handling and body-scroll locking. <dialog> + showModal() gives all of that,
    plus top-layer stacking and a real ::backdrop, from the platform.

    Open it with `<button data-modal-open="{{ $name }}">`; anything inside
    carrying `data-modal-close` dismisses it. Both are wired in
    resources/js/app.js. `:show="true"` opens it on load, which is how a form
    that failed validation inside the modal gets shown again.
--}}
@props([
    'name',
    'show' => false,
    'maxWidth' => '2xl',
])

@php
    $maxWidth = [
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'lg' => 'sm:max-w-lg',
        'xl' => 'sm:max-w-xl',
        '2xl' => 'sm:max-w-2xl',
    ][$maxWidth];
@endphp

<dialog
    id="{{ $name }}"
    class="modal {{ $maxWidth }}"
    aria-labelledby="{{ $name }}-title"
    @if ($show) data-modal-initially-open @endif
>
    {{ $slot }}
</dialog>
