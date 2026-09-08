{{--
    Dropdown menu. Replaces Breeze's Alpine version; the open/closed state is a
    single `.is-open` class that jQuery sets (resources/js/app.js), and the
    show/hide itself is CSS (_components.scss).

    With JS off the menu stays closed. Every destination it holds is reachable
    another way — Profile from /profile, Log out from the footer — so nothing
    becomes unreachable.
--}}
@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1 bg-white'])

@php
    $alignmentClasses = match ($align) {
        'left' => 'ltr:origin-top-left rtl:origin-top-right start-0',
        'top' => 'origin-top',
        default => 'ltr:origin-top-right rtl:origin-top-left end-0',
    };

    $width = match ($width) {
        '48' => 'w-48',
        default => $width,
    };
@endphp

<div class="dropdown relative">
    <button
        type="button"
        class="dropdown__trigger inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900"
        aria-expanded="false"
        aria-haspopup="true"
    >
        {{ $trigger }}
    </button>

    <div class="dropdown__menu mt-2 rounded-md shadow-lg {{ $width }} {{ $alignmentClasses }}">
        <div class="rounded-md ring-1 ring-black/5 {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
