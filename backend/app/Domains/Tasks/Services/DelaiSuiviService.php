<?php

namespace App\Domains\Tasks\Services;

use App\Domains\Tasks\Models\WorkflowTask;
use Illuminate\Support\Facades\DB;

class DelaiSuiviService
{
    /**
     * Compare les tâches ouvertes aux règles enregistrées dans sla_rules.
     * Sans règle, aucun dépassement n’est calculé.
     *
     * @return array<string, mixed>
     */
    public function portrait(): array
    {
        $regles = DB::table('sla_rules')->get();
        $taches = WorkflowTask::query()
            ->where('status', '!=', 'terminee')
            ->get(['id', 'reference', 'module', 'step', 'dossier_reference', 'created_at']);
        $dans = 0;
        $depassements = 0;
        $sans = 0;
        $exemples = [];
        foreach ($taches as $tache) {
            $regle = $regles->first(fn ($regle) => $regle->module === $tache->module && $regle->step === $tache->step);
            if ($regle === null) {
                $sans++;

                continue;
            }
            $heures = (int) $tache->created_at?->diffInHours(now());
            if ($heures > (int) $regle->target_hours) {
                $depassements++;
                if (count($exemples) < 30) {
                    $exemples[] = [
                        'reference' => $tache->dossier_reference,
                        'tache' => $tache->reference,
                        'module' => $tache->module,
                        'etape' => $tache->step,
                        'heures' => $heures,
                        'cible' => (int) $regle->target_hours,
                    ];
                }
            } else {
                $dans++;
            }
        }

        return [
            'regles' => $regles->count(),
            'dans_le_delai' => $dans,
            'depassements' => $depassements,
            'sans_regle' => $sans,
            'exemples' => $exemples,
            'precision' => $regles->isEmpty()
                ? 'Aucune règle de délai n’est enregistrée. Aucun dépassement n’est calculé.'
                : 'Un dépassement compare l’âge d’une tâche ouverte au délai cible de la règle de même module et de même étape.',
        ];
    }
}
