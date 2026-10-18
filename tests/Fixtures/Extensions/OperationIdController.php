<?php

declare(strict_types=1);

namespace Tests\Fixtures\Extensions;

class OperationIdController
{
    public function index(): array
    {
        return [];
    }

    /**
     * Explicit id.
     *
     * @operationId fetchEverything
     */
    public function explicit(): array
    {
        return [];
    }

    public function unnamed(): array
    {
        return [];
    }

    public function update(): array
    {
        return [];
    }
}
