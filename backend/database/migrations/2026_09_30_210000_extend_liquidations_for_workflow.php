<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropUnique(['engagement_id']);
        });

        Schema::table('liquidations', function (Blueprint $table) {
            $table->index('engagement_id');
            $table->string('status', 40)->default('generee')->after('montant');
            $table->string('workflow_step', 32)->default('initiateur')->after('status');
            $table->string('expected_actor_label')->default('Structure initiatrice')->after('workflow_step');
            $table->string('fournisseur')->nullable()->after('expected_actor_label');
            $table->timestamp('service_fait_at')->nullable()->after('fournisseur');
            $table->foreignId('certified_by')->nullable()->after('service_fait_at')->constrained('users')->nullOnDelete();
            $table->text('service_fait_reserves')->nullable()->after('certified_by');
            $table->unsignedBigInteger('montant_accepte')->default(0)->after('service_fait_reserves');
            $table->string('invoice_number')->nullable()->after('montant_accepte');
            $table->date('invoice_date')->nullable()->after('invoice_number');
            $table->unsignedBigInteger('montant_ht')->default(0)->after('invoice_date');
            $table->unsignedBigInteger('taxes')->default(0)->after('montant_ht');
            $table->unsignedBigInteger('montant_ttc')->default(0)->after('taxes');
            $table->unsignedBigInteger('montant_brut')->default(0)->after('montant_ttc');
            $table->unsignedBigInteger('retenue_garantie')->default(0)->after('montant_brut');
            $table->unsignedBigInteger('penalite')->default(0)->after('retenue_garantie');
            $table->unsignedBigInteger('montant_net')->default(0)->after('penalite');
            $table->boolean('doublon')->default(false)->after('montant_net');
            $table->string('last_action')->nullable()->after('doublon');
            $table->date('due_on')->nullable()->after('last_action');
            $table->string('visa_reference')->nullable()->after('due_on');
            $table->timestamp('vised_at')->nullable()->after('visa_reference');
            $table->string('ordonnancement_reference')->nullable()->after('vised_at');
            $table->string('return_motif')->nullable()->after('ordonnancement_reference');
            $table->string('rejection_motif')->nullable()->after('return_motif');
        });

        Schema::create('liq_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->string('motif')->nullable();
            $table->text('observations')->nullable();
            $table->timestamps();
        });

        Schema::create('ordonnancements', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('liquidation_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('montant');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordonnancements');
        Schema::dropIfExists('liq_events');
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('certified_by');
            $table->dropColumn([
                'status',
                'workflow_step',
                'expected_actor_label',
                'fournisseur',
                'service_fait_at',
                'service_fait_reserves',
                'montant_accepte',
                'invoice_number',
                'invoice_date',
                'montant_ht',
                'taxes',
                'montant_ttc',
                'montant_brut',
                'retenue_garantie',
                'penalite',
                'montant_net',
                'doublon',
                'last_action',
                'due_on',
                'visa_reference',
                'vised_at',
                'ordonnancement_reference',
                'return_motif',
                'rejection_motif',
            ]);
            $table->dropIndex(['engagement_id']);
            $table->unique('engagement_id');
        });
    }
};
