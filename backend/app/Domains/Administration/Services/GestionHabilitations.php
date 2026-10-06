<?php

namespace App\Domains\Administration\Services;

use App\Domains\Administration\Models\Permission;
use App\Domains\Administration\Models\Role;
use App\Domains\Administration\Models\SodRule;
use App\Domains\Tasks\Models\WorkflowTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Matrice des rôles et habilitations contextualisées.
 * Une case « appliquée » change un contrôle déjà exécuté par Laravel.
 * Les autres cases sont enregistrées et affichées dans les droits effectifs,
 * sans ouvrir un second moteur d’autorisation.
 */
class GestionHabilitations
{
    /**
     * @var list<array{code: string, label: string, famille: string}>
     */
    public const MODULES = [
        ['code' => 'preparation', 'label' => 'Préparation budgétaire', 'famille' => 'Budget'],
        ['code' => 'budget', 'label' => 'Budget', 'famille' => 'Budget'],
        ['code' => 'besoins', 'label' => 'Expressions de besoins', 'famille' => 'Chaîne de dépense'],
        ['code' => 'engagements', 'label' => 'Engagements', 'famille' => 'Chaîne de dépense'],
        ['code' => 'liquidations', 'label' => 'Liquidations', 'famille' => 'Chaîne de dépense'],
        ['code' => 'ordonnancements', 'label' => 'Ordonnancements', 'famille' => 'Chaîne de dépense'],
        ['code' => 'paiements', 'label' => 'Paiements', 'famille' => 'Chaîne de dépense'],
        ['code' => 'virements', 'label' => 'Virements de crédits', 'famille' => 'Budget'],
        ['code' => 'recettes', 'label' => 'Recettes', 'famille' => 'Budget'],
        ['code' => 'reporting', 'label' => 'Reporting et états', 'famille' => 'Pilotage'],
        ['code' => 'suivi', 'label' => 'Suivi-évaluation', 'famille' => 'Pilotage'],
        ['code' => 'documents', 'label' => 'Documents', 'famille' => 'Pilotage'],
        ['code' => 'administration', 'label' => 'Administration', 'famille' => 'Administration'],
    ];

    /**
     * @var list<array{code: string, label: string}>
     */
    public const ACTIONS = [
        ['code' => 'consulter', 'label' => 'Consulter'],
        ['code' => 'creer', 'label' => 'Créer'],
        ['code' => 'modifier', 'label' => 'Modifier'],
        ['code' => 'valider', 'label' => 'Valider'],
        ['code' => 'rejeter', 'label' => 'Rejeter'],
        ['code' => 'exporter', 'label' => 'Exporter'],
        ['code' => 'signer', 'label' => 'Signer'],
        ['code' => 'executer', 'label' => 'Exécuter'],
    ];

    /**
     * Cases dont le niveau modifie un contrôle déjà en place.
     *
     * @var array<string, string>
     */
    public const ALIAS = [
        'besoins.creer' => 'eb.creer',
        'engagements.valider' => 'engagement.viser',
        'liquidations.valider' => 'liquidation.viser',
        'paiements.signer' => 'paiement.signer',
        'paiements.executer' => 'paiement.executer',
    ];

    public function __construct(private AdministrationService $administration) {}

    /**
     * @return array<string, mixed>
     */
    public function grille(string $recherche = ''): array
    {
        $this->assurerPermissions();
        $codes = $this->codesPermission();
        $permissions = Permission::query()->whereIn('code', array_values($codes))->get()->keyBy('code');
        $pivots = DB::table('role_permission')
            ->whereIn('permission_id', $permissions->pluck('id'))
            ->get()
            ->groupBy('role_id');

        $roles = Role::query()->orderBy('label')
            ->when($recherche !== '', function ($query) use ($recherche) {
                $query->where(function ($inner) use ($recherche) {
                    $inner->where('label', 'like', '%'.$recherche.'%')
                        ->orWhere('code', 'like', '%'.$recherche.'%');
                });
            })
            ->get();

        $agents = $this->agentsParRole();

        return [
            'modules' => self::MODULES,
            'actions' => self::ACTIONS,
            'roles' => $roles->map(function (Role $role) use ($codes, $permissions, $pivots, $agents) {
                $liens = $pivots->get($role->id, collect())->keyBy('permission_id');
                $cellules = [];
                $accordees = 0;
                foreach ($codes as $cle => $permissionCode) {
                    $permission = $permissions->get($permissionCode);
                    $niveau = 'denied';
                    if ($permission !== null && $liens->has($permission->id)) {
                        $niveau = (string) ($liens->get($permission->id)->level ?: 'scoped');
                    }
                    if ($niveau !== 'denied') {
                        $accordees++;
                    }
                    $cellules[$cle] = [
                        'permission' => $permissionCode,
                        'niveau' => $niveau,
                        'applique' => HabilitationCatalogue::appliquee($permissionCode),
                    ];
                }

                return [
                    'code' => $role->code,
                    'label' => $role->label,
                    'description' => $role->description,
                    'categorie' => $role->category,
                    'systeme' => (bool) $role->system,
                    'actif' => (bool) $role->active,
                    'agents' => $agents[$role->code] ?? 0,
                    'permissions_accordees' => $accordees,
                    'permissions_total' => count($codes),
                    'updated_at' => $role->updated_at?->toIso8601String(),
                    'incompatibilites' => $this->incompatibilites($role->code),
                    'cellules' => $cellules,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  list<array{cle: string, niveau: string}>  $cellules
     */
    public function enregistrerMatrice(User $actor, string $roleCode, array $cellules, ?string $connu): Role
    {
        $role = Role::query()->where('code', $roleCode)->first();
        if ($role === null) {
            throw ValidationException::withMessages(['role' => 'Ce rôle n’existe pas.']);
        }
        if ($connu !== null && $connu !== '' && $role->updated_at?->toIso8601String() !== $connu) {
            throw ValidationException::withMessages(['role' => 'Ce rôle a été modifié entre-temps. Rechargez la matrice avant d’enregistrer.']);
        }

        $this->assurerPermissions();
        $avant = [];
        $apres = [];

        DB::transaction(function () use ($actor, $role, $cellules, &$avant, &$apres): void {
            foreach ($cellules as $cellule) {
                $cle = (string) ($cellule['cle'] ?? '');
                $niveau = (string) ($cellule['niveau'] ?? '');
                $permissionCode = $this->permissionDe($cle);
                if (! in_array($niveau, ['global', 'scoped', 'denied'], true)) {
                    throw ValidationException::withMessages(['niveau' => 'Le niveau doit être global, limité au périmètre, ou non accordé.']);
                }
                if ($permissionCode === 'ordonnancement.signer') {
                    throw ValidationException::withMessages(['permission' => 'La signature d’un ordonnancement suit le seuil de délégation, pas une case de la matrice.']);
                }
                $permission = Permission::query()->where('code', $permissionCode)->first();
                if ($permission === null) {
                    throw ValidationException::withMessages(['permission' => 'Cette case ne correspond à aucune permission.']);
                }
                $actuel = DB::table('role_permission')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permission->id)
                    ->value('level');
                $avant[$cle] = $actuel ?: 'denied';
                if ($niveau === 'denied') {
                    DB::table('role_permission')
                        ->where('role_id', $role->id)
                        ->where('permission_id', $permission->id)
                        ->delete();
                } elseif ($actuel === null) {
                    DB::table('role_permission')->insert([
                        'role_id' => $role->id,
                        'permission_id' => $permission->id,
                        'level' => $niveau,
                    ]);
                } else {
                    DB::table('role_permission')
                        ->where('role_id', $role->id)
                        ->where('permission_id', $permission->id)
                        ->update(['level' => $niveau]);
                }
                $apres[$cle] = $niveau;
            }
            $role->touch();
            $this->administration->audit($actor, 'role.matrice', 'role', $role->code, $avant, $apres);
        });

        return $role->fresh();
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    public function liste(array $filtres): array
    {
        $aujourd = now()->toDateString();
        $dans30 = now()->addDays(30)->toDateString();
        $base = DB::table('user_roles');
        $indicateurs = [
            'actives' => (clone $base)->where('status', 'active')->where(function ($query) use ($aujourd) {
                $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $aujourd);
            })->count(),
            'expirent' => (clone $base)->where('status', 'active')->whereDate('ends_on', '>=', $aujourd)->whereDate('ends_on', '<=', $dans30)->count(),
            'en_attente' => (clone $base)->where('status', 'en_attente')->count(),
            'conflits' => (clone $base)->where('status', 'bloquee')->count(),
        ];

        $page = max(1, (int) ($filtres['page'] ?? 1));
        $taille = min(100, max(1, (int) ($filtres['per_page'] ?? 25)));
        $requete = DB::table('user_roles')
            ->join('users', 'users.id', '=', 'user_roles.user_id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->leftJoin('organization_units as perimetre', 'perimetre.id', '=', 'user_roles.scope_unit_id')
            ->leftJoin('organization_units as rattachement', 'rattachement.id', '=', 'users.organization_unit_id')
            ->select([
                'user_roles.id',
                'users.id as user_id',
                'users.name as agent',
                'users.matricule',
                'users.function_title as fonction',
                'rattachement.name as structure',
                'roles.code as role',
                'roles.label as role_label',
                'user_roles.status',
                'user_roles.plafond_fcfa',
                'user_roles.origine',
                'user_roles.starts_on',
                'user_roles.ends_on',
                'user_roles.derogation',
                'user_roles.motif',
                'perimetre.name as perimetre',
            ])
            ->when(($filtres['q'] ?? '') !== '', function ($query) use ($filtres) {
                $terme = '%'.mb_strtolower((string) $filtres['q']).'%';
                $query->where(function ($inner) use ($terme) {
                    $inner->whereRaw('lower(users.name) like ?', [$terme])
                        ->orWhereRaw('lower(users.matricule) like ?', [$terme])
                        ->orWhereRaw('lower(users.email) like ?', [$terme]);
                });
            })
            ->when(($filtres['role'] ?? '') !== '', fn ($query) => $query->where('roles.code', $filtres['role']))
            ->when(($filtres['statut'] ?? '') === 'expire_bientot', function ($query) use ($aujourd, $dans30) {
                $query->where('user_roles.status', 'active')
                    ->whereDate('ends_on', '>=', $aujourd)
                    ->whereDate('ends_on', '<=', $dans30);
            })
            ->when(($filtres['statut'] ?? '') !== '' && ($filtres['statut'] ?? '') !== 'expire_bientot', fn ($query) => $query->where('user_roles.status', $filtres['statut']))
            ->orderByDesc('user_roles.id');

        $total = (clone $requete)->count();
        $lignes = $requete->forPage($page, $taille)->get()->map(fn (object $ligne) => [
            'id' => $ligne->id,
            'user_id' => $ligne->user_id,
            'agent' => $ligne->agent,
            'matricule' => $ligne->matricule,
            'fonction' => $ligne->fonction,
            'structure' => $ligne->structure,
            'role' => $ligne->role,
            'role_label' => $ligne->role_label,
            'perimetre' => $ligne->perimetre ?: 'Toute la Commission',
            'plafond' => $ligne->plafond_fcfa !== null ? (int) $ligne->plafond_fcfa : null,
            'origine' => $ligne->origine,
            'debut' => $ligne->starts_on,
            'fin' => $ligne->ends_on,
            'statut' => $this->statutVisible($ligne->status, $ligne->ends_on),
            'derogation' => (bool) $ligne->derogation,
            'motif' => $ligne->motif,
        ])->all();

        return [
            'indicateurs' => $indicateurs,
            'data' => $lignes,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $taille],
        ];
    }

    public function conflit(User $cible, string $role): ?string
    {
        foreach ($cible->heldRoleCodes() as $tenu) {
            try {
                $this->administration->assertCompatible($tenu, $role);
            } catch (ValidationException $exception) {
                return $exception->errors()['role'][0] ?? 'Séparation des tâches.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function soumettre(User $actor, array $data): array
    {
        $cible = User::query()->findOrFail($data['user_id']);
        if ($actor->id === $cible->id) {
            throw ValidationException::withMessages(['user_id' => 'Un administrateur ne s’attribue pas une habilitation.']);
        }
        $role = Role::query()->where('code', $data['role'])->where('active', true)->first();
        if ($role === null) {
            throw ValidationException::withMessages(['role' => 'Ce rôle n’existe pas ou n’est pas actif.']);
        }
        if ($cible->role === $role->code) {
            throw ValidationException::withMessages(['role' => 'Ce rôle est déjà le rôle principal du compte.']);
        }
        if (($data['ends_on'] ?? null) !== null && $data['ends_on'] < $data['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => 'La fin de validité doit suivre le début.']);
        }

        $conflit = $this->conflit($cible, $role->code);
        $derogation = (bool) ($data['derogation'] ?? false);
        if ($conflit !== null && ! $derogation) {
            throw ValidationException::withMessages(['role' => $conflit]);
        }
        if ($derogation && trim((string) ($data['motif'] ?? '')) === '') {
            throw ValidationException::withMessages(['motif' => 'Une dérogation exige un motif.']);
        }

        $pivot = [
            'status' => 'en_attente',
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'motif' => $derogation ? $data['motif'] : ($data['motif'] ?? null),
            'granted_by' => $actor->id,
            'decision' => null,
            'plafond_fcfa' => $data['plafond_fcfa'] ?? null,
            'origine' => $data['origine'],
            'scope_unit_id' => $data['scope_unit_id'] ?? null,
            'derogation' => $derogation,
            'updated_at' => now(),
        ];

        $existant = DB::table('user_roles')->where('user_id', $cible->id)->where('role_id', $role->id)->first();
        if ($existant !== null && $existant->status === 'active') {
            throw ValidationException::withMessages(['role' => 'Cette habilitation est déjà active.']);
        }

        $id = DB::transaction(function () use ($cible, $role, $pivot, $existant, $actor, $conflit) {
            if ($existant === null) {
                $pivot['created_at'] = now();
                $id = DB::table('user_roles')->insertGetId(array_merge($pivot, [
                    'user_id' => $cible->id,
                    'role_id' => $role->id,
                ]));
            } else {
                DB::table('user_roles')->where('id', $existant->id)->update($pivot);
                $id = $existant->id;
            }
            $this->tacheValidation($id, $cible, $role->label);
            $this->administration->audit(
                $actor,
                'habilitation.soumettre',
                'user_role',
                (string) $id,
                null,
                ['agent' => $cible->name, 'role' => $role->code, 'conflit' => $conflit, 'derogation' => $pivot['derogation']],
                $pivot['motif'],
            );

            return $id;
        });

        return ['id' => $id, 'statut' => 'en_attente', 'conflit' => $conflit];
    }

    public function decider(User $actor, int $id, string $decision, ?string $motif): void
    {
        $ligne = DB::table('user_roles')->where('id', $id)->first();
        if ($ligne === null) {
            throw ValidationException::withMessages(['habilitation' => 'Cette habilitation n’existe pas.']);
        }
        $autorise = $actor->role === 'administrateur_habilitations' || $actor->holds('ordonnateur');
        if (! $autorise) {
            throw ValidationException::withMessages(['habilitation' => 'La décision est réservée à l’administrateur des habilitations ou à l’ordonnateur.']);
        }

        $statut = match ($decision) {
            'approuver' => 'active',
            'rejeter' => 'rejetee',
            'suspendre' => 'suspendue',
            'revoquer' => 'revoquee',
            default => null,
        };
        if ($statut === null) {
            throw ValidationException::withMessages(['decision' => 'Décision inconnue.']);
        }
        if ($decision === 'approuver' && $ligne->status !== 'en_attente') {
            throw ValidationException::withMessages(['habilitation' => 'Seule une habilitation en attente peut être validée.']);
        }
        if (in_array($decision, ['rejeter'], true) && $ligne->status !== 'en_attente') {
            throw ValidationException::withMessages(['habilitation' => 'Seule une habilitation en attente peut être rejetée.']);
        }
        if (in_array($decision, ['suspendre', 'revoquer'], true) && ! in_array($ligne->status, ['active', 'suspendue'], true)) {
            throw ValidationException::withMessages(['habilitation' => 'Seule une habilitation active peut être suspendue ou révoquée.']);
        }

        DB::transaction(function () use ($actor, $ligne, $statut, $decision, $motif): void {
            DB::table('user_roles')->where('id', $ligne->id)->update([
                'status' => $statut,
                'decision' => $motif,
                'updated_at' => now(),
            ]);
            WorkflowTask::query()
                ->where('fingerprint', 'habilitation:'.$ligne->id)
                ->where('status', '!=', 'terminee')
                ->update([
                    'status' => 'terminee',
                    'completed_at' => now(),
                    'completion_action' => $decision,
                ]);
            $this->administration->audit(
                $actor,
                'habilitation.'.$decision,
                'user_role',
                (string) $ligne->id,
                ['statut' => $ligne->status],
                ['statut' => $statut],
                $motif,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function droits(User $user): array
    {
        $tenus = $user->heldRoleCodes();
        $roles = Role::query()->whereIn('code', $tenus)->get()->keyBy('code');
        $plafonds = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->id)
            ->where('user_roles.status', 'active')
            ->get(['roles.code', 'roles.label', 'user_roles.plafond_fcfa', 'user_roles.scope_unit_id'])
            ->keyBy('code');

        $lignes = DB::table('role_permission')
            ->join('roles', 'roles.id', '=', 'role_permission.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->whereIn('roles.code', $tenus)
            ->where('roles.active', true)
            ->where('permissions.active', true)
            ->where('role_permission.level', '!=', 'denied')
            ->get(['permissions.module', 'permissions.label', 'permissions.code', 'roles.label as role_label', 'roles.code as role_code', 'role_permission.level']);

        $groupes = [];
        foreach ($lignes as $ligne) {
            $cle = $ligne->module.'|'.$ligne->code;
            if (! isset($groupes[$cle])) {
                $groupes[$cle] = [
                    'module' => $ligne->module,
                    'action' => $ligne->label,
                    'permission' => $ligne->code,
                    'niveau' => $ligne->level,
                    'provenance' => [],
                    'applique' => HabilitationCatalogue::appliquee($ligne->code),
                ];
            }
            if ($ligne->level === 'global') {
                $groupes[$cle]['niveau'] = 'global';
            }
            $plafond = $plafonds->get($ligne->role_code)?->plafond_fcfa;
            $groupes[$cle]['provenance'][$ligne->role_label] = $plafond !== null ? (int) $plafond : null;
        }

        $habilitations = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->leftJoin('organization_units', 'organization_units.id', '=', 'user_roles.scope_unit_id')
            ->where('user_roles.user_id', $user->id)
            ->orderByDesc('user_roles.id')
            ->get([
                'user_roles.id', 'roles.code', 'roles.label', 'user_roles.status', 'user_roles.plafond_fcfa',
                'user_roles.origine', 'user_roles.starts_on', 'user_roles.ends_on', 'user_roles.derogation',
                'organization_units.name as perimetre',
            ])
            ->map(fn (object $ligne) => [
                'id' => $ligne->id,
                'role' => $ligne->code,
                'role_label' => $ligne->label,
                'statut' => $this->statutVisible($ligne->status, $ligne->ends_on),
                'perimetre' => $ligne->perimetre ?: 'Toute la Commission',
                'plafond' => $ligne->plafond_fcfa !== null ? (int) $ligne->plafond_fcfa : null,
                'origine' => $ligne->origine,
                'debut' => $ligne->starts_on,
                'fin' => $ligne->ends_on,
                'derogation' => (bool) $ligne->derogation,
            ])->all();

        return [
            'role_principal' => $user->role,
            'roles' => $roles->map(fn (Role $role) => ['code' => $role->code, 'label' => $role->label])->values()->all(),
            'habilitations' => $habilitations,
            'droits' => array_values(array_map(function (array $groupe) {
                $groupe['provenance'] = collect($groupe['provenance'])->map(function (?int $plafond, string $label) {
                    return $plafond === null ? $label : $label.' ≤ '.number_format($plafond, 0, ',', ' ').' FCFA';
                })->values()->all();

                return $groupe;
            }, $groupes)),
        ];
    }

    public function expirer(): int
    {
        $echues = DB::table('user_roles')
            ->where('status', 'active')
            ->whereDate('ends_on', '<', now()->toDateString())
            ->get(['id', 'user_id', 'role_id']);
        foreach ($echues as $ligne) {
            DB::table('user_roles')->where('id', $ligne->id)->update(['status' => 'expiree', 'updated_at' => now()]);
            DB::table('audit_events')->insert([
                'actor_id' => null,
                'role' => 'systeme',
                'action' => 'habilitation.expirer',
                'object_type' => 'user_role',
                'object_id' => (string) $ligne->id,
                'before' => json_encode(['statut' => 'active']),
                'after' => json_encode(['statut' => 'expiree']),
                'motif' => 'Date de fin dépassée',
                'result' => 'succes',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $echues->count();
    }

    private function assurerPermissions(): void
    {
        app(HabilitationCatalogue::class)->assurer();
        foreach (self::MODULES as $module) {
            foreach (self::ACTIONS as $action) {
                $cle = $module['code'].'.'.$action['code'];
                if (isset(self::ALIAS[$cle])) {
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

    /**
     * @return array<string, string>
     */
    private function codesPermission(): array
    {
        $codes = [];
        foreach (self::MODULES as $module) {
            foreach (self::ACTIONS as $action) {
                $cle = $module['code'].'.'.$action['code'];
                $codes[$cle] = self::ALIAS[$cle] ?? $cle;
            }
        }

        return $codes;
    }

    private function permissionDe(string $cle): string
    {
        if (! array_key_exists($cle, $this->codesPermission())) {
            throw ValidationException::withMessages(['permission' => 'Case inconnue : '.$cle]);
        }

        return self::ALIAS[$cle] ?? $cle;
    }

    /**
     * @return array<string, int>
     */
    private function agentsParRole(): array
    {
        $principaux = DB::table('users')->where('account_status', 'actif')->select('role')->selectRaw('count(*) as total')->groupBy('role')->pluck('total', 'role');
        $ajoutes = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('users', 'users.id', '=', 'user_roles.user_id')
            ->where('user_roles.status', 'active')
            ->where('users.account_status', 'actif')
            ->whereColumn('users.role', '!=', 'roles.code')
            ->select('roles.code')
            ->selectRaw('count(*) as total')
            ->groupBy('roles.code')
            ->pluck('total', 'code');
        $codes = $principaux->keys()->merge($ajoutes->keys())->unique();
        $totaux = [];
        foreach ($codes as $code) {
            $totaux[(string) $code] = (int) ($principaux[$code] ?? 0) + (int) ($ajoutes[$code] ?? 0);
        }

        return $totaux;
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    private function incompatibilites(string $code): array
    {
        return SodRule::query()
            ->where('active', true)
            ->where(function ($query) use ($code) {
                $query->where('role_a', $code)->orWhere('role_b', $code);
            })
            ->get()
            ->map(function (SodRule $regle) use ($code) {
                $autre = $regle->role_a === $code ? $regle->role_b : $regle->role_a;
                $label = Role::query()->where('code', $autre)->value('label') ?: $autre;

                return ['code' => $autre, 'label' => $label, 'motif' => $regle->label];
            })
            ->values()
            ->all();
    }

    private function statutVisible(string $statut, ?string $fin): string
    {
        if ($statut === 'active' && $fin !== null && $fin <= now()->addDays(30)->toDateString() && $fin >= now()->toDateString()) {
            return 'expire_bientot';
        }

        return $statut;
    }

    private function tacheValidation(int $id, User $cible, string $roleLabel): void
    {
        $fingerprint = 'habilitation:'.$id;
        if (WorkflowTask::query()->where('fingerprint', $fingerprint)->where('status', '!=', 'terminee')->exists()) {
            return;
        }
        $task = WorkflowTask::query()->create([
            'reference' => 'TMP-habilitation-'.$id,
            'fingerprint' => $fingerprint,
            'module' => 'administration',
            'entity_type' => 'user_role',
            'entity_id' => $id,
            'dossier_reference' => 'HAB-'.$id,
            'subject' => 'Valider l’habilitation de '.$cible->name.' comme '.$roleLabel,
            'action' => 'valider',
            'step' => 'ordonnateur',
            'assigned_role' => 'ordonnateur',
            'priority' => 'haute',
            'status' => 'a_traiter',
            'amount' => 0,
            'demandeur' => $cible->name,
            'lien' => '/administration/habilitations',
            'assigned_at' => now(),
            'due_on' => now()->addDays(5)->toDateString(),
        ]);
        $task->forceFill(['reference' => sprintf('TSK-%d-%06d', now()->year, $task->id)])->save();
    }
}
