{{--
    Nav item that works both stacked (mobile menu) and inline (md and up), so
    the header needs only one link component rather than Breeze's separate
    desktop/responsive pair.
--}}
@props(['active' => false])

@php
    $classes = $active
        ? 'block rounded-md px-3 py-2 text-sm font-medium text-brand-700 bg-brand-50'
        : 'block rounded-md px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }} @if ($active) aria-current="page" @endif>
    {{ $slot }}
</a>
