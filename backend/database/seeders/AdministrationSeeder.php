<?php

namespace Database\Seeders;

use App\Domains\Administration\Models\BusinessRule;
use App\Domains\Administration\Models\Permission;
use App\Domains\Administration\Models\Role;
use App\Domains\Administration\Models\SodRule;
use App\Domains\Administration\Models\SystemSetting;
use App\Domains\Administration\Models\WorkflowDefinition;
use App\Domains\Administration\Services\ChainWorkflowCatalog;
use App\Domains\Administration\Services\HabilitationCatalogue;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdministrationSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->whereNull('uuid')->each(function (User $user): void {
            $user->forceFill(['uuid' => (string) Str::uuid()])->save();
        });

        $permissions = [
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
        foreach ($permissions as [$code, $module, $label, $kind]) {
            Permission::query()->firstOrCreate(['code' => $code], ['module' => $module, 'label' => $label, 'kind' => $kind]);
        }

        $roles = [
            'initiateur' => 'Initiateur',
            'expert_budget' => 'Expert Budget',
            'chef_budget' => 'Chef de service Budget',
            'directeur_budget' => 'Directeur du Budget',
            'controleur_financier' => 'Contrôleur financier',
            'ordonnateur' => 'Ordonnateur',
            'secretaire_general' => 'Secrétaire général',
            'comptable' => 'Comptable',
            'chef_comptable' => 'Chef comptable',
            'agent_comptable' => 'Agent comptable',
            'administrateur_habilitations' => 'Administrateur des habilitations',
            'administrateur_fonctionnel' => 'Administrateur fonctionnel',
            'auditeur' => 'Auditeur',
        ];
        foreach ($roles as $code => $label) {
            Role::query()->firstOrCreate(['code' => $code], [
                'label' => $label,
                'sensitive' => in_array($code, ['ordonnateur', 'administrateur_habilitations', 'administrateur_fonctionnel', 'agent_comptable'], true),
                'active' => true,
            ]);
        }
        Role::query()->where('code', 'auditeur')->first()?->permissions()->sync(
            Permission::query()->whereIn('code', ['administration.users.view', 'administration.audit.view', 'audit.view'])->pluck('id')
        );
        Role::query()->where('code', 'administrateur_habilitations')->first()?->permissions()->sync(
            Permission::query()->where('module', 'administration')->where('code', '!=', 'administration.settings.manage')->pluck('id')
        );
        Role::query()->where('code', 'administrateur_fonctionnel')->first()?->permissions()->sync(
            Permission::query()->whereIn('code', ['administration.settings.manage', 'administration.audit.view'])->pluck('id')
        );

        SodRule::query()->firstOrCreate(
            ['role_a' => 'ordonnateur', 'role_b' => 'comptable'],
            ['blocking' => true, 'label' => 'L’ordonnateur ne peut pas être le comptable de la même dépense.'],
        );
        SodRule::query()->firstOrCreate(
            ['role_a' => 'initiateur', 'role_b' => 'controleur_financier'],
            ['blocking' => true, 'label' => 'L’initiateur ne peut pas viser sa propre dépense.'],
        );

        $unit = OrganizationUnit::query()->where('sigle', 'SG')->first() ?? OrganizationUnit::query()->first();
        $this->user($unit, 'Amina OKO', 'amina.oko@ceeac.int', 'Administrateur des habilitations', 'administrateur_habilitations', 'AO');
        $this->user($unit, 'Hervé BOUKA', 'herve.bouka@ceeac.int', 'Administrateur fonctionnel', 'administrateur_fonctionnel', 'HB');
        $this->user($unit, 'Chantal IBINGA', 'chantal.ibinga@ceeac.int', 'Auditeur interne', 'auditeur', 'CI');

        BusinessRule::query()->firstOrCreate(
            ['code' => 'plafond_caisse'],
            ['label' => 'Plafond de règlement en caisse', 'value' => '500000', 'unit' => 'XAF', 'active' => true],
        );
        SystemSetting::query()->firstOrCreate(
            ['key' => 'devise'],
            ['value' => 'XAF', 'critical' => true],
        );
        DB::table('security_policies')->insertOrIgnore([
            'id' => 1,
            'min_length' => 8,
            'max_failures' => 5,
            'lock_minutes' => 15,
            'mfa_roles' => json_encode(['ordonnateur', 'controleur_financier', 'agent_comptable', 'administrateur_habilitations']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('reference_values')->insertOrIgnore([
            ['set_code' => 'devise', 'code' => 'XAF', 'label' => 'Franc CFA', 'status' => 'actif', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['set_code' => 'mode_paiement', 'code' => 'virement', 'label' => 'Virement', 'status' => 'actif', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('document_types')->insertOrIgnore([
            'code' => 'facture',
            'label' => 'Facture',
            'operation' => 'liquidation',
            'required' => true,
            'min_count' => 1,
            'max_size_kb' => 10240,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('number_sequences')->insertOrIgnore([
            'code' => 'PAY',
            'prefix' => 'PAY',
            'padding' => 6,
            'separator' => '-',
            'last_value' => 0,
            'exercise_year' => 2026,
            'reset_policy' => 'annuel',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('holidays')->insertOrIgnore([
            'holiday_on' => '2026-08-17',
            'label' => 'Fête nationale',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('institutional_functions')->insertOrIgnore([
            'code' => 'ordonnateur',
            'label' => 'Ordonnateur principal',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (WorkflowDefinition::query()->where('code', 'chaine-depense')->doesntExist()) {
            $definition = WorkflowDefinition::query()->create([
                'code' => 'chaine-depense',
                'module' => 'depense',
                'label' => 'Chaîne EB → ENG → LIQ → ORD → PAY',
            ]);
            $version = $definition->versions()->create([
                'version' => 1,
                'status' => 'actif',
                'effective_on' => '2026-01-01',
            ]);
            foreach (ChainWorkflowCatalog::CANONICAL as $index => [$code, $label, $role]) {
                $version->steps()->create([
                    'ordre' => $index + 1,
                    'code' => $code,
                    'label' => $label,
                    'actor_role' => $role,
                ]);
            }
        }

        app(HabilitationCatalogue::class)->assurer();
    }

    private function user(?OrganizationUnit $unit, string $name, string $email, string $function, string $role, string $initials): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'uuid' => (string) Str::uuid(),
                'organization_unit_id' => $unit?->id,
                'name' => $name,
                'function_title' => $function,
                'role' => $role,
                'initials' => $initials,
                'account_status' => 'actif',
                'password' => Hash::make('password'),
            ],
        );
        $roleId = Role::query()->where('code', $role)->value('id');
        if ($roleId) {
            $user->roles()->syncWithoutDetaching([$roleId]);
        }
    }
}
