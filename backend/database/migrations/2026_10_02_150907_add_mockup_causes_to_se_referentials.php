<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Causes visibles sur la maquette de l’écart, en plus de celles déjà administrées.
     */
    public function up(): void
    {
        $now = now();
        foreach ([
            'contractuelle' => 'Contractuelle',
            'rh' => 'RH',
            'fournisseur' => 'Fournisseur',
            'autre' => 'Autre',
        ] as $code => $label) {
            $exists = DB::table('se_referentials')->where('kind', 'cause')->where('code', $code)->exists();
            if ($exists) {
                continue;
            }
            DB::table('se_referentials')->insert([
                'kind' => 'cause',
                'code' => $code,
                'label' => $label,
                'weight' => null,
                'formula_version' => 1,
                'effective_on' => '2026-01-01',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('se_referentials')->where('kind', 'cause')->whereIn('code', ['contractuelle', 'rh', 'fournisseur', 'autre'])->delete();
    }
};
