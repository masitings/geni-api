<?php

declare(strict_types=1);

namespace Geni\SchemaReader;

final class UnresolvedConstruct
{
    public string $file;

    public int $line;

    public string $reason;

    public function __construct(
        string $file,
        int $line,
        string $reason,
    ) {
        $this->file = $file;
        $this->line = $line;
        $this->reason = $reason;
    }
}
