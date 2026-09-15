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
            'file' => $this->file,
            'line' => $this->line,
            'reason' => $this->reason,
        ];
    }
}
