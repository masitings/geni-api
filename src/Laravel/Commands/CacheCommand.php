<?php

declare(strict_types=1);

namespace Geni\Laravel\Commands;

use Geni\Laravel\Commands\Concerns\ResolvesApiOption;
use Geni\Laravel\DocumentGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class CacheCommand extends Command
{
    use ResolvesApiOption;

    protected $signature = 'geni:cache
                            {--api=default : The API configuration name to cache}';

    protected $description = 'Pre-generate and cache the OpenAPI documentation specification';

    public function handle(): int
    {
        $apiName = $this->resolveApiOption();
        if ($apiName === null) {
            return self::FAILURE;
        }

        $document = app(DocumentGenerator::class)->generate($apiName);

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $cacheKey = DocumentGenerator::cacheKey($apiName);
        $cacheStore = config('geni.cache.store');

        Cache::store($cacheStore)->put($cacheKey, $json);

        $this->info(sprintf('OpenAPI specification for "%s" successfully cached under key "%s".', $apiName, $cacheKey));

        return self::SUCCESS;
    }
}
