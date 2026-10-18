<?php

declare(strict_types=1);

namespace Geni\Laravel\Commands;

use Geni\Laravel\Commands\Concerns\ResolvesApiOption;
use Geni\Laravel\DocumentGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class ClearCommand extends Command
{
    use ResolvesApiOption;

    protected $signature = 'geni:clear
                            {--api=default : The API configuration name to clear cache for}';

    protected $description = 'Clear the cached OpenAPI documentation specification';

    public function handle(): int
    {
        $apiName = $this->resolveApiOption();
        if ($apiName === null) {
            return self::FAILURE;
        }

        $cacheKey = DocumentGenerator::cacheKey($apiName);
        $cacheStore = config('geni.cache.store');

        Cache::store($cacheStore)->forget($cacheKey);

        $this->info(sprintf('Cached OpenAPI specification for "%s" cleared.', $apiName));

        return self::SUCCESS;
    }
}
