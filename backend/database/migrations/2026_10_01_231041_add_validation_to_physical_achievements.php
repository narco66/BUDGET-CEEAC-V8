<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Description S&E §56-58 et §98 : une réalisation physique suit le même
 * circuit qu’une mesure d’indicateur ; une valeur validée n’est plus
 * modifiable ni supprimable, elle se rectifie par une nouvelle version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('physical_achievements', function (Blueprint $table) {
            $table->foreignId('validator_id')->nullable()->after('author_id')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('physical_achievements')->restrictOnDelete();
            $table->string('rejection_motif')->nullable();
        });

        Schema::table('indicator_measurements', function (Blueprint $table) {
            $table->string('rejection_motif')->nullable();
        });

        foreach ([
            ['indicator_measurements', 'indicator_id', 'indicators'],
            ['indicator_measurements', 'monitoring_period_id', 'monitoring_periods'],
            ['physical_achievements', 'pap_enrichment_id', 'pap_enrichments'],
            ['physical_achievements', 'monitoring_period_id', 'monitoring_periods'],
        ] as [$table, $column, $parent]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $parent) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on($parent)->restrictOnDelete();
            });
        }

        // L’avancement des tâches ne reflète désormais que des réalisations validées.
        DB::table('pap_tasks')->update(['progress_percent' => null]);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gesbudep_protect_validated_se() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('valide', 'consolide') THEN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Donnée S&E validée : suppression interdite (%)', TG_TABLE_NAME;
                    END IF;
                    IF (to_jsonb(NEW) - ARRAY['status', 'superseded_at', 'updated_at'])
                        IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status', 'superseded_at', 'updated_at']) THEN
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

            CREATE TRIGGER indicator_measurements_protect_validated BEFORE UPDATE OR DELETE ON indicator_measurements
                FOR EACH ROW EXECUTE FUNCTION gesbudep_protect_validated_se();
            CREATE TRIGGER physical_achievements_protect_validated BEFORE UPDATE OR DELETE ON physical_achievements
                FOR EACH ROW EXECUTE FUNCTION gesbudep_protect_validated_se();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS indicator_measurements_protect_validated ON indicator_measurements;
                DROP TRIGGER IF EXISTS physical_achievements_protect_validated ON physical_achievements;
                DROP FUNCTION IF EXISTS gesbudep_protect_validated_se();
            SQL);
        }

        foreach ([
            ['indicator_measurements', 'indicator_id', 'indicators'],
            ['indicator_measurements', 'monitoring_period_id', 'monitoring_periods'],
            ['physical_achievements', 'pap_enrichment_id', 'pap_enrichments'],
            ['physical_achievements', 'monitoring_period_id', 'monitoring_periods'],
        ] as [$table, $column, $parent]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $parent) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on($parent)->cascadeOnDelete();
            });
        }

        Schema::table('indicator_measurements', function (Blueprint $table) {
            $table->dropColumn('rejection_motif');
        });
        Schema::table('physical_achievements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supersedes_id');
            $table->dropConstrainedForeignId('validator_id');
            $table->dropColumn(['submitted_at', 'validated_at', 'superseded_at', 'rejection_motif']);
        });
    }
};
