<?php

namespace App\Domains\Administration\Services;

use Illuminate\Support\Facades\DB;

/**
 * Lecture des référentiels administrables. Les libellés affichés dans les
 * formulaires viennent de PostgreSQL, pas de listes figées dans React.
 */
class ReferentialReader
{
    /**
     * @return list<string>
     */
    public function documentLabels(string $operation): array
    {
        return DB::table('document_types')
            ->where('operation', $operation)
            ->where('active', true)
            ->orderBy('label')
            ->pluck('label')
            ->all();
    }

    /**
     * @return list<string>
     */
    public function valueLabels(string $set): array
    {
        return DB::table('reference_values')
            ->where('set_code', $set)
            ->where('status', 'actif')
            ->orderBy('sort_order')
            ->pluck('label')
            ->all();
    }
}
