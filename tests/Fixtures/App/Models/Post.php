<?php

declare(strict_types=1);

namespace Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $table = 'posts';

    protected $fillable = [
        'title',
        'body',
        'published_at',
    ];
}
