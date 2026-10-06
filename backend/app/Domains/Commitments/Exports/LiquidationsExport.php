<?php

namespace App\Domains\Commitments\Exports;

use App\Domains\Commitments\Models\Liquidation;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** @implements WithMapping<Liquidation> */
class LiquidationsExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, Liquidation>  $rows
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
        return ['Référence', 'Engagement', 'Objet', 'Fournisseur', 'Brut', 'Net', 'Service fait', 'Statut', 'Ordonnancement'];
    }

    /**
     * @param  Liquidation  $row
     * @return list<int|string|null>
     */
    public function map($row): array
    {
        return [
            $row->reference,
            $row->engagement?->reference,
            $row->engagement?->expressionBesoin?->objet,
            $row->fournisseur,
            $row->montant_brut,
            $row->montant_net,
            $row->serviceFaitLabel(),
            $row->status?->label(),
            $row->ordonnancement_reference,
        ];
    }
}
