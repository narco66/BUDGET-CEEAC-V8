<?php

namespace App\Domains\Commitments\Exports;

use App\Domains\Commitments\Models\Ordonnancement;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** @implements WithMapping<Ordonnancement> */
class OrdonnancementsExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, Ordonnancement>  $rows
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
        return ['Référence', 'Liquidation', 'Engagement', 'Objet', 'Bénéficiaire', 'Net', 'Ordonnateur', 'Statut', 'Paiement'];
    }

    /**
     * @param  Ordonnancement  $row
     * @return list<int|string|null>
     */
    public function map($row): array
    {
        return [
            $row->reference,
            $row->liquidation?->reference,
            $row->liquidation?->engagement?->reference,
            $row->liquidation?->engagement?->expressionBesoin?->objet,
            $row->liquidation?->fournisseur,
            $row->montant,
            $row->ordonnateur_label,
            $row->status?->label(),
            $row->paiement_reference,
        ];
    }
}
