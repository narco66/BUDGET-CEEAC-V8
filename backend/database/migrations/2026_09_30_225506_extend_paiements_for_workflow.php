<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_lots', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('libelle');
            $table->string('status', 40)->default('ouvert');
            $table->string('compte_debiteur')->nullable();
            $table->unsignedBigInteger('montant')->default(0);
            $table->string('reference_reglement')->nullable();
            $table->date('date_valeur')->nullable();
            $table->timestamps();
        });

        Schema::table('paiements', function (Blueprint $table) {
            $table->string('workflow_step', 32)->default('comptable');
            $table->string('expected_actor_label')->nullable();
            $table->string('mode', 32)->nullable();
            $table->string('banque')->nullable();
            $table->string('agence')->nullable();
            $table->string('compte')->nullable();
            $table->string('titulaire')->nullable();
            $table->string('compte_ceeac')->nullable();
            $table->boolean('compte_modifie')->default(false);
            $table->string('motif_reglement')->nullable();
            $table->string('reference_reglement')->nullable();
            $table->date('date_valeur')->nullable();
            $table->unsignedBigInteger('montant_paye')->default(0);
            $table->timestamp('pris_en_charge_at')->nullable();
            $table->foreignId('pris_en_charge_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->string('reconciliation_reference')->nullable();
            $table->string('last_action')->nullable();
            $table->date('due_on')->nullable();
            $table->string('return_motif')->nullable();
            $table->string('rejection_motif')->nullable();
            $table->string('bank_rejection')->nullable();
            $table->foreignId('lot_id')->nullable()->constrained('pay_lots')->nullOnDelete();
        });

        Schema::create('pay_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paiement_id')->constrained('paiements')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->string('motif')->nullable();
            $table->text('observations')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_events');
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pris_en_charge_par');
            $table->dropConstrainedForeignId('signed_by');
            $table->dropConstrainedForeignId('lot_id');
            $table->dropColumn([
                'workflow_step', 'expected_actor_label', 'mode', 'banque', 'agence', 'compte', 'titulaire',
                'compte_ceeac', 'compte_modifie', 'motif_reglement', 'reference_reglement', 'date_valeur',
                'montant_paye', 'pris_en_charge_at', 'validated_at', 'signed_at', 'reconciled_at',
                'reconciliation_reference', 'last_action', 'due_on', 'return_motif', 'rejection_motif', 'bank_rejection',
            ]);
        });
        Schema::dropIfExists('pay_lots');
    }
};
