<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->renameColumn('name', 'full_name');
            $table->dropColumn('password');
            $table->string('email', 150)->change();
        });
    }

    public function down(): void {}
};
