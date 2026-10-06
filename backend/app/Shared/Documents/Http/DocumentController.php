<?php

namespace App\Shared\Documents\Http;

use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Needs\Models\EbDocument;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Http\Controllers\Controller;
use App\Shared\Documents\GeneratedDocument;
use App\Shared\Documents\OfficialDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    /**
     * @var array<string, class-string>
     */
    private const TYPES = [
        'expression_besoin' => ExpressionBesoin::class,
        'engagement' => Engagement::class,
        'liquidation' => Liquidation::class,
        'ordonnancement' => Ordonnancement::class,
        'paiement' => Paiement::class,
    ];

    /**
     * Versions archivées d’un acte, de la plus récente à la plus ancienne.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(self::TYPES))],
            'id' => ['required', 'integer'],
        ]);
        $model = self::TYPES[$data['type']]::query()->findOrFail($data['id']);
        $this->authorize('view', $model);

        return response()->json([
            'data' => GeneratedDocument::query()
                ->with('generatedBy')
                ->where('documentable_type', $model->getMorphClass())
                ->where('documentable_id', $model->getKey())
                ->where('kind', $data['type'])
                ->orderByDesc('version')
                ->get()
                ->map(fn (GeneratedDocument $document) => $document->summary()),
        ]);
    }

    /**
     * Vérifie un document à partir du code imprimé en pied de page : existence,
     * version, empreinte archivée et intégrité du fichier stocké.
     */
    /**
     * Contrôle public limité : existence, référence, révision, date et
     * statut d’archive. Ni empreinte, ni identité, ni contenu du dossier.
     */
    public function verifyPublic(string $code): JsonResponse
    {
        if (! Str::isUuid($code)) {
            return response()->json(['authentique' => false, 'message' => 'Aucun document officiel ne porte ce code.'], 404);
        }

        $document = GeneratedDocument::query()->where('verification_code', $code)->first();
        if ($document === null) {
            return response()->json(['authentique' => false, 'message' => 'Aucun document officiel ne porte ce code.'], 404);
        }

        $disk = Storage::disk(OfficialDocumentService::DISK);
        $intact = $disk->exists($document->path) && hash_equals($document->sha256, hash('sha256', (string) $disk->get($document->path)));
        $latest = GeneratedDocument::query()
            ->where('documentable_type', $document->documentable_type)
            ->where('documentable_id', $document->documentable_id)
            ->where('kind', $document->kind)
            ->max('version');

        return response()->json([
            'authentique' => true,
            'integre' => $intact,
            'reference' => $document->business_reference,
            'version' => $document->version,
            'emis_le' => $document->created_at?->toDateTimeString(),
            'type' => $document->kind,
            'archivage' => (int) $latest === $document->version ? 'courant' : 'remplace',
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holdsAny(), 403);
        $term = $request->string('q')->trim()->toString();
        $acts = GeneratedDocument::query()
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('business_reference', 'like', "%{$term}%")
                ->orWhere('filename', 'like', "%{$term}%")))
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (GeneratedDocument $document): array => [
                'source' => 'acte',
                'id' => $document->id,
                'titre' => $document->business_reference ?: $document->filename,
                'conservation' => $this->retention('generated_document', $document->id),
            ]);
        $pieces = EbDocument::query()
            ->when($term !== '', fn ($query) => $query->where('original_name', 'like', "%{$term}%"))
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (EbDocument $document): array => [
                'source' => 'piece',
                'id' => $document->id,
                'titre' => $document->original_name,
                'conservation' => $this->retention('eb_document', $document->id),
            ]);

        return response()->json(['data' => $acts->concat($pieces)->values()]);
    }

    public function retain(Request $request): JsonResponse
    {
        abort_unless($request->user()?->holds('auditeur', 'directeur_budget', 'administrateur_fonctionnel'), 403);
        $data = $request->validate([
            'source' => ['required', 'in:acte,piece'],
            'id' => ['required', 'integer'],
            'retain_until' => ['required', 'date', 'after:today'],
        ]);
        $type = $data['source'] === 'acte' ? 'generated_document' : 'eb_document';
        DB::table('ged_retentions')->updateOrInsert(
            ['source_type' => $type, 'source_id' => $data['id']],
            ['retain_until' => $data['retain_until'], 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['data' => ['conservation' => $data['retain_until']]]);
    }

    private function retention(string $type, int $id): ?string
    {
        $date = DB::table('ged_retentions')->where('source_type', $type)->where('source_id', $id)->value('retain_until');

        return $date === null ? null : (string) $date;
    }

    public function verify(string $code): JsonResponse
    {
        if (! Str::isUuid($code)) {
            return response()->json(['authentique' => false, 'message' => 'Aucun document officiel ne porte ce code.'], 404);
        }

        $document = GeneratedDocument::query()->with('generatedBy')->where('verification_code', $code)->first();
        if ($document === null) {
            return response()->json(['authentique' => false, 'message' => 'Aucun document officiel ne porte ce code.'], 404);
        }

        $disk = Storage::disk(OfficialDocumentService::DISK);
        $intact = $disk->exists($document->path) && hash_equals($document->sha256, hash('sha256', (string) $disk->get($document->path)));
        $latest = GeneratedDocument::query()
            ->where('documentable_type', $document->documentable_type)
            ->where('documentable_id', $document->documentable_id)
            ->where('kind', $document->kind)
            ->max('version');

        return response()->json([
            'authentique' => true,
            'integre' => $intact,
            'version_courante' => (int) $latest === $document->version,
            'document' => $document->summary(),
        ]);
    }
}
