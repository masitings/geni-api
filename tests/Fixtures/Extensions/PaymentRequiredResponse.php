<?php

declare(strict_types=1);

namespace Tests\Fixtures\Extensions;

use Geni\Inference\ExceptionToResponse;

class PaymentRequiredResponse implements ExceptionToResponse
{
    public function matches(string $exceptionClass): bool
    {
        return str_ends_with($exceptionClass, 'PaymentRequiredException');
    }

    public function statusFor(string $exceptionClass): ?int
    {
        return 402;
    }
}
