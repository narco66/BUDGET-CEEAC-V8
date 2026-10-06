<?php

namespace App\Domains\Commitments\Exports;

use App\Domains\Commitments\Models\Engagement;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** @implements WithMapping<Engagement> */
class EngagementsExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, Engagement>  $rows
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
        return ['Référence', 'EB source', 'Objet', 'Ligne', 'Bénéficiaire', 'Montant FCFA', 'Statut', 'Étape', 'Visa', 'Liquidation'];
    }

    /**
     * @param  Engagement  $row
     * @return list<int|string|null>
     */
    public function map($row): array
    {
        return [
            $row->reference,
            $row->expressionBesoin?->reference,
            $row->expressionBesoin?->objet,
            $row->budgetLine?->code,
            $row->beneficiary_name,
            $row->montant,
            $row->status?->label(),
            $row->expected_actor_label,
            $row->visa_reference,
            $row->liquidation_reference,
        ];
    }
}
