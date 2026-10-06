<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gar_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercice_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->string('statut', 32)->default('brouillon');
            $table->date('effective_on')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('justification')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('source_version_id')->nullable()->constrained('gar_versions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['exercice_id', 'numero']);
        });

        Schema::table('exercices', function (Blueprint $table) {
            $table->foreignId('gar_version_id')->nullable()->after('statut')->constrained('gar_versions')->nullOnDelete();
        });

        Schema::create('gar_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gar_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('gar_nodes')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('code', 32);
            $table->string('libelle');
            $table->unsignedSmallInteger('position')->default(1);
            $table->text('description')->nullable();
            $table->text('objectifs')->nullable();
            $table->text('resultats_attendus')->nullable();
            $table->foreignId('organization_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('unite_responsable')->nullable();
            $table->string('periode')->nullable();
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->string('indicateur')->nullable();
            $table->string('unite_mesure')->nullable();
            $table->string('cible')->nullable();
            $table->unsignedBigInteger('enveloppe')->default(0);
            $table->string('statut', 32)->default('brouillon');
            $table->foreignId('pap_enrichment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pap_task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('budget_line_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['gar_version_id', 'code']);
            $table->index(['gar_version_id', 'parent_id', 'position']);
        });

        Schema::create('gar_node_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gar_node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_unit_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['gar_node_id', 'organization_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::table('exercices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gar_version_id');
        });
        Schema::dropIfExists('gar_node_units');
        Schema::dropIfExists('gar_nodes');
        Schema::dropIfExists('gar_versions');
    }
};
