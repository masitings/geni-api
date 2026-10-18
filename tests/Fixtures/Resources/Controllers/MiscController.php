<?php

namespace Tests\Fixtures\Resources\Controllers;

use Tests\Fixtures\Resources\Models\Post;
use Tests\Fixtures\Resources\Models\Team;
use Tests\Fixtures\Resources\Resources\PostCollection;
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
}
