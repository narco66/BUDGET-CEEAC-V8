<?php

namespace App\Domains\Budget\Http\Controllers;

use App\Domains\Budget\Exports\PreparationExport;
use App\Domains\Budget\Models\BudgetArbitration;
use App\Domains\Budget\Models\BudgetCampaign;
use App\Domains\Budget\Models\BudgetCampaignStep;
use App\Domains\Budget\Models\BudgetDossier;
use App\Domains\Budget\Models\BudgetDossierLine;
use App\Domains\Budget\Models\BudgetEnvelope;
use App\Domains\Budget\Models\BudgetHypothesis;
use App\Domains\Budget\Models\BudgetLineDetail;
use App\Domains\Budget\Models\BudgetLineFunding;
use App\Domains\Budget\Models\BudgetLinePeriod;
use App\Domains\Budget\Models\BudgetOrientation;
use App\Domains\Budget\Models\BudgetPiece;
use App\Domains\Budget\Models\BudgetVersion;
use App\Domains\Budget\Services\PreparationModuleService;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PreparationModuleController extends Controller
{
    public function __construct(private readonly PreparationModuleService $module) {}

    public function referentiel(Request $request): JsonResponse
    {
        return response()->json($this->module->referentiel($request->user()));
    }

    public function campagnes(Request $request): JsonResponse
    {
        $page = $this->module->campagnes($request->user(), $request->all());

        return response()->json($this->page($page, fn (BudgetCampaign $row): array => [
            'id' => $row->id,
            'code' => $row->code,
            'libelle' => $row->label,
            'exercice' => $row->exercice?->annee,
            'exercice_id' => $row->exercice_id,
            'statut' => $row->statut,
            'ouverture' => $row->date_ouverture?->toDateString(),
            'cloture' => $row->date_cloture?->toDateString(),
            'responsable' => $row->responsable?->name,
            'dossiers' => $row->dossiers_count,
        ]));
    }

    public function storeCampagne(Request $request): JsonResponse
    {
        $data = $request->validate($this->reglesCampagne());
        $campagne = $this->module->creerCampagne($request->user(), $data);

        return response()->json(['data' => ['id' => $campagne->id, 'code' => $campagne->code]], 201);
    }

    public function showCampagne(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $campagne = $this->module->campagne($request->user(), $campaign);

        return response()->json(['data' => $this->carteCampagne($campagne)]);
    }

    public function updateCampagne(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $data = $request->validate($this->reglesCampagne());
        $this->module->modifierCampagne($request->user(), $campaign, $data);

        return response()->json(['data' => ['id' => $campaign->id]]);
    }

    public function destroyCampagne(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $this->module->supprimerCampagne($request->user(), $campaign);

        return response()->json(['data' => ['id' => $campaign->id]]);
    }

    public function ouvrir(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $this->module->ouvrirCampagne($request->user(), $campaign);

        return response()->json(['data' => ['statut' => 'ouverte']]);
    }

    public function suspendre(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $this->module->suspendreCampagne($request->user(), $campaign);

        return response()->json(['data' => ['statut' => 'suspendue']]);
    }

    public function prolonger(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $data = $request->validate(['date_cloture' => ['required', 'date']]);
        $this->module->prolongerCampagne($request->user(), $campaign, $data);

        return response()->json(['data' => ['date_cloture' => $data['date_cloture']]]);
    }

    public function cloturer(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $this->module->cloturerCampagne($request->user(), $campaign);

        return response()->json(['data' => ['statut' => 'cloturee']]);
    }

    public function archiver(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $this->module->archiverCampagne($request->user(), $campaign);

        return response()->json(['data' => ['statut' => 'archivee']]);
    }

    public function storeEtape(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $etape = $this->module->creerEtape($request->user(), $campaign, $request->validate($this->reglesEtape()));

        return response()->json(['data' => ['id' => $etape->id]], 201);
    }

    public function updateEtape(Request $request, BudgetCampaignStep $step): JsonResponse
    {
        $this->module->modifierEtape($request->user(), $step, $request->validate($this->reglesEtape()));

        return response()->json(['data' => ['id' => $step->id]]);
    }

    public function destroyEtape(Request $request, BudgetCampaignStep $step): JsonResponse
    {
        $this->module->supprimerEtape($request->user(), $step);

        return response()->json(['data' => ['id' => $step->id]]);
    }

    public function storeHypothese(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $ligne = $this->module->enregistrerHypothese($request->user(), $campaign, $request->validate($this->reglesHypothese()));

        return response()->json(['data' => ['id' => $ligne->id]], 201);
    }

    public function updateHypothese(Request $request, BudgetHypothesis $hypothesis): JsonResponse
    {
        $ligne = $this->module->enregistrerHypothese($request->user(), $hypothesis->campaign, $request->validate($this->reglesHypothese()), $hypothesis);

        return response()->json(['data' => ['id' => $ligne->id]]);
    }

    public function publierHypothese(Request $request, BudgetHypothesis $hypothesis): JsonResponse
    {
        $this->module->publierHypothese($request->user(), $hypothesis);

        return response()->json(['data' => ['statut' => 'publiee']]);
    }

    public function archiverHypothese(Request $request, BudgetHypothesis $hypothesis): JsonResponse
    {
        $this->module->archiverHypothese($request->user(), $hypothesis);

        return response()->json(['data' => ['statut' => 'archivee']]);
    }

    public function storeOrientation(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $ligne = $this->module->enregistrerOrientation($request->user(), $campaign, $request->validate($this->reglesOrientation()));

        return response()->json(['data' => ['id' => $ligne->id]], 201);
    }

    public function updateOrientation(Request $request, BudgetOrientation $orientation): JsonResponse
    {
        $ligne = $this->module->enregistrerOrientation($request->user(), $orientation->campaign, $request->validate($this->reglesOrientation()), $orientation);

        return response()->json(['data' => ['id' => $ligne->id]]);
    }

    public function publierOrientation(Request $request, BudgetOrientation $orientation): JsonResponse
    {
        $this->module->publierOrientation($request->user(), $orientation);

        return response()->json(['data' => ['statut' => 'publiee']]);
    }

    public function archiverOrientation(Request $request, BudgetOrientation $orientation): JsonResponse
    {
        $this->module->archiverOrientation($request->user(), $orientation);

        return response()->json(['data' => ['statut' => 'archivee']]);
    }

    public function storeEnveloppe(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $ligne = $this->module->enregistrerEnveloppe($request->user(), $campaign, $request->validate($this->reglesEnveloppe()));

        return response()->json(['data' => ['id' => $ligne->id]], 201);
    }

    public function updateEnveloppe(Request $request, BudgetEnvelope $envelope): JsonResponse
    {
        $ligne = $this->module->enregistrerEnveloppe($request->user(), $envelope->campaign, $request->validate($this->reglesEnveloppe()), $envelope);

        return response()->json(['data' => ['id' => $ligne->id]]);
    }

    public function destroyEnveloppe(Request $request, BudgetEnvelope $envelope): JsonResponse
    {
        $this->module->supprimerEnveloppe($request->user(), $envelope);

        return response()->json(['data' => ['id' => $envelope->id]]);
    }

    public function dossiers(Request $request): JsonResponse
    {
        $page = $this->module->dossiers($request->user(), $request->all());

        return response()->json($this->page($page, fn (BudgetDossier $row): array => [
            'id' => $row->id,
            'reference' => $row->reference,
            'titre' => $row->titre,
            'campagne' => $row->campaign?->code,
            'campaign_id' => $row->campaign_id,
            'exercice' => $row->campaign?->exercice?->annee,
            'structure' => $row->organizationUnit?->sigle,
            'montant' => (int) $row->lines_sum_montant,
            'statut' => $row->statut,
        ]));
    }

    public function storeDossier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campaign_id' => ['required', 'integer', 'exists:budget_campaigns,id'],
            'organization_unit_id' => ['required', 'integer', 'exists:organization_units,id'],
            'titre' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'justification' => ['nullable', 'string'],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'observations' => ['nullable', 'string'],
        ]);
        $dossier = $this->module->creerDossier($request->user(), $data);

        return response()->json(['data' => ['id' => $dossier->id, 'reference' => $dossier->reference]], 201);
    }

    public function showDossier(Request $request, BudgetDossier $dossier): JsonResponse
    {
        $fiche = $this->module->dossier($request->user(), $dossier);

        return response()->json(['data' => $this->carteDossier($fiche)]);
    }

    public function updateDossier(Request $request, BudgetDossier $dossier): JsonResponse
    {
        $data = $request->validate([
            'titre' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'justification' => ['nullable', 'string'],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'observations' => ['nullable', 'string'],
        ]);
        $this->module->modifierDossier($request->user(), $dossier, $data);

        return response()->json(['data' => ['id' => $dossier->id]]);
    }

    public function destroyDossier(Request $request, BudgetDossier $dossier): JsonResponse
    {
        $this->module->supprimerDossier($request->user(), $dossier);

        return response()->json(['data' => ['id' => $dossier->id]]);
    }

    public function dupliquerDossier(Request $request, BudgetDossier $dossier): JsonResponse
    {
        $copie = $this->module->dupliquerDossier($request->user(), $dossier);

        return response()->json(['data' => ['id' => $copie->id, 'reference' => $copie->reference]], 201);
    }

    public function soumettreDossier(Request $request, BudgetDossier $dossier): JsonResponse
    {
        $this->module->soumettreDossier($request->user(), $dossier);

        return response()->json(['data' => ['statut' => 'soumis']]);
    }

    public function retournerDossier(Request $request, BudgetDossier $dossier): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:2000']]);
        $this->module->retournerDossier($request->user(), $dossier, $data['motif']);

        return response()->json(['data' => ['statut' => 'retourne']]);
    }

    public function storeLigne(Request $request, BudgetDossier $dossier): JsonResponse
    {
        $ligne = $this->module->enregistrerLigne($request->user(), $dossier, $request->validate($this->reglesLigne()));

        return response()->json(['data' => ['id' => $ligne->id, 'montant' => $ligne->montant]], 201);
    }

    public function updateLigne(Request $request, BudgetDossierLine $line): JsonResponse
    {
        $ligne = $this->module->enregistrerLigne($request->user(), $line->dossier, $request->validate($this->reglesLigne()), $line);

        return response()->json(['data' => ['id' => $ligne->id, 'montant' => $ligne->montant]]);
    }

    public function destroyLigne(Request $request, BudgetDossierLine $line): JsonResponse
    {
        $this->module->supprimerLigne($request->user(), $line);

        return response()->json(['data' => ['id' => $line->id]]);
    }

    public function storeDetail(Request $request, BudgetDossierLine $line): JsonResponse
    {
        $detail = $this->module->enregistrerDetail($request->user(), $line, $request->validate($this->reglesDetail()));

        return response()->json(['data' => ['id' => $detail->id, 'montant' => $detail->montant]], 201);
    }

    public function updateDetail(Request $request, BudgetLineDetail $detail): JsonResponse
    {
        $ligne = $this->module->enregistrerDetail($request->user(), $detail->line, $request->validate($this->reglesDetail()), $detail);

        return response()->json(['data' => ['id' => $ligne->id]]);
    }

    public function destroyDetail(Request $request, BudgetLineDetail $detail): JsonResponse
    {
        $this->module->supprimerDetail($request->user(), $detail);

        return response()->json(['data' => ['id' => $detail->id]]);
    }

    public function storePeriode(Request $request, BudgetDossierLine $line): JsonResponse
    {
        $periode = $this->module->enregistrerPeriode($request->user(), $line, $request->validate([
            'periode' => ['required', 'string', 'max:32'],
            'montant' => ['required', 'integer', 'min:0'],
        ]));

        return response()->json(['data' => ['id' => $periode->id]], 201);
    }

    public function updatePeriode(Request $request, BudgetLinePeriod $period): JsonResponse
    {
        $this->module->enregistrerPeriode($request->user(), $period->line, $request->validate([
            'periode' => ['required', 'string', 'max:32'],
            'montant' => ['required', 'integer', 'min:0'],
        ]), $period);

        return response()->json(['data' => ['id' => $period->id]]);
    }

    public function destroyPeriode(Request $request, BudgetLinePeriod $period): JsonResponse
    {
        $this->module->supprimerPeriode($request->user(), $period);

        return response()->json(['data' => ['id' => $period->id]]);
    }

    public function storeFinancement(Request $request, BudgetDossierLine $line): JsonResponse
    {
        $financement = $this->module->enregistrerFinancement($request->user(), $line, $request->validate([
            'revenue_category_id' => ['nullable', 'integer', 'exists:revenue_categories,id'],
            'source' => ['nullable', 'string', 'max:255'],
            'montant' => ['required', 'integer', 'min:0'],
        ]));

        return response()->json(['data' => ['id' => $financement->id]], 201);
    }

    public function updateFinancement(Request $request, BudgetLineFunding $funding): JsonResponse
    {
        $this->module->enregistrerFinancement($request->user(), $funding->line, $request->validate([
            'revenue_category_id' => ['nullable', 'integer', 'exists:revenue_categories,id'],
            'source' => ['nullable', 'string', 'max:255'],
            'montant' => ['required', 'integer', 'min:0'],
        ]), $funding);

        return response()->json(['data' => ['id' => $funding->id]]);
    }

    public function destroyFinancement(Request $request, BudgetLineFunding $funding): JsonResponse
    {
        $this->module->supprimerFinancement($request->user(), $funding);

        return response()->json(['data' => ['id' => $funding->id]]);
    }

    public function storeArbitrage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dossier_id' => ['required', 'integer', 'exists:budget_dossiers,id'],
            'line_id' => ['nullable', 'integer', 'exists:budget_dossier_lines,id'],
            'cible_id' => ['nullable', 'integer', 'exists:budget_arbitrages,id'],
            'montant_retenu' => ['required', 'integer', 'min:0'],
            'decision' => ['required', 'in:retenu,ecarte,revise,annule'],
            'motif' => ['required', 'string', 'max:2000'],
        ]);
        $arbitrage = $this->module->arbitrer($request->user(), $data);

        return response()->json(['data' => ['id' => $arbitrage->id]], 201);
    }

    public function versions(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $this->module->campagne($request->user(), $campaign);

        return response()->json(['data' => $campaign->versions()->get()->map(fn (BudgetVersion $version): array => [
            'id' => $version->id,
            'numero' => $version->numero,
            'libelle' => $version->libelle,
            'statut' => $version->statut,
            'depenses' => $version->snapshot['depenses'] ?? 0,
            'recettes' => $version->snapshot['recettes_total'] ?? 0,
            'transmise' => $version->transmise_at !== null,
        ])]);
    }

    public function storeVersion(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $data = $request->validate(['libelle' => ['required', 'string', 'max:255']]);
        $version = $this->module->creerVersion($request->user(), $campaign, $data['libelle']);

        return response()->json(['data' => ['id' => $version->id, 'numero' => $version->numero]], 201);
    }

    public function showVersion(Request $request, BudgetVersion $version): JsonResponse
    {
        $this->module->campagne($request->user(), $version->campaign);

        return response()->json(['data' => [
            'id' => $version->id,
            'numero' => $version->numero,
            'libelle' => $version->libelle,
            'statut' => $version->statut,
            'snapshot' => $version->snapshot,
            'auteur_id' => $version->author_id,
            'motif' => $version->motif,
        ]]);
    }

    public function soumettreVersion(Request $request, BudgetVersion $version): JsonResponse
    {
        $this->module->soumettreVersion($request->user(), $version);

        return response()->json(['data' => ['statut' => 'soumise']]);
    }

    public function retournerVersion(Request $request, BudgetVersion $version): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:2000']]);
        $this->module->retournerVersion($request->user(), $version, $data['motif']);

        return response()->json(['data' => ['statut' => 'retournee']]);
    }

    public function validerVersion(Request $request, BudgetVersion $version): JsonResponse
    {
        $this->module->validerVersion($request->user(), $version);

        return response()->json(['data' => ['statut' => 'validee']]);
    }

    public function adopterVersion(Request $request, BudgetVersion $version): JsonResponse
    {
        $this->module->adopterVersion($request->user(), $version);

        return response()->json(['data' => ['statut' => 'adoptee']]);
    }

    public function publierVersion(Request $request, BudgetVersion $version): JsonResponse
    {
        $this->module->publierVersion($request->user(), $version);

        return response()->json(['data' => ['statut' => 'publiee']]);
    }

    public function archiverVersion(Request $request, BudgetVersion $version): JsonResponse
    {
        $this->module->archiverVersion($request->user(), $version);

        return response()->json(['data' => ['statut' => 'archivee']]);
    }

    public function comparer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gauche' => ['required', 'integer', 'exists:budget_versions,id'],
            'droite' => ['required', 'integer', 'exists:budget_versions,id'],
        ]);

        return response()->json($this->module->comparer(
            $request->user(),
            BudgetVersion::query()->findOrFail($data['gauche']),
            BudgetVersion::query()->findOrFail($data['droite']),
        ));
    }

    public function consolidation(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $version = $request->filled('version_id') ? BudgetVersion::query()->findOrFail($request->integer('version_id')) : null;

        return response()->json($this->module->consolidation($request->user(), $campaign, $version));
    }

    public function historique(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:campagne,dossier,version,piece'],
            'id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $this->module->historique($request->user(), $data['type'], (int) $data['id'])]);
    }

    public function storePiece(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fichier' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,xlsx,docx'],
            'campaign_id' => ['nullable', 'integer', 'exists:budget_campaigns,id'],
            'dossier_id' => ['nullable', 'integer', 'exists:budget_dossiers,id'],
            'line_id' => ['nullable', 'integer', 'exists:budget_dossier_lines,id'],
            'arbitrage_id' => ['nullable', 'integer', 'exists:budget_arbitrages,id'],
        ]);
        $piece = $this->module->ajouterPiece($request->user(), $request->file('fichier'), collect($data)->except('fichier')->filter()->all());

        return response()->json(['data' => ['id' => $piece->id]], 201);
    }

    public function remplacerPiece(Request $request, BudgetPiece $piece): JsonResponse
    {
        $request->validate(['fichier' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,xlsx,docx']]);
        $nouvelle = $this->module->remplacerPiece($request->user(), $piece, $request->file('fichier'));

        return response()->json(['data' => ['id' => $nouvelle->id, 'version' => $nouvelle->version]]);
    }

    public function destroyPiece(Request $request, BudgetPiece $piece): JsonResponse
    {
        $this->module->retirerPiece($request->user(), $piece);

        return response()->json(['data' => ['id' => $piece->id]]);
    }

    public function downloadPiece(Request $request, BudgetPiece $piece): StreamedResponse
    {
        $this->module->historique($request->user(), 'piece', $piece->id);
        abort_unless(Storage::disk('local')->exists($piece->path), 404);

        return Storage::disk('local')->download($piece->path, $piece->original_name);
    }

    public function excel(Request $request, BudgetCampaign $campaign): BinaryFileResponse
    {
        $portrait = $this->module->consolidation($request->user(), $campaign, $this->versionDemandee($request));

        return Excel::download(new PreparationExport($portrait['lignes']), 'preparation-'.$campaign->code.'.xlsx');
    }

    public function pdf(Request $request, BudgetCampaign $campaign): Response
    {
        $portrait = $this->module->consolidation($request->user(), $campaign, $this->versionDemandee($request));
        $pdf = Pdf::loadView('pdf.preparation-budgetaire', [
            'titre' => 'Projet de budget '.$campaign->code,
            'campagne' => $campaign->code,
            'depenses' => $portrait['depenses'],
            'recettes' => $portrait['recettes'],
            'equilibre' => $portrait['equilibre'],
            'lignes' => $portrait['lignes'],
        ])->setPaper('a4', 'portrait');

        return $pdf->download('preparation-'.$campaign->code.'.pdf');
    }

    public function cadrage(Request $request, BudgetCampaign $campaign): JsonResponse
    {
        $this->module->campagne($request->user(), $campaign);

        return response()->json([
            'hypotheses' => $campaign->hypotheses()->orderBy('code')->orderBy('version')->get(),
            'orientations' => $campaign->orientations()->orderBy('code')->orderBy('version')->get(),
            'enveloppes' => $campaign->envelopes()->with('organizationUnit:id,sigle')->orderBy('classification')->get(),
        ]);
    }

    private function versionDemandee(Request $request): ?BudgetVersion
    {
        return $request->filled('version_id') ? BudgetVersion::query()->findOrFail($request->integer('version_id')) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function carteCampagne(BudgetCampaign $campagne): array
    {
        return [
            'id' => $campagne->id,
            'code' => $campagne->code,
            'libelle' => $campagne->label,
            'description' => $campagne->description,
            'perimetre' => $campagne->perimetre,
            'exercice_id' => $campagne->exercice_id,
            'exercice' => $campagne->exercice?->annee,
            'date_ouverture' => $campagne->date_ouverture?->toDateString(),
            'date_cloture' => $campagne->date_cloture?->toDateString(),
            'devise' => $campagne->devise,
            'responsable_id' => $campagne->responsable_id,
            'responsable' => $campagne->responsable?->name,
            'version_cadrage' => $campagne->version_cadrage,
            'statut' => $campagne->statut,
            'structures' => $campagne->units->map(fn ($unit): array => ['id' => $unit->id, 'sigle' => $unit->sigle, 'name' => $unit->name])->all(),
            'etapes' => $campagne->steps->map(fn (BudgetCampaignStep $etape): array => [
                'id' => $etape->id,
                'ordre' => $etape->ordre,
                'libelle' => $etape->label,
                'description' => $etape->description,
                'debut' => $etape->debut?->toDateString(),
                'echeance' => $etape->echeance?->toDateString(),
                'acteurs' => $etape->acteurs,
                'structures' => $etape->structures,
                'prerequis' => $etape->prerequis,
                'livrables' => $etape->livrables,
                'verrouillee' => $etape->verrouillee,
                'retard' => $etape->echeance !== null && $etape->echeance->lt(today()) && ! $etape->verrouillee,
            ])->all(),
            'dossiers' => $campagne->dossiers->map(fn (BudgetDossier $dossier): array => [
                'id' => $dossier->id,
                'reference' => $dossier->reference,
                'titre' => $dossier->titre,
                'structure' => $dossier->organizationUnit?->sigle,
                'statut' => $dossier->statut,
            ])->all(),
            'versions' => $campagne->versions->map(fn (BudgetVersion $version): array => [
                'id' => $version->id,
                'numero' => $version->numero,
                'libelle' => $version->libelle,
                'statut' => $version->statut,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function carteDossier(BudgetDossier $dossier): array
    {
        $total = (int) $dossier->lines->sum(fn (BudgetDossierLine $ligne): int => $ligne->retenu());

        return [
            'id' => $dossier->id,
            'reference' => $dossier->reference,
            'campaign_id' => $dossier->campaign_id,
            'campagne' => $dossier->campaign?->code,
            'exercice' => $dossier->campaign?->exercice?->annee,
            'organization_unit_id' => $dossier->organization_unit_id,
            'structure' => $dossier->organizationUnit?->sigle,
            'titre' => $dossier->titre,
            'description' => $dossier->description,
            'justification' => $dossier->justification,
            'responsable_id' => $dossier->responsable_id,
            'responsable' => $dossier->responsable?->name,
            'auteur_id' => $dossier->author_id,
            'version' => $dossier->version,
            'statut' => $dossier->statut,
            'observations' => $dossier->observations,
            'retour_motif' => $dossier->retour_motif,
            'total' => $total,
            'lignes' => $dossier->lines->map(fn (BudgetDossierLine $ligne): array => [
                'id' => $ligne->id,
                'classification' => $ligne->classification,
                'code' => $ligne->code,
                'nature' => $ligne->nature->value,
                'libelle' => $ligne->label,
                'description' => $ligne->description,
                'justification' => $ligne->justification,
                'quantite' => $ligne->quantite,
                'unite' => $ligne->unite,
                'cout_unitaire' => $ligne->cout_unitaire,
                'montant' => $ligne->montant,
                'montant_retenu' => $ligne->montant_retenu,
                'mode' => $ligne->mode,
                'gar_node_id' => $ligne->gar_node_id,
                'activite' => $ligne->garNode?->libelle,
                'periode' => $ligne->periode,
                'observations' => $ligne->observations,
                'details' => $ligne->details,
                'periodes' => $ligne->periods,
                'financements' => $ligne->fundings,
            ])->all(),
            'arbitrages' => $dossier->arbitrages->map(fn (BudgetArbitration $arbitrage): array => [
                'id' => $arbitrage->id,
                'line_id' => $arbitrage->line_id,
                'cible_id' => $arbitrage->cible_id,
                'montant_demande' => $arbitrage->montant_demande,
                'montant_retenu' => $arbitrage->montant_retenu,
                'decision' => $arbitrage->decision,
                'motif' => $arbitrage->motif,
                'acteur' => $arbitrage->actor?->name,
                'date' => $arbitrage->created_at?->format('d/m/Y H:i'),
            ])->all(),
            'pieces' => $dossier->pieces->whereNull('retiree_at')->map(fn (BudgetPiece $piece): array => [
                'id' => $piece->id,
                'nom' => $piece->original_name,
                'version' => $piece->version,
                'remplace_id' => $piece->remplace_id,
            ])->values()->all(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @param  callable(mixed): array<string, mixed>  $carte
     * @return array<string, mixed>
     */
    private function page($page, callable $carte): array
    {
        return [
            'data' => collect($page->items())->map($carte)->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglesCampagne(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
            'label' => ['required', 'string', 'max:255'],
            'exercice_id' => ['required', 'integer', 'exists:exercices,id'],
            'description' => ['nullable', 'string'],
            'perimetre' => ['nullable', 'string', 'max:255'],
            'date_ouverture' => ['nullable', 'date'],
            'date_cloture' => ['nullable', 'date', 'after_or_equal:date_ouverture'],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'version_cadrage' => ['nullable', 'integer', 'min:1'],
            'structures' => ['array'],
            'structures.*' => ['integer', 'exists:organization_units,id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglesEtape(): array
    {
        return [
            'ordre' => ['required', 'integer', 'min:1'],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'debut' => ['nullable', 'date'],
            'echeance' => ['nullable', 'date'],
            'acteurs' => ['nullable', 'string', 'max:255'],
            'structures' => ['nullable', 'string', 'max:255'],
            'prerequis' => ['nullable', 'string'],
            'livrables' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglesHypothese(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
            'label' => ['required', 'string', 'max:255'],
            'categorie' => ['required', 'string', 'max:64'],
            'valeur' => ['nullable', 'string', 'max:255'],
            'unite' => ['nullable', 'string', 'max:32'],
            'periode' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:255'],
            'justification' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglesOrientation(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
            'label' => ['required', 'string', 'max:255'],
            'categorie' => ['required', 'string', 'max:64'],
            'instructions' => ['nullable', 'string'],
            'periode' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:255'],
            'justification' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglesEnveloppe(): array
    {
        return [
            'organization_unit_id' => ['required', 'integer', 'exists:organization_units,id'],
            'parent_id' => ['nullable', 'integer', 'exists:budget_envelopes,id'],
            'classification' => ['required', 'in:fonctionnement,investissement'],
            'perimetre' => ['nullable', 'string', 'max:255'],
            'montant' => ['required', 'integer', 'min:0'],
            'justification' => ['nullable', 'string'],
            'date_effet' => ['nullable', 'date'],
            'version' => ['nullable', 'integer', 'min:1'],
            'statut' => ['nullable', 'in:brouillon,actif'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglesLigne(): array
    {
        return [
            'classification' => ['required', 'in:fonctionnement,investissement'],
            'code' => ['required', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'justification' => ['nullable', 'string'],
            'quantite' => ['required', 'numeric', 'min:0.01'],
            'unite' => ['nullable', 'string', 'max:32'],
            'cout_unitaire' => ['required', 'integer', 'min:0'],
            'gar_node_id' => ['nullable', 'integer', 'exists:gar_nodes,id'],
            'periode' => ['nullable', 'string', 'max:64'],
            'observations' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglesDetail(): array
    {
        return [
            'designation' => ['required', 'string', 'max:255'],
            'gar_node_id' => ['nullable', 'integer', 'exists:gar_nodes,id'],
            'quantite' => ['required', 'numeric', 'min:0.01'],
            'unite' => ['nullable', 'string', 'max:32'],
            'cout_unitaire' => ['required', 'integer', 'min:0'],
            'justification' => ['nullable', 'string'],
            'hypothese_id' => ['nullable', 'integer', 'exists:budget_hypotheses,id'],
        ];
    }
}
