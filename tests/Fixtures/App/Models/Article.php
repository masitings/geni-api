<?php

declare(strict_types=1);

namespace Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $table = 'articles';

    protected $fillable = [
        'title',
        'views',
        'published',
    ];
}
