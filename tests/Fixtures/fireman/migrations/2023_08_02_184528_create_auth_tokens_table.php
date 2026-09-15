<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mongodb')->create('auth_tokens', function (Blueprint $table) {
            $table->longText('token');
            $table->string('ip_address')->nullable()->default(null);
            $table->string('country')->nullable()->default(null);
            $table->string('city')->nullable()->default(null);
            $table->string('province')->nullable()->default(null);
            $table->enum('platform', ['ios', 'android', 'desktop', 'mobile'])->default('desktop');
            $table->boolean('is_active')->default(true);
            $table->string('last_visit_url');
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_tokens');
    }
};
