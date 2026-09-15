<?php

declare(strict_types=1);

namespace Tests\Fixtures\AnnotatedApp\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreArticleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:200',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
        ];
    }
}
