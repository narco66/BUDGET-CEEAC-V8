<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercices', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('annee')->unique();
            $table->string('statut', 32)->default('ouvert');
            $table->date('date_debut');
            $table->date('date_fin');
            $table->timestamps();
        });

        Schema::create('organization_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->string('sigle', 32);
            $table->string('name');
            $table->string('kind', 32);
            $table->boolean('is_technical')->default(false);
            $table->timestamps();
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_unit_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('label');
            $table->string('nature', 16);
            $table->string('chapitre')->nullable();
            $table->string('article')->nullable();
            $table->string('paragraphe')->nullable();
            $table->string('nature_depense')->nullable();
            $table->unsignedBigInteger('montant_vote')->default(0);
            $table->bigInteger('ajustements')->default(0);
            $table->timestamps();

            $table->unique(['exercice_id', 'code']);
        });

        Schema::create('pap_enrichments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_line_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('a_completer');
            $table->string('pilier')->nullable();
            $table->string('axe')->nullable();
            $table->text('objectif_general')->nullable();
            $table->text('objectif_specifique')->nullable();
            $table->string('produit')->nullable();
            $table->string('sous_produit')->nullable();
            $table->string('activite')->nullable();
            $table->string('sous_activite')->nullable();
            $table->text('resultats_attendus')->nullable();
            $table->string('indicateur')->nullable();
            $table->string('unite_mesure')->nullable();
            $table->string('valeur_reference')->nullable();
            $table->string('cible')->nullable();
            $table->string('source_verification')->nullable();
            $table->string('unite_responsable')->nullable();
            $table->string('beneficiaires')->nullable();
            $table->string('localisation')->nullable();
            $table->string('periode')->nullable();
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->text('livrables')->nullable();
            $table->text('risques')->nullable();
            $table->text('observations')->nullable();
            $table->timestamps();
        });

        Schema::create('pap_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pap_enrichment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('label');
            $table->boolean('proposed')->default(false);
            $table->boolean('validated')->default(true);
            $table->timestamps();
        });

        Schema::create('expression_besoins', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('exercice_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('initiator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('budget_line_id')->constrained()->restrictOnDelete();
            $table->string('nature', 16);
            $table->string('objet')->nullable();
            $table->text('contexte')->nullable();
            $table->text('justification')->nullable();
            $table->string('urgence', 32)->default('normale');
            $table->string('priorite', 32)->default('normale');
            $table->text('resultats_attendus')->nullable();
            $table->string('status', 32)->default('brouillon');
            $table->string('workflow_step', 32)->default('initiateur');
            $table->string('expected_actor_label')->nullable();
            $table->date('due_on')->nullable();
            $table->unsignedBigInteger('montant')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('return_motif')->nullable();
            $table->string('rejection_motif')->nullable();
            $table->timestamps();

            $table->index(['status', 'nature']);
        });

        Schema::create('engagements', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('expression_besoin_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('budget_line_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('montant');
            $table->timestamps();
        });

        Schema::create('eb_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expression_besoin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pap_task_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('designation');
            $table->text('description')->nullable();
            $table->decimal('quantite', 12, 2);
            $table->string('unite', 32)->default('forfait');
            $table->unsignedBigInteger('prix_unitaire')->default(0);
            $table->unsignedBigInteger('montant')->default(0);
            $table->string('beneficiaire')->nullable();
            $table->string('lieu')->nullable();
            $table->string('periode')->nullable();
            $table->string('observation')->nullable();
            $table->timestamps();
        });

        Schema::create('eb_imputations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expression_besoin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_line_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('montant')->default(0);
            $table->timestamps();

            $table->unique(['expression_besoin_id', 'budget_line_id']);
        });

        Schema::create('eb_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expression_besoin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 64);
            $table->string('original_name');
            $table->string('path')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->string('confidentialite', 32)->default('interne');
            $table->unsignedSmallInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('eb_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expression_besoin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->string('motif')->nullable();
            $table->text('observations')->nullable();
            $table->json('fields')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eb_events');
        Schema::dropIfExists('eb_documents');
        Schema::dropIfExists('eb_imputations');
        Schema::dropIfExists('eb_lines');
        Schema::dropIfExists('engagements');
        Schema::dropIfExists('expression_besoins');
        Schema::dropIfExists('pap_tasks');
        Schema::dropIfExists('pap_enrichments');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('organization_units');
        Schema::dropIfExists('exercices');
    }
};
