<?php

namespace App\Domains\Commitments\Exports;

use App\Domains\Commitments\Models\Paiement;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** @implements WithMapping<Paiement> */
class PaiementsExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, Paiement>  $rows
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
        return ['Référence', 'Ordonnancement', 'Bénéficiaire', 'Objet', 'Ordonnancé', 'Payé', 'Reste', 'Mode', 'Statut'];
    }

    /**
     * @param  Paiement  $row
     * @return list<int|string|null>
     */
    public function map($row): array
    {
        $eb = $row->ordonnancement?->liquidation?->engagement?->expressionBesoin;

        return [
            $row->reference,
            $row->ordonnancement?->reference,
            $row->ordonnancement?->liquidation?->fournisseur,
            $eb?->objet,
            $row->montant,
            $row->montant_paye,
            $row->reste(),
            $row->mode,
            $row->status?->label(),
        ];
    }
}
