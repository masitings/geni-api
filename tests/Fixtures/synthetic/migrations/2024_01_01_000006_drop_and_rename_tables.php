<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('tags', 'categories');
        Schema::dropIfExists('taggables');
    }

    public function down(): void {}
};
