<?php

namespace App\Domains\Administration\Services;

use App\Domains\Administration\Models\WorkflowDefinition;

/**
 * Étapes publiées de la chaîne de dépense. Une définition incomplète
 * ne remplace pas le circuit métier : l’appelant conserve alors son circuit.
 */
class ChainWorkflowCatalog
{
    /**
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const CANONICAL = [
        ['eb.initiateur', 'Soumettre l’expression de besoin', 'initiateur'],
        ['eb.directeur', 'Valider dans la direction', 'directeur'],
        ['eb.commissaire', 'Visa du commissaire (structure technique)', 'commissaire'],
        ['eb.secretaire_general', 'Visa du Secrétaire général', 'secretaire_general'],
        ['eb.ordonnateur', 'Approuver l’expression de besoin', 'ordonnateur'],
        ['eng.expert_budget', 'Instruire l’engagement', 'expert_budget'],
        ['eng.chef_budget', 'Contrôler l’engagement', 'chef_budget'],
        ['eng.directeur_budget', 'Valider l’engagement', 'directeur_budget'],
        ['eng.controleur_financier', 'Viser l’engagement', 'controleur_financier'],
        ['liq.initiateur', 'Constater le service fait', 'initiateur'],
        ['liq.controleur_financier', 'Viser la liquidation', 'controleur_financier'],
        ['ord.ordonnateur', 'Signer l’ordonnancement', 'ordonnateur'],
        ['pay.comptable', 'Préparer le paiement', 'comptable'],
        ['pay.chef_comptable', 'Contrôler le paiement', 'chef_comptable'],
        ['pay.agent_comptable', 'Exécuter le règlement', 'agent_comptable'],
    ];

    /**
     * @param  list<string>  $required
     * @return list<string>|null
     */
    public function roles(string $prefix, array $required): ?array
    {
        $definition = WorkflowDefinition::query()->where('code', 'chaine-depense')->first();
        $version = $definition?->activeVersion();
        if ($version === null) {
            return null;
        }
        $roles = $version->steps()
            ->where('code', 'like', $prefix.'.%')
            ->orderBy('ordre')
            ->pluck('actor_role')
            ->all();
        foreach ($required as $role) {
            if (! in_array($role, $roles, true)) {
                return null;
            }
        }

        return array_values($roles);
    }
}
