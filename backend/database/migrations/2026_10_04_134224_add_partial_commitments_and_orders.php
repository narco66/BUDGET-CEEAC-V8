<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engagements', function (Blueprint $table) {
            $table->dropUnique(['expression_besoin_id']);
            $table->index('expression_besoin_id');
            $table->string('nature')->default('initial');
            $table->foreignId('parent_engagement_id')->nullable()->constrained('engagements')->nullOnDelete();
            $table->string('avenant_motif')->nullable();
        });

        Schema::table('ordonnancements', function (Blueprint $table) {
            $table->dropUnique(['liquidation_id']);
            $table->index('liquidation_id');
            $table->string('nature')->default('normal');
        });

        Schema::table('paiements', function (Blueprint $table) {
            $table->unsignedBigInteger('montant_a_recouvrer')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropColumn('montant_a_recouvrer');
        });

        Schema::table('ordonnancements', function (Blueprint $table) {
            $table->dropIndex(['liquidation_id']);
            $table->dropColumn('nature');
            $table->unique('liquidation_id');
        });

        Schema::table('engagements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_engagement_id');
            $table->dropIndex(['expression_besoin_id']);
            $table->dropColumn(['nature', 'avenant_motif']);
            $table->unique('expression_besoin_id');
        });
    }
};
