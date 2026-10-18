<?php

namespace Tests\Fixtures\Resources\Controllers;

use Tests\Fixtures\Resources\Models\User;
use Tests\Fixtures\Resources\Resources\UserResource;

class UserController
{
    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function index()
    {
        return UserResource::collection(User::paginate());
    }

    public function all()
    {
        return UserResource::collection(User::all());
    }

    public function raw(User $user)
    {
        return $user;
    }

    public function withMeta(User $user)
    {
        return (new UserResource($user))->additional(['meta' => ['version' => '1']]);
    }
}
