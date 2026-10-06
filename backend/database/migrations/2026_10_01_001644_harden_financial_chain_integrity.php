<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Principe 5 du CDC : aucune suppression silencieuse. Les dossiers de la
 * chaîne ne peuvent plus être supprimés en cascade et les journaux sont
 * protégés en écriture au niveau de la base (PostgreSQL).
 */
return new class extends Migration
{
    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private const RESTRICTED = [
        'engagements' => ['expression_besoin_id', 'expression_besoins'],
        'liquidations' => ['engagement_id', 'engagements'],
        'ordonnancements' => ['liquidation_id', 'liquidations'],
        'paiements' => ['ordonnancement_id', 'ordonnancements'],
        'eb_events' => ['expression_besoin_id', 'expression_besoins'],
        'eng_events' => ['engagement_id', 'engagements'],
        'liq_events' => ['liquidation_id', 'liquidations'],
        'ord_events' => ['ordonnancement_id', 'ordonnancements'],
        'pay_events' => ['paiement_id', 'paiements'],
        'liquidation_rectifications' => ['liquidation_id', 'liquidations'],
        'credit_movements' => ['budget_line_id', 'budget_lines'],
    ];

    /**
     * Journaux append-only : ni mise à jour ni suppression.
     *
     * @var list<string>
     */
    private const APPEND_ONLY = ['audit_events', 'eb_events', 'eng_events', 'liq_events', 'ord_events', 'pay_events'];

    public function up(): void
    {
        foreach (self::RESTRICTED as $table => [$column, $parent]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $parent) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on($parent)->restrictOnDelete();
            });
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gesbudep_forbid_journal_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Journal % en ajout seul : % interdit', TG_TABLE_NAME, TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION gesbudep_forbid_execution_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Une exécution de paiement ne se supprime pas : elle se rejette';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        foreach (self::APPEND_ONLY as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION gesbudep_forbid_journal_change();");
        }
        DB::unprepared('CREATE TRIGGER paiement_executions_no_delete BEFORE DELETE ON paiement_executions FOR EACH ROW EXECUTE FUNCTION gesbudep_forbid_execution_delete();');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::APPEND_ONLY as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table};");
            }
            DB::unprepared('DROP TRIGGER IF EXISTS paiement_executions_no_delete ON paiement_executions;');
            DB::unprepared('DROP FUNCTION IF EXISTS gesbudep_forbid_journal_change(); DROP FUNCTION IF EXISTS gesbudep_forbid_execution_delete();');
        }

        foreach (self::RESTRICTED as $table => [$column, $parent]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $parent) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on($parent)->cascadeOnDelete();
            });
        }
    }
};
