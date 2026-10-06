<?php

namespace App\Domains\Administration\Services;

use App\Domains\Administration\Models\Permission;
use App\Domains\Administration\Models\Role;
use App\Domains\Administration\Models\SystemSetting;

/**
 * Catalogue des actions dont le contrôle est implémenté.
 * Une permission absente de cette liste ne peut pas être créée depuis l’interface.
 */
class HabilitationCatalogue
{
    /**
     * Les permissions appliquées sont lues dans role_permission dès que le catalogue initial est posé.
     * Les autres lignes restent expliquées par le rôle, le périmètre ou le seuil.
     *
     * @var list<array{code: string, module: string, label: string, appliquee: bool, roles: list<string>}>
     */
    public const LIGNES = [
        ['code' => 'eb.creer', 'module' => 'EB', 'label' => 'Créer une expression de besoin', 'appliquee' => true, 'roles' => ['initiateur']],
        ['code' => 'eb.consulter', 'module' => 'EB', 'label' => 'Consulter une expression de besoin', 'appliquee' => false, 'roles' => []],
        ['code' => 'engagement.consulter', 'module' => 'Engagement', 'label' => 'Consulter un engagement', 'appliquee' => false, 'roles' => []],
        ['code' => 'engagement.viser', 'module' => 'Engagement', 'label' => 'Viser un engagement', 'appliquee' => true, 'roles' => ['controleur_financier']],
        ['code' => 'liquidation.viser', 'module' => 'Liquidation', 'label' => 'Viser une liquidation', 'appliquee' => true, 'roles' => ['controleur_financier']],
        ['code' => 'ordonnancement.signer', 'module' => 'Ordonnancement', 'label' => 'Signer un ordonnancement', 'appliquee' => false, 'roles' => []],
        ['code' => 'paiement.signer', 'module' => 'Paiement', 'label' => 'Autoriser un paiement', 'appliquee' => true, 'roles' => ['agent_comptable']],
        ['code' => 'paiement.executer', 'module' => 'Paiement', 'label' => 'Enregistrer l’exécution d’un paiement', 'appliquee' => true, 'roles' => ['comptable']],
        ['code' => 'administration.consulter', 'module' => 'Administration', 'label' => 'Consulter l’administration', 'appliquee' => false, 'roles' => []],
        ['code' => 'administration.habilitations', 'module' => 'Administration', 'label' => 'Administrer les habilitations', 'appliquee' => false, 'roles' => []],
        ['code' => 'administration.parametrer', 'module' => 'Administration', 'label' => 'Paramétrer l’application', 'appliquee' => false, 'roles' => []],
    ];

    /**
     * @return list<string>
     */
    public static function rolesParDefaut(string $code): array
    {
        foreach (self::LIGNES as $ligne) {
            if ($ligne['code'] === $code && $ligne['appliquee']) {
                return $ligne['roles'];
            }
        }

        return [];
    }

    public static function appliquee(string $code): bool
    {
        foreach (self::LIGNES as $ligne) {
            if ($ligne['code'] === $code) {
                return $ligne['appliquee'];
            }
        }

        return false;
    }

    /**
     * @return list<array{code: string, module: string, label: string, appliquee: bool}>
     */
    public function catalogue(): array
    {
        return array_map(fn (array $ligne): array => [
            'code' => $ligne['code'],
            'module' => $ligne['module'],
            'label' => $ligne['label'],
            'appliquee' => $ligne['appliquee'],
        ], self::LIGNES);
    }

    /**
     * Pose une fois les liens par défaut. Les retraits ultérieurs ne sont pas réécrits.
     */
    public function assurer(): void
    {
        foreach (self::LIGNES as $ligne) {
            if (! $ligne['appliquee']) {
                continue;
            }
            Permission::query()->firstOrCreate(
                ['code' => $ligne['code']],
                [
                    'module' => $ligne['module'],
                    'label' => $ligne['label'],
                    'kind' => 'metier',
                    'description' => 'Permission appliquée par les policies et les workflows.',
                    'sensitivity' => 'elevee',
                    'active' => true,
                    'origin' => 'systeme',
                ],
            );
        }

        if (SystemSetting::query()->where('key', 'habilitations.catalogue_initial')->exists()) {
            return;
        }
        if (Role::query()->doesntExist()) {
            return;
        }

        foreach (self::LIGNES as $ligne) {
            if (! $ligne['appliquee']) {
                continue;
            }
            $permission = Permission::query()->where('code', $ligne['code'])->first();
            if ($permission === null) {
                continue;
            }
            foreach ($ligne['roles'] as $roleCode) {
                $role = Role::query()->where('code', $roleCode)->first();
                if ($role !== null) {
                    $role->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }
        }

        SystemSetting::query()->firstOrCreate(
            ['key' => 'habilitations.catalogue_initial'],
            ['value' => now()->toIso8601String(), 'critical' => true],
        );
    }
}
