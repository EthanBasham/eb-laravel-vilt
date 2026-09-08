{{--
    Site header. Replaces Breeze's layouts/navigation.blade.php, which was
    Alpine-driven.

    The mobile menu is progressive enhancement: `.nav-menu` is collapsed below
    `md` and always open at `md` and up, both in CSS (_components.scss). jQuery
    only toggles `.is-open`, so with JS off the nav is permanently expanded
    rather than unreachable.
--}}
<header class="border-b border-gray-200 bg-white">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-center justify-between gap-y-2 py-3 md:flex-nowrap">
            <a href="{{ route('home') }}" class="flex items-baseline gap-2">
                <span class="text-lg font-semibold text-brand-600">{{ config('app.name') }}</span>
                <span class="hidden text-sm text-gray-500 sm:inline">Vue · Inertia · Laravel · Tailwind</span>
            </a>

            <button
                type="button"
                class="nav-toggle inline-flex items-center justify-center rounded-md p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 md:hidden"
                aria-expanded="false"
                aria-controls="nav-menu"
            >
                <span class="nav-toggle__label">Toggle navigation</span>
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <nav id="nav-menu" class="nav-menu w-full md:w-auto" aria-label="Primary">
                <div class="flex flex-col gap-1 py-2 md:flex-row md:items-center md:justify-end md:gap-2 md:py-0">
                    <x-nav-link :href="route('home')" :active="request()->routeIs('home')">
                        Home
                    </x-nav-link>

                    <x-nav-link :href="route('projects.index')" :active="request()->routeIs('projects.*')">
                        Projects
                    </x-nav-link>

                    @guest
                        <x-nav-link :href="route('login')" :active="request()->routeIs('login')">
                            Log in
                        </x-nav-link>

                        <a
                            href="{{ route('register') }}"
                            class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700 md:ms-2"
                        >
                            Register
                        </a>
                    @else
                        <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            Dashboard
                        </x-nav-link>

                        <x-dropdown align="right" width="48">
                            <x-slot name="trigger">
                                {{ Auth::user()->name }}

                                <svg class="ms-1 h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </x-slot>

                            <x-slot name="content">
                                <x-dropdown-link :href="route('profile.edit')">
                                    Profile
                                </x-dropdown-link>

                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf

                                    <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                        Log out
                                    </x-dropdown-link>
                                </form>
                            </x-slot>
                        </x-dropdown>
                    @endguest
                </div>
            </nav>
        </div>
    </div>
</header>
