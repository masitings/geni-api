@if(config('geni.promo.enabled') && config('geni.promo.title'))
    @php
        $promoBg = config('geni.promo.bg_color', '#022c22');
        $promoUrl = config('geni.promo.button_url');
        $promoUrl = $promoUrl && preg_match('#^https?://#i', $promoUrl) ? $promoUrl : null;
        $promoTag = $promoUrl ? 'a' : 'div';
    @endphp
    <{{ $promoTag }}
        @if($promoUrl)
            href="{{ $promoUrl }}"
            target="_blank"
            rel="noreferrer"
        @endif
        class="flex w-full items-center gap-2.5 rounded-xl p-2.5 text-left transition-opacity hover:opacity-90"
        style="background-color: {{ $promoBg }};"
    >
        <div class="flex size-7 shrink-0 items-center justify-center text-white">
            @if(config('geni.promo.icon_dark') || config('geni.promo.icon'))
                <img src="{{ config('geni.promo.icon_dark') ?: config('geni.promo.icon') }}" alt="" class="size-7 object-contain">
            @else
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            @endif
        </div>
        <div class="min-w-0">
            <p class="truncate text-xs font-bold text-white">{{ config('geni.promo.title') }}</p>
            @if(config('geni.promo.subtitle'))
                <p class="truncate text-[11px] text-slate-300">{{ config('geni.promo.subtitle') }}</p>
            @endif
        </div>
    </{{ $promoTag }}>
@endif
