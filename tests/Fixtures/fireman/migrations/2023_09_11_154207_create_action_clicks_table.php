<?php

use App\Models\Project\Campaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mongodb')->create('action_clicks', function (Blueprint $table) {
            $table->unsignedBigInteger('click_action')->default(0);
            $table->unsignedBigInteger('click_button_primary')->default(0);
            $table->unsignedBigInteger('click_button_secondary')->default(0);
            $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_clicks');
    }
};
