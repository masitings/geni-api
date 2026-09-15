<?php

declare(strict_types=1);

namespace Tests\Fixtures\AnnotatedApp\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $table = 'articles';

    protected $fillable = ['title', 'content', 'status'];
}
