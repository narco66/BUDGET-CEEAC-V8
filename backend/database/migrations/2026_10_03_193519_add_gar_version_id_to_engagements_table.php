<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engagements', function (Blueprint $table) {
            $table->foreignId('gar_version_id')->nullable()->after('tiers_id')->constrained('gar_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('engagements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gar_version_id');
        });
    }
};
