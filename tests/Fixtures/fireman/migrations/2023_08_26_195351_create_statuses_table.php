<?php

use App\Models\AuthToken;
use App\Models\Project\Campaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mongodb')->create('statuses', function (Blueprint $table) {
            $table->enum('status', ['pending', 'sent', 'read', 'reject'])->default('pending');
            $table->dateTime('read_at')->default(null)->nullable();
            $table->foreignIdFor(AuthToken::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statuses');
    }
};
