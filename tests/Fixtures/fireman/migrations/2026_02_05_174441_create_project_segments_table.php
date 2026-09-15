<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // project_segments lives in MongoDB, skip MySQL table creation
        if (Schema::connection('mysql')->hasTable('project_segments')) {
            return;
        }

        Schema::connection('mysql')->create('project_segments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('project_id');  // MongoDB UUID, no FK constraint
            $table->string('name');
            $table->string('slug')->index();
            $table->text('description')->nullable();
            $table->json('rules')->nullable();
            $table->string('topic_id')->nullable(); // MongoDB UUID, no FK constraint
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_segments');
    }
};
