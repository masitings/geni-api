<?php

declare(strict_types=1);

namespace Tests\Fixtures\Extensions;

use Illuminate\Http\Request;

class BillingController
{
    /**
     * Charge a card.
     *
     * @throws PaymentRequiredException
     */
    public function charge(Request $request): array
    {
        $request->validate(['network' => 'required|ipv4_cidr']);

        return [];
    }
}
