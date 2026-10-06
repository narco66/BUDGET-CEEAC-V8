<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_states', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('nom');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('revenue_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('label');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('revenue_payment_modes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('label');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('revenue_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->timestamps();
        });

        Schema::create('revenue_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercice_id')->constrained('exercices');
            $table->foreignId('category_id')->constrained('revenue_categories');
            $table->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->string('code', 32);
            $table->string('label');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('montant');
            $table->string('source_label')->nullable();
            $table->string('periode', 64)->nullable();
            $table->date('date_prevue')->nullable();
            $table->text('observations')->nullable();
            $table->string('statut', 32)->default('brouillon');
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['exercice_id', 'code']);
            $table->index('statut');
        });

        Schema::create('revenue_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('exercice_id')->constrained('exercices');
            $table->foreignId('category_id')->constrained('revenue_categories');
            $table->foreignId('forecast_id')->nullable()->constrained('revenue_forecasts')->nullOnDelete();
            $table->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->string('debtor_type', 32);
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->foreignId('member_state_id')->nullable()->constrained('member_states')->nullOnDelete();
            $table->string('debtor_label');
            $table->unsignedBigInteger('montant');
            $table->string('devise', 3)->default('XAF');
            $table->date('echeance');
            $table->string('motif');
            $table->text('description')->nullable();
            $table->text('observations')->nullable();
            $table->string('statut', 32)->default('brouillon');
            $table->unsignedBigInteger('montant_encaisse')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['statut', 'echeance']);
        });

        Schema::create('revenue_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercice_id')->constrained('exercices');
            $table->foreignId('member_state_id')->constrained('member_states');
            $table->unsignedInteger('quote_part');
            $table->unsignedBigInteger('montant_attendu');
            $table->date('echeance')->nullable();
            $table->text('observations')->nullable();
            $table->foreignId('order_id')->nullable()->unique()->constrained('revenue_orders')->nullOnDelete();
            $table->timestamps();
            $table->unique(['exercice_id', 'member_state_id']);
        });

        Schema::create('revenue_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->date('recu_le');
            $table->unsignedBigInteger('montant');
            $table->string('devise', 3)->default('XAF');
            $table->decimal('taux', 12, 6)->nullable();
            $table->string('mode', 32);
            $table->string('reference_bancaire')->nullable();
            $table->string('banque')->nullable();
            $table->string('compte')->nullable();
            $table->string('transaction_no')->nullable();
            $table->text('commentaire')->nullable();
            $table->string('statut', 32)->default('non_rapproche');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('rapproche_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rapproche_le')->nullable();
            $table->timestamps();
            $table->index('statut');
        });

        Schema::create('revenue_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('revenue_receipts')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('revenue_orders');
            $table->unsignedBigInteger('montant');
            $table->timestamps();
            $table->unique(['receipt_id', 'order_id']);
        });

        Schema::create('revenue_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('revenue_orders')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('canal', 32);
            $table->string('destinataire');
            $table->string('resultat')->nullable();
            $table->date('prochaine_action')->nullable();
            $table->foreignId('author_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('revenue_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('revenue_orders');
            $table->foreignId('receipt_id')->nullable()->constrained('revenue_receipts')->nullOnDelete();
            $table->string('kind', 32);
            $table->unsignedBigInteger('montant');
            $table->string('motif');
            $table->foreignId('author_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('revenue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('revenue_orders')->nullOnDelete();
            $table->foreignId('receipt_id')->nullable()->constrained('revenue_receipts')->nullOnDelete();
            $table->string('action', 64);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamps();
            $table->index('order_id');
        });

        Schema::create('revenue_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('revenue_orders')->cascadeOnDelete();
            $table->foreignId('receipt_id')->nullable()->constrained('revenue_receipts')->nullOnDelete();
            $table->string('nom');
            $table->string('chemin');
            $table->string('sha256', 64);
            $table->string('type_piece', 64);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });

        $now = now();
        DB::table('member_states')->insert([
            ['code' => 'AO', 'nom' => 'Angola', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'BI', 'nom' => 'Burundi', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CM', 'nom' => 'Cameroun', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CF', 'nom' => 'République centrafricaine', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CG', 'nom' => 'Congo', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CD', 'nom' => 'République démocratique du Congo', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'GA', 'nom' => 'Gabon', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'GQ', 'nom' => 'Guinée équatoriale', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'RW', 'nom' => 'Rwanda', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ST', 'nom' => 'Sao Tomé-et-Principe', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'TD', 'nom' => 'Tchad', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('revenue_categories')->insert([
            ['code' => 'CST', 'label' => 'Contributions statutaires', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CEX', 'label' => 'Contributions exceptionnelles', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SUB', 'label' => 'Subventions', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PAR', 'label' => 'Financements partenaires', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'DON', 'label' => 'Dons', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PFI', 'label' => 'Produits financiers', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'REM', 'label' => 'Remboursements', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'VTE', 'label' => 'Ventes de biens ou services', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PDV', 'label' => 'Produits divers', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'AUT', 'label' => 'Autres recettes', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('revenue_payment_modes')->insert([
            ['code' => 'virement', 'label' => 'Virement bancaire', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'cheque', 'label' => 'Chèque', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'especes', 'label' => 'Espèces', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'transfert', 'label' => 'Transfert', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'compensation', 'label' => 'Compensation', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'autre', 'label' => 'Autre', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('revenue_settings')->insert([
            ['key' => 'approche_jours', 'value' => '7', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'critique_jours', 'value' => '90', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_documents');
        Schema::dropIfExists('revenue_events');
        Schema::dropIfExists('revenue_adjustments');
        Schema::dropIfExists('revenue_reminders');
        Schema::dropIfExists('revenue_allocations');
        Schema::dropIfExists('revenue_receipts');
        Schema::dropIfExists('revenue_contributions');
        Schema::dropIfExists('revenue_orders');
        Schema::dropIfExists('revenue_forecasts');
        Schema::dropIfExists('revenue_settings');
        Schema::dropIfExists('revenue_payment_modes');
        Schema::dropIfExists('revenue_categories');
        Schema::dropIfExists('member_states');
    }
};
