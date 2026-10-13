<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- The saved theme, or the system one, is applied before first paint so a dark page never flashes white. --}}
        <script>
            (function () {
                var saved = null;
                try {
                    saved = localStorage.getItem('darkMode');
                } catch (error) {}
                var dark = saved === null ? window.matchMedia('(prefers-color-scheme: dark)').matches : saved === 'true';
                document.documentElement.classList.toggle('dark', dark);
            })();
        </script>

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
