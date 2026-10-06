<?php

namespace App\Domains\Monitoring\Support;

use Illuminate\Support\Facades\DB;

/**
 * Références lisibles des objets S&E (EC-2026-001, PB-2026-012,
 * AC-2026-031, DEC-2026-003), séquencées par année. À appeler dans une
 * transaction : la dernière référence est relue sous verrou.
 */
final class SeReference
{
    /**
     * @var array<string, string>
     */
    private const TABLES = [
        'EC' => 'performance_variances',
        'PB' => 'se_problems',
        'AC' => 'corrective_actions',
        'DEC' => 'se_decisions',
    ];

    public static function next(string $prefix): string
    {
        $year = now()->year;
        $last = DB::table(self::TABLES[$prefix])
            ->where('reference', 'like', $prefix.'-'.$year.'-%')
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference');

        return sprintf('%s-%d-%03d', $prefix, $year, $last ? ((int) substr((string) $last, -3)) + 1 : 1);
    }
}
