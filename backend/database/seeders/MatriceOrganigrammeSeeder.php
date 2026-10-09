<?php

namespace Database\Seeders;

use App\Domains\Administration\Models\Permission;
use App\Domains\Administration\Models\Role;
use App\Domains\Administration\Services\GestionHabilitations;
use App\Domains\Administration\Services\HabilitationCatalogue;
use Illuminate\Database\Seeder;

/**
 * Remplit la matrice Rôles et Permissions des fonctions du référentiel
 * organisationnel à partir des profils de navigation déjà définis.
 *
 * Les cases « consulter » et « exporter » reflètent les modules que la
 * fonction peut ouvrir. Les cases métier appliquées (création d’expression,
 * visa, signature de paiement) reprennent le niveau du rôle applicatif
 * équivalent afin de ne pas élargir les circuits.
 */
class MatriceOrganigrammeSeeder extends Seeder
{
    /**
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const LECTURE = [
        'president' => ['global', ['besoins', 'budget', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'paiements', 'preparation', 'recettes', 'reporting', 'suivi', 'virements']],
        'vice_president' => ['global', ['besoins', 'budget', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'paiements', 'preparation', 'recettes', 'reporting', 'suivi', 'virements']],
        'commissaire' => ['scoped', ['besoins', 'budget', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'preparation', 'recettes', 'reporting', 'suivi', 'virements']],
        'secretaire_general' => ['global', ['besoins', 'budget', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'paiements', 'preparation', 'recettes', 'reporting', 'suivi', 'virements']],
        'expert_budget' => ['global', ['besoins', 'budget', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'preparation', 'recettes', 'reporting', 'suivi', 'virements']],
        'chef_budget' => ['global', ['besoins', 'budget', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'preparation', 'reporting', 'suivi', 'virements']],
        'directeur_budget' => ['global', ['besoins', 'budget', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'paiements', 'preparation', 'recettes', 'reporting', 'suivi', 'virements']],
        'ordonnateur' => ['global', ['administration', 'besoins', 'budget', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'paiements', 'preparation', 'recettes', 'reporting', 'suivi', 'virements']],
        'chef_comptable' => ['global', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'paiements', 'recettes', 'reporting', 'suivi']],
        'administrateur_habilitations' => ['global', ['administration', 'besoins', 'budget', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'paiements', 'preparation', 'recettes', 'reporting', 'suivi', 'virements']],
        'administrateur_fonctionnel' => ['global', ['administration', 'besoins', 'budget', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'paiements', 'preparation', 'recettes', 'reporting', 'suivi', 'virements']],
        'directeur_cabinet' => ['scoped', ['besoins', 'budget', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'preparation', 'reporting', 'suivi', 'virements']],
        'chef_cabinet' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'conseiller' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'conseiller_juridique' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'directeur' => ['scoped', ['besoins', 'budget', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'preparation', 'reporting', 'suivi', 'virements']],
        'chef_etat_major_regional' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'agent_comptable_central' => ['global', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'paiements', 'recettes', 'reporting', 'suivi']],
        'auditeur_interne' => ['global', ['administration', 'besoins', 'budget', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'paiements', 'preparation', 'recettes', 'reporting', 'suivi', 'virements']],
        'controleur_financier_central' => ['global', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'reporting', 'suivi']],
        'chef_bureau_liaison' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'chef_service' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'chef_composante' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'chef_centre' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'coordonnateur_programme_projet' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'chef_cellule' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'chef_bureau' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'expert' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'charge_etudes' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'point_focal' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'agent' => ['scoped', ['besoins', 'documents', 'engagements', 'liquidations', 'ordonnancements', 'suivi']],
        'suppleant' => ['scoped', ['documents']],
        'interim' => ['scoped', ['documents']],
    ];

    /**
     * Permissions métier appliquées, calquées sur le rôle applicatif équivalent.
     *
     * @var array<string, list<string>>
     */
    private const APPLIQUEES = [
        'agent' => ['eb.creer'],
        'agent_comptable_central' => ['paiement.signer'],
        'controleur_financier_central' => ['engagement.viser', 'liquidation.viser'],
    ];

    /**
     * Actions d’administration de la matrice accordées aux deux rôles
     * d’administration (au-delà de la simple consultation/export).
     *
     * @var list<string>
     */
    private const ADMINISTRATION_ACTIONS = ['creer', 'modifier', 'valider', 'rejeter'];

    /**
     * @var list<string>
     */
    private const ROLES_ADMINISTRATION_TOTALE = ['administrateur_habilitations', 'administrateur_fonctionnel'];

    public function run(): void
    {
        app(HabilitationCatalogue::class)->assurer();
        $this->assurerPermissionsMatrice();

        foreach (self::LECTURE as $roleCode => [$niveau, $modules]) {
            $role = Role::query()->where('code', $roleCode)->first();
            if ($role === null) {
                continue;
            }

            $attribution = [];
            foreach ($modules as $module) {
                foreach (['consulter', 'exporter'] as $action) {
                    $permission = Permission::query()->where('code', $module.'.'.$action)->first();
                    if ($permission !== null) {
                        $attribution[$permission->id] = ['level' => $niveau];
                    }
                }
            }

            foreach (self::APPLIQUEES[$roleCode] ?? [] as $code) {
                $permission = Permission::query()->where('code', $code)->first();
                if ($permission !== null) {
                    $attribution[$permission->id] = ['level' => 'scoped'];
                }
            }

            if (in_array($roleCode, self::ROLES_ADMINISTRATION_TOTALE, true)) {
                foreach (self::ADMINISTRATION_ACTIONS as $action) {
                    $permission = Permission::query()->where('code', 'administration.'.$action)->first();
                    if ($permission !== null) {
                        $attribution[$permission->id] = ['level' => 'global'];
                    }
                }
            }

            $role->permissions()->syncWithoutDetaching($attribution);
        }
    }

    private function assurerPermissionsMatrice(): void
    {
        foreach (GestionHabilitations::MODULES as $module) {
            foreach (GestionHabilitations::ACTIONS as $action) {
                $cle = $module['code'].'.'.$action['code'];
                if (isset(GestionHabilitations::ALIAS[$cle])) {
                    continue;
                }

                Permission::query()->firstOrCreate(
                    ['code' => $cle],
                    [
                        'module' => $module['label'],
                        'label' => $action['label'],
                        'kind' => $action['code'],
                        'description' => $module['label'].' · '.$action['label'],
                        'origin' => 'matrice',
                        'active' => true,
                    ],
                );
            }
        }
    }
}
