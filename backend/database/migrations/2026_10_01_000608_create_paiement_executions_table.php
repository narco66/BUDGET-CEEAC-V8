<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiement_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paiement_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('rang');
            $table->unsignedBigInteger('montant');
            $table->string('reference_reglement', 64);
            $table->string('mode', 16)->nullable();
            $table->date('date_valeur')->nullable();
            $table->string('status', 16)->default('executee');
            $table->string('idempotence_key')->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('pay_lots')->nullOnDelete();
            $table->string('rejection_motif')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['paiement_id', 'rang']);
            $table->index('reference_reglement');
        });

        $now = now();
        DB::table('paiements')->where('montant_paye', '>', 0)->orderBy('id')->each(function (object $paiement) use ($now): void {
            DB::table('paiement_executions')->insert([
                'paiement_id' => $paiement->id,
                'rang' => 1,
                'montant' => $paiement->montant_paye,
                'reference_reglement' => $paiement->reference_reglement ?? 'REPRISE-'.$paiement->id,
                'mode' => $paiement->mode,
                'date_valeur' => $paiement->date_valeur,
                'status' => 'executee',
                'idempotence_key' => 'PAY-EXEC-'.$paiement->id.'-'.($paiement->reference_reglement ?? 'REPRISE'),
                'lot_id' => $paiement->lot_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiement_executions');
    }
};
