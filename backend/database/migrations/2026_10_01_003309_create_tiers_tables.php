<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('type', 32);
            $table->string('raison_sociale');
            $table->string('nom_normalise')->index();
            $table->string('nif', 64)->nullable()->unique();
            $table->string('rccm', 64)->nullable();
            $table->string('pays', 64)->nullable();
            $table->string('adresse')->nullable();
            $table->string('email')->nullable();
            $table->string('telephone', 32)->nullable();
            $table->string('status', 16)->default('actif');
            $table->string('status_motif')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tiers_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiers_id')->constrained('tiers')->restrictOnDelete();
            $table->string('banque');
            $table->string('agence')->nullable();
            $table->string('numero', 64);
            $table->string('titulaire');
            $table->string('devise', 3)->default('XAF');
            $table->string('justificatif')->nullable();
            $table->string('status', 16)->default('en_attente');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->string('rejection_motif')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['tiers_id', 'numero']);
        });

        Schema::table('engagements', function (Blueprint $table) {
            $table->foreignId('tiers_id')->nullable()->after('beneficiary_nif')->constrained('tiers')->restrictOnDelete();
        });

        Schema::table('paiements', function (Blueprint $table) {
            $table->foreignId('tiers_bank_account_id')->nullable()->after('compte')->constrained('tiers_bank_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tiers_bank_account_id');
        });
        Schema::table('engagements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tiers_id');
        });
        Schema::dropIfExists('tiers_bank_accounts');
        Schema::dropIfExists('tiers');
    }
};
