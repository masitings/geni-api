<?php

declare(strict_types=1);

namespace Tests\Fixtures\App\Data;

use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class SimpleUserData extends Data
{
    public function __construct(
        #[Required]
        public string $name,
        #[Required]
        #[Email]
        public string $email,
        public ?AddressData $address = null,
    ) {}
}
