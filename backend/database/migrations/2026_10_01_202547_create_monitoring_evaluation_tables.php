<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pap_tasks', function (Blueprint $table) {
            $table->unsignedInteger('weight')->default(1);
            $table->decimal('progress_percent', 8, 2)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
        });

        Schema::create('monitoring_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('exercice_year');
            $table->string('code')->unique();
            $table->string('label');
            $table->string('frequency');
            $table->date('opens_on');
            $table->date('closes_on');
            $table->string('status')->default('ouverte');
            $table->timestamps();
        });

        Schema::create('se_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('from_status');
            $table->string('action');
            $table->string('to_status');
            $table->unique(['from_status', 'action']);
        });

        Schema::create('indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pap_enrichment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('type');
            $table->string('gar_level')->nullable();
            $table->string('unit')->nullable();
            $table->string('direction');
            $table->string('aggregation')->default('non_aggregatable');
            $table->unsignedSmallInteger('formula_version')->default(1);
            $table->decimal('baseline_value', 14, 4)->nullable();
            $table->date('baseline_on')->nullable();
            $table->string('source')->nullable();
            $table->string('responsible_role')->nullable();
            $table->string('status')->default('actif');
            $table->timestamps();
        });

        Schema::create('indicator_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitoring_period_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 14, 4);
            $table->timestamps();
            $table->unique(['indicator_id', 'monitoring_period_id']);
        });

        Schema::create('indicator_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitoring_period_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 14, 4);
            $table->string('status')->default('brouillon');
            $table->unsignedSmallInteger('version')->default(1);
            $table->unsignedSmallInteger('formula_version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('indicator_measurements')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->decimal('attainment_rate', 8, 2)->nullable();
            $table->text('comment')->nullable();
            $table->string('source')->nullable();
            $table->string('exception_motif')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('physical_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pap_enrichment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pap_task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('monitoring_period_id')->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('planned', 14, 4)->default(0);
            $table->decimal('progress_percent', 8, 2)->nullable();
            $table->string('status')->default('brouillon');
            $table->text('comment')->nullable();
            $table->text('difficulties')->nullable();
            $table->string('exception_motif')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('performance_variances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pap_enrichment_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->decimal('physical_rate', 8, 2);
            $table->decimal('financial_rate', 8, 2);
            $table->decimal('gap', 8, 2);
            $table->string('cause_category')->nullable();
            $table->text('cause')->nullable();
            $table->text('consequence')->nullable();
            $table->text('comment')->nullable();
            $table->string('responsible_role');
            $table->date('due_on')->nullable();
            $table->string('status')->default('ouvert');
            $table->timestamps();
        });

        Schema::create('corrective_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_variance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pap_enrichment_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->string('responsible_role');
            $table->date('decided_on')->nullable();
            $table->date('due_on')->nullable();
            $table->text('expected_result')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('status')->default('ouverte');
            $table->timestamps();
        });

        Schema::create('se_risks', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('pap_enrichment_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->string('category');
            $table->unsignedTinyInteger('probability');
            $table->unsignedTinyInteger('impact');
            $table->string('responsible_role');
            $table->text('prevention')->nullable();
            $table->text('mitigation')->nullable();
            $table->string('status')->default('ouvert');
            $table->date('reviewed_on')->nullable();
            $table->timestamps();
        });

        Schema::create('se_recommendations', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('origin');
            $table->text('description');
            $table->string('responsible_role');
            $table->date('due_on')->nullable();
            $table->string('priority')->default('normale');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('status')->default('ouverte');
            $table->timestamps();
        });

        Schema::create('se_evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('subject');
            $table->string('scope')->nullable();
            $table->foreignId('monitoring_period_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pap_enrichment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('evaluator_role');
            $table->json('criteria')->nullable();
            $table->text('conclusions')->nullable();
            $table->string('status')->default('brouillon');
            $table->timestamps();
        });

        Schema::create('se_proofs', function (Blueprint $table) {
            $table->id();
            $table->string('proofable_type');
            $table->unsignedBigInteger('proofable_id');
            $table->string('category');
            $table->string('path');
            $table->string('sha256', 64);
            $table->string('confidentiality')->default('interne');
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['proofable_type', 'proofable_id']);
        });

        DB::table('se_transitions')->insert([
            ['from_status' => 'brouillon', 'action' => 'soumettre', 'to_status' => 'soumis'],
            ['from_status' => 'a_corriger', 'action' => 'soumettre', 'to_status' => 'soumis'],
            ['from_status' => 'soumis', 'action' => 'valider', 'to_status' => 'valide'],
            ['from_status' => 'soumis', 'action' => 'rejeter', 'to_status' => 'rejete'],
            ['from_status' => 'soumis', 'action' => 'corriger', 'to_status' => 'a_corriger'],
        ]);

        DB::table('monitoring_periods')->insert([
            'exercice_year' => 2026,
            'code' => '2026-T1',
            'label' => 'Premier trimestre 2026',
            'frequency' => 'trimestrielle',
            'opens_on' => '2026-01-01',
            'closes_on' => '2026-03-31',
            'status' => 'ouverte',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('se_proofs');
        Schema::dropIfExists('se_evaluations');
        Schema::dropIfExists('se_recommendations');
        Schema::dropIfExists('se_risks');
        Schema::dropIfExists('corrective_actions');
        Schema::dropIfExists('performance_variances');
        Schema::dropIfExists('physical_achievements');
        Schema::dropIfExists('indicator_measurements');
        Schema::dropIfExists('indicator_targets');
        Schema::dropIfExists('indicators');
        Schema::dropIfExists('se_transitions');
        Schema::dropIfExists('monitoring_periods');
        Schema::table('pap_tasks', function (Blueprint $table) {
            $table->dropColumn(['weight', 'progress_percent', 'starts_on', 'ends_on', 'actual_start', 'actual_end']);
        });
    }
};
