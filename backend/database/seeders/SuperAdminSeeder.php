<?php

namespace Database\Seeders;

use App\Domains\Administration\Models\Permission;
use App\Domains\Administration\Models\Role;
use App\Domains\Administration\Services\GestionHabilitations;
use App\Domains\Administration\Services\HabilitationCatalogue;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        app(HabilitationCatalogue::class)->assurer();
        $this->assurerPermissionsMatrice();

        $role = Role::query()->firstOrCreate(
            ['code' => 'super_admin'],
            [
                'label' => 'Super Administrateur',
                'description' => 'Accès total à l’ensemble des modules, des actions et des paramètres.',
                'category' => 'Administration',
                'sensitive' => true,
                'active' => true,
                'system' => true,
            ],
        );

        $role->permissions()->sync(
            Permission::query()->pluck('id')->mapWithKeys(fn ($id): array => [$id => ['level' => 'global']])->all(),
        );

        $user = User::query()->firstOrCreate(
            ['email' => 'narcisse.odoua@ceeac-eccas.org'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Narcisse ODOUA',
                'function_title' => 'Super Administrateur',
                'role' => 'super_admin',
                'initials' => 'NO',
                'account_status' => 'actif',
                'password' => Hash::make('password'),
            ],
        );

        $user->roles()->syncWithoutDetaching([$role->id]);
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

        $administratives = [
            ['administration.users.view', 'administration', 'Voir les utilisateurs', 'lecture'],
            ['administration.users.create', 'administration', 'Créer un utilisateur', 'creation'],
            ['administration.users.disable', 'administration', 'Désactiver un utilisateur', 'administration'],
            ['administration.roles.manage', 'administration', 'Gérer les rôles', 'administration'],
            ['administration.audit.view', 'administration', 'Consulter l’audit', 'audit'],
            ['administration.settings.manage', 'administration', 'Paramétrer l’application', 'parametrage'],
            ['paiement.prepare', 'paiement', 'Préparer un paiement', 'creation'],
            ['paiement.validate', 'paiement', 'Valider un paiement', 'validation'],
            ['paiement.sign', 'paiement', 'Autoriser un paiement', 'signature'],
            ['ordonnancement.sign', 'ordonnancement', 'Signer un ordre', 'signature'],
            ['audit.view', 'audit', 'Lire le journal', 'audit'],
        ];

        foreach ($administratives as [$code, $module, $label, $kind]) {
            Permission::query()->firstOrCreate(
                ['code' => $code],
                ['module' => $module, 'label' => $label, 'kind' => $kind],
            );
        }
    }
}
