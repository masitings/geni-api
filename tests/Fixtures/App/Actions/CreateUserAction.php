<?php

declare(strict_types=1);

namespace Tests\Fixtures\App\Actions;

use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateUserAction
{
    use AsAction;

    public function authorize(ActionRequest $request): bool
    {
        return $request->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
        ];
    }

    public function asController(ActionRequest $request): JsonResponse
    {
        return response()->json([
            'id' => 1,
            'name' => $request->input('name'),
            'email' => $request->input('email'),
        ], 201);
    }
}
