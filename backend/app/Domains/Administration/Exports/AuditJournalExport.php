<?php

namespace App\Domains\Administration\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class AuditJournalExport implements FromCollection, WithHeadings
{
    /**
     * @param  list<array<string, mixed>>  $lignes
     */
    public function __construct(private readonly array $lignes, private readonly string $fuseau) {}

    public function collection(): Collection
    {
        return collect($this->lignes)->map(fn (array $ligne): array => [
            $ligne['quand'] ?? '',
            $this->fuseau,
            $ligne['acteur'] ?? '',
            $ligne['role'] ?? '',
            $ligne['module'] ?? '',
            $ligne['action'] ?? '',
            $ligne['objet'] ?? '',
            $ligne['objet_id'] ?? '',
            $ligne['reference'] ?? '',
            $ligne['resultat'] ?? '',
            $ligne['motif'] ?? '',
        ]);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Quand', 'Fuseau', 'Acteur', 'Rôle', 'Module', 'Action', 'Objet', 'Objet id', 'Référence', 'Résultat', 'Motif'];
    }
}
