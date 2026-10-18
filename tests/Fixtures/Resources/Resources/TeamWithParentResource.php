<?php

namespace Tests\Fixtures\Resources\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\Models\Team;

/** @mixin Team */
class TeamWithParentResource extends JsonResource
{
    public static $wrap = 'team';

    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }

    public function with(Request $request): array
    {
        return ['version' => '2.0'];
    }
}
