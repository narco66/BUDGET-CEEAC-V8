<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordonnancements', function (Blueprint $table) {
            $table->string('status', 40)->default('a_signer')->after('montant');
            $table->string('workflow_step', 32)->default('ordonnateur')->after('status');
            $table->string('expected_actor_label')->nullable()->after('workflow_step');
            $table->string('ordonnateur_role', 40)->nullable()->after('expected_actor_label');
            $table->string('ordonnateur_label')->nullable()->after('ordonnateur_role');
            $table->string('fondement')->nullable()->after('ordonnateur_label');
            $table->string('signature_reference')->nullable()->after('fondement');
            $table->timestamp('signed_at')->nullable()->after('signature_reference');
            $table->foreignId('signed_by')->nullable()->after('signed_at')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('transmission_attempts')->default(0)->after('signed_by');
            $table->boolean('fail_next_transmission')->default(false)->after('transmission_attempts');
            $table->string('transmission_error')->nullable()->after('fail_next_transmission');
            $table->string('idempotence_key')->nullable()->unique()->after('transmission_error');
            $table->string('paiement_reference')->nullable()->after('idempotence_key');
            $table->string('last_action')->nullable()->after('paiement_reference');
            $table->date('due_on')->nullable()->after('last_action');
            $table->string('return_motif')->nullable()->after('due_on');
            $table->string('rejection_motif')->nullable()->after('return_motif');
        });

        Schema::create('ord_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordonnancement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->string('motif')->nullable();
            $table->text('observations')->nullable();
            $table->timestamps();
        });

        Schema::create('ord_delegations', function (Blueprint $table) {
            $table->id();
            $table->string('delegant');
            $table->string('delegataire');
            $table->string('fonction');
            $table->unsignedBigInteger('seuil_max');
            $table->string('type_depense')->default('Toutes natures');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('document')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('ordonnancement_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('montant');
            $table->string('status', 40)->default('genere');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
        Schema::dropIfExists('ord_delegations');
        Schema::dropIfExists('ord_events');
        Schema::table('ordonnancements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signed_by');
            $table->dropColumn([
                'status',
                'workflow_step',
                'expected_actor_label',
                'ordonnateur_role',
                'ordonnateur_label',
                'fondement',
                'signature_reference',
                'signed_at',
                'transmission_attempts',
                'fail_next_transmission',
                'transmission_error',
                'idempotence_key',
                'paiement_reference',
                'last_action',
                'due_on',
                'return_motif',
                'rejection_motif',
            ]);
        });
    }
};
