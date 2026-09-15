<?php

declare(strict_types=1);

namespace Geni\Laravel\Commands;

use Geni\Inference\DocumentAssembler;
use Geni\Laravel\Commands\Concerns\BuildsInfoOptions;
use Geni\Laravel\Commands\Concerns\BuildsSecurityConfig;
use Geni\Laravel\RouteDiscoverer;
use Geni\SchemaReader\SchemaReader;
use Illuminate\Console\Command;

final class AnalyzeCommand extends Command
{
    use BuildsInfoOptions;
    use BuildsSecurityConfig;

    protected $signature = 'geni:analyze
                            {--api=default : The API configuration name to analyze}
                            {--json : Output diagnostics as JSON}';

    protected $description = 'List every endpoint where inference failed or fell back to heuristics';

    public function handle(): int
    {
        $apiName = $this->option('api') ?: 'default';
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
        $assembler->assemble(
            routes: $plainRoutes,
            infoOptions: $this->buildInfoOptions(),
            dbSchema: $dbSchema,
            schemaOverrides: config('geni.schema_overrides', []),
            securityConfig: $this->buildSecurityConfig(),
            apiName: $apiName
        );

        $diagnostics = $assembler->recordedDiagnostics;

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (empty($diagnostics)) {
            $this->info('No unresolved inference diagnostics found. All endpoints inferred cleanly!');

            return self::SUCCESS;
        }

        $this->warn(sprintf('Found %d unresolved construct(s):', count($diagnostics)));

        $rows = array_map(function ($d) {
            return [
                'Operation' => $d['operation'],
                'File' => basename($d['file']).':'.$d['line'],
                'Reason' => $d['reason'],
            ];
        }, $diagnostics);

        $this->table(['Operation', 'File', 'Reason'], $rows);

        return self::SUCCESS;
    }
}
