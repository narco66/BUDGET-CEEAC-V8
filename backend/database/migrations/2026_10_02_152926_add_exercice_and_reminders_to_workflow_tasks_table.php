<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_tasks', function (Blueprint $table) {
            $table->unsignedSmallInteger('exercice_year')->nullable()->after('structure');
            $table->unsignedTinyInteger('reminder_level')->default(0)->after('completion_action');
            $table->unsignedTinyInteger('escalation_level')->default(0)->after('reminder_level');
            $table->index('exercice_year');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_tasks', function (Blueprint $table) {
            $table->dropIndex(['exercice_year']);
            $table->dropColumn(['exercice_year', 'reminder_level', 'escalation_level']);
        });
    }
};
