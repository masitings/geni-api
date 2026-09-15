<?php

declare(strict_types=1);

namespace Geni\Laravel\Commands;

use Geni\Inference\DocumentAssembler;
use Geni\Laravel\Commands\Concerns\BuildsInfoOptions;
use Geni\Laravel\Commands\Concerns\BuildsSecurityConfig;
use Geni\Laravel\RouteDiscoverer;
use Geni\SchemaReader\SchemaReader;
use Illuminate\Console\Command;

final class CheckCommand extends Command
{
    use BuildsInfoOptions;
    use BuildsSecurityConfig;

    protected $signature = 'geni:check
                            {--api=default : The API configuration name to check}
                            {--path=openapi.json : Path to the committed specification file}';

    protected $description = 'Check that the committed OpenAPI specification matches current application routes';

    public function handle(): int
    {
        $apiName = $this->option('api') ?: 'default';
        $path = $this->option('path') ?: 'openapi.json';
        $resolvedPath = file_exists($path) || str_starts_with($path, '/') ? $path : base_path($path);
        $filePath = file_exists($resolvedPath) ? realpath($resolvedPath) : $resolvedPath;

        if (! file_exists((string) $filePath)) {
            $this->error(sprintf('Committed specification file not found at: %s', $path));

            return self::FAILURE;
        }

        $committedContent = file_get_contents((string) $filePath);
        $committedData = json_decode((string) $committedContent, true);

        if ($committedData === null) {
            $this->error(sprintf('Failed to parse committed specification JSON at: %s', $path));

            return self::FAILURE;
        }

        // Generate fresh document
        $routes = RouteDiscoverer::discover();
        $plainRoutes = array_map(function ($route) {
            return [
                'uri' => $route->uri,
                'methods' => $route->methods,
                'action' => $route->action,
                'middleware' => $route->middleware,
                'name' => $route->name,
            ];
        }, $routes);

        $migrationPaths = config('geni.migration_paths', [database_path('migrations')]);
        $dbSchema = null;
        if (class_exists(SchemaReader::class)) {
            $schemaReader = new SchemaReader;
            $dbSchema = $schemaReader->read($migrationPaths);
        }

        $assembler = new DocumentAssembler;
        $document = $assembler->assemble(
            routes: $plainRoutes,
            infoOptions: $this->buildInfoOptions(),
            dbSchema: $dbSchema,
            schemaOverrides: config('geni.schema_overrides', []),
            securityConfig: $this->buildSecurityConfig(),
            apiName: $apiName
        );

        $generatedJson = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $generatedData = json_decode((string) $generatedJson, true);

        // Structural comparison (FR-003 / NFR-004)
        if ($this->areStructurallyEqual($generatedData, $committedData)) {
            $this->info(sprintf('OpenAPI specification at %s is up to date.', $path));

            return self::SUCCESS;
        }

        $this->error(sprintf('OpenAPI specification drift detected for %s!', $path));

        $this->printStructuralDiff($generatedData, $committedData);

        return self::FAILURE;
    }

    private function areStructurallyEqual(mixed $a, mixed $b): bool
    {
        return $this->canonicalize($a) === $this->canonicalize($b);
    }

    /**
     * Recursively sort associative array (JSON object) keys so comparison is
     * insensitive to key-emission order, which carries no semantic meaning in
     * JSON and can otherwise differ across environments (e.g. reflection or
     * filesystem iteration order) even when the document content is identical.
     * List arrays (JSON arrays) are left in their original order since
     * sequence there is semantically significant.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);

        $canonicalized = array_map(fn ($item) => $this->canonicalize($item), $value);

        if (! $isList) {
            ksort($canonicalized);
        }

        return $canonicalized;
    }

    private function printStructuralDiff(array $generated, array $committed): void
    {
        $genPaths = array_keys($generated['paths'] ?? []);
        $comPaths = array_keys($committed['paths'] ?? []);

        $added = array_diff($genPaths, $comPaths);
        $removed = array_diff($comPaths, $genPaths);

        if (! empty($added)) {
            $this->line('<fg=green>+ Added paths:</>');
            foreach ($added as $p) {
                $this->line("  + {$p}");
            }
        }

        if (! empty($removed)) {
            $this->line('<fg=red>- Removed paths:</>');
            foreach ($removed as $p) {
                $this->line("  - {$p}");
            }
        }

        // Check common paths for modifications
        $common = array_intersect($genPaths, $comPaths);
        foreach ($common as $p) {
            if ($generated['paths'][$p] !== $committed['paths'][$p]) {
                $this->line("<fg=yellow>~ Modified path: {$p}</>");
            }
        }
    }
}
