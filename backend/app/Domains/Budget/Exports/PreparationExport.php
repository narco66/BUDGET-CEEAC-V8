<?php

namespace App\Domains\Budget\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PreparationExport implements FromCollection, WithHeadings
{
    /**
     * @param  list<array<string, mixed>>  $lignes
     */
    public function __construct(private readonly array $lignes) {}

    public function collection(): Collection
    {
        return collect($this->lignes)->map(fn (array $ligne): array => [
            $ligne['code'] ?? '',
            $ligne['label'] ?? '',
            $ligne['structure'] ?? '',
            $ligne['classification'] ?? '',
            $ligne['montant'] ?? 0,
            $ligne['montant_retenu'] ?? 0,
            $ligne['activite'] ?? '',
        ]);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Code', 'Libellé', 'Structure', 'Classification', 'Proposé', 'Retenu', 'Activité'];
    }
}
