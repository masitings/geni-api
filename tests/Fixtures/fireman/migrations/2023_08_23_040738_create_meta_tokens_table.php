<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mongodb')->create('meta_tokens', function (Blueprint $table) {
            $table->string('key');
            $table->longText('value');
            $table->string('model_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_tokens');
    }
};
