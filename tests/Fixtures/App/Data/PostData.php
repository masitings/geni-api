<?php

declare(strict_types=1);

namespace Tests\Fixtures\App\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class PostData extends Data
{
    public function __construct(
        #[Required]
        #[Min(5)]
        #[Max(100)]
        public string $title,
        #[Required]
        public string $content,
        #[Regex('/^[A-Z0-9_-]+$/')]
        public Optional|string $slug,
    ) {}
}
