<?php

namespace Tests\Fixtures\Resources\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\Models\Post;

/** @mixin Post */
class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'published_at' => $this->published_at,
            'views' => $this->views,
            'rating' => $this->rating,
        ];
    }
}
