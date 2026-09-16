<?php

declare(strict_types=1);

namespace Tests\Fixtures\App\Actions;

use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\Concerns\AsAction;

class DualMethodAction
{
    use AsAction;

    public function handle(): JsonResponse
    {
        return response()->json(['source' => 'handle']);
    }

    public function asController(): JsonResponse
    {
        return response()->json(['source' => 'asController']);
    }
}
