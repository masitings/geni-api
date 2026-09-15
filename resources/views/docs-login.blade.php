<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $title }}</title>

    <!-- Theme Initialization to Prevent FOUC -->
    <script>
        (function () {
            const stored = localStorage.getItem('geni_theme');
            if (stored === 'dark' || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <!-- Tailwind CSS Play CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    },
                }
            }
        }
    </script>

    <!-- Alpine.js CDN -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body
    class="h-full bg-slate-50 text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-100 flex flex-col items-center justify-center p-4 selection:bg-sky-500 selection:text-white"
    x-data="{
        showPassword: false,
        isDark: document.documentElement.classList.contains('dark'),
        submitting: false,
        toggleTheme() {
            this.isDark = !this.isDark;
            if (this.isDark) {
                document.documentElement.classList.add('dark');
                localStorage.setItem('geni_theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('geni_theme', 'light');
            }
        }
    }"
>
    <!-- Main Content -->
    <main class="w-full max-w-sm">
        <div class="rounded-xl border border-slate-200/90 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900 flex flex-col gap-5">
            <!-- Header -->
            <div class="flex flex-col gap-1">
                <h1 class="text-base font-bold text-slate-900 dark:text-white">API Documentation</h1>
                <p class="text-xs text-slate-500 dark:text-slate-400">Enter docs credentials to continue.</p>
            </div>

            <!-- Error Banner -->
            @if($errors->has('credentials'))
                <div class="flex items-center gap-2 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-700 dark:text-rose-400" role="alert">
                    <svg class="h-3.5 w-3.5 shrink-0 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ $errors->first('credentials') }}</span>
                </div>
            @endif

            <!-- Form -->
            <form action="{{ $actionUrl }}" method="POST" @submit="submitting = true" class="flex flex-col gap-3.5">
                @csrf

                <!-- Username Input -->
                <div class="flex flex-col gap-1">
                    <label for="username" class="text-xs font-medium text-slate-700 dark:text-slate-300">Username</label>
                    <input
                        type="text"
                        name="username"
                        id="username"
                        value="{{ old('username') }}"
                        required
                        autofocus
                        autocomplete="username"
                        placeholder="Username"
                        class="h-9 w-full rounded-lg border border-slate-200 bg-white px-3 text-xs text-slate-900 placeholder:text-slate-400 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-100 transition-colors"
                    />
                </div>

                <!-- Password Input with Toggle -->
                <div class="flex flex-col gap-1">
                    <label for="password" class="text-xs font-medium text-slate-700 dark:text-slate-300">Password</label>
                    <div class="relative flex items-center">
                        <input
                            :type="showPassword ? 'text' : 'password'"
                            name="password"
                            id="password"
                            required
                            autocomplete="current-password"
                            placeholder="Password"
                            class="h-9 w-full rounded-lg border border-slate-200 bg-white pr-9 pl-3 text-xs text-slate-900 placeholder:text-slate-400 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-100 transition-colors"
                        />
                        <button
                            type="button"
                            @click="showPassword = !showPassword"
                            class="absolute right-2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 focus:outline-none"
                            :aria-label="showPassword ? 'Hide password' : 'Show password'"
                        >
                            <svg x-show="!showPassword" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            <svg x-show="showPassword" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    :disabled="submitting"
                    class="mt-1 flex h-9 w-full items-center justify-center gap-1.5 rounded-lg bg-sky-600 text-xs font-semibold text-white shadow-xs hover:bg-sky-500 active:scale-[0.98] transition-transform disabled:opacity-60 cursor-pointer"
                >
                    <svg x-show="submitting" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
                        <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path>
                    </svg>
                    <span x-text="submitting ? 'Signing in...' : 'Sign In'"></span>
                </button>
            </form>
        </div>
    </main>
</body>
</html>
