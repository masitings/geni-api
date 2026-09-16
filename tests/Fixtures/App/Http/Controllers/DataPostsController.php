<?php

declare(strict_types=1);

namespace Tests\Fixtures\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Tests\Fixtures\App\Data\PostData;

class DataPostsController
{
    public function store(PostData $data): JsonResponse
    {
        return response()->json([
            'title' => $data->title,
            'content' => $data->content,
        ], 201);
    }

    public function show(): PostData
    {
        return PostData::from([
            'title' => 'My Post Title',
            'content' => 'This is the post content',
            'slug' => 'my-post-title',
        ]);
    }
}
