<?php

namespace App\Shared\Navigation;

use App\Domains\Tasks\Services\TaskAudience;
use App\Models\User;

/**
 * Source unique de visibilitÃ© de la navigation.
 *
 * Le frontend ne dÃ©cide jamais seul de ce quâ€™il affiche : il interroge ce
 * service et reÃ§oit uniquement les entrÃ©es autorisÃ©es pour lâ€™acteur courant.
 * Les routes mÃ©tier restent protÃ©gÃ©es par les policies et les workflows.
 */
class NavigationService
{
    /**
     * RÃ´les autorisÃ©s Ã  ouvrir lâ€™administration en lecture.
     *
     * @var list<string>
     */
    private const ADMIN_LECTURE = ['administrateur_habilitations', 'administrateur_fonctionnel', 'auditeur'];

    /**
     * RÃ´les autorisÃ©s Ã  gÃ©rer les habilitations.
     *
     * @var list<string>
     */
    private const ADMIN_HABILITATIONS = ['administrateur_habilitations'];

    /**
     * RÃ´les autorisÃ©s Ã  modifier les paramÃ¨tres fonctionnels.
     *
     * @var list<string>
     */
    private const ADMIN_PARAMETRAGE = ['administrateur_fonctionnel'];

    /**
     * Correspondance entre un code de rôle applicatif et les familles
     * fonctionnelles de navigation. Les profils officiels du référentiel
     * organisationnel y sont activés sans dupliquer la logique d’autorisation.
     *
     * @var array<string, list<string>>
     */
    private const PROFILS_PAR_ROLE = [
        'initiateur' => ['initiateur', 'operationnel'],
        'expert_budget' => ['expert', 'operationnel', 'budget', 'recettes', 'planification', 'pilotage'],
        'chef_budget' => ['chef_service', 'operationnel', 'budget', 'planification', 'pilotage'],
        'directeur_budget' => ['directeur', 'operationnel', 'budget', 'recettes', 'planification', 'pilotage', 'tresorerie', 'ged'],
        'controleur_financier' => ['controleur', 'operationnel', 'pilotage', 'ged'],
        'controleur_financier_central' => ['controleur', 'operationnel', 'pilotage', 'ged'],
        'ordonnateur' => ['ordonnateur', 'pilotage', 'budget', 'execution', 'tresorerie', 'recettes', 'ged', 'administration'],
        'secretaire_general' => ['secretaire_general', 'pilotage', 'planification', 'budget', 'execution', 'tresorerie', 'recettes', 'ged'],
        'comptable' => ['comptable', 'tresorerie', 'recettes', 'ged', 'pilotage'],
        'chef_comptable' => ['comptable', 'tresorerie', 'recettes', 'ged', 'pilotage'],
        'agent_comptable' => ['comptable', 'tresorerie', 'recettes', 'ged', 'pilotage'],
        'agent_comptable_central' => ['comptable', 'tresorerie', 'recettes', 'ged', 'pilotage'],
        'administrateur_habilitations' => ['administrateur', 'administration', 'pilotage', 'planification', 'budget', 'execution', 'tresorerie', 'recettes', 'ged'],
        'administrateur_fonctionnel' => ['administrateur', 'parametrage', 'administration', 'pilotage', 'planification', 'budget', 'execution', 'tresorerie', 'recettes', 'ged'],
        'auditeur' => ['auditeur', 'administration', 'pilotage', 'planification', 'budget', 'execution', 'tresorerie', 'recettes', 'ged'],
        'auditeur_interne' => ['auditeur', 'administration', 'pilotage', 'planification', 'budget', 'execution', 'tresorerie', 'recettes', 'ged'],
        'president' => ['president', 'pilotage', 'planification', 'budget', 'execution', 'tresorerie', 'recettes', 'ged'],
        'vice_president' => ['vice_president', 'pilotage', 'planification', 'budget', 'execution', 'tresorerie', 'recettes', 'ged'],
        'commissaire' => ['commissaire', 'pilotage', 'planification', 'budget', 'execution', 'recettes', 'ged'],
        'directeur' => ['directeur', 'pilotage', 'planification', 'budget', 'execution', 'ged'],
        'chef_service' => ['chef_service', 'operationnel', 'planification', 'ged'],
        'chef_bureau' => ['chef_service', 'operationnel', 'planification', 'ged'],
        'chef_cellule' => ['chef_service', 'operationnel', 'planification', 'ged'],
        'chef_composante' => ['chef_service', 'operationnel', 'planification', 'ged'],
        'chef_centre' => ['chef_service', 'operationnel', 'planification', 'ged'],
        'chef_cabinet' => ['chef_service', 'operationnel', 'planification', 'ged'],
        'directeur_cabinet' => ['directeur', 'operationnel', 'planification', 'ged'],
        'chef_etat_major_regional' => ['chef_service', 'operationnel', 'planification', 'ged'],
        'chef_bureau_liaison' => ['chef_service', 'operationnel', 'planification', 'ged'],
        'coordonnateur_programme_projet' => ['chef_service', 'operationnel', 'planification', 'ged'],
        'conseiller' => ['expert', 'operationnel', 'ged'],
        'conseiller_juridique' => ['expert', 'operationnel', 'ged'],
        'expert' => ['expert', 'operationnel', 'ged'],
        'charge_etudes' => ['expert', 'operationnel', 'ged'],
        'point_focal' => ['expert', 'operationnel', 'ged'],
        'agent' => ['initiateur', 'operationnel'],
        'responsable_se' => ['responsable_se', 'planification', 'pilotage'],
        'responsable_activite' => ['responsable_activite', 'planification'],
        'suppleant' => ['consultation'],
        'interim' => ['consultation'],
    ];

    /**
     * @var list<array{
     *   id: string,
     *   label: string,
     *   profiles?: list<string>,
     *   items: list<array{
     *     key: string,
     *     label: string,
     *     route: string,
     *     icon: string,
     *     badge?: string,
     *     roles?: list<string>
     *   }>
     * }>
     */
    private const GROUPES = [
        [
            'id' => 'accueil',
            'label' => 'Accueil',
            'items' => [
                ['key' => 'taches', 'label' => 'Mes tâches', 'route' => '/taches', 'icon' => 'tasks', 'badge' => 'taches'],
                ['key' => 'notifications', 'label' => 'Notifications', 'route' => '/notifications', 'icon' => 'notifications', 'badge' => 'notifications'],
            ],
        ],
        [
            'id' => 'planification',
            'label' => 'Planification et performance',
            'profiles' => ['president', 'vice_president', 'commissaire', 'secretaire_general', 'directeur', 'directeur_budget', 'expert_budget', 'chef_budget', 'chef_service', 'expert', 'auditeur', 'administrateur', 'parametrage', 'responsable_se', 'responsable_activite', 'operationnel', 'planification'],
            'items' => [
                ['key' => 'planification', 'label' => 'Planification GAR', 'route' => '/planification', 'icon' => 'planning'],
                ['key' => 'suivi', 'label' => 'Tableau de bord S&E', 'route' => '/suivi', 'icon' => 'monitoring'],
                ['key' => 'gantt', 'label' => 'Gantt d’exécution', 'route' => '/suivi/gantt', 'icon' => 'gantt'],
                ['key' => 'saisie', 'label' => 'Saisie et validation', 'route' => '/suivi/saisie', 'icon' => 'entry'],
                ['key' => 'ecarts', 'label' => 'Écarts', 'route' => '/suivi/ecarts', 'icon' => 'gap'],
                ['key' => 'actions', 'label' => 'Suivi des actions', 'route' => '/suivi/actions', 'icon' => 'actions'],
                ['key' => 'referentiels', 'label' => 'Référentiels S&E', 'route' => '/suivi/referentiels', 'icon' => 'settings', 'roles' => self::ADMIN_PARAMETRAGE],
            ],
        ],
        [
            'id' => 'budget',
            'label' => 'Préparation et budget',
            'profiles' => ['president', 'vice_president', 'commissaire', 'secretaire_general', 'directeur', 'directeur_budget', 'expert_budget', 'chef_budget', 'ordonnateur', 'auditeur', 'administrateur', 'parametrage', 'budget'],
            'items' => [
                ['key' => 'preparation', 'label' => 'Vue d’ensemble', 'route' => '/preparation', 'icon' => 'entry'],
                ['key' => 'campagnes', 'label' => 'Campagnes', 'route' => '/preparation/campagnes', 'icon' => 'calendar'],
                ['key' => 'dossiers', 'label' => 'Propositions', 'route' => '/preparation/dossiers', 'icon' => 'document'],
                ['key' => 'lignes', 'label' => 'Lignes budgétaires', 'route' => '/lignes-budgetaires', 'icon' => 'budget'],
                ['key' => 'cloture', 'label' => 'Clôture annuelle', 'route' => '/cloture', 'icon' => 'archive'],
            ],
        ],
        [
            'id' => 'execution',
            'label' => 'Exécution budgétaire',
            'profiles' => ['initiateur', 'operationnel', 'president', 'vice_president', 'commissaire', 'secretaire_general', 'ordonnateur', 'directeur', 'directeur_budget', 'expert_budget', 'chef_budget', 'controleur', 'comptable', 'auditeur', 'administrateur', 'parametrage', 'execution'],
            'items' => [
                ['key' => 'besoins', 'label' => 'Expressions de besoin', 'route' => '/expressions-besoin', 'icon' => 'need'],
                ['key' => 'engagements', 'label' => 'Engagements', 'route' => '/engagements', 'icon' => 'commitment', 'badge' => 'engagements_a_viser'],
                ['key' => 'liquidations', 'label' => 'Liquidations', 'route' => '/liquidations', 'icon' => 'settlement', 'badge' => 'liquidations_a_viser'],
                ['key' => 'ordonnancements', 'label' => 'Ordonnancements', 'route' => '/ordonnancements', 'icon' => 'order', 'badge' => 'ordonnancements_a_signer'],
                ['key' => 'delegations', 'label' => 'Délégations et seuil', 'route' => '/ordonnancements/delegations', 'icon' => 'roles'],
                ['key' => 'marches', 'label' => 'Marchés et contrats', 'route' => '/marches', 'icon' => 'commitment'],
                ['key' => 'tiers', 'label' => 'Tiers et comptes', 'route' => '/tiers', 'icon' => 'suppliers'],
            ],
        ],
        [
            'id' => 'tresorerie',
            'label' => 'Trésorerie et agence comptable',
            'profiles' => ['comptable', 'tresorerie', 'president', 'vice_president', 'secretaire_general', 'ordonnateur', 'directeur_budget', 'auditeur', 'administrateur', 'parametrage'],
            'items' => [
                ['key' => 'paiements', 'label' => 'Paiements', 'route' => '/paiements', 'icon' => 'payment', 'badge' => 'paiements_a_executer'],
                ['key' => 'lots', 'label' => 'Lots de paiement', 'route' => '/paiements/lots', 'icon' => 'paymentBatch'],
                ['key' => 'tresorerie', 'label' => 'Trésorerie réalisée', 'route' => '/chaine/tresorerie', 'icon' => 'payment'],
                ['key' => 'obligations', 'label' => 'Restes à payer', 'route' => '/chaine/obligations', 'icon' => 'calendar'],
                ['key' => 'comptabilite', 'label' => 'Export comptable', 'route' => '/chaine/comptabilite', 'icon' => 'report'],
                ['key' => 'rapprochements', 'label' => 'Rapprochements des dépenses', 'route' => '/rapprochements', 'icon' => 'reconciliation'],
            ],
        ],
        [
            'id' => 'pilotage',
            'label' => 'Reporting et pilotage',
            'profiles' => ['pilotage', 'president', 'vice_president', 'commissaire', 'secretaire_general', 'ordonnateur', 'directeur', 'directeur_budget', 'controleur', 'comptable', 'auditeur', 'administrateur', 'parametrage'],
            'items' => [
                ['key' => 'chaine', 'label' => 'Tableau de chaîne', 'route' => '/chaine', 'icon' => 'dashboard'],
                ['key' => 'dossier', 'label' => 'Dossier financier', 'route' => '/chaine/dossier', 'icon' => 'need'],
                ['key' => 'execution', 'label' => 'Instantanés d’exécution', 'route' => '/rapports/execution', 'icon' => 'report'],
                ['key' => 'rapports_se', 'label' => 'Rapports S&E', 'route' => '/suivi/rapports', 'icon' => 'report'],
                ['key' => 'synthese', 'label' => 'Synthèse exécutive', 'route' => '/suivi/synthese', 'icon' => 'synthesis'],
                ['key' => 'etats', 'label' => 'États de base', 'route' => '/etats', 'icon' => 'report'],
            ],
        ],
        [
            'id' => 'recettes',
            'label' => 'Recettes',
            'profiles' => ['comptable', 'tresorerie', 'president', 'vice_president', 'secretaire_general', 'ordonnateur', 'directeur_budget', 'expert_budget', 'auditeur', 'administrateur', 'parametrage', 'recettes'],
            'items' => [
                ['key' => 'recettes', 'label' => 'Tableau de bord', 'route' => '/recettes', 'icon' => 'revenue'],
                ['key' => 'recettes_etats', 'label' => 'États', 'route' => '/recettes/etats', 'icon' => 'report'],
                ['key' => 'previsions', 'label' => 'Prévisions', 'route' => '/recettes/previsions', 'icon' => 'budget'],
                ['key' => 'titres', 'label' => 'Recettes', 'route' => '/recettes/titres', 'icon' => 'order'],
                ['key' => 'contributions', 'label' => 'Contributions', 'route' => '/recettes/contributions', 'icon' => 'users'],
                ['key' => 'creances', 'label' => 'Créances', 'route' => '/recettes/creances', 'icon' => 'calendar'],
                ['key' => 'encaissements', 'label' => 'Encaissements', 'route' => '/recettes/encaissements', 'icon' => 'payment'],
                ['key' => 'recettes_rapprochements', 'label' => 'Rapprochements des recettes', 'route' => '/recettes/rapprochements', 'icon' => 'reconciliation'],
                ['key' => 'relances', 'label' => 'Relances', 'route' => '/recettes/relances', 'icon' => 'comment'],
                ['key' => 'recettes_parametrage', 'label' => 'Paramétrage', 'route' => '/recettes/parametrage', 'icon' => 'settings', 'roles' => self::ADMIN_PARAMETRAGE],
            ],
        ],
        [
            'id' => 'ged',
            'label' => 'GED et contrôle',
            'profiles' => ['ged', 'president', 'vice_president', 'commissaire', 'secretaire_general', 'ordonnateur', 'directeur', 'directeur_budget', 'controleur', 'comptable', 'chef_service', 'expert', 'operationnel', 'initiateur', 'auditeur', 'administrateur', 'parametrage', 'consultation'],
            'items' => [
                ['key' => 'verifier', 'label' => 'Vérifier un document', 'route' => '/documents/verifier', 'icon' => 'verifyDocument'],
                ['key' => 'recherche', 'label' => 'Recherche documentaire', 'route' => '/documents/recherche', 'icon' => 'search'],
                ['key' => 'ged', 'label' => 'GED', 'route' => '/ged', 'icon' => 'document'],
                ['key' => 'anomalies', 'label' => 'Registre d’anomalies', 'route' => '/controles/anomalies', 'icon' => 'warning'],
                ['key' => 'imports', 'label' => 'Préparation des imports', 'route' => '/imports/preparation', 'icon' => 'document', 'roles' => ['directeur_budget', 'administrateur_fonctionnel']],
            ],
        ],
        [
            'id' => 'administration',
            'label' => 'Administration',
            'profiles' => ['administration', 'parametrage'],
            'items' => [
                ['key' => 'administration', 'label' => 'Administration', 'route' => '/administration', 'icon' => 'administration', 'roles' => self::ADMIN_LECTURE],
                ['key' => 'organisation', 'label' => 'Organisation', 'route' => '/administration/organisation', 'icon' => 'administration', 'roles' => self::ADMIN_PARAMETRAGE],
                ['key' => 'roles_permissions', 'label' => 'Rôles et permissions', 'route' => '/administration/roles-permissions', 'icon' => 'roles', 'roles' => self::ADMIN_LECTURE],
                ['key' => 'habilitations', 'label' => 'Habilitations', 'route' => '/administration/habilitations', 'icon' => 'roles', 'roles' => [...self::ADMIN_HABILITATIONS, 'ordonnateur']],
                ['key' => 'utilisateurs', 'label' => 'Utilisateurs', 'route' => '/administration/utilisateurs', 'icon' => 'users', 'roles' => self::ADMIN_LECTURE],
                ['key' => 'audit', 'label' => 'Journal d’audit', 'route' => '/administration/audit', 'icon' => 'history', 'roles' => self::ADMIN_LECTURE],
                ['key' => 'suivi_acces', 'label' => 'Suivi des accès', 'route' => '/administration/suivi-acces', 'icon' => 'roles', 'roles' => self::ADMIN_HABILITATIONS],
                ['key' => 'parametrage', 'label' => 'Paramétrage général', 'route' => '/administration/parametrage', 'icon' => 'settings', 'roles' => self::ADMIN_PARAMETRAGE],
            ],
        ],
    ];

    public function __construct(private readonly TaskAudience $audience) {}

    /**
     * @return array{groups: list<array<string, mixed>>, badges: array<string, int>}
     */
    public function forUser(User $user): array
    {
        $badges = $this->badges($user);
        $profiles = $this->profiles($user);

        $groups = [];
        foreach (self::GROUPES as $group) {
            $requis = $group['profiles'] ?? [];
            if ($requis !== [] && array_intersect($profiles, $requis) === []) {
                continue;
            }
            $items = [];
            foreach ($group['items'] as $item) {
                if (! $this->visible($user, $item)) {
                    continue;
                }
                $items[] = [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'route' => $item['route'],
                    'icon' => $item['icon'],
                    'badge' => $item['badge'] ?? null,
                ];
            }
            if ($items !== []) {
                $groups[] = [
                    'id' => $group['id'],
                    'label' => $group['label'],
                    'items' => $items,
                ];
            }
        }

        return ['groups' => $groups, 'badges' => $badges];
    }

    /**
     * @return list<string>
     */
    private function profiles(User $user): array
    {
        $profiles = [];
        foreach ($user->heldRoleCodes() as $role) {
            $profiles = [...$profiles, ...(self::PROFILS_PAR_ROLE[$role] ?? [])];
        }

        return array_values(array_unique($profiles));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function visible(User $user, array $item): bool
    {
        $roles = $item['roles'] ?? [];
        if ($roles !== [] && ! $user->holds(...$roles)) {
            return false;
        }

        $permissions = $item['permissions'] ?? [];
        foreach ($permissions as $permission) {
            if (! $user->porte((string) $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, int>
     */
    private function badges(User $user): array
    {
        $taches = $this->audience->openTasksFor($user)
            ->where('action', '!=', 'completer')
            ->values();

        $parModule = fn (string $module, array $actions) => $taches
            ->filter(fn ($task) => $task->module === $module && in_array($task->action, $actions, true))
            ->count();

        return [
            'taches' => $taches->count(),
            'notifications' => $user->unreadNotifications()->count(),
            'engagements_a_viser' => $parModule('engagement', ['viser']),
            'liquidations_a_viser' => $parModule('liquidation', ['viser']),
            'ordonnancements_a_signer' => $parModule('ordonnancement', ['signer']),
            'paiements_a_executer' => $parModule('paiement', ['prendre_en_charge', 'preparer', 'valider', 'signer', 'executer']),
        ];
    }
}
