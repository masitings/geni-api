<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mongodb')->create('temporary_files', function (Blueprint $table) {
            $table->string('folder');
            $table->string('filename');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_files');
    }
};
