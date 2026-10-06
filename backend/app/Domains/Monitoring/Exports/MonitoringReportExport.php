<?php

namespace App\Domains\Monitoring\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class MonitoringReportExport implements FromCollection, WithHeadings
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function __construct(private readonly Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn (array $row) => [
            $row['activite'],
            $row['structure'],
            $row['physique'],
            $row['financier'],
            $row['ecart'],
            $row['finances']['paye'] ?? 0,
        ]);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Activité', 'Structure', 'Physique %', 'Financier %', 'Écart', 'Payé'];
    }
}
