<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent qui a pris en charge une tâche partagée par un rôle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_tasks', function (Blueprint $table) {
            $table->foreignId('started_by')->nullable()->after('started_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('started_by');
        });
    }
};
