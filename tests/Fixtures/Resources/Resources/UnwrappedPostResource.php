<?php

namespace Tests\Fixtures\Resources\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnwrappedPostResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return ['id' => $this->id];
    }
}
