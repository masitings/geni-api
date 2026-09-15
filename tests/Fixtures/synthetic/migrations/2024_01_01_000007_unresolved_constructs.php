<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $colName = 'dynamic_column';
            $table->string($colName);

            if (true) {
                $table->string('conditional_col');
            }

            $list = ['a', 'b'];
            foreach ($list as $item) {
                $table->string($item);
            }
        });
    }

    public function down(): void {}
};
