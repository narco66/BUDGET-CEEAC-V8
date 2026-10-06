<?php

namespace App\Domains\Needs\Exports;

use App\Domains\Needs\Models\ExpressionBesoin;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** @implements WithMapping<ExpressionBesoin> */
class ExpressionsBesoinExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, ExpressionBesoin>  $rows
     */
    public function __construct(private readonly Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Référence', 'Objet', 'Ligne', 'Structure', 'Nature', 'Montant FCFA', 'Statut', 'Acteur attendu', 'Engagement'];
    }

    /**
     * @param  ExpressionBesoin  $row
     * @return list<int|string|null>
     */
    public function map($row): array
    {
        return [
            $row->reference,
            $row->objet,
            $row->budgetLine?->code,
            $row->organizationUnit?->structureLabel(),
            $row->nature?->label(),
            $row->montant,
            $row->status?->label(),
            $row->expected_actor_label,
            $row->engagement?->reference,
        ];
    }
}
