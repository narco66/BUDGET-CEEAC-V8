<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ged_versions', function (Blueprint $table) {
            $table->text('extracted_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ged_versions', function (Blueprint $table) {
            $table->dropColumn('extracted_text');
        });
    }
};
