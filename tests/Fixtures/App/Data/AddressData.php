<?php

declare(strict_types=1);

namespace Tests\Fixtures\App\Data;

use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class AddressData extends Data
{
    public function __construct(
        #[Required]
        public string $street,
        #[Required]
        public string $city,
        #[Min(5)]
        public int $postal_code,
    ) {}
}
