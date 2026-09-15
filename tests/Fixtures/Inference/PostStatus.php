<?php

declare(strict_types=1);

namespace Tests\Fixtures\Inference;

enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
