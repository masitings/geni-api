<?php

declare(strict_types=1);

namespace Tests\Fixtures\App\Http\Controllers;

use Tests\Fixtures\App\Data\SimpleUserData;

class DataCollectionController
{
    public function list()
    {
        return SimpleUserData::collect([
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob', 'email' => 'bob@example.com'],
        ]);
    }
}
