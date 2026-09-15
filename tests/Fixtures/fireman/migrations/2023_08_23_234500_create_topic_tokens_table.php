<?php

use App\Models\AuthToken;
use App\Models\Project\Topic;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mongodb')->create('topic_tokens', function (Blueprint $table) {
            $table->foreignIdFor(AuthToken::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Topic::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_tokens');
    }
};
