<div class="flex flex-col gap-4 text-xs">
    <!-- Security Token Inputs -->
    <template x-if="activeSecuritySchemes.length > 0">
        <div class="flex flex-col gap-2 rounded-xl bg-slate-100/70 p-3 dark:bg-slate-800/40">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                <span>Authentication</span>
            </span>
            <template x-for="item in activeSecuritySchemes" :key="item.name">
                <div class="flex flex-col gap-1">
                    <label :for="'token-' + item.name" class="font-medium text-slate-700 dark:text-slate-300" x-text="item.name + ' (' + (item.scheme.type === 'apiKey' ? 'API Key' : 'Bearer Token') + ')'"></label>
                    <div class="relative flex items-center">
                        <input
                            :id="'token-' + item.name"
                            :type="showTokens[item.name] ? 'text' : 'password'"
                            placeholder="Enter token..."
                            x-model="tokens[item.name]"
                            @input="localStorage.setItem('geni_token_' + item.name, $event.target.value)"
                            class="h-8 w-full rounded-lg border border-slate-200 bg-white pr-8 pl-2.5 text-xs dark:border-slate-800 dark:bg-slate-900"
                        />
                        <button
                            type="button"
                            @click="showTokens[item.name] = !showTokens[item.name]"
                            class="absolute right-2 text-slate-400 hover:text-slate-600"
                        >
                            <svg x-show="!showTokens[item.name]" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            <svg x-show="showTokens[item.name]" x-cloak class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </template>

    <!-- Path Params Input -->
    <template x-if="pathParams.length > 0">
        <div class="flex flex-col gap-2">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Path Parameters</span>
            <template x-for="p in pathParams" :key="p.name">
                <div class="flex flex-col gap-1">
                    <label :for="'try-path-' + p.name" class="text-xs text-slate-700 dark:text-slate-300" x-text="p.name + (p.required ? ' *' : '')"></label>
                    <input
                        :id="'try-path-' + p.name"
                        type="text"
                        x-model="paramValues[p.name]"
                        :placeholder="'value for ' + p.name"
                        class="h-8 rounded-lg border border-slate-200 bg-white px-2.5 text-xs dark:border-slate-800 dark:bg-slate-900"
                    />
                </div>
            </template>
        </div>
    </template>

    <!-- Query Params Input -->
    <template x-if="queryParams.length > 0">
        <div class="flex flex-col gap-2">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Query Parameters</span>
            <template x-for="p in queryParams" :key="p.name">
                <div class="flex flex-col gap-1">
                    <label :for="'try-query-' + p.name" class="text-xs text-slate-700 dark:text-slate-300" x-text="p.name + (p.required ? ' *' : '')"></label>
                    <input
                        :id="'try-query-' + p.name"
                        type="text"
                        x-model="paramValues[p.name]"
                        :placeholder="'value for ' + p.name"
                        class="h-8 rounded-lg border border-slate-200 bg-white px-2.5 text-xs dark:border-slate-800 dark:bg-slate-900"
                    />
                </div>
            </template>
        </div>
    </template>

    <!-- Header Params Input -->
    <template x-if="headerParams.length > 0">
        <div class="flex flex-col gap-2">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Header Parameters</span>
            <template x-for="p in headerParams" :key="p.name">
                <div class="flex flex-col gap-1">
                    <label :for="'try-header-' + p.name" class="text-xs text-slate-700 dark:text-slate-300" x-text="p.name + (p.required ? ' *' : '')"></label>
                    <input
                        :id="'try-header-' + p.name"
                        type="text"
                        x-model="paramValues[p.name]"
                        :placeholder="'value for ' + p.name"
                        class="h-8 rounded-lg border border-slate-200 bg-white px-2.5 text-xs dark:border-slate-800 dark:bg-slate-900"
                    />
                </div>
            </template>
        </div>
    </template>

    <!-- Request Body Editor (highlighted overlay) -->
    <template x-if="requestBodySchema">
        <div class="flex flex-col gap-1">
            <label for="try-body-textarea" class="text-xs font-semibold uppercase tracking-wider text-slate-400">Request Body (JSON)</label>
            <div class="relative rounded-xl bg-slate-950">
                <pre
                    x-ref="bodyHighlight"
                    aria-hidden="true"
                    class="pointer-events-none m-0 h-32 overflow-auto whitespace-pre-wrap break-words p-2.5 font-mono text-xs leading-normal"
                ><code class="language-json" x-html="highlight(bodyText, 'json') + '\n'"></code></pre>
                <textarea
                    id="try-body-textarea"
                    x-model="bodyText"
                    @scroll="$refs.bodyHighlight.scrollTop = $event.target.scrollTop; $refs.bodyHighlight.scrollLeft = $event.target.scrollLeft"
                    spellcheck="false"
                    class="absolute inset-0 h-32 w-full resize-none overflow-auto whitespace-pre-wrap break-words border-0 bg-transparent p-2.5 font-mono text-xs leading-normal text-transparent caret-white outline-none"
                ></textarea>
            </div>
        </div>
    </template>

    <!-- Send Button -->
    <button
        type="button"
        @click="sendRequest()"
        :disabled="sending"
        class="flex h-9 w-full items-center justify-center gap-1.5 rounded-xl bg-sky-600 font-medium text-white shadow-xs hover:bg-sky-500 active:scale-98 transition-transform disabled:opacity-50"
    >
        <svg x-show="sending" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
            <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path>
        </svg>
        <svg x-show="!sending" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
        </svg>
        <span x-text="sending ? 'Sending...' : 'Send Request'"></span>
    </button>

    <!-- Result Viewer -->
    <template x-if="result">
        <div class="flex flex-col gap-2 rounded-xl bg-slate-100/70 p-3 dark:bg-slate-900/60">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-slate-500">Response</span>
                    <span
                        class="rounded-md border px-2 py-0.5 font-mono text-xs font-bold"
                        :class="result.status >= 200 && result.status < 300 ? 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400' : 'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400'"
                        x-text="result.status + ' ' + (result.statusText || '')"
                    ></span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="font-mono text-[11px] text-slate-400" x-text="result.durationMs + ' ms'"></span>
                    <button
                        type="button"
                        @click="copy(result.body, 'response')"
                        class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                        x-text="copied.response ? 'Copied!' : 'Copy'"
                    ></button>
                </div>
            </div>
            <pre class="max-h-72 overflow-x-auto rounded-lg border border-slate-800 bg-slate-950 p-2.5 font-mono text-xs text-slate-100"><code class="language-json" x-html="highlight(result.body, 'json')"></code></pre>
        </div>
    </template>
</div>
