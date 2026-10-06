<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Données requises par les écrans de docs/maquette-SE qui n’existaient pas :
 * code et responsable d’activité, planning initial des tâches, jalons,
 * révisions de planning, circuit de validation à quatre niveaux, indicateurs
 * ratio, explication et suivi des écarts, problèmes, décisions de revue.
 * Aucune valeur historique n’est inventée : les champs inconnus restent vides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pap_enrichments', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->unique();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
            $table->string('se_status', 20)->nullable();
            $table->string('se_status_motif')->nullable();
        });

        Schema::table('pap_tasks', function (Blueprint $table) {
            $table->string('code', 16)->nullable();
            $table->string('unit', 60)->nullable();
            $table->decimal('planned_quantity', 14, 4)->nullable();
            $table->string('responsible_label', 120)->nullable();
            $table->date('baseline_starts_on')->nullable();
            $table->date('baseline_ends_on')->nullable();
        });

        Schema::table('indicators', function (Blueprint $table) {
            $table->string('frequency', 20)->nullable();
            $table->string('numerator_label')->nullable();
            $table->string('denominator_label')->nullable();
        });

        foreach (['indicator_measurements', 'physical_achievements'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                if ($name === 'indicator_measurements') {
                    $table->decimal('numerator', 14, 4)->nullable();
                    $table->decimal('denominator', 14, 4)->nullable();
                    $table->string('justification')->nullable();
                }
                $table->foreignId('responsible_validator_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('responsible_validated_at')->nullable();
                $table->foreignId('consolidated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('consolidated_at')->nullable();
            });
        }

        Schema::create('se_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pap_enrichment_id')->constrained()->restrictOnDelete();
            $table->foreignId('pap_task_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('label');
            $table->date('planned_on');
            $table->date('achieved_on')->nullable();
            $table->string('proof_label')->nullable();
            $table->string('responsible_label', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('se_planning_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pap_enrichment_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('proposee');
            $table->text('motif');
            $table->json('tasks');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_motif')->nullable();
            $table->timestamps();
            $table->unique(['pap_enrichment_id', 'version']);
        });

        Schema::table('performance_variances', function (Blueprint $table) {
            $table->string('reference', 32)->nullable()->unique();
            $table->timestamp('explanation_requested_at')->nullable();
            $table->timestamp('explanation_received_at')->nullable();
            $table->text('explanation')->nullable();
            $table->foreignId('explained_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('interpretations')->nullable();
            $table->json('cause_categories')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->string('reported_in')->nullable();
        });

        Schema::create('se_problems', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('pap_enrichment_id')->constrained()->restrictOnDelete();
            $table->foreignId('performance_variance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('se_risk_id')->nullable()->constrained('se_risks')->nullOnDelete();
            $table->string('nature');
            $table->text('impact')->nullable();
            $table->date('occurred_on');
            $table->string('status', 16)->default('ouvert');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('corrective_actions', function (Blueprint $table) {
            $table->string('reference', 32)->nullable()->unique();
            $table->text('anomaly')->nullable();
            $table->text('cause')->nullable();
            $table->string('responsible_label', 160)->nullable();
            $table->foreignId('se_problem_id')->nullable()->constrained('se_problems')->nullOnDelete();
        });

        Schema::create('se_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('pap_enrichment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('performance_report_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->string('responsible_label', 160);
            $table->date('due_on')->nullable();
            $table->string('priority', 16)->default('moyenne');
            $table->string('status', 20)->default('attendue');
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Circuit de la maquette : saisie → validation responsable → validation hiérarchique → consolidation.
        DB::table('se_transitions')->where('from_status', 'soumis')->where('action', 'valider')->delete();
        DB::table('se_transitions')->insert([
            ['from_status' => 'soumis', 'action' => 'valider', 'to_status' => 'valide_responsable'],
            ['from_status' => 'valide_responsable', 'action' => 'valider', 'to_status' => 'valide'],
            ['from_status' => 'valide_responsable', 'action' => 'rejeter', 'to_status' => 'rejete'],
            ['from_status' => 'valide_responsable', 'action' => 'corriger', 'to_status' => 'a_corriger'],
            ['from_status' => 'valide', 'action' => 'consolider', 'to_status' => 'consolide'],
        ]);

        // La consolidation horodate une valeur déjà validée : seuls ces deux champs s’ajoutent aux champs modifiables.
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION gesbudep_protect_validated_se() RETURNS trigger AS $$
                DECLARE
                    mutable text[] := ARRAY['status', 'superseded_at', 'updated_at', 'consolidated_by', 'consolidated_at'];
                BEGIN
                    IF OLD.status IN ('valide', 'consolide') THEN
                        IF TG_OP = 'DELETE' THEN
                            RAISE EXCEPTION 'Donnée S&E validée : suppression interdite (%)', TG_TABLE_NAME;
                        END IF;
                        IF (to_jsonb(NEW) - mutable) IS DISTINCT FROM (to_jsonb(OLD) - mutable) THEN
                            RAISE EXCEPTION 'Donnée S&E validée : modification interdite, utilisez une rectification (%)', TG_TABLE_NAME;
                        END IF;
                        IF NEW.status NOT IN ('valide', 'consolide') THEN
                            RAISE EXCEPTION 'Donnée S&E validée : retour à un statut antérieur interdit (%)', TG_TABLE_NAME;
                        END IF;
                    END IF;
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
        }

        // Échelle de la matrice des risques de la maquette : 1 à 4.
        DB::table('se_risks')->where('probability', '>', 4)->update(['probability' => 4]);
        DB::table('se_risks')->where('impact', '>', 4)->update(['impact' => 4]);

        // Planning initial = planning connu à la mise en place ; codes de tâche T1, T2…
        DB::table('pap_tasks')->orderBy('id')->get(['id', 'position', 'starts_on', 'ends_on'])->each(function (object $task): void {
            DB::table('pap_tasks')->where('id', $task->id)->update([
                'code' => 'T'.$task->position,
                'baseline_starts_on' => $task->starts_on,
                'baseline_ends_on' => $task->ends_on,
            ]);
        });

        // Code d’activité ACT-{structure}-{exercice}-{rang}.
        $rows = DB::table('pap_enrichments')
            ->join('budget_lines', 'budget_lines.id', '=', 'pap_enrichments.budget_line_id')
            ->leftJoin('organization_units', 'organization_units.id', '=', 'budget_lines.organization_unit_id')
            ->leftJoin('exercices', 'exercices.id', '=', 'budget_lines.exercice_id')
            ->orderBy('pap_enrichments.id')
            ->get(['pap_enrichments.id', 'organization_units.sigle', 'exercices.annee']);
        $ranks = [];
        foreach ($rows as $row) {
            $key = ($row->sigle ?? 'CEEAC').'-'.($row->annee ?? date('Y'));
            $ranks[$key] = ($ranks[$key] ?? 0) + 1;
            DB::table('pap_enrichments')->where('id', $row->id)->update([
                'code' => sprintf('ACT-%s-%02d', $key, $ranks[$key]),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('se_transitions')->whereIn('to_status', ['valide_responsable', 'consolide'])->delete();
        DB::table('se_transitions')->where('from_status', 'valide_responsable')->delete();
        DB::table('se_transitions')->insert(['from_status' => 'soumis', 'action' => 'valider', 'to_status' => 'valide']);

        Schema::dropIfExists('se_decisions');
        Schema::table('corrective_actions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('se_problem_id');
            $table->dropUnique(['reference']);
            $table->dropColumn(['reference', 'anomaly', 'cause', 'responsible_label']);
        });
        Schema::dropIfExists('se_problems');
        Schema::table('performance_variances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('explained_by');
            $table->dropUnique(['reference']);
            $table->dropColumn(['reference', 'explanation_requested_at', 'explanation_received_at', 'explanation', 'interpretations', 'cause_categories', 'reminded_at', 'escalated_at', 'reported_in']);
        });
        Schema::dropIfExists('se_planning_revisions');
        Schema::dropIfExists('se_milestones');
        foreach (['indicator_measurements', 'physical_achievements'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropConstrainedForeignId('responsible_validator_id');
                $table->dropConstrainedForeignId('consolidated_by');
                $table->dropColumn(['responsible_validated_at', 'consolidated_at']);
                if ($name === 'indicator_measurements') {
                    $table->dropColumn(['numerator', 'denominator', 'justification']);
                }
            });
        }
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropColumn(['frequency', 'numerator_label', 'denominator_label']);
        });
        Schema::table('pap_tasks', function (Blueprint $table) {
            $table->dropColumn(['code', 'unit', 'planned_quantity', 'responsible_label', 'baseline_starts_on', 'baseline_ends_on']);
        });
        Schema::table('pap_enrichments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_user_id');
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'actual_start', 'actual_end', 'se_status', 'se_status_motif']);
        });
    }
};
