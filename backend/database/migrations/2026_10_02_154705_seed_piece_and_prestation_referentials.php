<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $pieces = [
            ['expression_besoin', 'tdr', 'TDR', false],
            ['expression_besoin', 'devis', 'Devis', false],
            ['expression_besoin', 'facture_proforma', 'Facture pro forma', false],
            ['expression_besoin', 'specifications', 'Spécifications techniques', false],
            ['expression_besoin', 'note', 'Note justificative', false],
            ['expression_besoin', 'planning', 'Planning', false],
            ['engagement', 'eng_tdr', 'TDR', true],
            ['engagement', 'eng_devis', 'Devis', true],
            ['engagement', 'eng_proforma', 'Facture pro forma', true],
            ['engagement', 'eng_specifications', 'Spécifications techniques', true],
            ['engagement', 'eng_note', 'Note justificative', true],
            ['engagement', 'eng_planning', 'Planning', true],
            ['engagement', 'eng_bdc', 'Bon de commande', true],
        ];

        foreach ($pieces as [$operation, $code, $label, $required]) {
            DB::table('document_types')->insertOrIgnore([
                'code' => $code,
                'label' => $label,
                'operation' => $operation,
                'required' => $required,
                'min_count' => $required ? 1 : 0,
                'max_size_kb' => 10240,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $natures = [
            ['biens', 'Fourniture de biens', 1],
            ['services', 'Prestation de services', 2],
            ['travaux', 'Travaux', 3],
            ['intellectuelle', 'Prestation intellectuelle', 4],
        ];

        foreach ($natures as [$code, $label, $order]) {
            DB::table('reference_values')->insertOrIgnore([
                'set_code' => 'nature_prestation',
                'code' => $code,
                'label' => $label,
                'status' => 'actif',
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('document_types')->whereIn('code', [
            'tdr', 'devis', 'facture_proforma', 'specifications', 'note', 'planning',
            'eng_tdr', 'eng_devis', 'eng_proforma', 'eng_specifications', 'eng_note', 'eng_planning', 'eng_bdc',
        ])->delete();
        DB::table('reference_values')->where('set_code', 'nature_prestation')->delete();
    }
};
