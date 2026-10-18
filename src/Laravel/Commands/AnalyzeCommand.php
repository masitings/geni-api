<?php

declare(strict_types=1);

namespace Geni\Laravel\Commands;

use Geni\Laravel\Commands\Concerns\ResolvesApiOption;
use Geni\Laravel\DocumentGenerator;
use Illuminate\Console\Command;

final class AnalyzeCommand extends Command
{
    use ResolvesApiOption;

    protected $signature = 'geni:analyze
                            {--api=default : The API configuration name to analyze}
                            {--json : Output diagnostics as JSON}';

    protected $description = 'List every endpoint where inference failed or fell back to heuristics';

    public function handle(): int
    {
        $apiName = $this->resolveApiOption();
        if ($apiName === null) {
            return self::FAILURE;
        }

        $generator = app(DocumentGenerator::class);
        $assembler = $generator->assemblerFor($apiName);
        $generator->generate($apiName, $assembler);

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
