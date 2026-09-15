<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mongodb')->create('campaigns', function (Blueprint $table) {
            $table->string('name');
            $table->enum('type', ['bulk', 'segment'])->default('bulk');
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->enum('layout', ['icon', 'icon_image'])->default('icon');
            $table->string('link');
            $table->string('message_title');
            $table->text('message_description');
            $table->string('message_button')->nullable()->default(null);
            $table->text('message_link')->nullable()->default(null);
            $table->string('message_button_second')->nullable()->default(null);
            $table->text('message_link_second')->nullable()->default(null);
            $table->text('icon')->nullable()->default(null);
            $table->text('image')->nullable()->default(null);
            $table->enum('location_type', ['country', 'city', 'province'])->default('country');
            $table->string('location');
            $table->dateTime('schedule_at')->nullable()->default(null);
            $table->unsignedBigInteger('click_link')->default(0);
            $table->unsignedBigInteger('click_button')->default(0);
            $table->unsignedBigInteger('click_button_second')->default(0);
            $table->string('batch_id')->default(null)->nullable();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
