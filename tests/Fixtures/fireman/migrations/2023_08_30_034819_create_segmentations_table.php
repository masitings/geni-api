<?php

use App\Models\Project\Campaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mongodb')->create('segmentations', function (Blueprint $table) {
            $table->json('values');
            $table->enum('relation', ['and', 'or'])->default('and');
            $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segmentations');
    }
};
