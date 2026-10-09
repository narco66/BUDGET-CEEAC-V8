<?php

namespace App\Domains\Revenues\Http\Controllers;

use App\Domains\Revenues\Models\RevenueCategory;
use App\Domains\Revenues\Models\RevenueContribution;
use App\Domains\Revenues\Models\RevenueForecast;
use App\Domains\Revenues\Models\RevenueOrder;
use App\Domains\Revenues\Models\RevenuePaymentMode;
use App\Domains\Revenues\Models\RevenueReceipt;
use App\Domains\Revenues\Services\RevenueAccess;
use App\Domains\Revenues\Services\RevenueCollectionService;
use App\Domains\Revenues\Services\RevenueCycleService;
use App\Domains\Revenues\Services\RevenuePortraitService;
use App\Http\Controllers\Controller;
use App\Shared\Documents\OfficialDocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RevenueController extends Controller
{
    public function __construct(
        private readonly RevenueAccess $access,
        private readonly RevenueCycleService $cycle,
        private readonly RevenueCollectionService $collection,
        private readonly RevenuePortraitService $portrait,
        private readonly OfficialDocumentService $documents,
    ) {}

    public function tableau(Request $request): JsonResponse
    {
        $this->voir($request);

        return response()->json($this->portrait->tableau($request->integer('exercice_id') ?: null) + ['droits' => $this->access->droits($request->user())]);
    }

    public function referentiel(Request $request): JsonResponse
    {
        $this->voir($request);

        return response()->json($this->portrait->referentiel());
    }

    public function previsions(Request $request): JsonResponse
    {
        $this->voir($request);

        return response()->json($this->portrait->previsions($request->query()) + ['droits' => $this->access->droits($request->user())]);
    }

    public function showPrevision(Request $request, RevenueForecast $forecast): JsonResponse
    {
        $this->voir($request);

        return response()->json($this->portrait->fichePrevision($forecast) + ['droits' => $this->access->droits($request->user())]);
    }

    public function storePrevision(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exercice_id' => ['required', 'integer'],
            'category_id' => ['required', 'integer'],
            'organization_unit_id' => ['nullable', 'integer'],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'montant' => ['required', 'integer', 'min:1'],
            'source_label' => ['nullable', 'string', 'max:255'],
            'periode' => ['nullable', 'string', 'max:64'],
            'date_prevue' => ['nullable', 'date'],
            'observations' => ['nullable', 'string'],
        ]);
        $forecast = $this->cycle->creerPrevision($request->user(), $data);

        return response()->json(['data' => ['id' => $forecast->id, 'code' => $forecast->code]], 201);
    }

    public function updatePrevision(Request $request, RevenueForecast $forecast): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['sometimes', 'integer'],
            'label' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'montant' => ['sometimes', 'integer', 'min:1'],
            'source_label' => ['nullable', 'string', 'max:255'],
            'periode' => ['nullable', 'string', 'max:64'],
            'date_prevue' => ['nullable', 'date'],
            'observations' => ['nullable', 'string'],
            'organization_unit_id' => ['nullable', 'integer'],
        ]);
        $this->cycle->modifierPrevision($request->user(), $forecast, $data);

        return response()->json(['data' => ['id' => $forecast->id]]);
    }

    public function destroyPrevision(Request $request, RevenueForecast $forecast): JsonResponse
    {
        $this->cycle->supprimerPrevision($request->user(), $forecast);

        return response()->json(['data' => ['id' => $forecast->id]]);
    }

    public function soumettrePrevision(Request $request, RevenueForecast $forecast): JsonResponse
    {
        $this->cycle->soumettrePrevision($request->user(), $forecast);

        return response()->json(['data' => ['statut' => $forecast->fresh()->statut]]);
    }

    public function validerPrevision(Request $request, RevenueForecast $forecast): JsonResponse
    {
        $this->cycle->validerPrevision($request->user(), $forecast);

        return response()->json(['data' => ['statut' => $forecast->fresh()->statut]]);
    }

    public function annulerPrevision(Request $request, RevenueForecast $forecast): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:500']]);
        $this->cycle->annulerPrevision($request->user(), $forecast, $data['motif']);

        return response()->json(['data' => ['statut' => $forecast->fresh()->statut]]);
    }

    public function titres(Request $request): JsonResponse
    {
        $this->voir($request);

        return response()->json($this->portrait->titres($request->query()) + ['droits' => $this->access->droits($request->user())]);
    }

    public function storeTitre(Request $request): JsonResponse
    {
        $order = $this->cycle->creerTitre($request->user(), $this->titreData($request));

        return response()->json(['data' => ['id' => $order->id, 'reference' => $order->reference]], 201);
    }

    public function showTitre(Request $request, RevenueOrder $order): JsonResponse
    {
        $this->voir($request);

        return response()->json($this->portrait->fiche($order) + ['droits' => $this->access->droits($request->user())]);
    }

    public function updateTitre(Request $request, RevenueOrder $order): JsonResponse
    {
        $this->cycle->modifierTitre($request->user(), $order, $this->titreData($request, false));

        return response()->json(['data' => ['id' => $order->id]]);
    }

    public function soumettre(Request $request, RevenueOrder $order): JsonResponse
    {
        return response()->json(['data' => ['statut' => $this->cycle->soumettre($request->user(), $order)->statut]]);
    }

    public function verifier(Request $request, RevenueOrder $order): JsonResponse
    {
        return response()->json(['data' => ['statut' => $this->cycle->verifier($request->user(), $order)->statut]]);
    }

    public function valider(Request $request, RevenueOrder $order): JsonResponse
    {
        return response()->json(['data' => ['statut' => $this->cycle->valider($request->user(), $order)->statut]]);
    }

    public function prendre(Request $request, RevenueOrder $order): JsonResponse
    {
        return response()->json(['data' => ['statut' => $this->cycle->prendreEnCharge($request->user(), $order)->statut]]);
    }

    public function rejeter(Request $request, RevenueOrder $order): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => ['statut' => $this->cycle->rejeter($request->user(), $order, $data['motif'])->statut]]);
    }

    public function suspendre(Request $request, RevenueOrder $order): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => ['statut' => $this->cycle->suspendre($request->user(), $order, $data['motif'])->statut]]);
    }

    public function reprendre(Request $request, RevenueOrder $order): JsonResponse
    {
        return response()->json(['data' => ['statut' => $this->cycle->reprendre($request->user(), $order)->statut]]);
    }

    public function annuler(Request $request, RevenueOrder $order): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => ['statut' => $this->cycle->annuler($request->user(), $order, $data['motif'])->statut]]);
    }

    public function regulariser(Request $request, RevenueOrder $order): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:avoir,regularisation'],
            'montant' => ['required', 'integer', 'min:1'],
            'motif' => ['required', 'string', 'max:500'],
        ]);
        $updated = $this->cycle->regulariser($request->user(), $order, $data['kind'], (int) $data['montant'], $data['motif']);

        return response()->json(['data' => ['statut' => $updated->statut, 'montant' => (int) $updated->montant]]);
    }

    public function piece(Request $request, RevenueOrder $order): JsonResponse
    {
        $data = $request->validate([
            'fichier' => ['required', 'file', 'max:10240', 'extensions:'.implode(',', config('ged.extensions')), 'mimes:'.implode(',', config('ged.extensions'))],
            'type_piece' => ['required', 'string', 'max:64'],
        ]);
        $document = $this->cycle->attacher($request->user(), $order, $data['fichier'], $data['type_piece']);

        return response()->json(['data' => ['id' => $document->id, 'sha256' => $document->sha256]], 201);
    }

    public function document(Request $request, RevenueOrder $order): StreamedResponse
    {
        $this->voir($request);
        $kind = $request->string('kind')->toString() ?: 'titre';
        $order->load(['exercice', 'category', 'author']);
        $archive = $this->documents->archive($order, 'recette-'.$kind, $order->reference, 'pdf.recette', [
            'titre' => match ($kind) {
                'appel' => 'Avis d’appel de fonds',
                'situation' => 'Situation du débiteur',
                default => 'Titre de recette',
            },
            'order' => $order,
        ], $kind, $request->user());

        return $this->documents->download($archive);
    }

    public function contributions(Request $request): JsonResponse
    {
        $this->voir($request);

        return response()->json($this->portrait->contributions($request->integer('exercice_id') ?: null) + ['droits' => $this->access->droits($request->user())]);
    }

    public function storeContribution(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exercice_id' => ['required', 'integer'],
            'member_state_id' => ['required', 'integer'],
            'quote_part' => ['required', 'integer', 'min:0', 'max:10000'],
            'montant_attendu' => ['required', 'integer', 'min:1'],
            'echeance' => ['nullable', 'date'],
            'observations' => ['nullable', 'string'],
        ]);
        $row = $this->cycle->creerContribution($request->user(), $data);

        return response()->json(['data' => ['id' => $row->id]], 201);
    }

    public function appeler(Request $request, RevenueContribution $contribution): JsonResponse
    {
        $order = $this->cycle->appeler($request->user(), $contribution);

        return response()->json(['data' => ['id' => $order->id, 'reference' => $order->reference]], 201);
    }

    public function creances(Request $request): JsonResponse
    {
        $this->voir($request);

        return response()->json($this->portrait->creances($request->query()));
    }

    public function echeancier(Request $request): JsonResponse
    {
        $this->voir($request);

        return response()->json($this->portrait->echeancier());
    }

    public function encaissements(Request $request): JsonResponse
    {
        $this->voir($request);

        return response()->json($this->portrait->encaissements() + ['droits' => $this->access->droits($request->user())]);
    }

    public function storeEncaissement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recu_le' => ['required', 'date'],
            'montant' => ['required', 'integer', 'min:1'],
            'devise' => ['nullable', 'string', 'size:3'],
            'taux' => ['nullable', 'numeric', 'min:0'],
            'mode' => ['required', 'string'],
            'reference_bancaire' => ['nullable', 'string', 'max:255'],
            'banque' => ['nullable', 'string', 'max:255'],
            'compte' => ['nullable', 'string', 'max:255'],
            'transaction_no' => ['nullable', 'string', 'max:255'],
            'commentaire' => ['nullable', 'string'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.order_id' => ['required', 'integer'],
            'allocations.*.montant' => ['required', 'integer', 'min:1'],
            'trop_percu' => ['nullable', 'array'],
            'trop_percu.kind' => ['required_with:trop_percu', 'in:avance,remboursement'],
            'trop_percu.montant' => ['required_with:trop_percu', 'integer', 'min:1'],
            'trop_percu.order_id' => ['required_with:trop_percu', 'integer'],
        ]);
        $receipt = $this->collection->encaisser($request->user(), $data);

        return response()->json(['data' => ['id' => $receipt->id, 'reference' => $receipt->reference, 'statut' => $receipt->statut]], 201);
    }

    public function affecter(Request $request, RevenueReceipt $receipt): JsonResponse
    {
        $data = $request->validate([
            'allocations' => ['nullable', 'array'],
            'allocations.*.order_id' => ['required', 'integer'],
            'allocations.*.montant' => ['required', 'integer', 'min:1'],
            'trop_percu' => ['nullable', 'array'],
            'trop_percu.kind' => ['required_with:trop_percu', 'in:avance,remboursement'],
            'trop_percu.montant' => ['required_with:trop_percu', 'integer', 'min:1'],
            'trop_percu.order_id' => ['required_with:trop_percu', 'integer'],
        ]);
        $updated = $this->collection->affecter($request->user(), $receipt, $data);

        return response()->json(['data' => ['id' => $updated->id, 'statut' => $updated->statut]]);
    }

    public function rapprochements(Request $request): JsonResponse
    {
        $this->voir($request);

        return response()->json($this->portrait->encaissements());
    }

    public function rapprocher(Request $request, RevenueReceipt $receipt): JsonResponse
    {
        $data = $request->validate([
            'statut' => ['required', 'in:rapproche,anomalie'],
            'motif' => ['nullable', 'string', 'max:500'],
        ]);
        $updated = $this->collection->rapprocher($request->user(), $receipt, $data['statut'], $data['motif'] ?? null);

        return response()->json(['data' => ['statut' => $updated->statut]]);
    }

    public function relances(Request $request): JsonResponse
    {
        $this->voir($request);

        return response()->json($this->portrait->relances());
    }

    public function storeRelance(Request $request, RevenueOrder $order): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string'],
            'canal' => ['required', 'string', 'max:32'],
            'destinataire' => ['required', 'string', 'max:255'],
            'resultat' => ['nullable', 'string', 'max:255'],
            'prochaine_action' => ['nullable', 'date'],
        ]);
        $reminder = $this->cycle->relancer($request->user(), $order, $data);

        return response()->json(['data' => ['id' => $reminder->id]], 201);
    }

    public function etats(Request $request): StreamedResponse
    {
        $this->voir($request);
        $lignes = $this->portrait->exportLignes($request->integer('exercice_id') ?: null);
        if ($request->query('format') === 'pdf') {
            $pdf = Pdf::loadView('pdf.recettes-etat', ['lignes' => $lignes, 'titre' => 'État des recettes']);

            return response()->streamDownload(function () use ($pdf): void {
                echo $pdf->output();
            }, 'etat-recettes.pdf', ['Content-Type' => 'application/pdf']);
        }
        $sheet = new Spreadsheet;
        $grid = $sheet->getActiveSheet();
        $headers = array_keys($lignes[0] ?? ['Référence' => '', 'Exercice' => '', 'Nature' => '', 'Débiteur' => '', 'Prévu' => '', 'Encaissé' => '', 'Solde' => '', 'Échéance' => '', 'Statut' => '']);
        $grid->fromArray($headers, null, 'A1');
        $line = 2;
        foreach ($lignes as $row) {
            $grid->fromArray(array_values($row), null, 'A'.$line);
            $line++;
        }
        $writer = new Xlsx($sheet);

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, 'etat-recettes.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function storeCategorie(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'label' => ['required', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ]);
        $category = $this->cycle->enregistrerCategorie($request->user(), $data);

        return response()->json(['data' => ['id' => $category->id]], 201);
    }

    public function updateCategorie(Request $request, RevenueCategory $category): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'label' => ['required', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ]);
        $this->cycle->enregistrerCategorie($request->user(), $data, $category);

        return response()->json(['data' => ['id' => $category->id]]);
    }

    public function storeMode(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32'], 'label' => ['required', 'string', 'max:255'], 'active' => ['nullable', 'boolean']]);
        $mode = $this->cycle->enregistrerMode($request->user(), $data);

        return response()->json(['data' => ['id' => $mode->id]], 201);
    }

    public function updateMode(Request $request, RevenuePaymentMode $mode): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32'], 'label' => ['required', 'string', 'max:255'], 'active' => ['nullable', 'boolean']]);
        $this->cycle->enregistrerMode($request->user(), $data, $mode);

        return response()->json(['data' => ['id' => $mode->id]]);
    }

    public function storeSeuil(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string'], 'value' => ['required', 'integer', 'min:0', 'max:3650']]);
        $this->cycle->reglerSeuil($request->user(), $data['key'], (int) $data['value']);

        return response()->json(['data' => ['key' => $data['key']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function titreData(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'exercice_id' => [$creating ? 'required' : 'sometimes', 'integer'],
            'category_id' => [$creating ? 'required' : 'sometimes', 'integer'],
            'forecast_id' => ['nullable', 'integer'],
            'organization_unit_id' => ['nullable', 'integer'],
            'debtor_type' => [$creating ? 'required' : 'sometimes', 'string'],
            'tiers_id' => ['nullable', 'integer'],
            'member_state_id' => ['nullable', 'integer'],
            'debtor_label' => ['nullable', 'string', 'max:255'],
            'montant' => [$creating ? 'required' : 'sometimes', 'integer', 'min:1'],
            'echeance' => [$creating ? 'required' : 'sometimes', 'date'],
            'motif' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'observations' => ['nullable', 'string'],
        ]);
    }

    private function voir(Request $request): void
    {
        abort_unless($this->access->voir($request->user()), 403);
    }
}
