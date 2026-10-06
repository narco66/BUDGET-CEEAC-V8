<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des mesures correctives, risques et recommandations (description
 * S&E §33, §35, §42-44) : avancement, statut, clôture prouvée. L’historique
 * des changements est conservé dans le journal d’audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corrective_actions', function (Blueprint $table) {
            $table->text('last_comment')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('se_risks', function (Blueprint $table) {
            $table->text('last_comment')->nullable();
            $table->timestamp('closed_at')->nullable();
        });

        Schema::table('se_recommendations', function (Blueprint $table) {
            $table->foreignId('pap_enrichment_id')->nullable()->constrained()->nullOnDelete();
            $table->text('last_comment')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
        });

        Schema::table('corrective_actions', function (Blueprint $table) {
            $table->timestamp('last_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('corrective_actions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['last_comment', 'closed_at', 'last_reminded_at']);
        });
        Schema::table('se_risks', function (Blueprint $table) {
            $table->dropColumn(['last_comment', 'closed_at']);
        });
        Schema::table('se_recommendations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pap_enrichment_id');
            $table->dropColumn(['last_comment', 'closed_at', 'last_reminded_at']);
        });
    }
};
