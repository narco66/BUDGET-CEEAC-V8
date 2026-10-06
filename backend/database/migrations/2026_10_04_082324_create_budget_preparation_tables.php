<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('label');
            $table->foreignId('exercice_id')->constrained('exercices')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->string('perimetre')->nullable();
            $table->date('date_ouverture')->nullable();
            $table->date('date_cloture')->nullable();
            $table->string('devise', 8)->default('XAF');
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('version_cadrage')->default(1);
            $table->string('statut', 32)->default('brouillon');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['exercice_id', 'statut']);
        });

        Schema::create('budget_campaign_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('budget_campaigns')->cascadeOnDelete();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'organization_unit_id'], 'budget_campaign_units_unique');
        });

        Schema::create('budget_campaign_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('budget_campaigns')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre');
            $table->string('label');
            $table->text('description')->nullable();
            $table->date('debut')->nullable();
            $table->date('echeance')->nullable();
            $table->string('acteurs')->nullable();
            $table->string('structures')->nullable();
            $table->text('prerequis')->nullable();
            $table->text('livrables')->nullable();
            $table->boolean('verrouillee')->default(false);
            $table->timestamp('alerte_echeance_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'ordre']);
        });

        Schema::create('budget_hypotheses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('budget_campaigns')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('label');
            $table->string('categorie', 64);
            $table->string('valeur')->nullable();
            $table->string('unite', 32)->nullable();
            $table->string('periode', 64)->nullable();
            $table->string('source')->nullable();
            $table->text('justification')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('remplace_id')->nullable()->constrained('budget_hypotheses')->nullOnDelete();
            $table->string('statut', 32)->default('brouillon');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'code', 'version']);
        });

        Schema::create('budget_orientations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('budget_campaigns')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('label');
            $table->string('categorie', 64);
            $table->text('instructions')->nullable();
            $table->string('periode', 64)->nullable();
            $table->string('source')->nullable();
            $table->text('justification')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('remplace_id')->nullable()->constrained('budget_orientations')->nullOnDelete();
            $table->string('statut', 32)->default('brouillon');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'code', 'version']);
        });

        Schema::create('budget_envelopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('budget_campaigns')->restrictOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('budget_envelopes')->restrictOnDelete();
            $table->string('classification', 32);
            $table->string('perimetre')->nullable();
            $table->unsignedBigInteger('montant')->default(0);
            $table->string('devise', 8)->default('XAF');
            $table->text('justification')->nullable();
            $table->date('date_effet')->nullable();
            $table->string('statut', 32)->default('brouillon');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['campaign_id', 'organization_unit_id', 'classification', 'version'],
                'budget_envelopes_scope_unique',
            );
        });

        Schema::create('budget_dossiers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('campaign_id')->constrained('budget_campaigns')->restrictOnDelete();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->string('titre');
            $table->text('description')->nullable();
            $table->text('justification')->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('statut', 32)->default('brouillon');
            $table->text('observations')->nullable();
            $table->text('retour_motif')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('budget_dossiers')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'statut']);
        });

        Schema::create('budget_dossier_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dossier_id')->constrained('budget_dossiers')->cascadeOnDelete();
            $table->string('classification', 32);
            $table->string('code', 32);
            $table->string('nature', 16);
            $table->string('label');
            $table->text('description')->nullable();
            $table->text('justification')->nullable();
            $table->decimal('quantite', 12, 2)->default(1);
            $table->string('unite', 32)->nullable();
            $table->unsignedBigInteger('cout_unitaire')->default(0);
            $table->unsignedBigInteger('montant')->default(0);
            $table->unsignedBigInteger('montant_retenu')->nullable();
            $table->string('mode', 16)->default('direct');
            $table->foreignId('gar_node_id')->nullable()->constrained('gar_nodes')->nullOnDelete();
            $table->string('periode', 64)->nullable();
            $table->text('observations')->nullable();
            $table->timestamps();

            $table->index(['dossier_id', 'classification']);
        });

        Schema::create('budget_line_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_id')->constrained('budget_dossier_lines')->cascadeOnDelete();
            $table->string('designation');
            $table->foreignId('gar_node_id')->nullable()->constrained('gar_nodes')->nullOnDelete();
            $table->decimal('quantite', 12, 2)->default(1);
            $table->string('unite', 32)->nullable();
            $table->unsignedBigInteger('cout_unitaire')->default(0);
            $table->unsignedBigInteger('montant')->default(0);
            $table->text('justification')->nullable();
            $table->foreignId('hypothese_id')->nullable()->constrained('budget_hypotheses')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('budget_line_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_id')->constrained('budget_dossier_lines')->cascadeOnDelete();
            $table->string('periode', 32);
            $table->unsignedBigInteger('montant')->default(0);
            $table->timestamps();

            $table->unique(['line_id', 'periode']);
        });

        Schema::create('budget_line_fundings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_id')->constrained('budget_dossier_lines')->cascadeOnDelete();
            $table->foreignId('revenue_category_id')->nullable()->constrained('revenue_categories')->nullOnDelete();
            $table->string('source');
            $table->unsignedBigInteger('montant')->default(0);
            $table->timestamps();
        });

        Schema::create('budget_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('budget_campaigns')->restrictOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->string('libelle');
            $table->string('statut', 32)->default('travail');
            $table->json('snapshot');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('motif')->nullable();
            $table->timestamp('transmise_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'numero']);
        });

        Schema::create('budget_version_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('budget_versions')->cascadeOnDelete();
            $table->foreignId('forecast_id')->nullable()->constrained('revenue_forecasts')->nullOnDelete();
            $table->string('code', 32);
            $table->string('label');
            $table->unsignedBigInteger('montant')->default(0);
            $table->timestamps();
        });

        Schema::create('budget_arbitrages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('budget_campaigns')->restrictOnDelete();
            $table->foreignId('dossier_id')->nullable()->constrained('budget_dossiers')->restrictOnDelete();
            $table->foreignId('line_id')->nullable()->constrained('budget_dossier_lines')->restrictOnDelete();
            $table->foreignId('cible_id')->nullable()->constrained('budget_arbitrages')->nullOnDelete();
            $table->unsignedBigInteger('montant_demande')->default(0);
            $table->unsignedBigInteger('montant_retenu')->default(0);
            $table->string('decision', 32);
            $table->text('motif');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('budget_versions')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('budget_pieces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained('budget_campaigns')->cascadeOnDelete();
            $table->foreignId('dossier_id')->nullable()->constrained('budget_dossiers')->cascadeOnDelete();
            $table->foreignId('line_id')->nullable()->constrained('budget_dossier_lines')->cascadeOnDelete();
            $table->foreignId('arbitrage_id')->nullable()->constrained('budget_arbitrages')->nullOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('budget_versions')->nullOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('remplace_id')->nullable()->constrained('budget_pieces')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retiree_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_pieces');
        Schema::dropIfExists('budget_arbitrages');
        Schema::dropIfExists('budget_version_forecasts');
        Schema::dropIfExists('budget_versions');
        Schema::dropIfExists('budget_line_fundings');
        Schema::dropIfExists('budget_line_periods');
        Schema::dropIfExists('budget_line_details');
        Schema::dropIfExists('budget_dossier_lines');
        Schema::dropIfExists('budget_dossiers');
        Schema::dropIfExists('budget_envelopes');
        Schema::dropIfExists('budget_orientations');
        Schema::dropIfExists('budget_hypotheses');
        Schema::dropIfExists('budget_campaign_steps');
        Schema::dropIfExists('budget_campaign_units');
        Schema::dropIfExists('budget_campaigns');
    }
};
