<?php

namespace Database\Seeders;

use App\Domains\Administration\Models\SodRule;
use Illuminate\Database\Seeder;

class CloisonnementSodSeeder extends Seeder
{
    public function run(): void
    {
        $regles = [
            ['initiateur', 'controleur_financier', 'L’initiateur ne peut pas viser sa propre dépense.'],
            ['initiateur', 'agent_comptable', 'L’initiateur ne peut pas payer sa propre dépense.'],
            ['initiateur', 'comptable', 'L’initiateur ne peut pas préparer le paiement de sa propre dépense.'],
            ['ordonnateur', 'agent_comptable', 'L’ordonnateur ne peut pas exécuter le paiement qu’il autorise.'],
            ['controleur_financier', 'agent_comptable', 'Le contrôleur financier ne peut pas payer les dossiers qu’il vise.'],
        ];

        foreach ($regles as [$roleA, $roleB, $label]) {
            SodRule::query()->firstOrCreate(
                ['role_a' => $roleA, 'role_b' => $roleB],
                ['blocking' => true, 'label' => $label, 'active' => true],
            );
        }
    }
}
