<?php

namespace App\Domains\Revenues\Services;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Revenues\Models\MemberState;
use App\Domains\Revenues\Models\RevenueCategory;
use App\Domains\Revenues\Models\RevenueContribution;
use App\Domains\Revenues\Models\RevenueDocument;
use App\Domains\Revenues\Models\RevenueForecast;
use App\Domains\Revenues\Models\RevenueOrder;
use App\Domains\Revenues\Models\RevenuePaymentMode;
use App\Domains\Revenues\Models\RevenueReminder;
use App\Domains\Revenues\Models\RevenueSetting;
use App\Domains\Suppliers\Models\Tiers;
use App\Domains\Tasks\Services\TaskProjector;
use App\Models\User;
use App\Shared\Support\NumberingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RevenueCycleService
{
    public function __construct(
        private readonly RevenueAccess $access,
        private readonly RevenueJournal $journal,
        private readonly NumberingService $numbers,
        private readonly TaskProjector $tasks,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function creerPrevision(User $actor, array $data): RevenueForecast
    {
        $this->guard($this->access->editer($actor));
        $exercice = $this->exercice((int) $data['exercice_id'], true);
        $category = $this->categorie((int) $data['category_id']);
        $unit = $this->unite($data['organization_unit_id'] ?? null);

        return DB::transaction(function () use ($actor, $data, $exercice, $category, $unit): RevenueForecast {
            $forecast = RevenueForecast::query()->create([
                'exercice_id' => $exercice->id,
                'category_id' => $category->id,
                'organization_unit_id' => $unit?->id,
                'code' => $this->numbers->nextPrevision((int) $exercice->annee),
                'label' => $data['label'],
                'description' => $data['description'] ?? null,
                'montant' => (int) $data['montant'],
                'source_label' => $data['source_label'] ?? null,
                'periode' => $data['periode'] ?? null,
                'date_prevue' => $data['date_prevue'] ?? null,
                'observations' => $data['observations'] ?? null,
                'statut' => 'brouillon',
                'author_id' => $actor->id,
            ]);
            $this->journal->write($actor, 'prevision_creee', null, null, null, ['forecast_id' => $forecast->id, 'code' => $forecast->code, 'montant' => $forecast->montant]);

            return $forecast;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function modifierPrevision(User $actor, RevenueForecast $forecast, array $data): RevenueForecast
    {
        $this->guard($this->access->editer($actor));
        if ($forecast->statut !== 'brouillon') {
            throw ValidationException::withMessages(['statut' => 'Seule une prévision en brouillon se modifie.']);
        }
        $before = ['montant' => (int) $forecast->montant, 'label' => $forecast->label];
        $forecast->fill([
            'label' => $data['label'] ?? $forecast->label,
            'description' => $data['description'] ?? $forecast->description,
            'montant' => (int) ($data['montant'] ?? $forecast->montant),
            'source_label' => $data['source_label'] ?? $forecast->source_label,
            'periode' => $data['periode'] ?? $forecast->periode,
            'date_prevue' => $data['date_prevue'] ?? $forecast->date_prevue,
            'observations' => $data['observations'] ?? $forecast->observations,
            'organization_unit_id' => array_key_exists('organization_unit_id', $data)
                ? $this->unite($data['organization_unit_id'])?->id
                : $forecast->organization_unit_id,
            'category_id' => isset($data['category_id']) ? $this->categorie((int) $data['category_id'])->id : $forecast->category_id,
        ])->save();
        $this->journal->write($actor, 'prevision_modifiee', null, null, $before, ['forecast_id' => $forecast->id, 'montant' => (int) $forecast->montant, 'label' => $forecast->label]);

        return $forecast;
    }

    public function supprimerPrevision(User $actor, RevenueForecast $forecast): void
    {
        $this->guard($this->access->editer($actor));
        if ($forecast->statut !== 'brouillon' || RevenueOrder::query()->where('forecast_id', $forecast->id)->exists()) {
            throw ValidationException::withMessages(['statut' => 'Cette prévision ne peut plus être supprimée.']);
        }
        $id = $forecast->id;
        $forecast->delete();
        $this->journal->write($actor, 'prevision_supprimee', null, null, ['forecast_id' => $id, 'code' => $forecast->code], null);
        $this->tasks->sync('revenue_forecast', $id);
    }

    public function annulerPrevision(User $actor, RevenueForecast $forecast, string $motif): RevenueForecast
    {
        $this->guard($this->access->editer($actor) || $this->access->decider($actor));
        if ($forecast->statut !== 'soumis') {
            throw ValidationException::withMessages(['statut' => 'Seule une prévision soumise et non encore validée s’annule. Un brouillon se supprime.']);
        }
        if (RevenueOrder::query()->where('forecast_id', $forecast->id)->exists()) {
            throw ValidationException::withMessages(['statut' => 'Une recette est déjà rattachée à cette prévision.']);
        }
        $forecast->forceFill(['statut' => 'annule'])->save();
        $this->journal->write($actor, 'prevision_annulee', null, null, ['forecast_id' => $forecast->id, 'statut' => 'soumis'], ['forecast_id' => $forecast->id, 'statut' => 'annule'], $motif);
        $this->tasks->sync('revenue_forecast', $forecast->id);

        return $forecast;
    }

    public function soumettrePrevision(User $actor, RevenueForecast $forecast): RevenueForecast
    {
        $this->guard($this->access->editer($actor));
        if ($forecast->statut !== 'brouillon') {
            throw ValidationException::withMessages(['statut' => 'La prévision n’est pas en brouillon.']);
        }
        $forecast->forceFill(['statut' => 'soumis'])->save();
        $this->journal->write($actor, 'prevision_soumise', null, null, ['statut' => 'brouillon'], ['forecast_id' => $forecast->id, 'statut' => 'soumis']);
        $this->tasks->sync('revenue_forecast', $forecast->id);

        return $forecast;
    }

    public function validerPrevision(User $actor, RevenueForecast $forecast): RevenueForecast
    {
        $this->guard($this->access->decider($actor));
        if ($forecast->statut !== 'soumis') {
            throw ValidationException::withMessages(['statut' => 'La prévision doit être soumise.']);
        }
        if ((int) $forecast->author_id === $actor->id) {
            throw ValidationException::withMessages(['author' => 'L’auteur ne valide pas sa propre prévision.']);
        }
        $forecast->forceFill(['statut' => 'valide', 'validated_by' => $actor->id])->save();
        $this->journal->write($actor, 'prevision_validee', null, null, ['statut' => 'soumis'], ['forecast_id' => $forecast->id, 'statut' => 'valide']);
        $this->tasks->sync('revenue_forecast', $forecast->id);

        return $forecast;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function creerTitre(User $actor, array $data): RevenueOrder
    {
        $this->guard($this->access->editer($actor));
        $exercice = $this->exercice((int) $data['exercice_id'], false);
        $category = $this->categorie((int) $data['category_id']);
        [$type, $state, $tiers, $label] = $this->debiteur($data);
        $forecast = $this->previsionLiee($data['forecast_id'] ?? null, $exercice->id);
        if (($data['echeance'] ?? null) === null) {
            throw ValidationException::withMessages(['echeance' => 'L’échéance est obligatoire.']);
        }

        return DB::transaction(function () use ($actor, $data, $exercice, $category, $type, $state, $tiers, $label, $forecast): RevenueOrder {
            $order = RevenueOrder::query()->create([
                'reference' => $this->numbers->nextTitre((int) $exercice->annee),
                'exercice_id' => $exercice->id,
                'category_id' => $category->id,
                'forecast_id' => $forecast?->id,
                'organization_unit_id' => $this->unite($data['organization_unit_id'] ?? null)?->id,
                'debtor_type' => $type,
                'tiers_id' => $tiers?->id,
                'member_state_id' => $state?->id,
                'debtor_label' => $label,
                'montant' => (int) $data['montant'],
                'devise' => 'XAF',
                'echeance' => $data['echeance'],
                'motif' => $data['motif'],
                'description' => $data['description'] ?? null,
                'observations' => $data['observations'] ?? null,
                'statut' => 'brouillon',
                'montant_encaisse' => 0,
                'created_by' => $actor->id,
            ]);
            $this->journal->write($actor, 'titre_cree', $order->id, null, null, ['reference' => $order->reference, 'montant' => (int) $order->montant]);

            return $order;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function modifierTitre(User $actor, RevenueOrder $order, array $data): RevenueOrder
    {
        $this->guard($this->access->editer($actor));
        if (! in_array($order->statut, ['brouillon', 'rejete'], true) || (int) $order->montant_encaisse > 0) {
            throw ValidationException::withMessages(['statut' => 'Ce titre n’est plus modifiable.']);
        }
        $before = ['montant' => (int) $order->montant, 'motif' => $order->motif, 'echeance' => $order->echeance?->toDateString()];
        if (isset($data['debtor_type'])) {
            [$type, $state, $tiers, $label] = $this->debiteur($data);
            $order->debtor_type = $type;
            $order->member_state_id = $state?->id;
            $order->tiers_id = $tiers?->id;
            $order->debtor_label = $label;
        }
        $order->fill([
            'category_id' => isset($data['category_id']) ? $this->categorie((int) $data['category_id'])->id : $order->category_id,
            'montant' => (int) ($data['montant'] ?? $order->montant),
            'echeance' => $data['echeance'] ?? $order->echeance,
            'motif' => $data['motif'] ?? $order->motif,
            'description' => $data['description'] ?? $order->description,
            'observations' => $data['observations'] ?? $order->observations,
        ])->save();
        $this->journal->write($actor, 'titre_modifie', $order->id, null, $before, ['montant' => (int) $order->montant, 'motif' => $order->motif]);

        return $order;
    }

    public function soumettre(User $actor, RevenueOrder $order): RevenueOrder
    {
        $this->guard($this->access->editer($actor));

        return $this->transition($actor, $order, ['brouillon', 'rejete'], 'soumis', 'titre_soumis', function (RevenueOrder $locked): void {
            $locked->verified_by = null;
            $locked->validated_by = null;
        });
    }

    public function verifier(User $actor, RevenueOrder $order): RevenueOrder
    {
        $this->guard($this->access->verifier($actor));
        if ((int) $order->created_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => 'L’auteur ne vérifie pas son propre titre.']);
        }

        return $this->transition($actor, $order, ['soumis'], 'verifie', 'titre_verifie', function (RevenueOrder $locked) use ($actor): void {
            $locked->verified_by = $actor->id;
        });
    }

    public function valider(User $actor, RevenueOrder $order): RevenueOrder
    {
        $this->guard($this->access->decider($actor));
        if (in_array($actor->id, [(int) $order->created_by, (int) $order->verified_by], true)) {
            throw ValidationException::withMessages(['actor' => 'Le validateur est distinct de l’auteur et du vérificateur.']);
        }

        return $this->transition($actor, $order, ['verifie'], 'valide', 'titre_valide', function (RevenueOrder $locked) use ($actor): void {
            $locked->validated_by = $actor->id;
        });
    }

    public function prendreEnCharge(User $actor, RevenueOrder $order): RevenueOrder
    {
        $this->guard($this->access->encaisser($actor));
        if (in_array($actor->id, [(int) $order->created_by, (int) $order->validated_by], true)) {
            throw ValidationException::withMessages(['actor' => 'La prise en charge est faite par un autre acteur que l’auteur et le validateur.']);
        }

        return $this->transition($actor, $order, ['valide'], 'pris_en_charge', 'titre_pris_en_charge');
    }

    public function rejeter(User $actor, RevenueOrder $order, string $motif): RevenueOrder
    {
        $this->guard($this->access->verifier($actor) || $this->access->decider($actor));

        return $this->transition($actor, $order, ['soumis', 'verifie'], 'rejete', 'titre_rejete', null, $motif);
    }

    public function suspendre(User $actor, RevenueOrder $order, string $motif): RevenueOrder
    {
        $this->guard($this->access->decider($actor));

        return $this->transition($actor, $order, ['pris_en_charge', 'partiellement_encaisse'], 'suspendu', 'titre_suspendu', null, $motif);
    }

    public function reprendre(User $actor, RevenueOrder $order): RevenueOrder
    {
        $this->guard($this->access->decider($actor));
        $cible = (int) $order->montant_encaisse > 0 ? 'partiellement_encaisse' : 'pris_en_charge';

        return $this->transition($actor, $order, ['suspendu'], $cible, 'titre_repris');
    }

    public function annuler(User $actor, RevenueOrder $order, string $motif): RevenueOrder
    {
        $this->guard($this->access->decider($actor));
        if ((int) $order->montant_encaisse > 0) {
            throw ValidationException::withMessages(['montant' => 'Un titre encaissé se régularise, il ne s’annule pas.']);
        }

        return $this->transition($actor, $order, ['brouillon', 'soumis', 'verifie', 'valide', 'pris_en_charge', 'rejete', 'suspendu'], 'annule', 'titre_annule', null, $motif);
    }

    public function regulariser(User $actor, RevenueOrder $order, string $kind, int $montant, string $motif): RevenueOrder
    {
        $this->guard($this->access->decider($actor));
        if (! in_array($kind, ['avoir', 'regularisation'], true)) {
            throw ValidationException::withMessages(['kind' => 'Régularisation inconnue.']);
        }

        return DB::transaction(function () use ($actor, $order, $kind, $montant, $motif): RevenueOrder {
            $locked = RevenueOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->exercice((int) $locked->exercice_id, false);
            if (! in_array($locked->statut, ['pris_en_charge', 'partiellement_encaisse', 'solde', 'suspendu'], true)) {
                throw ValidationException::withMessages(['statut' => 'Le titre n’est pas encore pris en charge.']);
            }
            $before = (int) $locked->montant;
            if ($kind === 'avoir') {
                if ($montant < 1 || $before - $montant < (int) $locked->montant_encaisse) {
                    throw ValidationException::withMessages(['montant' => 'L’avoir ne peut pas descendre sous le montant déjà encaissé.']);
                }
                $locked->montant = $before - $montant;
                if ((int) $locked->montant_encaisse === (int) $locked->montant && (int) $locked->montant > 0) {
                    $locked->statut = 'solde';
                }
            }
            $locked->save();
            $locked->adjustments()->create([
                'kind' => $kind,
                'montant' => $montant,
                'motif' => $motif,
                'author_id' => $actor->id,
            ]);
            $this->journal->write($actor, 'titre_regularise', $locked->id, null, ['montant' => $before], ['montant' => (int) $locked->montant, 'kind' => $kind], $motif);
            $this->tasks->sync('revenue_order', $locked->id);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function creerContribution(User $actor, array $data): RevenueContribution
    {
        $this->guard($this->access->editer($actor));
        $exercice = $this->exercice((int) $data['exercice_id'], true);
        $state = MemberState::query()->where('active', true)->find($data['member_state_id'] ?? 0);
        if ($state === null) {
            throw ValidationException::withMessages(['member_state_id' => 'État membre inconnu.']);
        }
        if (RevenueContribution::query()->where('exercice_id', $exercice->id)->where('member_state_id', $state->id)->exists()) {
            throw ValidationException::withMessages(['member_state_id' => 'Une contribution existe déjà pour cet État et cet exercice.']);
        }
        $quote = (int) $data['quote_part'];
        if ($quote < 0 || $quote > 10000) {
            throw ValidationException::withMessages(['quote_part' => 'La quote-part est exprimée en centièmes de pourcent, entre 0 et 10 000.']);
        }

        return RevenueContribution::query()->create([
            'exercice_id' => $exercice->id,
            'member_state_id' => $state->id,
            'quote_part' => $quote,
            'montant_attendu' => (int) $data['montant_attendu'],
            'echeance' => $data['echeance'] ?? null,
            'observations' => $data['observations'] ?? null,
        ]);
    }

    public function appeler(User $actor, RevenueContribution $contribution): RevenueOrder
    {
        $this->guard($this->access->editer($actor));
        if ($contribution->order_id !== null) {
            throw ValidationException::withMessages(['order' => 'Cette contribution a déjà un titre.']);
        }
        $contribution->load('memberState');
        $category = RevenueCategory::query()->where('code', 'CST')->where('active', true)->first()
            ?? RevenueCategory::query()->where('active', true)->first();
        if ($category === null) {
            throw ValidationException::withMessages(['category' => 'Aucune catégorie de recette active.']);
        }
        $order = $this->creerTitre($actor, [
            'exercice_id' => $contribution->exercice_id,
            'category_id' => $category->id,
            'debtor_type' => 'etat_membre',
            'member_state_id' => $contribution->member_state_id,
            'montant' => (int) $contribution->montant_attendu,
            'echeance' => $contribution->echeance?->toDateString() ?? now()->toDateString(),
            'motif' => 'Appel de contribution '.$contribution->memberState?->nom,
        ]);
        $contribution->forceFill(['order_id' => $order->id])->save();
        $this->journal->write($actor, 'contribution_appelee', $order->id, null, null, ['contribution_id' => $contribution->id]);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function relancer(User $actor, RevenueOrder $order, array $data): RevenueReminder
    {
        $this->guard($this->access->relancer($actor));
        if (! in_array($order->statut, ['pris_en_charge', 'partiellement_encaisse', 'suspendu'], true)) {
            throw ValidationException::withMessages(['statut' => 'Seule une créance prise en charge se relance.']);
        }
        if (! in_array($data['kind'], RevenueLexicon::REMINDERS, true)) {
            throw ValidationException::withMessages(['kind' => 'Type de relance inconnu.']);
        }
        $reminder = $order->reminders()->create([
            'kind' => $data['kind'],
            'canal' => $data['canal'],
            'destinataire' => $data['destinataire'],
            'resultat' => $data['resultat'] ?? null,
            'prochaine_action' => $data['prochaine_action'] ?? null,
            'author_id' => $actor->id,
        ]);
        $this->journal->write($actor, 'relance', $order->id, null, null, ['kind' => $data['kind'], 'destinataire' => $data['destinataire']]);

        return $reminder;
    }

    public function attacher(User $actor, RevenueOrder $order, UploadedFile $file, string $type): RevenueDocument
    {
        $this->guard($this->access->editer($actor) || $this->access->encaisser($actor));
        $bytes = (string) file_get_contents($file->getRealPath());
        $path = $file->store('recettes/'.$order->id, 'local');
        $document = $order->documents()->create([
            'nom' => $file->getClientOriginalName(),
            'chemin' => $path,
            'sha256' => hash('sha256', $bytes),
            'type_piece' => $type,
            'uploaded_by' => $actor->id,
        ]);
        $this->journal->write($actor, 'piece_ajoutee', $order->id, null, null, ['nom' => $document->nom, 'sha256' => $document->sha256]);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function enregistrerCategorie(User $actor, array $data, ?RevenueCategory $category = null): RevenueCategory
    {
        $this->guard($this->access->decider($actor));
        $payload = [
            'code' => strtoupper((string) $data['code']),
            'label' => $data['label'],
            'active' => (bool) ($data['active'] ?? true),
        ];
        if ($category === null) {
            return RevenueCategory::query()->create($payload);
        }
        $category->fill($payload)->save();

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function enregistrerMode(User $actor, array $data, ?RevenuePaymentMode $mode = null): RevenuePaymentMode
    {
        $this->guard($this->access->decider($actor));
        $payload = [
            'code' => (string) $data['code'],
            'label' => $data['label'],
            'active' => (bool) ($data['active'] ?? true),
        ];
        if ($mode === null) {
            return RevenuePaymentMode::query()->create($payload);
        }
        $mode->fill($payload)->save();

        return $mode;
    }

    public function reglerSeuil(User $actor, string $key, int $value): void
    {
        $this->guard($this->access->decider($actor));
        if (! in_array($key, ['approche_jours', 'critique_jours'], true) || $value < 0) {
            throw ValidationException::withMessages(['key' => 'Seuil inconnu.']);
        }
        RevenueSetting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    /**
     * @param  list<string>  $from
     */
    private function transition(User $actor, RevenueOrder $order, array $from, string $to, string $action, ?callable $mutate = null, ?string $motif = null): RevenueOrder
    {
        return DB::transaction(function () use ($actor, $order, $from, $to, $action, $mutate, $motif): RevenueOrder {
            $locked = RevenueOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->statut, ['annule', 'solde'], true)) {
                $this->exercice((int) $locked->exercice_id, false);
            }
            if (! in_array($locked->statut, $from, true)) {
                throw ValidationException::withMessages(['statut' => 'Transition impossible depuis '.RevenueLexicon::label($locked->statut).'.']);
            }
            $before = $locked->statut;
            $locked->statut = $to;
            if ($mutate !== null) {
                $mutate($locked);
            }
            $locked->save();
            $this->journal->write($actor, $action, $locked->id, null, ['statut' => $before], ['statut' => $to], $motif);
            $this->tasks->sync('revenue_order', $locked->id);

            return $locked;
        });
    }

    private function exercice(int $id, bool $preparation): Exercice
    {
        $exercice = Exercice::query()->find($id);
        $ouvert = $exercice !== null && ($exercice->isOpen() || ($preparation && $exercice->statut === 'preparation'));
        if (! $ouvert) {
            throw ValidationException::withMessages(['exercice_id' => 'L’exercice n’est pas ouvert à cette opération.']);
        }

        return $exercice;
    }

    private function categorie(int $id): RevenueCategory
    {
        $category = RevenueCategory::query()->where('active', true)->find($id);
        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => 'Catégorie de recette inactive ou inconnue.']);
        }

        return $category;
    }

    private function unite(mixed $id): ?OrganizationUnit
    {
        if ($id === null || $id === '') {
            return null;
        }
        $unit = OrganizationUnit::query()->find($id);
        if ($unit === null) {
            throw ValidationException::withMessages(['organization_unit_id' => 'Service inconnu.']);
        }

        return $unit;
    }

    private function previsionLiee(mixed $id, int $exerciceId): ?RevenueForecast
    {
        if ($id === null || $id === '') {
            return null;
        }
        $forecast = RevenueForecast::query()->find($id);
        if ($forecast === null || (int) $forecast->exercice_id !== $exerciceId || $forecast->statut !== 'valide') {
            throw ValidationException::withMessages(['forecast_id' => 'La prévision doit être validée sur le même exercice.']);
        }

        return $forecast;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: ?MemberState, 2: ?Tiers, 3: string}
     */
    private function debiteur(array $data): array
    {
        $type = (string) ($data['debtor_type'] ?? '');
        if (! in_array($type, RevenueLexicon::DEBTORS, true)) {
            throw ValidationException::withMessages(['debtor_type' => 'Type de débiteur inconnu.']);
        }
        if ($type === 'etat_membre') {
            $state = MemberState::query()->where('active', true)->find($data['member_state_id'] ?? 0);
            if ($state === null) {
                throw ValidationException::withMessages(['member_state_id' => 'État membre requis.']);
            }

            return [$type, $state, null, $state->nom];
        }
        $tiers = null;
        if (! empty($data['tiers_id'])) {
            $tiers = Tiers::query()->find($data['tiers_id']);
            if ($tiers === null || $tiers->status !== 'actif') {
                throw ValidationException::withMessages(['tiers_id' => 'Le tiers doit être actif.']);
            }
        }
        $label = $tiers?->raison_sociale ?: trim((string) ($data['debtor_label'] ?? ''));
        if ($label === '') {
            throw ValidationException::withMessages(['debtor_label' => 'Le débiteur est obligatoire.']);
        }

        return [$type, null, $tiers, $label];
    }

    private function guard(bool $allowed): void
    {
        abort_unless($allowed, 403);
    }

    public function oublierPiece(string $chemin): void
    {
        Storage::disk('local')->delete($chemin);
    }
}
