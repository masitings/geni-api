<?php

namespace Tests\Fixtures\Resources\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\Models\User;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email_verified_at' => $this->email_verified_at,
            'is_admin' => $this->is_admin,
            'settings' => $this->settings,
            'status' => $this->status,
            'team_id' => $this->resource->team_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'is_verified' => (bool) $this->email_verified_at,
            'label' => 'user',
            'score' => 1.5,
            'rank' => 3,
            'flag' => true,
            'meta' => ['source' => 'api', 'version' => 2],
            'tags' => ['a', 'b'],
            'posts' => PostResource::collection($this->whenLoaded('posts')),
            'team' => new TeamResource($this->whenLoaded('team')),
            'latest_post' => PostResource::make($this->whenLoaded('latestPost')),
            'raw_posts' => $this->whenLoaded('posts'),
            'secret' => $this->when($request->user() !== null, 'hidden'),
            'posts_count' => $this->whenCounted('posts'),
            'nickname' => $this->whenNotNull($this->name),
            $this->mergeWhen($request->has('full'), [
                'extra_one' => 'x',
                'extra_two' => 2,
            ]),
            $this->merge(['always' => true]),
        ];
    }
}
