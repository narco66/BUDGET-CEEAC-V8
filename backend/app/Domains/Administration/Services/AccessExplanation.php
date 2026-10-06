<?php

namespace App\Domains\Administration\Services;

use App\Domains\Commitments\Services\OrdonnancementWorkflow;
use App\Models\User;

/**
 * Explique un droit déjà appliqué par les policies et les workflows.
 * Cette lecture n’accorde aucun accès et n’exécute aucune action.
 */
class AccessExplanation
{
    public function __construct(private readonly OrdonnancementWorkflow $ordonnancement) {}

    /**
     * @return list<array{code: string, module: string, label: string}>
     */
    public function catalogue(): array
    {
        return app(HabilitationCatalogue::class)->catalogue();
    }

    /**
     * @return array{autorise: bool, raisons: list<string>}
     */
    public function expliquer(User $user, string $action, ?int $unitId, ?int $montant): array
    {
        if ($user->account_status !== 'actif') {
            return $this->decision(false, ['Le compte n’est pas actif.']);
        }

        return match ($action) {
            'eb.creer' => $this->permission($user, 'eb.creer', 'La création d’une expression de besoin exige la permission eb.creer sur un rôle détenu.'),
            'eb.consulter', 'engagement.consulter' => $this->consultation($user, $unitId),
            'engagement.viser' => $this->permission($user, 'engagement.viser', 'Le visa d’engagement exige la permission engagement.viser, et un dossier encore à l’étape du contrôleur financier.'),
            'liquidation.viser' => $this->permission($user, 'liquidation.viser', 'Le visa de liquidation exige la permission liquidation.viser, et un dossier encore à l’étape du contrôleur financier.'),
            'ordonnancement.signer' => $this->signature($user, $montant),
            'paiement.signer' => $this->permission($user, 'paiement.signer', 'L’autorisation de paiement exige la permission paiement.signer, sur un paiement à signer.'),
            'paiement.executer' => $this->permission($user, 'paiement.executer', 'L’exécution du paiement exige la permission paiement.executer, sur un paiement autorisé.'),
            'administration.consulter' => $this->primaire($user, ['administrateur_habilitations', 'administrateur_fonctionnel', 'auditeur'], 'La consultation administrative lit le rôle principal.'),
            'administration.habilitations' => $this->primaire($user, ['administrateur_habilitations'], 'L’administration des habilitations lit le rôle principal. Un rôle ajouté ou un intérim ne l’ouvre pas.'),
            'administration.parametrer' => $this->primaire($user, ['administrateur_fonctionnel'], 'Le paramétrage lit le rôle principal d’administrateur fonctionnel.'),
            default => $this->decision(false, ['Cette action n’a pas de règle publiée. Elle n’est pas accordée par défaut.']),
        };
    }

    /**
     * @param  list<string>  $roles
     * @return array{autorise: bool, raisons: list<string>}
     */
    /**
     * @return array{autorise: bool, raisons: list<string>}
     */
    private function permission(User $user, string $code, string $regle): array
    {
        $tient = $user->porte($code);

        return $this->decision($tient, [
            $regle,
            $tient
                ? 'Un rôle détenu porte la permission active '.$code.'.'
                : 'Aucun rôle détenu ne porte la permission active '.$code.'.',
            'La signature financière et le périmètre du dossier restent contrôlés à part. Cette vérification ne modifie pas le dossier.',
        ]);
    }

    /**
     * @param  list<string>  $roles
     * @return array{autorise: bool, raisons: list<string>}
     */
    private function roles(User $user, array $roles, string $regle): array
    {
        $tient = $user->holds(...$roles);

        return $this->decision($tient, [
            $regle,
            $tient
                ? 'Le compte tient l’un des rôles requis : '.implode(', ', $roles).'.'
                : 'Le compte ne tient aucun des rôles requis : '.implode(', ', $roles).'.',
            'Cette vérification ne modifie pas le dossier.',
        ]);
    }

    /**
     * @param  list<string>  $roles
     * @return array{autorise: bool, raisons: list<string>}
     */
    private function primaire(User $user, array $roles, string $regle): array
    {
        $tient = in_array((string) $user->role, $roles, true);

        return $this->decision($tient, [
            $regle,
            $tient
                ? 'Le rôle principal est '.$user->role.'.'
                : 'Le rôle principal est '.$user->role.', hors de la liste '.implode(', ', $roles).'.',
        ]);
    }

    /**
     * @return array{autorise: bool, raisons: list<string>}
     */
    private function consultation(User $user, ?int $unitId): array
    {
        if (! $user->holdsAny()) {
            return $this->decision(false, ['Aucun rôle n’est détenu. La consultation est refusée.']);
        }
        if ($unitId === null) {
            return $this->decision(true, [
                'Un rôle est détenu.',
                $user->organizationScopeIds() === null
                    ? 'Aucun périmètre de structure n’est défini : la consultation n’est pas limitée à une structure.'
                    : 'Un périmètre de structures est défini. Un dossier hors de ces structures serait refusé.',
            ]);
        }
        $voit = $user->seesOrganization($unitId);

        return $this->decision($voit, [
            $voit
                ? 'La structure demandée est dans le périmètre de consultation.'
                : 'La structure demandée est hors du périmètre de consultation.',
        ]);
    }

    /**
     * @return array{autorise: bool, raisons: list<string>}
     */
    private function signature(User $user, ?int $montant): array
    {
        if ($montant === null) {
            return $this->decision(false, ['Indiquez le montant net en XAF. Le seuil de délégation choisit l’ordonnateur compétent.']);
        }
        $authority = $this->ordonnancement->authority($montant);
        $tient = $user->holds($authority['ordonnateur_role']);

        return $this->decision($tient, [
            $authority['fondement'],
            'Rôle compétent pour ce montant : '.$authority['ordonnateur_role'].'.',
            $tient
                ? 'Le compte tient ce rôle.'
                : 'Le compte ne tient pas ce rôle.',
            'La signature réelle exige en plus un ordre au statut à signer. Cette vérification ne signe rien.',
        ]);
    }

    /**
     * @param  list<string>  $raisons
     * @return array{autorise: bool, raisons: list<string>}
     */
    private function decision(bool $autorise, array $raisons): array
    {
        return ['autorise' => $autorise, 'raisons' => $raisons];
    }
}
