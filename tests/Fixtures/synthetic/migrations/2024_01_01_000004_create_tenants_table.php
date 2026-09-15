<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('orders', function (Blueprint $t) {
            $t->id();
            $t->foreignIdFor(User::class);
            $t->decimal('total', 10, 2)->default(0.00);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('orders');
    }
};
