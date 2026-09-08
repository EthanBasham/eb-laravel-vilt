<footer class="border-t border-gray-200 bg-white">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-col items-center justify-between gap-4 text-sm text-gray-500 md:flex-row">
            <p>&copy; {{ now()->year }} {{ config('app.name') }}. Built while learning the VILT stack.</p>

            <nav aria-label="Footer" class="flex items-center gap-6">
                <a href="{{ route('home') }}" class="hover:text-gray-700">Home</a>
                <a href="{{ route('projects.index') }}" class="hover:text-gray-700">Projects</a>

                @guest
                    <a href="{{ route('login') }}" class="hover:text-gray-700">Log in</a>
                @else
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="hover:text-gray-700">Log out</button>
                    </form>
                @endguest
            </nav>
        </div>
    </div>
</footer>
