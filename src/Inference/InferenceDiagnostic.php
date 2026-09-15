<?php

declare(strict_types=1);

namespace Geni\Inference;

use JsonSerializable;

/**
 * Diagnostic record representing an inference failure or unresolvable construct.
 * Mirrored after Geni\SchemaReader\UnresolvedConstruct.
 */
final class InferenceDiagnostic implements JsonSerializable
{
    public string $file;

    public int $line;

    public string $reason;

    public function __construct(string $file, int $line, string $reason)
    {
        $this->file = $file;
        $this->line = $line;
        $this->reason = $reason;
    }

    /**
     * @return array{file: string, line: int, reason: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'file' => self::relativize($this->file),
            'line' => $this->line,
            'reason' => $this->reason,
        ];
    }

    /**
     * Convert an absolute path to one relative to the current working directory
     * (the project root when run via Artisan/Testbench). Diagnostics are
     * embedded in the OpenAPI document's `x-geni-unresolved` extension, so a
     * machine-specific absolute path (e.g. a developer's home directory) would
     * make `geni:check` report false drift when re-generated on a different
     * machine or CI runner with an identical codebase.
     */
    private static function relativize(string $file): string
    {
        $cwd = getcwd();

        if ($cwd === false) {
            return $file;
        }

        $prefix = rtrim($cwd, '/').'/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }
}
