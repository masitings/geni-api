<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The database connection that should be used by the migration.
     */
    protected $connection = 'mongodb';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('whitelist_domains', function (Blueprint $collection) {
            $collection->id();
            $collection->string('project_id');
            $collection->string('domain');
            $collection->boolean('is_active')->default(true);
            $collection->timestamps();

            $collection->index(['project_id', 'domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('whitelist_domains');
    }
};
