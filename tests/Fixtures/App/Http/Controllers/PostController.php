<?php

declare(strict_types=1);

namespace Tests\Fixtures\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tests\Fixtures\App\Http\Resources\PostResource;
use Tests\Fixtures\App\Models\Post;

class PostController extends Controller
{
    /**
     * GET /api/posts - list all posts
     */
    public function index(): array
    {
        return Post::all()->toArray();
    }

    /**
     * POST /api/posts - create a new post
     * Uses literal validate() rules.
     */
    public function store(Request $request): PostResource
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'nullable|string',
            'published_at' => 'nullable|date',
        ]);

        $post = Post::create($request->only(['title', 'body', 'published_at']));

        return new PostResource($post);
    }

    /**
     * GET /api/posts/{post} - show a single post (route-model bound, returns JsonResource)
     */
    public function show(Post $post): PostResource
    {
        return new PostResource($post);
    }

    /**
     * PUT /api/posts/{post} - update a post
     */
    public function update(Request $request, Post $post): PostResource
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'nullable|string',
        ]);

        $post->update($request->only(['title', 'body']));

        return new PostResource($post);
    }

    /**
     * DELETE /api/posts/{post} - delete a post
     */
    public function destroy(Post $post): bool
    {
        return $post->delete();
    }
}
