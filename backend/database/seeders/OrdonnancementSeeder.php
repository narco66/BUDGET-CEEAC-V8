<?php

namespace Database\Seeders;

use App\Domains\Commitments\Enums\OrdonnancementStatus;
use App\Domains\Commitments\Models\OrdDelegation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\OrdSuppleance;
use App\Domains\Commitments\Services\OrdonnancementWorkflow;
use Illuminate\Database\Seeder;

class OrdonnancementSeeder extends Seeder
{
    public function run(): void
    {
        OrdDelegation::query()->firstOrCreate(
            ['document' => 'DEC-2026-014'],
            [
                'delegant' => 'Président de la Commission',
                'delegataire' => 'Secrétaire Général',
                'fonction' => 'Ordonnateur délégué',
                'seuil_max' => 5_000_000,
                'type_depense' => 'Toutes natures',
                'starts_on' => '2026-01-01',
                'ends_on' => '2026-12-31',
                'active' => true,
            ],
        );
        OrdDelegation::query()->firstOrCreate(
            ['document' => 'DEC-2025-011'],
            [
                'delegant' => 'Président de la Commission',
                'delegataire' => 'Secrétaire Général',
                'fonction' => 'Ordonnateur délégué',
                'seuil_max' => 3_000_000,
                'type_depense' => 'Toutes natures',
                'starts_on' => '2025-01-01',
                'ends_on' => '2025-12-31',
                'active' => false,
            ],
        );

        $workflow = app(OrdonnancementWorkflow::class);
        Ordonnancement::query()->whereNull('ordonnateur_role')->each(function (Ordonnancement $ordre) use ($workflow) {
            $authority = $workflow->authority((int) $ordre->montant);
            $ordre->forceFill([
                'status' => $ordre->status ?? OrdonnancementStatus::ASigner,
                'workflow_step' => 'ordonnateur',
                'ordonnateur_role' => $authority['ordonnateur_role'],
                'ordonnateur_label' => $authority['ordonnateur_label'],
                'fondement' => $authority['fondement'],
                'expected_actor_label' => $authority['expected_actor_label'],
                'last_action' => $ordre->last_action ?? 'Présenté à la signature',
                'due_on' => $ordre->due_on ?? now()->addDays(2)->toDateString(),
            ])->save();
        });

        OrdSuppleance::query()->firstOrCreate(
            ['fondement' => 'ACTE-2026-SUP-01'],
            [
                'titulaire' => 'Secrétaire Général',
                'suppleant' => 'Directeur du Budget',
                'starts_on' => '2026-08-04',
                'ends_on' => '2026-08-15',
                'active' => false,
            ],
        );
    }
}
