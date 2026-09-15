<?php

declare(strict_types=1);

namespace Tests\Fixtures\Inference;

use Illuminate\Foundation\Http\FormRequest;

class CreatePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['required', 'enum:'.PostStatus::class],
            'tags' => ['nullable', 'array'],
        ];
    }
}
