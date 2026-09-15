<?php

declare(strict_types=1);

namespace Geni\Inference;

/**
 * Extension point for mapping exception classes to HTTP status codes.
 *
 * Framework-free.
 */
interface ExceptionToResponse
{
    /**
     * Determine if this transformer handles the exception class.
     */
    public function matches(string $exceptionClass): bool;

    /**
     * Map exception class to HTTP status code.
     */
    public function statusFor(string $exceptionClass): ?int;
}
