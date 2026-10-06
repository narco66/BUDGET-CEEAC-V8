<?php

use App\Domains\Administration\Services\HabilitationCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->string('sensitivity', 32)->default('standard');
            $table->boolean('active')->default(true);
            $table->string('origin', 32)->default('systeme');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->string('category', 64)->nullable();
            $table->boolean('system')->default(true);
        });

        Schema::table('user_roles', function (Blueprint $table) {
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 32)->default('active');
            $table->string('motif')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision')->nullable();
        });

        Schema::table('sod_rules', function (Blueprint $table) {
            $table->boolean('active')->default(true);
            $table->string('justification')->nullable();
        });

        app(HabilitationCatalogue::class)->assurer();
    }

    public function down(): void
    {
        Schema::table('sod_rules', function (Blueprint $table) {
            $table->dropColumn(['active', 'justification']);
        });

        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('granted_by');
            $table->dropColumn(['starts_on', 'ends_on', 'status', 'motif', 'decision']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['category', 'system']);
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['description', 'sensitivity', 'active', 'origin']);
        });
    }
};
