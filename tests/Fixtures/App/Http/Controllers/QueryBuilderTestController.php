<?php

declare(strict_types=1);

namespace Tests\Fixtures\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Tests\Fixtures\App\Models\Article;
use Tests\Fixtures\App\Models\Post;

final class QueryBuilderTestController
{
    public function index(): JsonResponse
    {
        $articles = QueryBuilder::for(Article::class)
            ->allowedFilters([
                'title',
                AllowedFilter::exact('views'),
                AllowedFilter::exact('is_published', 'published'),
                AllowedFilter::scope('published'),
                AllowedFilter::trashed(),
            ])
            ->allowedSorts(['title', 'created_at'])
            ->defaultSort('-created_at')
            ->allowedIncludes(['user', 'comments'])
            ->allowedFields(['id', 'title', 'user.name'])
            ->allowedAppends(['full_title'])
            ->get();

        return response()->json($articles);
    }

    public function dynamic($dynamicFilter): JsonResponse
    {
        $posts = QueryBuilder::for(Post::class)
            ->allowedFilters([$dynamicFilter])
            ->get();

        return response()->json($posts);
    }
}
