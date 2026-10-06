<?php

namespace App\Domains\Procurement\Services;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Procurement\Models\Marche;
use App\Domains\Suppliers\Models\Tiers;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Support\NumberingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarcheService
{
    public const PROCEDURES = ['appel_offres', 'consultation', 'gre_a_gre', 'contrat'];

    /**
     * Tenue du registre : le Budget et l’administration fonctionnelle, comme pour le référentiel des tiers.
     *
     * @var list<string>
     */
    public const EDITEURS = ['expert_budget', 'directeur_budget', 'administrateur_fonctionnel'];

    public const STATUTS = ['projet', 'notifie', 'en_execution', 'clos', 'resilie'];

    /**
     * Cycle de vie : projet → notifié → en exécution → clos ; la résiliation
     * reste possible tant que le marché n’est pas clos.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'projet' => ['notifie', 'resilie'],
        'notifie' => ['en_execution', 'resilie'],
        'en_execution' => ['clos', 'resilie'],
        'clos' => [],
        'resilie' => [],
    ];

    public function creer(User $actor, array $data): Marche
    {
        $this->assertEditor($actor);
        $exercice = Exercice::query()->find($data['exercice_id']);
        if ($exercice === null || ! $exercice->isOpen()) {
            throw ValidationException::withMessages(['exercice_id' => 'Le marché se rattache à un exercice ouvert.']);
        }
        if (! in_array($data['procedure'], self::PROCEDURES, true)) {
            throw ValidationException::withMessages(['procedure' => 'Procédure inconnue.']);
        }
        $tiers = $this->titulaire($data['tiers_id'] ?? null);

        return DB::transaction(function () use ($actor, $exercice, $data, $tiers): Marche {
            $marche = Marche::query()->create([
                'exercice_id' => $exercice->id,
                'reference' => app(NumberingService::class)->nextMarche((int) $exercice->annee),
                'objet' => $data['objet'],
                'tiers_id' => $tiers?->id,
                'montant' => (int) $data['montant'],
                'procedure' => $data['procedure'],
                'statut' => 'projet',
                'created_by' => $actor->id,
            ]);
            FinancialAudit::record($actor, 'marche.cree', 'marche', (string) $marche->id, null, ['reference' => $marche->reference]);

            return $marche;
        });
    }

    /**
     * Le contenu d’un marché (objet, titulaire, montant, procédure) ne se
     * modifie qu’au stade de projet ; ensuite, seul le statut évolue.
     *
     * @param  array{objet: string, montant: int, procedure: string, tiers_id?: int|null}  $data
     */
    public function modifier(User $actor, Marche $marche, array $data): Marche
    {
        $this->assertEditor($actor);
        if ($marche->statut !== 'projet') {
            throw ValidationException::withMessages(['statut' => 'Seul un marché au stade de projet se modifie. Après notification, utilisez le changement de statut.']);
        }
        if (! in_array($data['procedure'], self::PROCEDURES, true)) {
            throw ValidationException::withMessages(['procedure' => 'Procédure inconnue.']);
        }
        $tiers = $this->titulaire($data['tiers_id'] ?? null);

        return DB::transaction(function () use ($actor, $marche, $data, $tiers): Marche {
            $fields = ['objet', 'montant', 'procedure', 'tiers_id'];
            $before = $marche->only($fields);
            $marche->forceFill([
                'objet' => $data['objet'],
                'montant' => (int) $data['montant'],
                'procedure' => $data['procedure'],
                'tiers_id' => $tiers?->id,
            ])->save();
            FinancialAudit::record($actor, 'marche.modifie', 'marche', (string) $marche->id, $before, $marche->only($fields));

            return $marche->fresh(['tiers', 'engagement', 'exercice']);
        });
    }

    public function changerStatut(User $actor, Marche $marche, string $statut, ?string $motif, ?string $notifieLe): Marche
    {
        $this->assertEditor($actor);
        if (! in_array($statut, self::TRANSITIONS[$marche->statut] ?? [], true)) {
            throw ValidationException::withMessages(['statut' => 'Ce marché ne peut pas passer de « '.$marche->statut.' » à « '.$statut.' ».']);
        }
        if ($statut === 'resilie' && blank($motif)) {
            throw ValidationException::withMessages(['motif' => 'La résiliation exige un motif.']);
        }
        if ($statut === 'notifie' && blank($notifieLe) && $marche->notified_on === null) {
            throw ValidationException::withMessages(['notified_on' => 'Indiquez la date de notification au titulaire.']);
        }

        return DB::transaction(function () use ($actor, $marche, $statut, $motif, $notifieLe): Marche {
            $avant = $marche->statut;
            $marche->forceFill([
                'statut' => $statut,
                'notified_on' => $statut === 'notifie' && filled($notifieLe) ? $notifieLe : $marche->notified_on,
            ])->save();
            FinancialAudit::record($actor, 'marche.statut', 'marche', (string) $marche->id, ['statut' => $avant], ['statut' => $statut], $motif);

            return $marche->fresh(['tiers', 'engagement', 'exercice']);
        });
    }

    /**
     * Un marché ne se supprime qu’au stade de projet et sans engagement rattaché ;
     * au-delà, il se résilie.
     */
    public function supprimer(User $actor, Marche $marche): void
    {
        $this->assertEditor($actor);
        if ($marche->statut !== 'projet' || $marche->engagement_id !== null) {
            throw ValidationException::withMessages(['statut' => 'Seul un marché au stade de projet, sans engagement rattaché, se supprime. Sinon, résiliez-le.']);
        }

        DB::transaction(function () use ($actor, $marche): void {
            FinancialAudit::record($actor, 'marche.supprime', 'marche', (string) $marche->id, $marche->only(['reference', 'objet', 'montant']), null);
            $marche->delete();
        });
    }

    public function peutModifier(User $actor): bool
    {
        return $actor->holds(...self::EDITEURS);
    }

    public function rattacher(Marche $marche, Engagement $engagement, User $actor): Marche
    {
        $this->assertEditor($actor);
        if ($marche->engagement_id !== null && (int) $marche->engagement_id !== (int) $engagement->id) {
            throw ValidationException::withMessages(['engagement_id' => 'Ce marché est déjà rattaché à un engagement.']);
        }
        $autre = Marche::query()->where('engagement_id', $engagement->id)->whereKeyNot($marche->id)->exists();
        if ($autre) {
            throw ValidationException::withMessages(['engagement_id' => 'Cet engagement a déjà un marché.']);
        }
        $exerciceId = $engagement->expressionBesoin?->exercice_id;
        if ((int) $exerciceId !== (int) $marche->exercice_id) {
            throw ValidationException::withMessages(['engagement_id' => 'L’engagement et le marché doivent relever du même exercice.']);
        }
        $marche->forceFill(['engagement_id' => $engagement->id, 'statut' => $marche->statut === 'projet' ? 'notifie' : $marche->statut])->save();
        FinancialAudit::record($actor, 'marche.rattache', 'marche', (string) $marche->id, null, ['engagement' => $engagement->reference]);

        return $marche->fresh(['tiers']);
    }

    private function titulaire(mixed $tiersId): ?Tiers
    {
        if ($tiersId === null || $tiersId === '') {
            return null;
        }
        $tiers = Tiers::query()->find($tiersId);
        if ($tiers === null || $tiers->status !== 'actif') {
            throw ValidationException::withMessages(['tiers_id' => 'Le titulaire doit être un tiers actif.']);
        }

        return $tiers;
    }

    private function assertEditor(User $actor): void
    {
        if (! $actor->holds(...self::EDITEURS)) {
            throw ValidationException::withMessages(['action' => 'Le registre des marchés est tenu par l’expert Budget, le Directeur du Budget ou l’administration fonctionnelle.']);
        }
    }
}
