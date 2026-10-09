<?php

namespace Database\Seeders;

use App\Domains\Administration\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReferentielOrganisationRolesSeeder extends Seeder
{
    /**
     * Fonctions de responsabilité issues du référentiel organisationnel
     * consolidé de la Commission de la CEEAC (juin 2026).
     */
    public function run(): void
    {
        $roles = [
            'president' => ['Président de la Commission', 'Gouvernance', true],
            'vice_president' => ['Vice-Président de la Commission', 'Gouvernance', true],
            'commissaire' => ['Commissaire, Chef de Département', 'Gouvernance', true],
            'secretaire_general' => ['Secrétaire Général', 'Gouvernance', true],
            'directeur_cabinet' => ['Directeur de Cabinet', 'Cabinet', true],
            'chef_cabinet' => ['Chef de Cabinet', 'Cabinet', false],
            'conseiller' => ['Conseiller', 'Cabinet', false],
            'conseiller_juridique' => ['Conseiller Juridique', 'Cabinet', true],
            'directeur' => ['Directeur', 'Direction', true],
            'chef_etat_major_regional' => ['Chef d’État-Major Régional', 'Direction', true],
            'agent_comptable_central' => ['Agent Comptable Central', 'Agence comptable', true],
            'auditeur_interne' => ['Auditeur Interne', 'Contrôle', true],
            'controleur_financier_central' => ['Contrôleur Financier Central', 'Contrôle', true],
            'chef_bureau_liaison' => ['Chef de Bureau de Liaison', 'Liaison', false],
            'chef_service' => ['Chef de Service', 'Service', true],
            'chef_composante' => ['Chef de Composante', 'Service', false],
            'chef_centre' => ['Chef de Centre', 'Service', false],
            'coordonnateur_programme_projet' => ['Coordonnateur de programme ou projet', 'Projet', false],
            'chef_cellule' => ['Chef de cellule', 'Service', false],
            'chef_bureau' => ['Chef de Bureau', 'Bureau', false],
            'expert' => ['Expert', 'Expertise', false],
            'charge_etudes' => ['Chargé d’études', 'Expertise', false],
            'point_focal' => ['Point focal', 'Opérationnel', false],
            'agent' => ['Agent', 'Opérationnel', false],
            'suppleant' => ['Suppléant', 'Intérim', false],
            'interim' => ['Intérimaire', 'Intérim', false],
        ];

        foreach ($roles as $code => [$label, $category, $sensitive]) {
            Role::query()->updateOrCreate(
                ['code' => $code],
                [
                    'label' => $label,
                    'description' => 'Fonction issue du référentiel organisationnel consolidé de la Commission de la CEEAC.',
                    'category' => $category,
                    'sensitive' => $sensitive,
                    'active' => true,
                    'system' => false,
                ],
            );

            DB::table('institutional_functions')->updateOrInsert(
                ['code' => $code],
                [
                    'label' => $label,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
