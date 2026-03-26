<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('installations', function (Blueprint $table) {
            $table->renameColumn('enabled', 'hidden');
        });

        Schema::table('installations', function (Blueprint $table) {
            $table->boolean('hidden')->default(false)->change();
        });

        DB::statement('UPDATE installations SET hidden = CASE WHEN hidden = 1 THEN 0 ELSE 1 END');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('UPDATE installations SET hidden = CASE WHEN hidden = 1 THEN 0 ELSE 1 END');

        Schema::table('installations', function (Blueprint $table) {
            $table->renameColumn('hidden', 'enabled');
        });

        Schema::table('installations', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->change();
        });
    }
};
