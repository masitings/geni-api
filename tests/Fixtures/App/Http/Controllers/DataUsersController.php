<?php

declare(strict_types=1);

namespace Tests\Fixtures\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Tests\Fixtures\App\Data\SimpleUserData;

class DataUsersController
{
    public function store(SimpleUserData $data): JsonResponse
    {
        return response()->json([
            'name' => $data->name,
            'email' => $data->email,
        ], 201);
    }

    public function show(): SimpleUserData
    {
        return SimpleUserData::from([
            'name' => 'Alice',
            'email' => 'alice@example.com',
        ]);
    }

    public function list()
    {
        return SimpleUserData::collect([
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob', 'email' => 'bob@example.com'],
        ]);
    }
}
