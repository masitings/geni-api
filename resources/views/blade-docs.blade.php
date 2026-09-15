<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $title }} - API Documentation</title>

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
    <script src="{{ $configuration['tailwind_cdn_url'] ?? 'https://cdn.tailwindcss.com' }}"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    },
                    colors: {
                        brand: {
                            50: '#f0fdf4',
                            500: '#10b981',
                            600: '#059669',
                        }
                    }
                }
            }
        }
    </script>

    <!-- Alpine.js CDN -->
    <script defer src="{{ $configuration['alpine_cdn_url'] ?? 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js' }}"></script>

    <!-- Prism.js CDN — real syntax highlighting for code samples -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-core.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-clike.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-json.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markup-templating.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-bash.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-ruby.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-go.min.js"></script>

    <style>
        pre[class*="language-"] { background: transparent !important; margin: 0 !important; }
        code[class*="language-"] { text-shadow: none !important; }
        [x-cloak] { display: none !important; }
        /* Smooth scrolling */
        html { scroll-behavior: smooth; }
        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(156, 163, 175, 0.4); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(156, 163, 175, 0.6); }
        .dark ::-webkit-scrollbar-thumb { background: rgba(75, 85, 99, 0.5); }
        .dark ::-webkit-scrollbar-thumb:hover { background: rgba(75, 85, 99, 0.8); }
        {!! $configuration['custom_css'] ?? '' !!}
    </style>
</head>
<body
    class="h-full bg-slate-50 text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-100 selection:bg-sky-500 selection:text-white"
    x-data="geniDocs({
        specUrl: '{{ $url }}',
        currentApi: '{{ $currentApi ?? 'default' }}',
        availableApis: {{ json_encode($availableApis ?? []) }}
    })"
    x-init="init()"
>
    <!-- Loading State -->
    <div x-show="loading" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-50 dark:bg-slate-950 gap-3 text-sm text-slate-500 font-medium">
        <svg class="h-5 w-5 animate-spin text-sky-600 dark:text-sky-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
            <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path>
        </svg>
        <span>Loading API specification...</span>
    </div>

    <!-- Error State -->
    <div x-show="error" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-50 dark:bg-slate-950 p-6">
        <div class="max-w-md w-full rounded-xl border border-rose-500/30 bg-rose-500/10 p-6 text-center text-slate-900 dark:text-slate-100 flex flex-col items-center gap-3 shadow-lg">
            <svg class="h-8 w-8 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <h2 class="text-base font-bold">Failed to load API Specification</h2>
            <p class="text-xs text-slate-600 dark:text-slate-400" x-text="error"></p>
            <button @click="window.location.reload()" class="mt-2 rounded-lg bg-slate-900 px-4 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">
                Retry
            </button>
        </div>
    </div>

    <!-- App Container -->
    <div x-show="!loading && !error" class="flex h-screen flex-col overflow-hidden">
        <!-- Mobile Bar (drawer trigger only; title & theme toggle live in the sidebar) -->
        <div class="flex h-12 shrink-0 items-center gap-3 border-b border-slate-200/80 bg-white/95 px-4 backdrop-blur-md dark:border-slate-800/80 dark:bg-slate-900/95 lg:hidden">
            <button
                type="button"
                @click="isMobileMenuOpen = true"
                class="flex size-8 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:border-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-500"
                aria-label="Open navigation sidebar"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <span class="font-bold text-sm tracking-tight text-slate-900 dark:text-white truncate" x-text="doc?.info?.title || '{{ $title }}'"></span>
        </div>

        <!-- Main Workspace -->
        <div class="flex flex-1 gap-3 overflow-hidden p-3">
            <!-- Sidebar Navigation (Desktop) -->
            <aside
                class="hidden lg:flex shrink-0 flex-col rounded-2xl bg-white shadow-xs dark:bg-slate-900 overflow-hidden transition-all duration-200"
                :class="sidebarCollapsed ? 'w-0 opacity-0 pointer-events-none' : 'w-64 opacity-100'"
            >
                <template x-if="true">
                    <div class="flex h-full w-64 flex-col">
                        <!-- Sidebar Header: Title, Collapse & Theme Toggle -->
                        <div class="flex items-center justify-between gap-2 p-3">
                            <span class="font-bold text-sm tracking-tight text-slate-900 dark:text-white truncate" x-text="doc?.info?.title || '{{ $title }}'"></span>
                            <div class="flex shrink-0 items-center divide-x divide-slate-200 rounded-lg border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                                <button
                                    type="button"
                                    @click="toggleSidebar()"
                                    class="flex size-6 items-center justify-center rounded-l-lg text-slate-700 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800 transition-colors focus:outline-none focus:ring-2 focus:ring-sky-500"
                                    aria-label="Collapse sidebar"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    @click="toggleAutoCollapse()"
                                    class="flex size-6 items-center justify-center text-slate-700 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800 transition-colors focus:outline-none focus:ring-2 focus:ring-sky-500"
                                    :class="sidebarAutoCollapse ? 'bg-sky-500/10 text-sky-600 dark:text-sky-400' : ''"
                                    :aria-pressed="sidebarAutoCollapse"
                                    aria-label="Auto-collapse sidebar after selecting an endpoint"
                                    title="Auto-collapse after selecting an endpoint"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25"/>
                                    </svg>
                                </button>
                                @php
                                    $hasSignOut = config('geni.docs_auth.mode') === 'form' && config('geni.docs_auth.username') !== null && config('geni.docs_auth.password') !== null;
                                @endphp
                                <button
                                    type="button"
                                    @click="toggleTheme()"
                                    class="flex size-6 items-center justify-center {{ $hasSignOut ? '' : 'rounded-r-lg' }} text-slate-700 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800 transition-colors focus:outline-none focus:ring-2 focus:ring-sky-500"
                                    aria-label="Toggle dark and light mode"
                                >
                                <svg x-show="!isDark" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                                </svg>
                                <svg x-show="isDark" x-cloak class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                                </svg>
                                </button>
                                @if($hasSignOut)
                                    <form action="{{ url(config('geni.docs_ui_path', 'docs/api') . '/logout') }}" method="POST">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="flex size-6 items-center justify-center rounded-r-lg text-slate-700 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800 transition-colors focus:outline-none focus:ring-2 focus:ring-sky-500"
                                            aria-label="Sign out"
                                            title="Sign out"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                            </svg>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>

                        <!-- Version Switcher Dropdown (Shown only when availableApis > 1) -->
                        @if(isset($availableApis) && count($availableApis) > 1)
                        <div x-show="availableApis && availableApis.length > 1" x-cloak class="px-3 pb-1">
                            <div class="relative">
                                <button
                                    type="button"
                                    @click="isVersionDropdownOpen = !isVersionDropdownOpen"
                                    @click.away="isVersionDropdownOpen = false"
                                    @keydown.escape="isVersionDropdownOpen = false"
                                    class="flex w-full items-center justify-between gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-800 dark:bg-slate-800/60 dark:text-slate-300 dark:hover:bg-slate-800 transition-colors focus:outline-none focus:ring-2 focus:ring-sky-500"
                                    aria-haspopup="listbox"
                                    :aria-expanded="isVersionDropdownOpen"
                                    aria-label="Select API version"
                                >
                                    <span class="truncate" x-text="currentApiItem?.label || 'Select Version'"></span>
                                    <svg class="h-3 w-3 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>
                                <div
                                    x-show="isVersionDropdownOpen"
                                    x-cloak
                                    class="absolute left-0 right-0 top-full mt-1 max-h-64 overflow-y-auto z-30 rounded-lg border border-slate-200 bg-white p-1 shadow-lg dark:border-slate-800 dark:bg-slate-900"
                                    role="listbox"
                                    aria-label="API versions"
                                >
                                    <template x-for="apiItem in availableApis" :key="apiItem.key">
                                        <button
                                            type="button"
                                            @click="switchApi(apiItem.key)"
                                            class="flex w-full items-center justify-between rounded-md px-2 py-1 text-xs text-left transition-colors"
                                            :class="apiItem.key === currentApi ? 'bg-sky-500/10 font-semibold text-sky-600 dark:text-sky-400' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800'"
                                            role="option"
                                            :aria-selected="apiItem.key === currentApi"
                                        >
                                            <span class="truncate" x-text="apiItem.label"></span>
                                            <svg x-show="apiItem.key === currentApi" class="h-3.5 w-3.5 shrink-0 text-sky-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- Live Search Input -->
                        <div class="p-3">
                            <div class="relative flex items-center">
                                <svg class="absolute left-2.5 h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                                <input
                                    type="search"
                                    placeholder="Search endpoints..."
                                    x-model="searchQuery"
                                    class="h-8 w-full rounded-lg border border-slate-200 bg-white pl-8 pr-7 text-xs text-slate-900 placeholder:text-slate-400 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100"
                                    aria-label="Filter endpoints by path, method, or summary"
                                />
                                <button
                                    type="button"
                                    x-show="searchQuery"
                                    @click="searchQuery = ''"
                                    class="absolute right-2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                                    aria-label="Clear search query"
                                >
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Tag Groups Navigation -->
                        <nav class="flex-1 overflow-y-auto p-2" aria-label="API Endpoints Navigation">
                            <div x-show="filteredTags.length === 0" class="p-6 text-center text-xs text-slate-400">
                                No endpoints match "<span x-text="searchQuery"></span>"
                            </div>

                            <template x-for="tag in filteredTags" :key="tag">
                                <div class="mb-2">
                                    <button
                                        type="button"
                                        @click="toggleGroup(tag)"
                                        class="flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-xs font-bold uppercase tracking-wider text-slate-500 hover:bg-slate-200/50 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800/50 dark:hover:text-slate-200 transition-colors"
                                    >
                                        <div class="flex items-center gap-1.5 truncate">
                                            <svg
                                                class="h-3.5 w-3.5 shrink-0 transition-transform duration-150"
                                                :class="{ 'rotate-90': openGroups[tag] }"
                                                fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                            >
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                            </svg>
                                            <span class="truncate" x-text="tag"></span>
                                        </div>
                                        <span class="flex size-4.5 items-center justify-center rounded-full bg-slate-200/70 text-[10px] font-mono text-slate-600 dark:bg-slate-800 dark:text-slate-400" x-text="endpointsByTag[tag].length"></span>
                                    </button>

                                    <!-- Endpoint Items -->
                                    <div x-show="openGroups[tag]" class="mt-1 flex flex-col gap-0.5 rounded-lg bg-slate-100/70 p-1.5 dark:bg-slate-800/40">
                                        <template x-for="ep in endpointsByTag[tag]" :key="ep.method + '-' + ep.path">
                                            <button
                                                type="button"
                                                @click="selectEndpoint(ep)"
                                                class="group relative flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs transition-colors"
                                                :class="isSelected(ep) ? 'bg-slate-200/70 dark:bg-slate-800 font-medium text-slate-900 dark:text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800/50 dark:hover:text-slate-200'"
                                            >
                                                <!-- Active indicator bar -->
                                                <span x-show="isSelected(ep)" class="absolute left-0 top-1 bottom-1 w-1 rounded-r-full bg-slate-900 dark:bg-white"></span>

                                                <!-- Summary or Path -->
                                                <span class="min-w-0 flex-1 truncate" x-text="ep.operation.summary || ep.path"></span>

                                                <!-- Deprecated icon -->
                                                <span x-show="ep.operation.deprecated" class="text-[10px] font-mono text-amber-500" title="Deprecated">⚠️</span>

                                                <!-- Method (text only, right-aligned) -->
                                                <span
                                                    class="shrink-0 text-[9px] font-bold uppercase tracking-wide"
                                                    :class="getMethodTextClass(ep.method)"
                                                    x-text="ep.method"
                                                ></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </nav>

                        <!-- Sidebar Footer -->
                        <div class="p-3 flex flex-col gap-2 text-center text-[11px] text-slate-400">
                            @include('geni::partials.promo-card')
                            <span>Built with ❤️ by Masitings</span>
                            @if($packageVersion)
                                <span class="block font-mono text-[10px] text-slate-500">Geni v{{ $packageVersion }}</span>
                            @endif
                        </div>
                    </div>
                </template>
            </aside>

            <!-- Main Content Area -->
            <!-- Expand Sidebar Button (visible only while collapsed) -->
            <button
                type="button"
                x-show="sidebarCollapsed"
                x-cloak
                @click="toggleSidebar()"
                class="hidden lg:flex size-8 shrink-0 self-start items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 shadow-xs hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 transition-colors focus:outline-none focus:ring-2 focus:ring-sky-500"
                aria-label="Expand sidebar"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
                </svg>
            </button>

            <main class="min-w-0 flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                <template x-if="selectedEndpoint">
                    <div class="grid grid-cols-1 gap-8 xl:grid-cols-[minmax(0,1fr)_440px]">
                        <!-- Center Documentation Column -->
                        <div class="flex flex-col gap-8 min-w-0">
                            <!-- Endpoint Title & Path Banner -->
                            <div class="flex flex-col gap-3">
                                <nav aria-label="Breadcrumb" class="flex items-center gap-1.5 text-xs text-slate-400">
                                    <span>API Reference</span>
                                    <span>/</span>
                                    <span x-text="selectedEndpoint.operation.tags?.[0] || 'Default'"></span>
                                    <span>/</span>
                                    <span class="text-slate-800 dark:text-slate-200 font-medium truncate" x-text="selectedEndpoint.operation.summary || selectedEndpoint.path"></span>
                                </nav>

                                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white" x-text="selectedEndpoint.operation.summary || selectedEndpoint.path"></h1>

                                <!-- Deprecated Alert Banner -->
                                <div x-show="selectedEndpoint.operation.deprecated" class="flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-300">
                                    <svg class="h-4 w-4 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <span>This endpoint is marked as deprecated and may be removed in a future release.</span>
                                </div>

                                <!-- Path Bar with Copy Action -->
                                <div class="flex items-center justify-between gap-3 rounded-xl bg-white p-2.5 shadow-xs dark:bg-slate-900">
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <span
                                            class="rounded-md border px-2 py-0.5 font-mono text-xs font-bold uppercase tracking-wider"
                                            :class="getMethodBadgeClass(selectedEndpoint.method)"
                                            x-text="selectedEndpoint.method"
                                        ></span>
                                        <code class="font-mono text-xs sm:text-sm font-semibold text-slate-900 dark:text-slate-100 truncate" x-text="selectedEndpoint.path"></code>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <button
                                            type="button"
                                            @click="copy(window.location.origin + selectedEndpoint.path, 'path')"
                                            class="flex size-7 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:border-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100 transition-transform active:scale-95"
                                            :title="copied.path ? 'Copied!' : 'Copy full URL'"
                                        >
                                            <svg x-show="!copied.path" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                            </svg>
                                            <svg x-show="copied.path" x-cloak class="h-3.5 w-3.5 text-sky-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </button>

                                        <!-- Mobile Try It Trigger Button -->
                                        <button
                                            type="button"
                                            @click="isMobileTryItOpen = true"
                                            class="xl:hidden flex h-7 items-center gap-1 rounded-lg border border-sky-500/30 bg-sky-500/10 px-2.5 text-xs font-semibold text-sky-600 dark:text-sky-400 hover:bg-sky-500/20"
                                        >
                                            <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M8 5v14l11-7z"/>
                                            </svg>
                                            <span>Try It</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Description -->
                                <template x-if="selectedEndpoint.operation.description">
                                    <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed" x-text="selectedEndpoint.operation.description"></p>
                                </template>
                            </div>

                            <!-- Security Schemes -->
                            <template x-if="activeSecuritySchemes.length > 0">
                                <div class="rounded-xl border border-slate-200/80 bg-white shadow-xs dark:border-transparent dark:bg-slate-900 overflow-hidden">
                                    <div class="flex items-center gap-1.5 border-b border-slate-200/80 bg-slate-50/70 px-3 py-2.5 text-sm font-bold uppercase tracking-wider text-slate-900 dark:border-slate-800 dark:bg-slate-800/40 dark:text-white">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                        </svg>
                                        <span>Security Requirements</span>
                                    </div>
                                    <div class="text-xs">
                                        <template x-for="item in activeSecuritySchemes" :key="item.name">
                                            <div class="flex items-center justify-between border-b border-slate-200/80 p-3 last:border-b-0 dark:border-slate-800">
                                                <div class="flex items-center gap-2.5">
                                                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                                                    </svg>
                                                    <div class="flex flex-col">
                                                        <span class="font-semibold text-slate-900 dark:text-slate-100" x-text="item.name"></span>
                                                        <span class="text-[11px] text-slate-500 dark:text-slate-400" x-text="item.scheme.type === 'apiKey' ? 'API Key via header (' + (item.scheme.name || 'X-API-Key') + ')' : 'Bearer Token (Authorization: Bearer <token>)'"></span>
                                                    </div>
                                                </div>
                                                <span class="rounded-md bg-slate-100 px-2 py-0.5 font-mono text-[10px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-400 uppercase" x-text="item.scheme.type || 'http'"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <!-- Parameters Section -->
                            <template x-if="pathParams.length > 0 || queryParams.length > 0 || headerParams.length > 0">
                                <section class="flex flex-col gap-4">
                                    <!-- Path Parameters -->
                                    <template x-if="pathParams.length > 0">
                                        <div class="rounded-xl border border-slate-200/80 bg-white shadow-xs dark:border-transparent dark:bg-slate-900 overflow-hidden">
                                            <div class="flex items-center gap-1.5 border-b border-slate-200/80 bg-slate-50/70 px-3 py-2 text-sm font-bold uppercase tracking-wider text-slate-900 dark:border-slate-800 dark:bg-slate-800/40 dark:text-white">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/>
                                                </svg>
                                                <span>Parameters</span>
                                            </div>
                                            <div class="text-xs">
                                                <template x-for="p in pathParams" :key="p.name">
                                                    <div class="flex flex-col gap-1 border-b border-slate-200/80 p-3 last:border-b-0 dark:border-slate-800">
                                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                                            <code class="font-mono text-xs font-semibold text-slate-900 dark:text-white" x-text="p.name"></code>
                                                            <div class="flex items-center gap-2">
                                                                <span class="rounded border border-rose-500/30 px-1.5 py-0 text-[10px] font-mono text-rose-600 dark:text-rose-400">required</span>
                                                                <span class="rounded bg-slate-100 px-1.5 py-0 text-[10px] font-mono text-slate-600 dark:bg-slate-800 dark:text-slate-400" x-text="p.schema?.type || 'string'"></span>
                                                            </div>
                                                        </div>
                                                        <template x-if="p.description">
                                                            <p class="text-slate-600 dark:text-slate-400 text-xs leading-relaxed" x-text="p.description"></p>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Query Parameters -->
                                    <template x-if="queryParams.length > 0">
                                        <div class="rounded-xl border border-slate-200/80 bg-white shadow-xs dark:border-transparent dark:bg-slate-900 overflow-hidden">
                                            <div class="flex items-center gap-1.5 border-b border-slate-200/80 bg-slate-50/70 px-3 py-2 text-sm font-bold uppercase tracking-wider text-slate-900 dark:border-slate-800 dark:bg-slate-800/40 dark:text-white">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607Z"/>
                                                </svg>
                                                <span>Query Parameters</span>
                                            </div>
                                            <div class="text-xs">
                                                <template x-for="p in queryParams" :key="p.name">
                                                    <div class="flex flex-col gap-1 border-b border-slate-200/80 p-3 last:border-b-0 dark:border-slate-800">
                                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                                            <code class="font-mono text-xs font-semibold text-slate-900 dark:text-white" x-text="p.name"></code>
                                                            <div class="flex items-center gap-2">
                                                                <span x-show="p.required" class="rounded border border-rose-500/30 px-1.5 py-0 text-[10px] font-mono text-rose-600 dark:text-rose-400">required</span>
                                                                <span x-show="!p.required" class="text-[10px] font-mono text-slate-400">optional</span>
                                                                <span class="rounded bg-slate-100 px-1.5 py-0 text-[10px] font-mono text-slate-600 dark:bg-slate-800 dark:text-slate-400" x-text="p.schema?.type || 'string'"></span>
                                                            </div>
                                                        </div>
                                                        <template x-if="p.description">
                                                            <p class="text-slate-600 dark:text-slate-400 text-xs leading-relaxed" x-text="p.description"></p>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Header Parameters -->
                                    <template x-if="headerParams.length > 0">
                                        <div class="rounded-xl border border-slate-200/80 bg-white shadow-xs dark:border-transparent dark:bg-slate-900 overflow-hidden">
                                            <div class="flex items-center gap-1.5 border-b border-slate-200/80 bg-slate-50/70 px-3 py-2 text-sm font-bold uppercase tracking-wider text-slate-900 dark:border-slate-800 dark:bg-slate-800/40 dark:text-white">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6h.008v.008H6V6z"/>
                                                </svg>
                                                <span>Header Parameters</span>
                                            </div>
                                            <div class="text-xs">
                                                <template x-for="p in headerParams" :key="p.name">
                                                    <div class="flex flex-col gap-1 border-b border-slate-200/80 p-3 last:border-b-0 dark:border-slate-800">
                                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                                            <code class="font-mono text-xs font-semibold text-slate-900 dark:text-white" x-text="p.name"></code>
                                                            <div class="flex items-center gap-2">
                                                                <span x-show="p.required" class="rounded border border-rose-500/30 px-1.5 py-0 text-[10px] font-mono text-rose-600 dark:text-rose-400">required</span>
                                                                <span x-show="!p.required" class="text-[10px] font-mono text-slate-400">optional</span>
                                                                <span class="rounded bg-slate-100 px-1.5 py-0 text-[10px] font-mono text-slate-600 dark:bg-slate-800 dark:text-slate-400" x-text="p.schema?.type || 'string'"></span>
                                                            </div>
                                                        </div>
                                                        <template x-if="p.description">
                                                            <p class="text-slate-600 dark:text-slate-400 text-xs leading-relaxed" x-text="p.description"></p>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </section>
                            </template>

                            <!-- Request Body Section -->
                            <template x-if="requestBodySchema">
                                <section class="rounded-xl border border-slate-200/80 bg-white shadow-xs dark:border-transparent dark:bg-slate-900 overflow-hidden">
                                    <div class="flex items-center justify-between border-b border-slate-200/80 bg-slate-50/70 px-3 py-2.5 dark:border-slate-800 dark:bg-slate-800/40">
                                        <h3 class="flex items-center gap-1.5 text-sm font-bold uppercase tracking-wider text-slate-900 dark:text-white">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                                            </svg>
                                            <span>Request Body</span>
                                        </h3>
                                        <span class="rounded bg-slate-100 px-2 py-0.5 font-mono text-[10px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-400">application/json</span>
                                    </div>
                                    <div class="text-xs">
                                        <template x-for="(prop, name) in (resolvedRequestSchema?.properties || {})" :key="name">
                                            <div class="flex flex-col gap-1.5 border-b border-slate-200/80 p-3.5 last:border-b-0 dark:border-slate-800">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <code class="font-mono text-xs font-semibold text-slate-900 dark:text-white" x-text="name"></code>
                                                    <span x-show="(resolvedRequestSchema.required || []).includes(name)" class="rounded border border-rose-500/30 px-1.5 py-0 text-[10px] font-mono text-rose-600 dark:text-rose-400">required</span>
                                                    <span x-show="!(resolvedRequestSchema.required || []).includes(name)" class="text-[10px] font-mono text-slate-400">optional</span>
                                                    <span class="rounded bg-slate-100 px-1.5 py-0 text-[10px] font-mono text-slate-600 dark:bg-slate-800 dark:text-slate-400" x-text="Array.isArray(prop.type) ? prop.type.join(' · ') : (prop.type || 'any')"></span>
                                                </div>
                                                <template x-if="prop.description">
                                                    <p class="text-slate-600 dark:text-slate-400 text-xs leading-relaxed" x-text="prop.description"></p>
                                                </template>
                                                <template x-if="prop.default !== undefined">
                                                    <div class="font-mono text-[11px] text-slate-400">default: <span class="text-slate-700 dark:text-slate-300" x-text="JSON.stringify(prop.default)"></span></div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </section>
                            </template>

                            <!-- Response Example Card -->
                            <div class="rounded-2xl border border-slate-200/80 bg-white shadow-xs dark:border-transparent dark:bg-slate-900 overflow-hidden">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200/80 bg-slate-50/70 px-3 py-2.5 dark:border-slate-800 dark:bg-slate-800/40">
                                    <span class="flex items-center gap-1.5 text-sm font-bold uppercase tracking-wider text-slate-900 dark:text-white">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3m-9 13.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5"/>
                                        </svg>
                                        <span>Response Example</span>
                                    </span>
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <template x-for="st in availableStatuses" :key="st">
                                            <button
                                                type="button"
                                                @click="selectedStatus = st"
                                                class="rounded-md border px-2 py-0.5 font-mono text-[11px] font-semibold transition-all active:scale-95"
                                                :class="selectedStatus === st
                                                    ? 'border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900 shadow-sm'
                                                    : (st.startsWith('2')
                                                        ? 'border-sky-500/30 text-sky-600 hover:bg-sky-500/10 dark:text-sky-400'
                                                        : 'border-rose-500/30 text-rose-600 hover:bg-rose-500/10 dark:text-rose-400')"
                                                x-text="st"
                                            ></button>
                                        </template>
                                    </div>
                                </div>

                                <template x-if="activeResponse?.description">
                                    <p class="border-b border-slate-200/80 px-3 py-2 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400" x-text="activeResponse.description"></p>
                                </template>

                                <!-- Schema / Raw tab switcher (only shown when a schema table is available) -->
                                <template x-if="resolvedResponseSchema?.properties">
                                    <div class="border-b border-slate-200/80 bg-slate-50/50 px-3 py-2 dark:border-slate-800 dark:bg-slate-800/20">
                                        <div class="flex w-fit items-center gap-1 rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
                                            <button
                                                type="button"
                                                @click="responseViewMode = 'schema'"
                                                class="flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium transition-colors"
                                                :class="responseViewMode === 'schema' ? 'bg-white text-slate-900 shadow-xs dark:bg-slate-900 dark:text-white' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h10M4 18h10"/>
                                                </svg>
                                                <span>Schema</span>
                                            </button>
                                            <button
                                                type="button"
                                                @click="responseViewMode = 'raw'"
                                                class="flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium transition-colors"
                                                :class="responseViewMode === 'raw' ? 'bg-white text-slate-900 shadow-xs dark:bg-slate-900 dark:text-white' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                                                </svg>
                                                <span>Raw</span>
                                            </button>
                                        </div>
                                    </div>
                                </template>

                                <!-- Response Schema Table -->
                                <template x-if="resolvedResponseSchema?.properties && responseViewMode === 'schema'">
                                    <div class="border-b border-slate-200/80 text-xs dark:border-slate-800">
                                        <template x-for="(prop, name) in resolvedResponseSchema.properties" :key="name">
                                            <div class="flex flex-col gap-1.5 border-b border-slate-200/80 p-3 last:border-b-0 dark:border-slate-800">
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <code class="font-mono text-xs font-semibold text-slate-900 dark:text-white" x-text="name"></code>
                                                    <span class="rounded bg-slate-100 px-1.5 py-0 text-[10px] font-mono text-slate-600 dark:bg-slate-800 dark:text-slate-400" x-text="Array.isArray(prop.type) ? prop.type.join(' · ') : (prop.type || 'any')"></span>
                                                </div>
                                                <template x-if="prop.description">
                                                    <p class="text-slate-600 dark:text-slate-400 text-xs leading-relaxed" x-text="prop.description"></p>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <template x-if="responseExample && (!resolvedResponseSchema?.properties || responseViewMode === 'raw')">
                                    <div class="relative">
                                        <button
                                            type="button"
                                            @click="copy(JSON.stringify(responseExample, null, 2), 'example')"
                                            class="absolute right-2 top-2 text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                                            x-text="copied.example ? 'Copied!' : 'Copy'"
                                        ></button>
                                        <pre class="max-h-80 overflow-x-auto rounded-b-2xl border-t border-slate-800 bg-slate-950 p-4 font-mono text-xs text-slate-100"><code class="language-json" x-html="highlight(JSON.stringify(responseExample, null, 2).trim(), 'json')"></code></pre>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Right Column: Developer Workbench (Desktop Sticky) -->
                        <aside class="hidden xl:flex flex-col gap-6 sticky top-20 self-start">
                            <div class="rounded-2xl border border-slate-200/80 bg-white shadow-xs dark:border-transparent dark:bg-slate-900">
                                <!-- Segmented Tab Header -->
                                <div class="flex items-center justify-between rounded-t-2xl border-b border-slate-200/80 bg-slate-50/70 px-3 py-2.5 dark:border-slate-800 dark:bg-slate-800/40">
                                    <div class="flex items-center gap-1 rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
                                        <button
                                            type="button"
                                            @click="activeTab = 'sample'"
                                            class="flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium transition-colors"
                                            :class="activeTab === 'sample' ? 'bg-white text-slate-900 shadow-xs dark:bg-slate-900 dark:text-white' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                                            </svg>
                                            <span>Sample</span>
                                        </button>
                                        <button
                                            type="button"
                                            @click="activeTab = 'tryit'"
                                            class="flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium transition-colors"
                                            :class="activeTab === 'tryit' ? 'bg-white text-slate-900 shadow-xs dark:bg-slate-900 dark:text-white' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M8 5v14l11-7z"/>
                                            </svg>
                                            <span>Try It</span>
                                        </button>
                                    </div>

                                    <!-- Language Dropdown (Sample tab only) -->
                                    <div x-show="activeTab === 'sample'" class="relative" x-data="{ langOpen: false }">
                                        <button
                                            type="button"
                                            @click="langOpen = !langOpen"
                                            class="flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 shadow-xs dark:bg-slate-800 dark:text-slate-300"
                                        >
                                            <span x-text="sampleLanguage"></span>
                                            <svg class="h-3 w-3 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                        <div
                                            x-show="langOpen"
                                            @click.outside="langOpen = false"
                                            x-cloak
                                            class="absolute right-0 mt-1 max-h-72 w-48 overflow-y-auto rounded-xl bg-white p-1 shadow-lg dark:bg-slate-900 ring-1 ring-slate-900/5 dark:ring-white/10 z-30 text-xs"
                                        >
                                            <button type="button" @click="sampleLanguage = 'Shell / cURL'; langOpen = false" class="flex w-full px-2.5 py-1.5 rounded-lg text-left hover:bg-slate-100 dark:hover:bg-slate-800">Shell / cURL</button>
                                            <button type="button" @click="sampleLanguage = 'JavaScript / Fetch'; langOpen = false" class="flex w-full px-2.5 py-1.5 rounded-lg text-left hover:bg-slate-100 dark:hover:bg-slate-800">JavaScript / Fetch</button>
                                            <button type="button" @click="sampleLanguage = 'Node.js / Axios'; langOpen = false" class="flex w-full px-2.5 py-1.5 rounded-lg text-left hover:bg-slate-100 dark:hover:bg-slate-800">Node.js / Axios</button>
                                            <button type="button" @click="sampleLanguage = 'PHP / Guzzle'; langOpen = false" class="flex w-full px-2.5 py-1.5 rounded-lg text-left hover:bg-slate-100 dark:hover:bg-slate-800">PHP / Guzzle</button>
                                            <button type="button" @click="sampleLanguage = 'Python / Requests'; langOpen = false" class="flex w-full px-2.5 py-1.5 rounded-lg text-left hover:bg-slate-100 dark:hover:bg-slate-800">Python / Requests</button>
                                            <button type="button" @click="sampleLanguage = 'Ruby / Net::HTTP'; langOpen = false" class="flex w-full px-2.5 py-1.5 rounded-lg text-left hover:bg-slate-100 dark:hover:bg-slate-800">Ruby / Net::HTTP</button>
                                            <button type="button" @click="sampleLanguage = 'Go / net/http'; langOpen = false" class="flex w-full px-2.5 py-1.5 rounded-lg text-left hover:bg-slate-100 dark:hover:bg-slate-800">Go / net/http</button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Tab Content -->
                                <div class="rounded-b-2xl overflow-hidden p-4">
                                    <!-- Request Sample View -->
                                    <div x-show="activeTab === 'sample'">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-[11px] text-slate-400">Request Code</span>
                                            <button
                                                type="button"
                                                @click="copy(requestCodeSample, 'snippet')"
                                                class="flex items-center gap-1 rounded-md px-2 py-0.5 text-xs text-slate-500 hover:text-slate-900 dark:hover:text-white"
                                            >
                                                <span x-text="copied.snippet ? 'Copied!' : 'Copy'"></span>
                                            </button>
                                        </div>
                                        <pre class="max-h-96 overflow-x-auto rounded-xl bg-slate-900 p-3.5 font-mono text-xs"><code :class="'language-' + requestCodeLanguage" x-html="highlight(requestCodeSample.trim(), requestCodeLanguage)"></code></pre>
                                    </div>

                                    <!-- Try It Console View -->
                                    <div x-show="activeTab === 'tryit'" x-cloak>
                                        @include('geni::partials.tryit-form')
                                    </div>
                                </div>
                            </div>
                        </aside>
                    </div>
                </template>
            </main>
        </div>

        <!-- Mobile Drawer Navigation -->
        <div x-show="isMobileMenuOpen" x-cloak class="fixed inset-0 z-50 flex lg:hidden">
            <div @click="isMobileMenuOpen = false" class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs"></div>
            <div class="relative flex w-80 max-w-full flex-col bg-white dark:bg-slate-900 shadow-2xl">
                <div class="flex items-center justify-between p-4 border-b border-slate-200 dark:border-slate-800">
                    <span class="font-bold text-sm" x-text="doc?.info?.title"></span>
                    <button @click="isMobileMenuOpen = false" class="text-slate-400 hover:text-slate-600">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                @if(isset($availableApis) && count($availableApis) > 1)
                <div x-show="availableApis && availableApis.length > 1" x-cloak class="px-3 pt-3">
                    <div class="relative">
                        <button
                            type="button"
                            @click="isVersionDropdownOpen = !isVersionDropdownOpen"
                            @click.away="isVersionDropdownOpen = false"
                            class="flex w-full items-center justify-between gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-medium text-slate-700 dark:border-slate-800 dark:bg-slate-800/60 dark:text-slate-300"
                            aria-haspopup="listbox"
                            :aria-expanded="isVersionDropdownOpen"
                            aria-label="Select API version"
                        >
                            <span class="truncate" x-text="currentApiItem?.label || 'Select Version'"></span>
                            <svg class="h-3 w-3 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div
                            x-show="isVersionDropdownOpen"
                            x-cloak
                            class="absolute left-0 right-0 top-full mt-1 max-h-64 overflow-y-auto z-30 rounded-lg border border-slate-200 bg-white p-1 shadow-lg dark:border-slate-800 dark:bg-slate-900"
                            role="listbox"
                        >
                            <template x-for="apiItem in availableApis" :key="'mob-api-' + apiItem.key">
                                <button
                                    type="button"
                                    @click="switchApi(apiItem.key); isVersionDropdownOpen = false"
                                    class="flex w-full items-center justify-between rounded-md px-2 py-1 text-xs text-left transition-colors"
                                    :class="apiItem.key === currentApi ? 'bg-sky-500/10 font-semibold text-sky-600 dark:text-sky-400' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                                    role="option"
                                    :aria-selected="apiItem.key === currentApi"
                                >
                                    <span class="truncate" x-text="apiItem.label"></span>
                                    <svg x-show="apiItem.key === currentApi" class="h-3.5 w-3.5 shrink-0 text-sky-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
                @endif

                <!-- Live Search Input -->
                <div class="p-3">
                    <div class="relative flex items-center">
                        <svg class="absolute left-2.5 h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input
                            type="search"
                            placeholder="Search endpoints..."
                            x-model="searchQuery"
                            class="h-8 w-full rounded-lg border border-slate-200 bg-white pl-8 pr-7 text-xs text-slate-900 placeholder:text-slate-400 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100"
                            aria-label="Filter endpoints by path, method, or summary"
                        />
                        <button
                            type="button"
                            x-show="searchQuery"
                            @click="searchQuery = ''"
                            class="absolute right-2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                            aria-label="Clear search query"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Dynamic Navigation -->
                <nav class="flex-1 overflow-y-auto p-3">
                    <template x-for="tag in filteredTags" :key="'mob-' + tag">
                        <div class="mb-3">
                            <div class="px-2 py-1 text-xs font-bold uppercase tracking-wider text-slate-400" x-text="tag"></div>
                            <div class="mt-1 flex flex-col gap-1">
                                <template x-for="ep in endpointsByTag[tag]" :key="'mob-' + ep.method + '-' + ep.path">
                                    <button
                                        type="button"
                                        @click="selectEndpoint(ep); isMobileMenuOpen = false"
                                        class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs text-left"
                                        :class="isSelected(ep) ? 'bg-slate-100 dark:bg-slate-800 font-semibold text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400'"
                                    >
                                        <span class="rounded px-1.5 py-0.5 font-mono text-[9px] font-bold uppercase" :class="getMethodBadgeClass(ep.method)" x-text="ep.method"></span>
                                        <span class="truncate" x-text="ep.operation.summary || ep.path"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                </nav>

                <!-- Sidebar Footer -->
                <div class="p-3 flex flex-col gap-2 text-center text-[11px] text-slate-400 border-t border-slate-200 dark:border-slate-800">
                    @include('geni::partials.promo-card')
                    <span>Built with ❤️ by Masitings</span>
                    @if($packageVersion)
                        <span class="block font-mono text-[10px] text-slate-500">Geni v{{ $packageVersion }}</span>
                    @endif
                </div>
            </div>
        </div>

        <!-- Mobile Try It Slide-Over Panel -->
        <div x-show="isMobileTryItOpen" x-cloak class="fixed inset-0 z-50 flex justify-end xl:hidden">
            <div @click="isMobileTryItOpen = false" class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs"></div>
            <div class="relative flex w-full max-w-md flex-col bg-white dark:bg-slate-900 shadow-2xl p-4 overflow-y-auto">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800 mb-4">
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-sm">Try It Console</span>
                    </div>
                    <button @click="isMobileTryItOpen = false" class="text-slate-400 hover:text-slate-600">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                @include('geni::partials.tryit-form')
            </div>
        </div>
    </div>

    <!-- Alpine Component Definition -->
    <script>
        function geniDocs({ specUrl, currentApi = 'default', availableApis = [] }) {
            return {
                specUrl: specUrl,
                currentApi: currentApi,
                availableApis: availableApis,
                isVersionDropdownOpen: false,
                loading: true,
                error: null,
                doc: null,
                endpoints: [],
                selectedEndpoint: null,
                searchQuery: '',
                selectedStatus: '200',
                activeTab: 'sample',
                sampleLanguage: 'Shell / cURL',
                responseViewMode: 'schema',
                openGroups: {},
                paramValues: {},
                bodyText: '',
                tokens: {},
                showTokens: {},
                sending: false,
                result: null,
                isDark: document.documentElement.classList.contains('dark'),
                isMobileMenuOpen: false,
                isMobileTryItOpen: false,
                sidebarCollapsed: localStorage.getItem('geni_sidebar_collapsed') === '1',
                sidebarAutoCollapse: localStorage.getItem('geni_sidebar_autocollapse') === '1',
                copied: { path: false, snippet: false, response: false, example: false },

                async init() {
                    try {
                        const res = await fetch(this.specUrl);
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        this.doc = await res.json();
                        this.normalizeEndpoints();
                        this.initTheme();
                        this.loading = false;
                    } catch (e) {
                        this.error = e.message || String(e);
                        this.loading = false;
                    }
                },

                initTheme() {
                    this.isDark = document.documentElement.classList.contains('dark');
                },

                async switchApi(apiKey) {
                    const target = this.availableApis.find(a => a.key === apiKey);
                    if (!target) return;

                    this.currentApi = apiKey;
                    this.specUrl = target.json_url;
                    this.isVersionDropdownOpen = false;
                    this.loading = true;
                    this.selectedEndpoint = null;

                    await this.init();

                    if (window.history && window.history.pushState) {
                        window.history.pushState({}, '', target.ui_url);
                    }
                },

                get currentApiItem() {
                    return this.availableApis.find(a => a.key === this.currentApi) || this.availableApis[0];
                },

                toggleSidebar() {
                    this.sidebarCollapsed = !this.sidebarCollapsed;
                    localStorage.setItem('geni_sidebar_collapsed', this.sidebarCollapsed ? '1' : '0');
                },

                toggleAutoCollapse() {
                    this.sidebarAutoCollapse = !this.sidebarAutoCollapse;
                    localStorage.setItem('geni_sidebar_autocollapse', this.sidebarAutoCollapse ? '1' : '0');
                },

                toggleTheme() {
                    this.isDark = !this.isDark;
                    if (this.isDark) {
                        document.documentElement.classList.add('dark');
                        localStorage.setItem('geni_theme', 'dark');
                    } else {
                        document.documentElement.classList.remove('dark');
                        localStorage.setItem('geni_theme', 'light');
                    }
                },

                normalizeEndpoints() {
                    const list = [];
                    for (const [path, methods] of Object.entries(this.doc.paths || {})) {
                        for (const [method, operation] of Object.entries(methods)) {
                            list.push({ path, method: method.toLowerCase(), operation });
                        }
                    }
                    this.endpoints = list;

                    // All groups start collapsed; only the selected endpoint's group opens.
                    this.openGroups = {};

                    // Select from URL hash or first endpoint
                    const hash = window.location.hash.slice(1);
                    if (hash) {
                        const found = list.find(e => `${e.method}-${e.path}` === hash);
                        if (found) {
                            this.selectEndpoint(found);
                            return;
                        }
                    }
                    if (list.length > 0) {
                        this.selectEndpoint(list[0]);
                    }
                },

                selectEndpoint(ep) {
                    this.selectedEndpoint = ep;
                    window.location.hash = `${ep.method}-${ep.path}`;

                    if (this.sidebarAutoCollapse) {
                        this.sidebarCollapsed = true;
                    }

                    // Accordion: only the group containing the selected endpoint stays open.
                    const tag = ep.operation.tags?.[0] || 'Default';
                    this.openGroups = { [tag]: true };
                    this.paramValues = {};
                    this.result = null;

                    // Set default status
                    const statuses = Object.keys(ep.operation.responses || {});
                    this.selectedStatus = statuses[0] || '200';
                    this.responseViewMode = 'schema';

                    // Load pre-populated request body
                    const bodySchema = this.requestBodySchema;
                    this.bodyText = bodySchema ? JSON.stringify(this.buildExample(bodySchema, this.doc), null, 2) : '';

                    // Load tokens from localStorage
                    const activeSec = this.activeSecuritySchemes;
                    const loadedTokens = {};
                    for (const s of activeSec) {
                        loadedTokens[s.name] = localStorage.getItem('geni_token_' + s.name) || '';
                    }
                    this.tokens = loadedTokens;
                },

                isSelected(ep) {
                    return this.selectedEndpoint &&
                        this.selectedEndpoint.path === ep.path &&
                        this.selectedEndpoint.method === ep.method;
                },

                toggleGroup(tag) {
                    const wasOpen = !!this.openGroups[tag];
                    this.openGroups = wasOpen ? {} : { [tag]: true };
                },

                get filteredEndpoints() {
                    if (!this.searchQuery.trim()) return this.endpoints;
                    const q = this.searchQuery.toLowerCase();
                    return this.endpoints.filter(ep => {
                        return ep.path.toLowerCase().includes(q) ||
                            ep.method.toLowerCase().includes(q) ||
                            (ep.operation.summary || '').toLowerCase().includes(q) ||
                            (ep.operation.tags || []).some(t => t.toLowerCase().includes(q));
                    });
                },

                get endpointsByTag() {
                    const map = {};
                    for (const ep of this.filteredEndpoints) {
                        const tag = ep.operation.tags?.[0] || 'Default';
                        if (!map[tag]) map[tag] = [];
                        map[tag].push(ep);
                    }
                    return map;
                },

                get filteredTags() {
                    return Object.keys(this.endpointsByTag);
                },

                get pathParams() {
                    return (this.selectedEndpoint?.operation?.parameters || []).filter(p => p.in === 'path');
                },

                get queryParams() {
                    return (this.selectedEndpoint?.operation?.parameters || []).filter(p => p.in === 'query');
                },

                get headerParams() {
                    return (this.selectedEndpoint?.operation?.parameters || []).filter(p => p.in === 'header');
                },

                get requestBodySchema() {
                    return this.selectedEndpoint?.operation?.requestBody?.content?.['application/json']?.schema;
                },

                get resolvedRequestSchema() {
                    return this.resolveSchema(this.requestBodySchema, this.doc);
                },

                get availableStatuses() {
                    return Object.keys(this.selectedEndpoint?.operation?.responses || {});
                },

                get activeResponse() {
                    return this.selectedEndpoint?.operation?.responses?.[this.selectedStatus];
                },

                get activeResponseSchema() {
                    return this.activeResponse?.content?.['application/json']?.schema;
                },

                get resolvedResponseSchema() {
                    return this.resolveSchema(this.activeResponseSchema, this.doc);
                },

                get responseExample() {
                    return this.activeResponseSchema ? this.buildExample(this.activeResponseSchema, this.doc) : null;
                },

                get activeSecuritySchemes() {
                    if (!this.selectedEndpoint || !this.doc) return [];
                    const list = [];
                    for (const req of (this.selectedEndpoint.operation.security || [])) {
                        for (const name of Object.keys(req)) {
                            list.push({
                                name: name,
                                scheme: (this.doc.components?.securitySchemes?.[name] || {})
                            });
                        }
                    }
                    return list;
                },

                get requestCodeLanguage() {
                    if (this.sampleLanguage === 'JavaScript / Fetch') return 'javascript';
                    if (this.sampleLanguage === 'PHP / Guzzle') return 'php';
                    if (this.sampleLanguage === 'Python / Requests') return 'python';
                    if (this.sampleLanguage === 'Node.js / Axios') return 'javascript';
                    if (this.sampleLanguage === 'Ruby / Net::HTTP') return 'ruby';
                    if (this.sampleLanguage === 'Go / net/http') return 'go';
                    return 'bash';
                },

                escapeHtml(str) {
                    const div = document.createElement('div');
                    div.textContent = str;
                    return div.innerHTML;
                },

                highlight(code, lang) {
                    if (!code) return '';
                    const grammar = window.Prism?.languages?.[lang];
                    if (!grammar) return this.escapeHtml(code);
                    return window.Prism.highlight(code, grammar, lang);
                },

                get requestCodeSample() {
                    if (!this.selectedEndpoint || !this.doc) return '';
                    const ep = this.selectedEndpoint;
                    const url = window.location.origin + ep.path;
                    const sample = this.requestBodySchema ? this.buildExample(this.requestBodySchema, this.doc) : null;

                    if (this.sampleLanguage === 'JavaScript / Fetch') {
                        const lines = ["fetch('" + url + "', {", "  method: '" + ep.method.toUpperCase() + "',"];
                        if (sample) {
                            lines.push("  headers: { 'Content-Type': 'application/json' },");
                            lines.push("  body: JSON.stringify(" + JSON.stringify(sample, null, 2) + "),");
                        }
                        lines.push("});");
                        return lines.join('\n');
                    }

                    if (this.sampleLanguage === 'PHP / Guzzle') {
                        const lines = ["$client = new \\GuzzleHttp\\Client();", "$client->request('" + ep.method.toUpperCase() + "', '" + url + "', ["];
                        if (sample) {
                            lines.push("    'json' => " + JSON.stringify(sample, null, 4) + ",");
                        }
                        lines.push("]);");
                        return lines.join('\n');
                    }

                    if (this.sampleLanguage === 'Python / Requests') {
                        const lines = ["import requests", "", "response = requests.request("];
                        lines.push("    '" + ep.method.toUpperCase() + "',");
                        lines.push("    '" + url + "',");
                        if (sample) lines.push("    json=" + JSON.stringify(sample, null, 4).replace(/"/g, "'") + ",");
                        lines.push(")");
                        lines.push("print(response.json())");
                        return lines.join('\n');
                    }

                    if (this.sampleLanguage === 'Node.js / Axios') {
                        const lines = ["const axios = require('axios');", "", "axios({", "  method: '" + ep.method.toLowerCase() + "',", "  url: '" + url + "',"];
                        if (sample) lines.push("  data: " + JSON.stringify(sample, null, 2) + ",");
                        lines.push("}).then((response) => console.log(response.data));");
                        return lines.join('\n');
                    }

                    if (this.sampleLanguage === 'Ruby / Net::HTTP') {
                        const lines = ["require 'net/http'", "require 'uri'", "require 'json'", "", "uri = URI('" + url + "')", "req = Net::HTTP::" + (ep.method.charAt(0) + ep.method.slice(1).toLowerCase()) + ".new(uri)"];
                        if (sample) {
                            lines.push("req['Content-Type'] = 'application/json'");
                            lines.push("req.body = " + JSON.stringify(JSON.stringify(sample)));
                        }
                        lines.push("res = Net::HTTP.start(uri.hostname, uri.port, use_ssl: uri.scheme == 'https') { |http| http.request(req) }");
                        lines.push("puts res.body");
                        return lines.join('\n');
                    }

                    if (this.sampleLanguage === 'Go / net/http') {
                        const lines = ['package main', '', 'import (', '\t"fmt"', '\t"net/http"'];
                        if (sample) lines.push('\t"strings"');
                        lines.push(')', '', 'func main() {');
                        if (sample) {
                            lines.push('\tbody := strings.NewReader(`' + JSON.stringify(sample) + '`)');
                            lines.push('\treq, _ := http.NewRequest("' + ep.method.toUpperCase() + '", "' + url + '", body)');
                            lines.push('\treq.Header.Set("Content-Type", "application/json")');
                        } else {
                            lines.push('\treq, _ := http.NewRequest("' + ep.method.toUpperCase() + '", "' + url + '", nil)');
                        }
                        lines.push('\tres, _ := http.DefaultClient.Do(req)');
                        lines.push('\tfmt.Println(res.Status)');
                        lines.push('}');
                        return lines.join('\n');
                    }

                    // cURL
                    const lines = ["curl --request " + ep.method.toUpperCase() + " \\", "  --url '" + url + "' \\"];
                    if (sample) {
                        lines.push("  --header 'Content-Type: application/json' \\");
                        lines.push("  --data '" + JSON.stringify(sample) + "'");
                    }
                    return lines.join('\n');
                },

                async sendRequest() {
                    this.sending = true;
                    this.result = null;
                    const start = performance.now();
                    try {
                        let url = this.selectedEndpoint.path;
                        for (const p of this.pathParams) {
                            url = url.replace('{' + p.name + '}', encodeURIComponent(this.paramValues[p.name] || ''));
                        }
                        const query = this.queryParams
                            .filter(p => this.paramValues[p.name])
                            .map(p => encodeURIComponent(p.name) + '=' + encodeURIComponent(this.paramValues[p.name]))
                            .join('&');
                        if (query) url += '?' + query;

                        const headers = { 'Accept': 'application/json' };
                        for (const p of this.headerParams) {
                            if (this.paramValues[p.name]) headers[p.name] = this.paramValues[p.name];
                        }
                        for (const s of this.activeSecuritySchemes) {
                            const token = this.tokens[s.name];
                            if (token) {
                                if (s.scheme.type === 'apiKey' && s.scheme.name) {
                                    headers[s.scheme.name] = token;
                                } else {
                                    headers['Authorization'] = 'Bearer ' + token;
                                }
                            }
                        }
                        if (this.requestBodySchema) headers['Content-Type'] = 'application/json';

                        const res = await fetch(window.location.origin + url, {
                            method: this.selectedEndpoint.method.toUpperCase(),
                            headers: headers,
                            body: this.requestBodySchema && this.bodyText ? this.bodyText : undefined
                        });

                        const durationMs = Math.round(performance.now() - start);
                        const text = await res.text();
                        let parsedBody = text;
                        try {
                            parsedBody = JSON.stringify(JSON.parse(text), null, 2);
                        } catch (e) {}

                        this.result = {
                            status: res.status,
                            statusText: res.statusText,
                            durationMs: durationMs,
                            body: parsedBody
                        };
                    } catch (err) {
                        const durationMs = Math.round(performance.now() - start);
                        this.result = {
                            status: 0,
                            statusText: 'Client Error',
                            durationMs: durationMs,
                            body: String(err)
                        };
                    } finally {
                        this.sending = false;
                    }
                },

                copy(text, key) {
                    navigator.clipboard.writeText(text);
                    this.copied[key] = true;
                    setTimeout(() => { this.copied[key] = false; }, 1500);
                },

                resolveSchema(schema, doc) {
                    if (!schema) return schema;
                    if (schema.$ref) {
                        const name = schema.$ref.split('/').pop() || '';
                        return doc?.components?.schemas?.[name] || schema;
                    }
                    return schema;
                },

                buildExample(schema, doc, depth = 0) {
                    if (!schema || depth > 4) return null;
                    const resolved = this.resolveSchema(schema, doc);
                    if (!resolved) return null;

                    if (resolved.type === 'array' && resolved.items) {
                        return [this.buildExample(resolved.items, doc, depth + 1)];
                    }
                    if (resolved.properties) {
                        const out = {};
                        for (const [name, field] of Object.entries(resolved.properties)) {
                            out[name] = field.example !== undefined ? field.example : this.buildExample(field, doc, depth + 1);
                        }
                        return out;
                    }
                    if (resolved.example !== undefined) return resolved.example;
                    if (resolved.enum && resolved.enum.length) return resolved.enum[0];

                    const type = Array.isArray(resolved.type) ? resolved.type[0] : resolved.type;
                    if (type === 'integer' || type === 'number') return 0;
                    if (type === 'boolean') return true;
                    return 'string';
                },

                getMethodBadgeClass(method) {
                    switch ((method || '').toLowerCase()) {
                        case 'get':
                            return 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400';
                        case 'post':
                            return 'border-blue-500/30 bg-blue-500/10 text-blue-600 dark:text-blue-400';
                        case 'put':
                        case 'patch':
                            return 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400';
                        case 'delete':
                            return 'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400';
                        default:
                            return 'border-slate-500/30 bg-slate-500/10 text-slate-600 dark:text-slate-400';
                    }
                },

                getMethodTextClass(method) {
                    switch ((method || '').toLowerCase()) {
                        case 'get':
                            return 'text-sky-600 dark:text-sky-400';
                        case 'post':
                            return 'text-blue-600 dark:text-blue-400';
                        case 'put':
                        case 'patch':
                            return 'text-amber-600 dark:text-amber-400';
                        case 'delete':
                            return 'text-rose-600 dark:text-rose-400';
                        default:
                            return 'text-slate-600 dark:text-slate-400';
                    }
                }
            }
        }
    </script>
</body>
</html>
