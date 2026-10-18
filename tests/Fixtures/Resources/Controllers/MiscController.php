<?php

namespace Tests\Fixtures\Resources\Controllers;

use Tests\Fixtures\Resources\Models\Post;
use Tests\Fixtures\Resources\Models\Team;
use Tests\Fixtures\Resources\Resources\PostCollection;
use Tests\Fixtures\Resources\Resources\PostResource;
use Tests\Fixtures\Resources\Resources\TeamWithParentResource;
use Tests\Fixtures\Resources\Resources\UnwrappedPostResource;

class MiscController
{
    public function team(Team $team)
    {
        return new TeamWithParentResource($team);
    }

    public function unwrapped(Post $post)
    {
        return new UnwrappedPostResource($post);
    }

    public function posts()
    {
        return new PostCollection(Post::paginate());
    }

    public function find(int $id)
    {
        return Post::query()->where('id', $id)->firstOrFail();
    }

    public function list()
    {
        return Post::all();
    }

    public function destroy(Post $post)
    {
        $post->delete();

        return response()->noContent();
    }

    public function store()
    {
        $post = Post::create(['title' => 'Hello']);

        return new PostResource($post);
    }

    public function storeWithSave()
    {
        $post = new Post;
        $post->title = 'Hello';
        $post->save();

        return PostResource::make($post);
    }

    public function accepted()
    {
        return response()->json(['queued' => true], 202);
    }

    public function custom(Post $post)
    {
        return (new PostResource($post))->response()->setStatusCode(203);
    }
}
