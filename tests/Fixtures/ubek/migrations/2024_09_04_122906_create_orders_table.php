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
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->index()->primary();
            $table->unsignedBigInteger('sort')->nullable();
            $table->string('order_id')->nullable()->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('company');
            $table->string('phone_code');
            $table->string('phone_number');
            $table->string('occasion');
            $table->date('delivery_date');
            $table->string('dimension');
            $table->unsignedBigInteger('total')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
