<?php

namespace App\Domains\Suppliers\Services;

use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Suppliers\Models\Tiers;
use App\Domains\Suppliers\Models\TiersBankAccount;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Support\TransitionLock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TiersService
{
    /**
     * @var list<string>
     */
    public const EDITORS = ['expert_budget', 'comptable', 'administrateur_fonctionnel'];

    /**
     * @var list<string>
     */
    public const ACCOUNT_CREATORS = ['comptable', 'administrateur_fonctionnel'];

    /**
     * @var list<string>
     */
    public const ACCOUNT_VALIDATORS = ['chef_comptable', 'agent_comptable'];

    /**
     * @var list<string>
     */
    public const STATUS_MANAGERS = ['agent_comptable', 'administrateur_fonctionnel'];

    /**
     * @param  array{type: string, raison_sociale: string, nif?: string|null, rccm?: string|null, pays?: string|null, adresse?: string|null, email?: string|null, telephone?: string|null}  $data
     */
    public function create(User $actor, array $data): Tiers
    {
        $this->assertRole($actor, self::EDITORS, 'La création d’un tiers est réservée au Budget, au Comptable ou à l’administration fonctionnelle.');
        $normalized = Tiers::normalize($data['raison_sociale']);
        if ($normalized === '') {
            throw ValidationException::withMessages(['raison_sociale' => 'La raison sociale est obligatoire.']);
        }

        return DB::transaction(function () use ($actor, $data, $normalized) {
            $nif = filled($data['nif'] ?? null) ? strtoupper(trim((string) $data['nif'])) : null;
            if ($nif !== null && Tiers::query()->where('nif', $nif)->exists()) {
                throw ValidationException::withMessages(['nif' => 'Un tiers porte déjà ce NIF.']);
            }
            $homonym = Tiers::query()->where('nom_normalise', $normalized)->first();
            if ($homonym !== null) {
                throw ValidationException::withMessages([
                    'raison_sociale' => 'Doublon probable : '.$homonym->code.' · '.$homonym->raison_sociale.'.',
                ]);
            }

            $last = Tiers::query()->lockForUpdate()->orderByDesc('id')->value('code');
            $sequence = $last !== null ? ((int) substr($last, 4)) + 1 : 1;
            $tiers = Tiers::query()->create([
                'code' => sprintf('TIE-%06d', $sequence),
                'type' => $data['type'],
                'raison_sociale' => trim($data['raison_sociale']),
                'nom_normalise' => $normalized,
                'nif' => $nif,
                'rccm' => $data['rccm'] ?? null,
                'pays' => $data['pays'] ?? null,
                'adresse' => $data['adresse'] ?? null,
                'email' => $data['email'] ?? null,
                'telephone' => $data['telephone'] ?? null,
                'status' => 'actif',
                'created_by' => $actor->id,
            ]);
            FinancialAudit::record($actor, 'tiers.creation', 'tiers', (string) $tiers->id, null, $tiers->only(['code', 'raison_sociale', 'nif']));

            return $tiers;
        });
    }

    /**
     * Mise à jour de l’identité du tiers : mêmes contrôles d’unicité qu’à la création (NIF, homonyme).
     *
     * @param  array{type: string, raison_sociale: string, nif?: string|null, rccm?: string|null, pays?: string|null, adresse?: string|null, email?: string|null, telephone?: string|null}  $data
     */
    public function update(User $actor, Tiers $tiers, array $data): Tiers
    {
        $this->assertRole($actor, self::EDITORS, 'La modification d’un tiers est réservée au Budget, au Comptable ou à l’administration fonctionnelle.');
        $normalized = Tiers::normalize($data['raison_sociale']);
        if ($normalized === '') {
            throw ValidationException::withMessages(['raison_sociale' => 'La raison sociale est obligatoire.']);
        }

        return TransitionLock::run($tiers, function (Tiers $tiers) use ($actor, $data, $normalized) {
            if ($tiers->status === 'archive') {
                throw ValidationException::withMessages(['tiers' => 'Un tiers archivé ne se modifie plus.']);
            }
            $nif = filled($data['nif'] ?? null) ? strtoupper(trim((string) $data['nif'])) : null;
            if ($nif !== null && Tiers::query()->where('nif', $nif)->whereKeyNot($tiers->id)->exists()) {
                throw ValidationException::withMessages(['nif' => 'Un tiers porte déjà ce NIF.']);
            }
            $homonym = Tiers::query()->where('nom_normalise', $normalized)->whereKeyNot($tiers->id)->first();
            if ($homonym !== null) {
                throw ValidationException::withMessages([
                    'raison_sociale' => 'Doublon probable : '.$homonym->code.' · '.$homonym->raison_sociale.'.',
                ]);
            }
            $fields = ['type', 'raison_sociale', 'nif', 'rccm', 'pays', 'adresse', 'email', 'telephone'];
            $before = $tiers->only($fields);
            $tiers->forceFill([
                'type' => $data['type'],
                'raison_sociale' => trim($data['raison_sociale']),
                'nom_normalise' => $normalized,
                'nif' => $nif,
                'rccm' => $data['rccm'] ?? null,
                'pays' => $data['pays'] ?? null,
                'adresse' => $data['adresse'] ?? null,
                'email' => $data['email'] ?? null,
                'telephone' => $data['telephone'] ?? null,
            ])->save();
            $after = $tiers->only($fields);
            $changed = array_keys(array_filter($after, fn ($value, string $field): bool => (string) $value !== (string) ($before[$field] ?? ''), ARRAY_FILTER_USE_BOTH));
            FinancialAudit::record($actor, 'tiers.modification', 'tiers', (string) $tiers->id, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));

            return $tiers->fresh();
        });
    }

    /**
     * Suppression réservée à une fiche jamais utilisée : sans compte bancaire,
     * engagement, marché ni titre de recette. Sinon, on suspend, bloque ou fusionne.
     */
    public function supprimer(User $actor, Tiers $tiers): void
    {
        $this->assertRole($actor, self::STATUS_MANAGERS, 'La suppression d’un tiers est réservée à l’Agent Comptable ou à l’administration fonctionnelle.');
        $usages = array_filter([
            'compte(s) bancaire(s)' => $tiers->bankAccounts()->count(),
            'engagement(s)' => Engagement::query()->where('tiers_id', $tiers->id)->count(),
            'marché(s)' => DB::table('marches')->where('tiers_id', $tiers->id)->count(),
            'titre(s) de recette' => DB::table('revenue_orders')->where('tiers_id', $tiers->id)->count(),
            'fusion(s)' => Tiers::query()->where('merged_into_id', $tiers->id)->count(),
        ]);
        if ($usages !== []) {
            $detail = collect($usages)->map(fn (int $nombre, string $objet): string => $nombre.' '.$objet)->implode(', ');
            throw ValidationException::withMessages(['tiers' => 'Ce tiers est déjà utilisé ('.$detail.') : suspendez-le, bloquez-le ou fusionnez-le plutôt que de le supprimer.']);
        }

        DB::transaction(function () use ($actor, $tiers): void {
            FinancialAudit::record($actor, 'tiers.suppression', 'tiers', (string) $tiers->id, $tiers->only(['code', 'raison_sociale', 'nif']), null);
            $tiers->delete();
        });
    }

    public function changeStatus(User $actor, Tiers $tiers, string $status, string $motif): Tiers
    {
        $this->assertRole($actor, self::STATUS_MANAGERS, 'La suspension ou le blocage d’un tiers est réservé à l’Agent Comptable ou à l’administration fonctionnelle.');
        if (! in_array($status, Tiers::STATUSES, true)) {
            throw ValidationException::withMessages(['statut' => 'Statut de tiers inconnu.']);
        }

        return TransitionLock::run($tiers, function (Tiers $tiers) use ($actor, $status, $motif) {
            $before = $tiers->status;
            $tiers->forceFill(['status' => $status, 'status_motif' => $motif])->save();
            FinancialAudit::record($actor, 'tiers.statut', 'tiers', (string) $tiers->id, ['statut' => $before], ['statut' => $status], $motif);

            return $tiers->fresh();
        });
    }

    /**
     * @param  array{banque: string, agence?: string|null, numero: string, titulaire: string, devise?: string|null, justificatif?: string|null}  $data
     */
    public function addAccount(User $actor, Tiers $tiers, array $data): TiersBankAccount
    {
        $this->assertRole($actor, self::ACCOUNT_CREATORS, 'L’ajout d’un compte bancaire est réservé au Comptable ou à l’administration fonctionnelle.');
        $numero = strtoupper(preg_replace('/\s+/', '', (string) $data['numero']));
        if ($numero === '') {
            throw ValidationException::withMessages(['numero' => 'Le numéro de compte est obligatoire.']);
        }

        return TransitionLock::run($tiers, function (Tiers $tiers) use ($actor, $data, $numero) {
            if ($tiers->status === 'bloque' || $tiers->status === 'archive') {
                throw ValidationException::withMessages(['tiers' => 'Ce tiers est '.$tiers->status.' : aucun compte ne peut lui être ajouté.']);
            }
            if ($tiers->bankAccounts()->where('numero', $numero)->exists()) {
                throw ValidationException::withMessages(['numero' => 'Ce compte existe déjà pour ce tiers.']);
            }
            $account = $tiers->bankAccounts()->create([
                'banque' => $data['banque'],
                'agence' => $data['agence'] ?? null,
                'numero' => $numero,
                'titulaire' => $data['titulaire'],
                'devise' => $data['devise'] ?? 'XAF',
                'justificatif' => $data['justificatif'] ?? null,
                'status' => TiersBankAccount::EN_ATTENTE,
                'created_by' => $actor->id,
            ]);
            FinancialAudit::record($actor, 'tiers.compte_ajout', 'tiers_bank_account', (string) $account->id, null, ['tiers' => $tiers->code, 'banque' => $account->banque, 'compte' => $account->maskedNumber()]);

            return $account;
        });
    }

    /**
     * Validation par un second acteur : le créateur du compte ne peut pas le
     * valider (séparation des fonctions, CDC §39).
     */
    public function validateAccount(User $actor, TiersBankAccount $account): TiersBankAccount
    {
        $this->assertRole($actor, self::ACCOUNT_VALIDATORS, 'La validation d’un compte bancaire est réservée au Chef Comptable ou à l’Agent Comptable.');

        return TransitionLock::run($account, function (TiersBankAccount $account) use ($actor) {
            $this->assertPending($account);
            if ($account->created_by === $actor->id) {
                throw ValidationException::withMessages(['action' => 'Séparation des fonctions : vous ne pouvez pas valider un compte que vous avez saisi.']);
            }
            $account->forceFill([
                'status' => TiersBankAccount::VALIDE,
                'validated_by' => $actor->id,
                'validated_at' => now(),
            ])->save();
            FinancialAudit::record($actor, 'tiers.compte_validation', 'tiers_bank_account', (string) $account->id, ['statut' => TiersBankAccount::EN_ATTENTE], ['statut' => TiersBankAccount::VALIDE]);

            return $account->fresh();
        });
    }

    public function rejectAccount(User $actor, TiersBankAccount $account, string $motif): TiersBankAccount
    {
        $this->assertRole($actor, self::ACCOUNT_VALIDATORS, 'Le rejet d’un compte bancaire est réservé au Chef Comptable ou à l’Agent Comptable.');

        return TransitionLock::run($account, function (TiersBankAccount $account) use ($actor, $motif) {
            $this->assertPending($account);
            $account->forceFill(['status' => TiersBankAccount::REJETE, 'rejection_motif' => $motif])->save();
            FinancialAudit::record($actor, 'tiers.compte_rejet', 'tiers_bank_account', (string) $account->id, ['statut' => TiersBankAccount::EN_ATTENTE], ['statut' => TiersBankAccount::REJETE], $motif);

            return $account->fresh();
        });
    }

    public function deactivateAccount(User $actor, TiersBankAccount $account, string $motif): TiersBankAccount
    {
        $this->assertRole($actor, [...self::ACCOUNT_VALIDATORS, ...self::ACCOUNT_CREATORS], 'La désactivation d’un compte est réservée à l’Agence Comptable.');

        return TransitionLock::run($account, function (TiersBankAccount $account) use ($actor, $motif) {
            if ($account->status === TiersBankAccount::DESACTIVE) {
                throw ValidationException::withMessages(['action' => 'Ce compte est déjà désactivé.']);
            }
            $before = $account->status;
            $account->forceFill(['status' => TiersBankAccount::DESACTIVE, 'deactivated_at' => now(), 'rejection_motif' => $motif])->save();
            FinancialAudit::record($actor, 'tiers.compte_desactivation', 'tiers_bank_account', (string) $account->id, ['statut' => $before], ['statut' => TiersBankAccount::DESACTIVE], $motif);

            return $account->fresh();
        });
    }

    /**
     * Tiers attendu pour un paiement : le tiers rattaché à l’engagement ou,
     * pour un engagement historique sans rattachement, le tiers actif dont le
     * nom normalisé correspond exactement au bénéficiaire liquidé.
     */
    public function expectedTiers(Paiement $paiement): ?Tiers
    {
        $engagement = $paiement->ordonnancement?->liquidation?->engagement;
        if ($engagement?->tiers_id !== null) {
            return Tiers::query()->find($engagement->tiers_id);
        }

        $beneficiary = $paiement->ordonnancement?->liquidation?->fournisseur ?? $engagement?->beneficiary_name;
        if (blank($beneficiary)) {
            return null;
        }

        $matches = Tiers::query()->where('nom_normalise', Tiers::normalize((string) $beneficiary))->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @return Collection<int, TiersBankAccount>
     */
    public function eligibleAccounts(Paiement $paiement): Collection
    {
        $tiers = $this->expectedTiers($paiement);
        if ($tiers === null || ! $tiers->isActive()) {
            return collect();
        }

        return $tiers->bankAccounts()->where('status', TiersBankAccount::VALIDE)->with('tiers')->get();
    }

    /**
     * @param  array{kind: string, reference: string, expires_on: string, path?: string|null}  $data
     */
    public function ajouterConformite(User $actor, Tiers $tiers, array $data): int
    {
        $this->assertRole($actor, self::EDITORS, 'Seul un gestionnaire du référentiel ajoute une pièce de conformité.');

        return (int) DB::table('tiers_compliance_documents')->insertGetId([
            'tiers_id' => $tiers->id,
            'kind' => $data['kind'],
            'reference' => $data['reference'],
            'expires_on' => $data['expires_on'],
            'path' => $data['path'] ?? null,
            'author_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array{occurred_on: string, nature: string, suite: string}  $data
     */
    public function ajouterIncident(User $actor, Tiers $tiers, array $data): int
    {
        $this->assertRole($actor, self::STATUS_MANAGERS, 'Seul un gestionnaire du référentiel consigne un incident.');

        return (int) DB::table('tiers_incidents')->insertGetId([
            'tiers_id' => $tiers->id,
            'occurred_on' => $data['occurred_on'],
            'nature' => $data['nature'],
            'suite' => $data['suite'],
            'author_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function fusionner(User $actor, Tiers $source, Tiers $cible): Tiers
    {
        $this->assertRole($actor, self::STATUS_MANAGERS, 'Seul un gestionnaire du référentiel fusionne deux tiers.');
        if ($source->id === $cible->id || $source->merged_into_id !== null || $cible->status !== 'actif') {
            throw ValidationException::withMessages(['tiers' => 'La fusion conserve le tiers actif et archive le doublon.']);
        }

        return DB::transaction(function () use ($source, $cible): Tiers {
            $source->bankAccounts()->update(['tiers_id' => $cible->id]);
            Engagement::query()->where('tiers_id', $source->id)->update(['tiers_id' => $cible->id]);
            DB::table('tiers_compliance_documents')->where('tiers_id', $source->id)->update(['tiers_id' => $cible->id]);
            DB::table('tiers_incidents')->where('tiers_id', $source->id)->update(['tiers_id' => $cible->id]);
            DB::table('marches')->where('tiers_id', $source->id)->update(['tiers_id' => $cible->id]);
            DB::table('revenue_orders')->where('tiers_id', $source->id)->update(['tiers_id' => $cible->id]);
            $source->forceFill([
                'status' => 'archive',
                'merged_into_id' => $cible->id,
                'status_motif' => 'Fusionné dans '.$cible->code,
            ])->save();

            return $cible->fresh();
        });
    }

    /**
     * @param  list<string>  $roles
     */
    private function assertRole(User $actor, array $roles, string $message): void
    {
        if (! $actor->holds(...$roles)) {
            throw ValidationException::withMessages(['action' => $message]);
        }
    }

    private function assertPending(TiersBankAccount $account): void
    {
        if ($account->status !== TiersBankAccount::EN_ATTENTE) {
            throw ValidationException::withMessages(['action' => 'Seul un compte en attente peut être validé ou rejeté.']);
        }
    }
}
