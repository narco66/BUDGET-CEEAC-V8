<?php

namespace App\Domains\Budget\Services;

use App\Domains\Administration\Models\AuditEvent;
use App\Domains\Budget\Enums\BudgetNature;
use App\Domains\Budget\Models\BudgetArbitration;
use App\Domains\Budget\Models\BudgetCampaign;
use App\Domains\Budget\Models\BudgetCampaignStep;
use App\Domains\Budget\Models\BudgetDossier;
use App\Domains\Budget\Models\BudgetDossierLine;
use App\Domains\Budget\Models\BudgetEnvelope;
use App\Domains\Budget\Models\BudgetHypothesis;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\BudgetLineDetail;
use App\Domains\Budget\Models\BudgetLineFunding;
use App\Domains\Budget\Models\BudgetLinePeriod;
use App\Domains\Budget\Models\BudgetOrientation;
use App\Domains\Budget\Models\BudgetPiece;
use App\Domains\Budget\Models\BudgetVersion;
use App\Domains\Budget\Models\BudgetVersionForecast;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Budget\Notifications\PreparationAlerte;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Planning\Models\GarNode;
use App\Domains\Revenues\Models\RevenueCategory;
use App\Domains\Revenues\Models\RevenueForecast;
use App\Domains\Tasks\Services\TaskProjector;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use App\Shared\Notifications\RoleHolders;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreparationModuleService
{
    public function __construct(private readonly TaskProjector $taches) {}

    /**
     * @return array<string, mixed>
     */
    public function referentiel(User $actor): array
    {
        $this->voir($actor);

        return [
            'exercices' => Exercice::query()->orderByDesc('annee')->get(['id', 'annee', 'statut']),
            'structures' => OrganizationUnit::query()->active()->orderBy('sigle')->get(['id', 'sigle', 'name']),
            'responsables' => User::query()->whereIn('role', ['expert_budget', 'directeur_budget'])->orderBy('name')->get(['id', 'name', 'role']),
            'categories' => RevenueCategory::query()->orderBy('label')->get(['id', 'code', 'label']),
            'activites' => GarNode::query()->with('version:id,exercice_id')->whereIn('type', ['activite', 'tache'])->orderBy('code')->get(['id', 'gar_version_id', 'type', 'code', 'libelle', 'resultats_attendus', 'indicateur', 'organization_unit_id', 'periode']),
            'campagnes' => BudgetCampaign::query()->with('exercice:id,annee')->orderByDesc('id')->get(['id', 'code', 'label', 'exercice_id', 'statut']),
            'droits' => $this->droits($actor),
        ];
    }

    /**
     * @param  array<string, mixed>  $filtres
     */
    public function campagnes(User $actor, array $filtres): LengthAwarePaginator
    {
        $this->voir($actor);
        $query = BudgetCampaign::query()->with(['exercice:id,annee,statut', 'responsable:id,name'])->withCount('dossiers');
        if (($filtres['q'] ?? '') !== '') {
            $terme = '%'.$filtres['q'].'%';
            $query->where(fn ($inner) => $inner->where('code', 'like', $terme)->orWhere('label', 'like', $terme));
        }
        if (($filtres['statut'] ?? '') !== '') {
            $query->where('statut', $filtres['statut']);
        }
        if (($filtres['exercice_id'] ?? '') !== '') {
            $query->where('exercice_id', (int) $filtres['exercice_id']);
        }
        $tri = $filtres['tri'] ?? 'recent';
        $query->orderBy($tri === 'code' ? 'code' : 'id', $tri === 'code' ? 'asc' : 'desc');

        return $query->paginate(min(50, max(1, (int) ($filtres['per_page'] ?? 20))));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function creerCampagne(User $actor, array $data): BudgetCampaign
    {
        $this->editer($actor);
        $exercice = Exercice::query()->findOrFail((int) $data['exercice_id']);
        if ($exercice->statut !== 'preparation') {
            throw ValidationException::withMessages(['exercice_id' => 'Une campagne se rattache à un exercice en préparation.']);
        }
        $this->assertCampagneUnique($exercice->id, null);

        return DB::transaction(function () use ($actor, $data, $exercice): BudgetCampaign {
            $campagne = BudgetCampaign::query()->create([
                'code' => strtoupper(trim((string) $data['code'])),
                'label' => $data['label'],
                'exercice_id' => $exercice->id,
                'description' => $data['description'] ?? null,
                'perimetre' => $data['perimetre'] ?? null,
                'date_ouverture' => $data['date_ouverture'] ?? null,
                'date_cloture' => $data['date_cloture'] ?? null,
                'devise' => 'XAF',
                'responsable_id' => $data['responsable_id'] ?? null,
                'version_cadrage' => (int) ($data['version_cadrage'] ?? 1),
                'statut' => 'brouillon',
                'author_id' => $actor->id,
            ]);
            $campagne->units()->sync($data['structures'] ?? []);
            $this->journal($actor, 'budget.campagne.creee', 'campagne', $campagne->id, null, ['code' => $campagne->code]);

            return $campagne->load('units', 'exercice');
        });
    }

    public function campagne(User $actor, BudgetCampaign $campagne): BudgetCampaign
    {
        $this->voir($actor);

        return $campagne->load([
            'exercice', 'responsable', 'author', 'units', 'steps', 'dossiers.organizationUnit', 'versions',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function modifierCampagne(User $actor, BudgetCampaign $campagne, array $data): BudgetCampaign
    {
        $this->editer($actor);
        $this->assertCampagneMutable($campagne);
        if ($campagne->statut !== 'brouillon' && (int) $data['exercice_id'] !== (int) $campagne->exercice_id) {
            throw ValidationException::withMessages(['exercice_id' => 'L’exercice ne change plus après l’ouverture.']);
        }

        return DB::transaction(function () use ($actor, $campagne, $data): BudgetCampaign {
            $avant = $campagne->only(['code', 'label', 'date_cloture', 'statut']);
            $campagne->fill([
                'code' => strtoupper(trim((string) $data['code'])),
                'label' => $data['label'],
                'description' => $data['description'] ?? null,
                'perimetre' => $data['perimetre'] ?? null,
                'date_ouverture' => $data['date_ouverture'] ?? null,
                'date_cloture' => $data['date_cloture'] ?? null,
                'responsable_id' => $data['responsable_id'] ?? null,
                'version_cadrage' => (int) ($data['version_cadrage'] ?? $campagne->version_cadrage),
            ])->save();
            $campagne->units()->sync($data['structures'] ?? []);
            $this->journal($actor, 'budget.campagne.modifiee', 'campagne', $campagne->id, $avant, $campagne->only(['code', 'label', 'date_cloture']));

            return $campagne->fresh()->load('units', 'exercice');
        });
    }

    public function supprimerCampagne(User $actor, BudgetCampaign $campagne): void
    {
        $this->editer($actor);
        if ($campagne->statut !== 'brouillon' || $campagne->dossiers()->exists() || $campagne->versions()->exists()) {
            throw ValidationException::withMessages(['campagne' => 'Seule une campagne brouillon, sans dossier ni version, peut être supprimée.']);
        }
        DB::transaction(function () use ($actor, $campagne): void {
            $this->journal($actor, 'budget.campagne.supprimee', 'campagne', $campagne->id, ['code' => $campagne->code], null);
            $campagne->delete();
        });
    }

    public function ouvrirCampagne(User $actor, BudgetCampaign $campagne): BudgetCampaign
    {
        $this->editer($actor);
        if ($campagne->statut !== 'brouillon') {
            throw ValidationException::withMessages(['statut' => 'Seule une campagne en brouillon s’ouvre.']);
        }
        if ($campagne->date_ouverture === null || $campagne->date_cloture === null || $campagne->units()->doesntExist()) {
            throw ValidationException::withMessages(['campagne' => 'Renseignez les dates et au moins une structure participante.']);
        }
        $this->assertCampagneUnique((int) $campagne->exercice_id, $campagne->id);
        $campagne->forceFill(['statut' => 'ouverte'])->save();
        $this->journal($actor, 'budget.campagne.ouverte', 'campagne', $campagne->id, null, ['statut' => 'ouverte']);
        $this->notifier($campagne, 'Campagne '.$campagne->code.' ouverte.', 'campagne', $campagne->id);

        return $campagne;
    }

    public function suspendreCampagne(User $actor, BudgetCampaign $campagne): BudgetCampaign
    {
        $this->decider($actor);
        if ($campagne->statut !== 'ouverte') {
            throw ValidationException::withMessages(['statut' => 'Seule une campagne ouverte se suspend.']);
        }
        $campagne->forceFill(['statut' => 'suspendue'])->save();
        $this->journal($actor, 'budget.campagne.suspendue', 'campagne', $campagne->id, ['statut' => 'ouverte'], ['statut' => 'suspendue']);

        return $campagne;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function prolongerCampagne(User $actor, BudgetCampaign $campagne, array $data): BudgetCampaign
    {
        $this->decider($actor);
        if (! in_array($campagne->statut, ['ouverte', 'suspendue'], true)) {
            throw ValidationException::withMessages(['statut' => 'La prolongation concerne une campagne ouverte ou suspendue.']);
        }
        if ($campagne->date_cloture !== null && $data['date_cloture'] <= $campagne->date_cloture->toDateString()) {
            throw ValidationException::withMessages(['date_cloture' => 'La nouvelle échéance doit être postérieure.']);
        }
        $avant = $campagne->date_cloture?->toDateString();
        $campagne->forceFill(['date_cloture' => $data['date_cloture'], 'statut' => 'ouverte'])->save();
        $this->journal($actor, 'budget.campagne.prolongee', 'campagne', $campagne->id, ['date_cloture' => $avant], ['date_cloture' => $data['date_cloture']]);

        return $campagne;
    }

    public function cloturerCampagne(User $actor, BudgetCampaign $campagne): BudgetCampaign
    {
        $this->decider($actor);
        if (! in_array($campagne->statut, ['ouverte', 'suspendue'], true)) {
            throw ValidationException::withMessages(['statut' => 'Seule une campagne ouverte ou suspendue se clôture.']);
        }
        if ($campagne->dossiers()->whereIn('statut', ['brouillon', 'soumis', 'retourne'])->exists()) {
            throw ValidationException::withMessages(['campagne' => 'Des dossiers sont encore en cours.']);
        }
        $campagne->forceFill(['statut' => 'cloturee'])->save();
        $this->journal($actor, 'budget.campagne.cloturee', 'campagne', $campagne->id, null, ['statut' => 'cloturee']);

        return $campagne;
    }

    public function archiverCampagne(User $actor, BudgetCampaign $campagne): BudgetCampaign
    {
        $this->decider($actor);
        if (! in_array($campagne->statut, ['cloturee', 'brouillon'], true) || $campagne->dossiers()->exists()) {
            throw ValidationException::withMessages(['campagne' => 'Archivez une campagne clôturée, ou un brouillon sans dossier.']);
        }
        if ($campagne->statut === 'brouillon' && $campagne->dossiers()->exists()) {
            throw ValidationException::withMessages(['campagne' => 'Cette campagne a des dossiers.']);
        }
        $campagne->forceFill(['statut' => 'archivee'])->save();
        $this->journal($actor, 'budget.campagne.archivee', 'campagne', $campagne->id, null, ['statut' => 'archivee']);

        return $campagne;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function creerEtape(User $actor, BudgetCampaign $campagne, array $data): BudgetCampaignStep
    {
        $this->editer($actor);
        $this->assertCampagneMutable($campagne);
        $etape = $campagne->steps()->create([
            'ordre' => (int) $data['ordre'],
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'debut' => $data['debut'] ?? null,
            'echeance' => $data['echeance'] ?? null,
            'acteurs' => $data['acteurs'] ?? null,
            'structures' => $data['structures'] ?? null,
            'prerequis' => $data['prerequis'] ?? null,
            'livrables' => $data['livrables'] ?? null,
        ]);
        $this->journal($actor, 'budget.etape.creee', 'campagne', $campagne->id, null, ['etape' => $etape->id]);

        return $etape;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function modifierEtape(User $actor, BudgetCampaignStep $etape, array $data): BudgetCampaignStep
    {
        $this->editer($actor);
        $this->assertCampagneMutable($etape->campaign);
        if ($etape->verrouillee) {
            throw ValidationException::withMessages(['etape' => 'Une étape déjà exécutée ne se modifie pas.']);
        }
        $etape->fill([
            'ordre' => (int) $data['ordre'],
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'debut' => $data['debut'] ?? null,
            'echeance' => $data['echeance'] ?? null,
            'acteurs' => $data['acteurs'] ?? null,
            'structures' => $data['structures'] ?? null,
            'prerequis' => $data['prerequis'] ?? null,
            'livrables' => $data['livrables'] ?? null,
        ])->save();

        return $etape;
    }

    public function supprimerEtape(User $actor, BudgetCampaignStep $etape): void
    {
        $this->editer($actor);
        if ($etape->verrouillee) {
            throw ValidationException::withMessages(['etape' => 'Une étape déjà exécutée est conservée.']);
        }
        $etape->delete();
        $this->journal($actor, 'budget.etape.retiree', 'campagne', $etape->campaign_id, ['etape' => $etape->label], null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function enregistrerHypothese(User $actor, BudgetCampaign $campagne, array $data, ?BudgetHypothesis $existante = null): BudgetHypothesis
    {
        $this->editer($actor);
        $this->assertCampagneMutable($campagne);
        if ($existante !== null && $existante->statut !== 'brouillon') {
            $nouvelle = BudgetHypothesis::query()->create($this->attributsHypothese($data, $actor->id) + [
                'campaign_id' => $existante->campaign_id,
                'code' => $existante->code,
                'version' => (int) $existante->version + 1,
                'remplace_id' => $existante->id,
                'statut' => 'brouillon',
            ]);
            $this->journal($actor, 'budget.hypothese.versionnee', 'campagne', $existante->campaign_id, ['id' => $existante->id], ['id' => $nouvelle->id]);

            return $nouvelle;
        }
        $attributs = $this->attributsHypothese($data, $actor->id);
        if ($existante === null) {
            $ligne = $campagne->hypotheses()->create($attributs + ['version' => 1, 'statut' => 'brouillon']);
        } else {
            $existante->fill($attributs)->save();
            $ligne = $existante;
        }
        $this->journal($actor, 'budget.hypothese.enregistree', 'campagne', $campagne->id, null, ['id' => $ligne->id]);

        return $ligne;
    }

    public function publierHypothese(User $actor, BudgetHypothesis $hypothese): BudgetHypothesis
    {
        $this->editer($actor);
        if ($hypothese->statut !== 'brouillon') {
            throw ValidationException::withMessages(['statut' => 'Seule une hypothèse en brouillon se publie.']);
        }
        $hypothese->forceFill(['statut' => 'publiee'])->save();
        $this->journal($actor, 'budget.hypothese.publiee', 'campagne', $hypothese->campaign_id, null, ['id' => $hypothese->id, 'version' => $hypothese->version]);

        return $hypothese;
    }

    public function archiverHypothese(User $actor, BudgetHypothesis $hypothese): BudgetHypothesis
    {
        $this->editer($actor);
        if ($this->hypotheseFigee($hypothese)) {
            throw ValidationException::withMessages(['hypothese' => 'Une hypothèse utilisée par une version conservée reste lisible.']);
        }
        $hypothese->forceFill(['statut' => 'archivee'])->save();

        return $hypothese;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function enregistrerOrientation(User $actor, BudgetCampaign $campagne, array $data, ?BudgetOrientation $existante = null): BudgetOrientation
    {
        $this->editer($actor);
        $this->assertCampagneMutable($campagne);
        $attributs = [
            'code' => strtoupper(trim((string) $data['code'])),
            'label' => $data['label'],
            'categorie' => $data['categorie'],
            'instructions' => $data['instructions'] ?? null,
            'periode' => $data['periode'] ?? null,
            'source' => $data['source'] ?? null,
            'justification' => $data['justification'] ?? null,
            'author_id' => $actor->id,
        ];
        if ($existante !== null && $existante->statut !== 'brouillon') {
            $ligne = BudgetOrientation::query()->create($attributs + [
                'campaign_id' => $existante->campaign_id,
                'code' => $existante->code,
                'version' => (int) $existante->version + 1,
                'remplace_id' => $existante->id,
                'statut' => 'brouillon',
            ]);
        } elseif ($existante === null) {
            $ligne = $campagne->orientations()->create($attributs + ['version' => 1, 'statut' => 'brouillon']);
        } else {
            $existante->fill($attributs)->save();
            $ligne = $existante;
        }
        $this->journal($actor, 'budget.orientation.enregistree', 'campagne', $campagne->id, null, ['id' => $ligne->id]);

        return $ligne;
    }

    public function publierOrientation(User $actor, BudgetOrientation $orientation): BudgetOrientation
    {
        $this->editer($actor);
        if ($orientation->statut !== 'brouillon') {
            throw ValidationException::withMessages(['statut' => 'Seule une orientation en brouillon se publie.']);
        }
        $orientation->forceFill(['statut' => 'publiee'])->save();
        $this->notifier($orientation->campaign, 'Orientation '.$orientation->code.' publiée.', 'campagne', $orientation->campaign_id);

        return $orientation;
    }

    public function archiverOrientation(User $actor, BudgetOrientation $orientation): BudgetOrientation
    {
        $this->editer($actor);
        $orientation->forceFill(['statut' => 'archivee'])->save();

        return $orientation;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function enregistrerEnveloppe(User $actor, BudgetCampaign $campagne, array $data, ?BudgetEnvelope $existante = null): BudgetEnvelope
    {
        $this->editer($actor);
        $this->assertCampagneMutable($campagne);
        if ($existante !== null && $existante->statut !== 'brouillon') {
            throw ValidationException::withMessages(['enveloppe' => 'Une enveloppe active s’archive ; elle ne se réécrit pas.']);
        }
        $montant = (int) $data['montant'];
        $parentId = $data['parent_id'] ?? null;
        if ($parentId !== null) {
            $parent = BudgetEnvelope::query()->where('campaign_id', $campagne->id)->find($parentId);
            if ($parent === null || $parent->classification !== $data['classification']) {
                throw ValidationException::withMessages(['parent_id' => 'L’enveloppe parente est introuvable pour cette classification.']);
            }
            if ($montant > (int) $parent->montant) {
                throw ValidationException::withMessages(['montant' => 'L’enveloppe fille ne peut pas dépasser l’enveloppe parente.']);
            }
        }

        $attributs = [
            'organization_unit_id' => (int) $data['organization_unit_id'],
            'parent_id' => $parentId,
            'classification' => $data['classification'],
            'perimetre' => $data['perimetre'] ?? null,
            'montant' => $montant,
            'devise' => 'XAF',
            'justification' => $data['justification'] ?? null,
            'date_effet' => $data['date_effet'] ?? null,
            'author_id' => $actor->id,
            'version' => (int) ($data['version'] ?? 1),
            'statut' => $data['statut'] ?? 'brouillon',
        ];
        if (! in_array($attributs['statut'], ['brouillon', 'actif'], true)) {
            throw ValidationException::withMessages(['statut' => 'Statut d’enveloppe invalide.']);
        }
        if ($existante === null) {
            $ligne = $campagne->envelopes()->create($attributs);
        } else {
            $existante->fill($attributs)->save();
            $ligne = $existante;
        }
        $this->assertEnfantsDansParent($ligne);
        $this->journal($actor, 'budget.enveloppe.enregistree', 'campagne', $campagne->id, null, ['id' => $ligne->id, 'montant' => $montant]);

        return $ligne->load('organizationUnit');
    }

    public function supprimerEnveloppe(User $actor, BudgetEnvelope $enveloppe): void
    {
        $this->editer($actor);
        if ($enveloppe->statut !== 'brouillon' || $enveloppe->children()->exists()) {
            throw ValidationException::withMessages(['enveloppe' => 'Seule une enveloppe brouillon sans fille se retire.']);
        }
        $utilisee = BudgetDossierLine::query()
            ->where('classification', $enveloppe->classification)
            ->whereHas('dossier', fn ($query) => $query->where('campaign_id', $enveloppe->campaign_id)->where('organization_unit_id', $enveloppe->organization_unit_id)->whereNotIn('statut', ['annule']))
            ->exists();
        if ($utilisee) {
            throw ValidationException::withMessages(['enveloppe' => 'Des lignes de proposition utilisent cette enveloppe.']);
        }
        $enveloppe->delete();
    }

    /**
     * @param  array<string, mixed>  $filtres
     */
    public function dossiers(User $actor, array $filtres): LengthAwarePaginator
    {
        $this->voir($actor);
        $query = BudgetDossier::query()->with(['campaign.exercice', 'organizationUnit:id,sigle,name'])->withSum('lines', 'montant');
        if (($filtres['q'] ?? '') !== '') {
            $terme = '%'.$filtres['q'].'%';
            $query->where(fn ($inner) => $inner->where('reference', 'like', $terme)->orWhere('titre', 'like', $terme));
        }
        foreach (['statut', 'campaign_id', 'organization_unit_id'] as $cle) {
            if (($filtres[$cle] ?? '') !== '') {
                $query->where($cle, $filtres[$cle]);
            }
        }

        return $query->orderByDesc('id')->paginate(min(50, max(1, (int) ($filtres['per_page'] ?? 20))));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function creerDossier(User $actor, array $data): BudgetDossier
    {
        $this->editer($actor);

        return DB::transaction(function () use ($actor, $data): BudgetDossier {
            $campagne = BudgetCampaign::query()->lockForUpdate()->findOrFail((int) $data['campaign_id']);
            $this->assertCampagneCollecte($campagne);
            $this->assertStructureParticipante($campagne, (int) $data['organization_unit_id']);
            $dossier = BudgetDossier::query()->create([
                'reference' => $this->reference($campagne),
                'campaign_id' => $campagne->id,
                'organization_unit_id' => (int) $data['organization_unit_id'],
                'titre' => $data['titre'],
                'description' => $data['description'] ?? null,
                'justification' => $data['justification'] ?? null,
                'responsable_id' => $data['responsable_id'] ?? $actor->id,
                'observations' => $data['observations'] ?? null,
                'statut' => 'brouillon',
                'author_id' => $actor->id,
            ]);
            $this->journal($actor, 'budget.dossier.cree', 'dossier', $dossier->id, null, ['reference' => $dossier->reference]);

            return $dossier;
        });
    }

    public function dossier(User $actor, BudgetDossier $dossier): BudgetDossier
    {
        $this->voir($actor);

        return $dossier->load([
            'campaign.exercice', 'organizationUnit', 'responsable', 'author',
            'lines.details', 'lines.periods', 'lines.fundings.category', 'lines.garNode',
            'arbitrages.actor', 'pieces',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function modifierDossier(User $actor, BudgetDossier $dossier, array $data): BudgetDossier
    {
        $this->editer($actor);
        $this->assertDossierEditable($dossier);
        $dossier->fill([
            'titre' => $data['titre'],
            'description' => $data['description'] ?? null,
            'justification' => $data['justification'] ?? null,
            'responsable_id' => $data['responsable_id'] ?? $dossier->responsable_id,
            'observations' => $data['observations'] ?? null,
        ])->save();
        $this->journal($actor, 'budget.dossier.modifie', 'dossier', $dossier->id, null, ['titre' => $dossier->titre]);

        return $dossier;
    }

    public function supprimerDossier(User $actor, BudgetDossier $dossier): void
    {
        $this->editer($actor);
        if ($dossier->statut !== 'brouillon' || $dossier->arbitrages()->exists()) {
            throw ValidationException::withMessages(['dossier' => 'Seul un brouillon sans arbitrage se supprime.']);
        }
        $this->journal($actor, 'budget.dossier.supprime', 'dossier', $dossier->id, ['reference' => $dossier->reference], null);
        $dossier->delete();
    }

    public function dupliquerDossier(User $actor, BudgetDossier $dossier): BudgetDossier
    {
        $this->editer($actor);

        return DB::transaction(function () use ($actor, $dossier): BudgetDossier {
            $campagne = BudgetCampaign::query()->lockForUpdate()->findOrFail($dossier->campaign_id);
            $this->assertCampagneCollecte($campagne);
            $copie = BudgetDossier::query()->create([
                'reference' => $this->reference($campagne),
                'campaign_id' => $campagne->id,
                'organization_unit_id' => $dossier->organization_unit_id,
                'titre' => $dossier->titre.' (copie)',
                'description' => $dossier->description,
                'justification' => $dossier->justification,
                'responsable_id' => $actor->id,
                'statut' => 'brouillon',
                'author_id' => $actor->id,
                'duplicate_of_id' => $dossier->id,
            ]);
            foreach ($dossier->lines()->with(['details', 'periods', 'fundings'])->get() as $ligne) {
                $nouvelle = $copie->lines()->create($ligne->only([
                    'classification', 'code', 'nature', 'label', 'description', 'justification', 'quantite',
                    'unite', 'cout_unitaire', 'montant', 'mode', 'gar_node_id', 'periode', 'observations',
                ]));
                foreach ($ligne->details as $detail) {
                    $nouvelle->details()->create($detail->only(['designation', 'gar_node_id', 'quantite', 'unite', 'cout_unitaire', 'montant', 'justification', 'hypothese_id']));
                }
                foreach ($ligne->periods as $periode) {
                    $nouvelle->periods()->create($periode->only(['periode', 'montant']));
                }
                foreach ($ligne->fundings as $financement) {
                    $nouvelle->fundings()->create($financement->only(['revenue_category_id', 'source', 'montant']));
                }
            }
            $this->journal($actor, 'budget.dossier.duplique', 'dossier', $copie->id, ['source' => $dossier->id], ['reference' => $copie->reference]);

            return $copie;
        });
    }

    public function soumettreDossier(User $actor, BudgetDossier $dossier): BudgetDossier
    {
        $this->editer($actor);
        $this->assertDossierEditable($dossier);
        if ($dossier->lines()->doesntExist()) {
            throw ValidationException::withMessages(['dossier' => 'Ajoutez au moins une ligne avant la soumission.']);
        }
        foreach ($dossier->lines()->with(['details', 'periods', 'fundings'])->get() as $ligne) {
            $this->assertRepartitions($ligne);
            $this->assertPlafond($dossier, $ligne, null);
        }
        $dossier->forceFill(['statut' => 'soumis', 'submitted_at' => now(), 'retour_motif' => null])->save();
        $etape = $dossier->campaign->steps()->where('verrouillee', false)->orderBy('ordre')->first();
        $etape?->forceFill(['verrouillee' => true])->save();
        $this->journal($actor, 'budget.dossier.soumis', 'dossier', $dossier->id, null, ['statut' => 'soumis']);
        $this->notifier($dossier->campaign, $dossier->reference.' soumis pour arbitrage.', 'dossier', $dossier->id, 'directeur_budget');
        $this->taches->sync('budget_dossier', $dossier->id);

        return $dossier;
    }

    public function retournerDossier(User $actor, BudgetDossier $dossier, string $motif): BudgetDossier
    {
        $this->decider($actor);
        if ($actor->id === $dossier->author_id) {
            throw ValidationException::withMessages(['action' => 'L’auteur ne retourne pas son propre dossier.']);
        }
        if ($dossier->statut !== 'soumis') {
            throw ValidationException::withMessages(['dossier' => 'Seul un dossier soumis se retourne.']);
        }
        $dossier->forceFill(['statut' => 'retourne', 'retour_motif' => $motif])->save();
        $this->journal($actor, 'budget.dossier.retourne', 'dossier', $dossier->id, ['statut' => 'soumis'], ['statut' => 'retourne'], $motif);
        $this->taches->sync('budget_dossier', $dossier->id);

        return $dossier;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function enregistrerLigne(User $actor, BudgetDossier $dossier, array $data, ?BudgetDossierLine $existante = null): BudgetDossierLine
    {
        $this->editer($actor);
        $this->assertDossierEditable($dossier);
        $classification = (string) $data['classification'];
        $nature = $classification === 'investissement' ? BudgetNature::Pap : BudgetNature::HorsPap;
        $garId = $data['gar_node_id'] ?? null;
        if ($classification === 'investissement' && $garId === null) {
            throw ValidationException::withMessages(['gar_node_id' => 'Rattachez la ligne à une activité du module Planification stratégique.']);
        }
        $noeud = null;
        if ($garId !== null) {
            $noeud = $this->activiteDeLExercice($dossier, (int) $garId);
        }
        $montant = $this->produit((string) $data['quantite'], (int) $data['cout_unitaire']);
        if ($montant < 1 && ($data['mode'] ?? 'direct') === 'direct') {
            throw ValidationException::withMessages(['montant' => 'Le montant calculé doit être positif.']);
        }
        $this->assertCodeUnique($dossier, (string) $data['code'], $existante?->id);
        $attributs = [
            'classification' => $classification,
            'code' => strtoupper(trim((string) $data['code'])),
            'nature' => $nature,
            'label' => ($data['label'] ?? '') !== '' ? $data['label'] : ($noeud->libelle ?? ''),
            'description' => $data['description'] ?? $noeud?->resultats_attendus,
            'justification' => $data['justification'] ?? null,
            'quantite' => $data['quantite'],
            'unite' => $data['unite'] ?? null,
            'cout_unitaire' => (int) $data['cout_unitaire'],
            'montant' => $montant,
            'mode' => 'direct',
            'gar_node_id' => $noeud?->id,
            'periode' => $data['periode'] ?? $noeud?->periode,
            'observations' => $data['observations'] ?? null,
        ];

        return DB::transaction(function () use ($dossier, $existante, $attributs, $actor): BudgetDossierLine {
            $ligne = $existante === null ? $dossier->lines()->create($attributs) : tap($existante, fn (BudgetDossierLine $row) => $row->fill($attributs)->save());
            if ($ligne->details()->exists()) {
                $this->recalculerDepuisDetails($ligne);
            }
            $this->assertPlafond($dossier, $ligne->fresh(), null);
            $this->journal($actor, 'budget.ligne.enregistree', 'dossier', $dossier->id, null, ['ligne' => $ligne->id, 'montant' => $ligne->montant]);

            return $ligne->fresh()->load('details', 'periods', 'fundings', 'garNode');
        });
    }

    public function supprimerLigne(User $actor, BudgetDossierLine $ligne): void
    {
        $this->editer($actor);
        $this->assertDossierEditable($ligne->dossier);
        if ($ligne->dossier->arbitrages()->where('line_id', $ligne->id)->exists()) {
            throw ValidationException::withMessages(['ligne' => 'Une ligne déjà arbitrée est conservée.']);
        }
        $ligne->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function enregistrerDetail(User $actor, BudgetDossierLine $ligne, array $data, ?BudgetLineDetail $existant = null): BudgetLineDetail
    {
        $this->editer($actor);
        $this->assertDossierEditable($ligne->dossier);
        $montant = $this->produit((string) $data['quantite'], (int) $data['cout_unitaire']);
        $attributs = [
            'designation' => $data['designation'],
            'gar_node_id' => $data['gar_node_id'] ?? null,
            'quantite' => $data['quantite'],
            'unite' => $data['unite'] ?? null,
            'cout_unitaire' => (int) $data['cout_unitaire'],
            'montant' => $montant,
            'justification' => $data['justification'] ?? null,
            'hypothese_id' => $data['hypothese_id'] ?? null,
        ];

        return DB::transaction(function () use ($ligne, $existant, $attributs): BudgetLineDetail {
            $detail = $existant === null ? $ligne->details()->create($attributs) : tap($existant, fn (BudgetLineDetail $row) => $row->fill($attributs)->save());
            $this->recalculerDepuisDetails($ligne);
            $this->assertPlafond($ligne->dossier, $ligne->fresh(), null);

            return $detail;
        });
    }

    public function supprimerDetail(User $actor, BudgetLineDetail $detail): void
    {
        $this->editer($actor);
        $ligne = $detail->line;
        $this->assertDossierEditable($ligne->dossier);
        DB::transaction(function () use ($detail, $ligne): void {
            $detail->delete();
            if ($ligne->details()->exists()) {
                $this->recalculerDepuisDetails($ligne);
            } else {
                $ligne->forceFill([
                    'mode' => 'direct',
                    'montant' => $this->produit((string) $ligne->quantite, (int) $ligne->cout_unitaire),
                ])->save();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function enregistrerPeriode(User $actor, BudgetDossierLine $ligne, array $data, ?BudgetLinePeriod $existante = null): BudgetLinePeriod
    {
        $this->editer($actor);
        $this->assertDossierEditable($ligne->dossier);
        $montant = (int) $data['montant'];
        $deja = (int) $ligne->periods()->when($existante !== null, fn ($query) => $query->whereKeyNot($existante->id))->sum('montant');
        if ($deja + $montant > (int) $ligne->montant) {
            throw ValidationException::withMessages(['periodes' => 'La somme des périodes dépasse le montant de la ligne.']);
        }
        if ($existante === null) {
            return $ligne->periods()->create(['periode' => $data['periode'], 'montant' => $montant]);
        }
        $existante->fill(['periode' => $data['periode'], 'montant' => $montant])->save();

        return $existante;
    }

    public function supprimerPeriode(User $actor, BudgetLinePeriod $periode): void
    {
        $this->editer($actor);
        $this->assertDossierEditable($periode->line->dossier);
        $periode->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function enregistrerFinancement(User $actor, BudgetDossierLine $ligne, array $data, ?BudgetLineFunding $existant = null): BudgetLineFunding
    {
        $this->editer($actor);
        $this->assertDossierEditable($ligne->dossier);
        $categorie = isset($data['revenue_category_id']) ? RevenueCategory::query()->find($data['revenue_category_id']) : null;
        if ($categorie === null && ($data['source'] ?? '') === '') {
            throw ValidationException::withMessages(['source' => 'Choisissez une source de financement du référentiel.']);
        }
        $attributs = [
            'revenue_category_id' => $categorie?->id,
            'source' => $categorie?->label ?? $data['source'],
            'montant' => (int) $data['montant'],
        ];
        $deja = (int) $ligne->fundings()->when($existant !== null, fn ($query) => $query->whereKeyNot($existant->id))->sum('montant');
        if ($deja + (int) $attributs['montant'] > (int) $ligne->montant) {
            throw ValidationException::withMessages(['financements' => 'La somme des financements dépasse le montant de la ligne.']);
        }
        $financement = $existant === null ? $ligne->fundings()->create($attributs) : tap($existant, fn (BudgetLineFunding $row) => $row->fill($attributs)->save());

        return $financement->load('category');
    }

    public function supprimerFinancement(User $actor, BudgetLineFunding $financement): void
    {
        $this->editer($actor);
        $this->assertDossierEditable($financement->line->dossier);
        $financement->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function arbitrer(User $actor, array $data): BudgetArbitration
    {
        $this->decider($actor);
        $dossier = BudgetDossier::query()->findOrFail((int) $data['dossier_id']);
        if ($actor->id === $dossier->author_id) {
            throw ValidationException::withMessages(['action' => 'L’auteur du dossier ne tranche pas son arbitrage.']);
        }
        if (! in_array($dossier->statut, ['soumis', 'retenu'], true)) {
            throw ValidationException::withMessages(['dossier' => 'Arbitrez un dossier soumis.']);
        }
        $ligne = isset($data['line_id']) ? $dossier->lines()->findOrFail((int) $data['line_id']) : null;
        $demande = $ligne !== null ? (int) $ligne->montant : (int) $dossier->lines()->sum('montant');
        $retenu = (int) $data['montant_retenu'];
        if ($data['decision'] === 'annule' && empty($data['cible_id'])) {
            throw ValidationException::withMessages(['cible_id' => 'L’annulation vise une décision antérieure.']);
        }

        return DB::transaction(function () use ($actor, $data, $dossier, $ligne, $demande, $retenu): BudgetArbitration {
            $arbitrage = BudgetArbitration::query()->create([
                'campaign_id' => $dossier->campaign_id,
                'dossier_id' => $dossier->id,
                'line_id' => $ligne?->id,
                'cible_id' => $data['cible_id'] ?? null,
                'montant_demande' => $demande,
                'montant_retenu' => $data['decision'] === 'ecarte' ? 0 : $retenu,
                'decision' => $data['decision'],
                'motif' => $data['motif'],
                'actor_id' => $actor->id,
            ]);
            if ($data['decision'] === 'annule') {
                $precedent = BudgetArbitration::query()->find($data['cible_id']);
                $cibleLigne = $precedent?->line_id ? BudgetDossierLine::query()->find($precedent->line_id) : $ligne;
                $cibleLigne?->forceFill(['montant_retenu' => null])->save();
            } elseif ($ligne !== null) {
                $ligne->forceFill(['montant_retenu' => $data['decision'] === 'ecarte' ? 0 : $retenu])->save();
                $this->assertPlafond($dossier, $ligne->fresh(), null);
            }
            $dossier->forceFill(['statut' => $data['decision'] === 'ecarte' ? 'ecarte' : 'retenu'])->save();
            $this->journal($actor, 'budget.arbitrage.enregistre', 'dossier', $dossier->id, ['demande' => $demande], [
                'decision' => $data['decision'],
                'retenu' => $arbitrage->montant_retenu,
                'arbitrage' => $arbitrage->id,
            ], $data['motif']);
            $this->notifier($dossier->campaign, 'Arbitrage '.$data['decision'].' sur '.$dossier->reference.'.', 'dossier', $dossier->id);
            $this->taches->sync('budget_dossier', $dossier->id);

            return $arbitrage->load('actor');
        });
    }

    public function creerVersion(User $actor, BudgetCampaign $campagne, string $libelle): BudgetVersion
    {
        $this->editer($actor);
        if (! in_array($campagne->statut, ['ouverte', 'suspendue'], true)) {
            throw ValidationException::withMessages(['campagne' => 'La version se constitue sur une campagne ouverte.']);
        }

        return DB::transaction(function () use ($actor, $campagne, $libelle): BudgetVersion {
            $numero = (int) $campagne->versions()->max('numero') + 1;
            $snapshot = $this->figer($campagne);
            $version = $campagne->versions()->create([
                'numero' => $numero,
                'libelle' => $libelle,
                'statut' => 'travail',
                'snapshot' => $snapshot,
                'author_id' => $actor->id,
            ]);
            foreach ($snapshot['recettes'] as $recette) {
                BudgetVersionForecast::query()->create([
                    'version_id' => $version->id,
                    'forecast_id' => $recette['forecast_id'],
                    'code' => $recette['code'],
                    'label' => $recette['label'],
                    'montant' => $recette['montant'],
                ]);
            }
            $this->journal($actor, 'budget.version.creee', 'version', $version->id, null, ['numero' => $numero]);

            return $version;
        });
    }

    public function soumettreVersion(User $actor, BudgetVersion $version): BudgetVersion
    {
        $this->editer($actor);
        $this->assertStatut($version, ['travail', 'retournee']);
        $version->forceFill(['statut' => 'soumise'])->save();
        $this->journal($actor, 'budget.version.soumise', 'version', $version->id, null, ['statut' => 'soumise']);
        $this->notifier($version->campaign, 'Version '.$version->numero.' soumise.', 'campagne', $version->campaign_id, 'directeur_budget');

        return $version;
    }

    public function retournerVersion(User $actor, BudgetVersion $version, string $motif): BudgetVersion
    {
        $this->decider($actor);
        $this->assertStatut($version, ['soumise']);
        if ($actor->id === $version->author_id) {
            throw ValidationException::withMessages(['action' => 'L’auteur ne retourne pas sa version.']);
        }
        $version->forceFill(['statut' => 'retournee', 'motif' => $motif])->save();
        $this->journal($actor, 'budget.version.retournee', 'version', $version->id, null, ['statut' => 'retournee'], $motif);

        return $version;
    }

    public function validerVersion(User $actor, BudgetVersion $version): BudgetVersion
    {
        $this->decider($actor);
        $this->assertStatut($version, ['soumise']);
        if ($actor->id === $version->author_id) {
            throw ValidationException::withMessages(['action' => 'L’auteur de la version ne la valide pas.']);
        }
        $version->forceFill(['statut' => 'validee', 'decided_by' => $actor->id, 'decided_at' => now()])->save();
        $this->journal($actor, 'budget.version.validee', 'version', $version->id, null, ['statut' => 'validee']);

        return $version;
    }

    public function adopterVersion(User $actor, BudgetVersion $version): BudgetVersion
    {
        $this->decider($actor);
        if ($version->transmise_at !== null && $version->statut === 'adoptee') {
            return $version;
        }
        $this->assertStatut($version, ['validee']);
        if ($actor->id === $version->author_id) {
            throw ValidationException::withMessages(['action' => 'L’auteur de la version ne peut pas l’adopter.']);
        }

        return DB::transaction(function () use ($actor, $version): BudgetVersion {
            $version = BudgetVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($version->transmise_at !== null) {
                return $version;
            }
            $campagne = $version->campaign()->lockForUpdate()->firstOrFail();
            $exercice = Exercice::query()->lockForUpdate()->findOrFail($campagne->exercice_id);
            if ($exercice->statut !== 'preparation') {
                throw ValidationException::withMessages(['exercice' => 'L’exercice n’est plus en préparation.']);
            }
            $this->transmettre($exercice, $version->snapshot['lignes'] ?? []);
            $exercice->forceFill(['statut' => 'executoire'])->save();
            $version->forceFill([
                'statut' => 'adoptee',
                'transmise_at' => now(),
            ])->save();
            $campagne->forceFill(['statut' => 'cloturee'])->save();
            $this->journal($actor, 'budget.version.adoptee', 'version', $version->id, null, [
                'exercice' => $exercice->annee,
                'lignes' => count($version->snapshot['lignes'] ?? []),
            ]);
            $this->notifier($campagne, 'Budget '.$exercice->annee.' adopté et transmis.', 'campagne', $campagne->id);

            return $version;
        });
    }

    public function publierVersion(User $actor, BudgetVersion $version): BudgetVersion
    {
        $this->decider($actor);
        $this->assertStatut($version, ['adoptee']);
        $version->forceFill(['statut' => 'publiee'])->save();
        $this->journal($actor, 'budget.version.publiee', 'version', $version->id, null, ['statut' => 'publiee']);

        return $version;
    }

    public function archiverVersion(User $actor, BudgetVersion $version): BudgetVersion
    {
        $this->decider($actor);
        if (in_array($version->statut, BudgetVersion::FIGEES, true)) {
            throw ValidationException::withMessages(['version' => 'Une version validée, adoptée ou publiée reste immuable.']);
        }
        $version->forceFill(['statut' => 'archivee'])->save();

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    public function comparer(User $actor, BudgetVersion $gauche, BudgetVersion $droite): array
    {
        $this->voir($actor);
        $codes = collect($gauche->snapshot['lignes'] ?? [])->keyBy('code');
        $ecarts = [];
        foreach ($droite->snapshot['lignes'] ?? [] as $ligne) {
            $avant = (int) ($codes->get($ligne['code'])['montant_retenu'] ?? 0);
            $apres = (int) $ligne['montant_retenu'];
            if ($avant !== $apres) {
                $ecarts[] = ['code' => $ligne['code'], 'avant' => $avant, 'apres' => $apres, 'variation' => $apres - $avant];
            }
            $codes->forget($ligne['code']);
        }
        foreach ($codes as $code => $ligne) {
            $ecarts[] = ['code' => $code, 'avant' => (int) $ligne['montant_retenu'], 'apres' => 0, 'variation' => -((int) $ligne['montant_retenu'])];
        }

        return [
            'gauche' => ['id' => $gauche->id, 'numero' => $gauche->numero, 'libelle' => $gauche->libelle, 'depenses' => $gauche->snapshot['depenses'] ?? 0, 'recettes' => $gauche->snapshot['recettes_total'] ?? 0],
            'droite' => ['id' => $droite->id, 'numero' => $droite->numero, 'libelle' => $droite->libelle, 'depenses' => $droite->snapshot['depenses'] ?? 0, 'recettes' => $droite->snapshot['recettes_total'] ?? 0],
            'ecarts' => $ecarts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function consolidation(User $actor, BudgetCampaign $campagne, ?BudgetVersion $version = null): array
    {
        $this->voir($actor);
        $campagne->loadMissing('exercice');
        if ($version !== null && $version->campaign_id === $campagne->id && in_array($version->statut, BudgetVersion::FIGEES, true)) {
            return $this->consoliderSnapshot($campagne, $version->snapshot);
        }

        return $this->consoliderSnapshot($campagne, $this->figer($campagne));
    }

    /**
     * @return array<string, mixed>
     */
    public function historique(User $actor, string $type, int $id): array
    {
        $this->voir($actor);

        return AuditEvent::query()
            ->with('actor:id,name')
            ->where('object_type', $type)
            ->where('object_id', (string) $id)
            ->latest('id')
            ->limit(80)
            ->get()
            ->map(fn (AuditEvent $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'acteur' => $event->actor?->name,
                'motif' => $event->motif,
                'date' => $event->created_at?->format('d/m/Y H:i'),
                'apres' => $event->after,
            ])->all();
    }

    public function ajouterPiece(User $actor, UploadedFile $fichier, array $liens): BudgetPiece
    {
        $this->editer($actor);
        $this->assertPieceEditable($liens);
        $contenu = (string) file_get_contents($fichier->getRealPath());
        $chemin = $fichier->store('preparation-pieces');
        $piece = BudgetPiece::query()->create([
            ...$liens,
            'original_name' => $fichier->getClientOriginalName(),
            'path' => $chemin,
            'mime' => (string) $fichier->getMimeType(),
            'size' => $fichier->getSize(),
            'sha256' => hash('sha256', $contenu),
            'version' => 1,
            'uploaded_by' => $actor->id,
        ]);
        $this->journal($actor, 'budget.piece.ajoutee', 'piece', $piece->id, null, ['nom' => $piece->original_name]);

        return $piece;
    }

    public function remplacerPiece(User $actor, BudgetPiece $piece, UploadedFile $fichier): BudgetPiece
    {
        $this->editer($actor);
        $this->assertPieceEditable([
            'campaign_id' => $piece->campaign_id,
            'dossier_id' => $piece->dossier_id,
            'line_id' => $piece->line_id,
        ]);
        $contenu = (string) file_get_contents($fichier->getRealPath());
        $nouvelle = BudgetPiece::query()->create([
            'campaign_id' => $piece->campaign_id,
            'dossier_id' => $piece->dossier_id,
            'line_id' => $piece->line_id,
            'arbitrage_id' => $piece->arbitrage_id,
            'version_id' => $piece->version_id,
            'original_name' => $fichier->getClientOriginalName(),
            'path' => $fichier->store('preparation-pieces'),
            'mime' => (string) $fichier->getMimeType(),
            'size' => $fichier->getSize(),
            'sha256' => hash('sha256', $contenu),
            'version' => (int) $piece->version + 1,
            'remplace_id' => $piece->id,
            'uploaded_by' => $actor->id,
        ]);
        $this->journal($actor, 'budget.piece.remplacee', 'piece', $nouvelle->id, ['precedente' => $piece->id], ['version' => $nouvelle->version]);

        return $nouvelle;
    }

    public function retirerPiece(User $actor, BudgetPiece $piece): void
    {
        $this->editer($actor);
        $this->assertPieceEditable([
            'campaign_id' => $piece->campaign_id,
            'dossier_id' => $piece->dossier_id,
        ]);
        if (BudgetPiece::query()->where('remplace_id', $piece->id)->exists()) {
            throw ValidationException::withMessages(['piece' => 'Une version ultérieure conserve cette pièce.']);
        }
        $piece->forceFill(['retiree_at' => now()])->save();
        $this->journal($actor, 'budget.piece.retiree', 'piece', $piece->id, ['nom' => $piece->original_name], null);
    }

    public function relancerEcheances(): int
    {
        $nombre = 0;
        $etapes = BudgetCampaignStep::query()
            ->with('campaign')
            ->whereNull('alerte_echeance_at')
            ->whereDate('echeance', '>=', now()->toDateString())
            ->whereDate('echeance', '<=', now()->addDays(7)->toDateString())
            ->whereHas('campaign', fn ($query) => $query->where('statut', 'ouverte'))
            ->get();
        foreach ($etapes as $etape) {
            $this->notifier($etape->campaign, 'Échéance proche : '.$etape->label.' ('.$etape->echeance?->format('d/m/Y').').', 'campagne', $etape->campaign_id);
            $etape->forceFill(['alerte_echeance_at' => now()])->save();
            $nombre++;
        }

        return $nombre;
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     */
    private function transmettre(Exercice $exercice, array $lignes): void
    {
        foreach ($lignes as $ligne) {
            if ((int) ($ligne['montant_retenu'] ?? 0) < 1) {
                continue;
            }
            $code = (string) $ligne['code'];
            $montant = (int) $ligne['montant_retenu'];
            $existante = BudgetLine::query()->where('exercice_id', $exercice->id)->where('code', $code)->first();
            if ($existante !== null) {
                if ((int) $existante->montant_vote !== $montant) {
                    throw ValidationException::withMessages(['transmission' => "La ligne {$code} existe déjà avec un autre montant."]);
                }

                continue;
            }
            BudgetLine::query()->create([
                'exercice_id' => $exercice->id,
                'organization_unit_id' => (int) $ligne['organization_unit_id'],
                'code' => $code,
                'label' => $ligne['label'],
                'nature' => $ligne['nature'],
                'chapitre' => substr($code, 0, 2),
                'article' => substr($code, 2, 2),
                'paragraphe' => substr($code, 4, 2),
                'nature_depense' => $ligne['classification'] === 'investissement' ? 'Investissement' : 'Fonctionnement',
                'montant_vote' => $montant,
                'ajustements' => 0,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function figer(BudgetCampaign $campagne): array
    {
        $lignes = BudgetDossierLine::query()
            ->with(['dossier.organizationUnit', 'periods', 'fundings', 'garNode'])
            ->whereHas('dossier', fn ($query) => $query->where('campaign_id', $campagne->id)->whereNotIn('statut', ['annule', 'ecarte', 'brouillon']))
            ->get()
            ->map(fn (BudgetDossierLine $ligne): array => [
                'id' => $ligne->id,
                'code' => $ligne->code,
                'label' => $ligne->label,
                'classification' => $ligne->classification,
                'nature' => $ligne->nature->value,
                'organization_unit_id' => $ligne->dossier->organization_unit_id,
                'structure' => $ligne->dossier->organizationUnit?->sigle,
                'montant' => (int) $ligne->montant,
                'montant_retenu' => $ligne->retenu(),
                'gar_node_id' => $ligne->gar_node_id,
                'activite' => $ligne->garNode?->libelle,
                'periodes' => $ligne->periods->map(fn (BudgetLinePeriod $periode): array => ['periode' => $periode->periode, 'montant' => (int) $periode->montant])->all(),
                'financements' => $ligne->fundings->map(fn (BudgetLineFunding $financement): array => ['source' => $financement->source, 'montant' => (int) $financement->montant])->all(),
            ])->all();
        $enveloppes = $campagne->envelopes()->with('organizationUnit')->where('statut', 'actif')->get()->map(fn (BudgetEnvelope $enveloppe): array => [
            'id' => $enveloppe->id,
            'parent_id' => $enveloppe->parent_id,
            'structure' => $enveloppe->organizationUnit?->sigle,
            'organization_unit_id' => $enveloppe->organization_unit_id,
            'classification' => $enveloppe->classification,
            'montant' => (int) $enveloppe->montant,
            'feuille' => $enveloppe->children()->doesntExist(),
        ])->all();
        $recettes = RevenueForecast::query()->where('exercice_id', $campagne->exercice_id)->where('statut', 'valide')->get()
            ->map(fn (RevenueForecast $prevision): array => [
                'forecast_id' => $prevision->id,
                'code' => $prevision->code,
                'label' => $prevision->label,
                'montant' => (int) $prevision->montant,
            ])->all();
        $hypotheses = $campagne->hypotheses()->where('statut', 'publiee')->get(['id', 'code', 'version', 'valeur', 'unite'])->toArray();
        $depenses = array_sum(array_column($lignes, 'montant_retenu'));

        return [
            'lignes' => $lignes,
            'enveloppes' => $enveloppes,
            'recettes' => $recettes,
            'hypotheses' => $hypotheses,
            'depenses' => $depenses,
            'recettes_total' => array_sum(array_column($recettes, 'montant')),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function consoliderSnapshot(BudgetCampaign $campagne, array $snapshot): array
    {
        $lignes = $snapshot['lignes'] ?? [];
        $feuilles = array_values(array_filter($snapshot['enveloppes'] ?? [], fn (array $enveloppe): bool => (bool) ($enveloppe['feuille'] ?? true)));
        $plafonds = array_sum(array_column($feuilles, 'montant'));
        $depenses = (int) ($snapshot['depenses'] ?? 0);
        $recettes = (int) ($snapshot['recettes_total'] ?? 0);

        return [
            'campagne' => ['id' => $campagne->id, 'code' => $campagne->code, 'exercice' => $campagne->exercice?->annee],
            'par_structure' => $this->grouper($lignes, 'structure'),
            'par_imputation' => $this->grouper($lignes, 'code'),
            'par_classification' => $this->grouper($lignes, 'classification'),
            'par_activite' => $this->grouper($lignes, 'activite'),
            'par_financement' => $this->grouperImbrique($lignes, 'financements', 'source'),
            'par_periode' => $this->grouperImbrique($lignes, 'periodes', 'periode'),
            'plafonds' => $plafonds,
            'depenses' => $depenses,
            'recettes' => $recettes,
            'ecart_plafonds' => $plafonds - $depenses,
            'equilibre' => $recettes - $depenses,
            'lignes' => $lignes,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return list<array{cle: string, montant: int}>
     */
    private function grouper(array $lignes, string $cle): array
    {
        $groupes = [];
        foreach ($lignes as $ligne) {
            $nom = (string) ($ligne[$cle] ?? '—');
            $groupes[$nom] = ($groupes[$nom] ?? 0) + (int) $ligne['montant_retenu'];
        }
        $resultat = [];
        foreach ($groupes as $nom => $montant) {
            $resultat[] = ['cle' => $nom, 'montant' => $montant];
        }

        return $resultat;
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return list<array{cle: string, montant: int}>
     */
    private function grouperImbrique(array $lignes, string $liste, string $cle): array
    {
        $groupes = [];
        foreach ($lignes as $ligne) {
            foreach ($ligne[$liste] ?? [] as $portion) {
                $nom = (string) ($portion[$cle] ?? '—');
                $groupes[$nom] = ($groupes[$nom] ?? 0) + (int) $portion['montant'];
            }
        }
        $resultat = [];
        foreach ($groupes as $nom => $montant) {
            $resultat[] = ['cle' => $nom, 'montant' => $montant];
        }

        return $resultat;
    }

    private function recalculerDepuisDetails(BudgetDossierLine $ligne): void
    {
        $total = (int) $ligne->details()->sum('montant');
        $ligne->forceFill(['mode' => 'details', 'montant' => $total])->save();
    }

    private function assertRepartitions(BudgetDossierLine $ligne): void
    {
        $periodes = (int) $ligne->periods()->sum('montant');
        if ($ligne->periods()->exists() && $periodes !== (int) $ligne->montant) {
            throw ValidationException::withMessages(['periodes' => 'La somme des périodes doit égaler le montant de la ligne.']);
        }
        $financements = (int) $ligne->fundings()->sum('montant');
        if ($ligne->fundings()->exists() && $financements !== (int) $ligne->montant) {
            throw ValidationException::withMessages(['financements' => 'La somme des financements doit égaler le montant de la ligne.']);
        }
    }

    private function assertPlafond(BudgetDossier $dossier, BudgetDossierLine $ligne, ?int $ignore): void
    {
        $enveloppe = BudgetEnvelope::query()
            ->where('campaign_id', $dossier->campaign_id)
            ->where('organization_unit_id', $dossier->organization_unit_id)
            ->where('classification', $ligne->classification)
            ->where('statut', 'actif')
            ->whereDoesntHave('children')
            ->orderByDesc('version')
            ->first();
        if ($enveloppe === null) {
            return;
        }
        $somme = BudgetDossierLine::query()
            ->where('classification', $ligne->classification)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore))
            ->where('id', '!=', $ligne->id)
            ->whereHas('dossier', fn ($query) => $query
                ->where('campaign_id', $dossier->campaign_id)
                ->where('organization_unit_id', $dossier->organization_unit_id)
                ->whereNotIn('statut', ['annule', 'ecarte']))
            ->get()
            ->sum(fn (BudgetDossierLine $row): int => $row->retenu());
        if ($somme + $ligne->retenu() > (int) $enveloppe->montant) {
            throw ValidationException::withMessages(['montant' => 'Le plafond de la structure est dépassé. L’enregistrement est bloqué.']);
        }
    }

    private function assertEnfantsDansParent(BudgetEnvelope $enveloppe): void
    {
        if ($enveloppe->parent_id === null) {
            $enfants = (int) $enveloppe->children()->sum('montant');
            if ($enfants > (int) $enveloppe->montant) {
                throw ValidationException::withMessages(['montant' => 'Le plafond parent est inférieur à la somme de ses enveloppes filles.']);
            }

            return;
        }
        $parent = $enveloppe->parent;
        $soeurs = (int) BudgetEnvelope::query()->where('parent_id', $parent->id)->sum('montant');
        if ($soeurs > (int) $parent->montant) {
            throw ValidationException::withMessages(['montant' => 'La somme des enveloppes filles dépasse le plafond parent.']);
        }
    }

    private function assertCodeUnique(BudgetDossier $dossier, string $code, ?int $ignore): void
    {
        $existe = BudgetDossierLine::query()
            ->where('code', strtoupper(trim($code)))
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore))
            ->whereHas('dossier', fn ($query) => $query->where('campaign_id', $dossier->campaign_id)->whereNotIn('statut', ['annule', 'ecarte']))
            ->exists();
        if ($existe) {
            throw ValidationException::withMessages(['code' => 'Ce code est déjà porté par une ligne de la campagne.']);
        }
    }

    private function activiteDeLExercice(BudgetDossier $dossier, int $garId): GarNode
    {
        $noeud = GarNode::query()->with('version')->find($garId);
        if ($noeud === null || ! in_array($noeud->type, ['activite', 'tache'], true)) {
            throw ValidationException::withMessages(['gar_node_id' => 'Sélectionnez une activité ou une tâche de la planification.']);
        }
        if ((int) $noeud->version?->exercice_id !== (int) $dossier->campaign->exercice_id) {
            throw ValidationException::withMessages(['gar_node_id' => 'Cette activité appartient à un autre exercice.']);
        }

        return $noeud;
    }

    private function assertCampagneUnique(int $exerciceId, ?int $ignore): void
    {
        $existe = BudgetCampaign::query()
            ->where('exercice_id', $exerciceId)
            ->whereIn('statut', BudgetCampaign::OUVERTES)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore))
            ->exists();
        if ($existe) {
            throw ValidationException::withMessages(['exercice_id' => 'Une campagne est déjà en cours sur cet exercice.']);
        }
    }

    private function assertCampagneMutable(BudgetCampaign $campagne): void
    {
        if (in_array($campagne->statut, ['cloturee', 'archivee'], true) || $campagne->versions()->whereIn('statut', ['adoptee', 'publiee'])->exists()) {
            throw ValidationException::withMessages(['campagne' => 'Cette campagne est close ou son budget est adopté.']);
        }
    }

    private function assertCampagneCollecte(BudgetCampaign $campagne): void
    {
        if ($campagne->statut !== 'ouverte') {
            throw ValidationException::withMessages(['campagne' => 'Les propositions se saisissent sur une campagne ouverte.']);
        }
        $this->assertCampagneMutable($campagne);
    }

    private function assertStructureParticipante(BudgetCampaign $campagne, int $structureId): void
    {
        if (! $campagne->units()->whereKey($structureId)->exists()) {
            throw ValidationException::withMessages(['organization_unit_id' => 'Cette structure ne participe pas à la campagne.']);
        }
    }

    private function assertDossierEditable(BudgetDossier $dossier): void
    {
        if (! in_array($dossier->statut, BudgetDossier::EDITER, true)) {
            throw ValidationException::withMessages(['dossier' => 'Le dossier n’est plus modifiable dans son statut actuel.']);
        }
        $this->assertCampagneMutable($dossier->campaign);
    }

    /**
     * @param  array<string, mixed>  $liens
     */
    private function assertPieceEditable(array $liens): void
    {
        if (! empty($liens['dossier_id'])) {
            $dossier = BudgetDossier::query()->findOrFail((int) $liens['dossier_id']);
            $this->assertDossierEditable($dossier);
        }
        if (! empty($liens['campaign_id'])) {
            $this->assertCampagneMutable(BudgetCampaign::query()->findOrFail((int) $liens['campaign_id']));
        }
    }

    /**
     * @param  list<string>  $statuts
     */
    private function assertStatut(BudgetVersion $version, array $statuts): void
    {
        if (! in_array($version->statut, $statuts, true)) {
            throw ValidationException::withMessages(['version' => 'Cette action n’est pas disponible pour le statut '.$version->statut.'.']);
        }
    }

    private function hypotheseFigee(BudgetHypothesis $hypothese): bool
    {
        return BudgetVersion::query()
            ->where('campaign_id', $hypothese->campaign_id)
            ->whereIn('statut', BudgetVersion::FIGEES)
            ->get()
            ->contains(fn (BudgetVersion $version): bool => collect($version->snapshot['hypotheses'] ?? [])->contains('id', $hypothese->id));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributsHypothese(array $data, int $authorId): array
    {
        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'label' => $data['label'],
            'categorie' => $data['categorie'],
            'valeur' => $data['valeur'] ?? null,
            'unite' => $data['unite'] ?? null,
            'periode' => $data['periode'] ?? null,
            'source' => $data['source'] ?? null,
            'justification' => $data['justification'] ?? null,
            'author_id' => $authorId,
        ];
    }

    private function reference(BudgetCampaign $campagne): string
    {
        $annee = (int) $campagne->exercice->annee;
        $prefixe = 'PREP-'.$annee.'-';
        $derniere = BudgetDossier::query()->where('reference', 'like', $prefixe.'%')->orderByDesc('id')->lockForUpdate()->value('reference');
        $sequence = 1;
        if (is_string($derniere) && preg_match('/(\d+)$/', $derniere, $correspondance) === 1) {
            $sequence = (int) $correspondance[1] + 1;
        }

        return $prefixe.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function produit(string $quantite, int $cout): int
    {
        $normalisee = number_format((float) $quantite, 2, '.', '');

        return (int) round((float) bcmul($normalisee, (string) $cout, 2));
    }

    private function voir(User $actor): void
    {
        if (! $actor->holdsAny()) {
            throw ValidationException::withMessages(['action' => 'Aucun rôle ne permet de consulter la préparation.']);
        }
    }

    private function editer(User $actor): void
    {
        if (! $actor->holds('expert_budget', 'directeur_budget')) {
            throw ValidationException::withMessages(['action' => 'La saisie est réservée à l’expert Budget ou au Directeur du Budget.']);
        }
    }

    private function decider(User $actor): void
    {
        if (! $actor->holds('directeur_budget')) {
            throw ValidationException::withMessages(['action' => 'Cette décision est réservée au Directeur du Budget.']);
        }
    }

    /**
     * @return array<string, bool>
     */
    private function droits(User $actor): array
    {
        return [
            'consulter' => $actor->holdsAny(),
            'editer' => $actor->holds('expert_budget', 'directeur_budget'),
            'decider' => $actor->holds('directeur_budget'),
        ];
    }

    private function notifier(BudgetCampaign $campagne, string $message, string $type, int $id, ?string $role = null): void
    {
        $lien = $type === 'dossier' ? '/preparation/dossiers/'.$id : '/preparation/campagnes/'.$id;
        $roles = $role !== null ? [$role] : ['expert_budget', 'directeur_budget'];
        app(RoleHolders::class)->query($roles)->orderBy('id')->each(
            fn (User $user) => $user->notify(new PreparationAlerte($message, $type, $id, $lien)),
        );
        unset($campagne);
    }

    /**
     * @param  array<string, mixed>|null  $avant
     * @param  array<string, mixed>|null  $apres
     */
    private function journal(User $actor, string $action, string $type, int $id, ?array $avant, ?array $apres, ?string $motif = null): void
    {
        FinancialAudit::record($actor, $action, $type, (string) $id, $avant, $apres, $motif);
    }
}
