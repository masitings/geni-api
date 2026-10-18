<?php

declare(strict_types=1);

namespace Geni\Laravel\Commands;

use Geni\Laravel\Commands\Concerns\ResolvesApiOption;
use Geni\Laravel\DocumentGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class ExportCommand extends Command
{
    use ResolvesApiOption;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geni:export
                            {--path= : The destination file path for the OpenAPI document}
                            {--api=default : The API configuration name to export}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export the generated OpenAPI specification to a file';

    public function handle(): int
    {
        $apiName = $this->resolveApiOption();
        if ($apiName === null) {
            return self::FAILURE;
        }

        $path = $this->option('path') ?: 'openapi.json';

        $cacheEnabled = config('geni.cache.enabled', false);
        $cacheKey = DocumentGenerator::cacheKey($apiName);
        $cacheStore = config('geni.cache.store');

        $json = null;
        if ($cacheEnabled && Cache::store($cacheStore)->has($cacheKey)) {
            $json = Cache::store($cacheStore)->get($cacheKey);
        }

        if ($json === null) {
            $document = app(DocumentGenerator::class)->generate($apiName);

            $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode OpenAPI document to JSON.');

                return self::FAILURE;
            }

            if ($cacheEnabled) {
                Cache::store($cacheStore)->put($cacheKey, $json);
            }
        }

        $destination = file_exists($path) || str_starts_with($path, '/') ? $path : base_path($path);
        $directory = dirname($destination);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($destination, $json);

        $this->info(sprintf('OpenAPI document successfully exported to %s', $path));

        return self::SUCCESS;
    }
}
