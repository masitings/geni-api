<?php

declare(strict_types=1);

namespace Tests\Fixtures\AnnotatedApp\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\AnnotatedApp\Models\Article;

/**
 * @mixin Article
 */
class ArticleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'status' => $this->status,
        ];
    }
}
