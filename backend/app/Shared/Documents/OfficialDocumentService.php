<?php

namespace App\Shared\Documents;

use App\Domains\Ged\Services\GedService;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Génère, archive et restitue les actes officiels. Un acte est produit à
 * l’événement métier puis servi tel qu’archivé ; une nouvelle génération
 * crée une nouvelle version et ne remplace jamais la précédente.
 */
class OfficialDocumentService
{
    public const DISK = 'local';

    /**
     * @param  array<string, mixed>  $viewData
     */
    public function archive(Model $model, string $kind, string $reference, string $view, array $viewData, string $event, ?User $actor): GeneratedDocument
    {
        $generated = DB::transaction(function () use ($model, $kind, $reference, $view, $viewData, $event, $actor) {
            $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->first();
            $previous = $this->current($model, $kind);
            $version = ($previous?->version ?? 0) + 1;
            $code = (string) Str::uuid();
            $generatedAt = now();

            $bytes = Pdf::loadView($view, $viewData + [
                'verification' => app(DocumentVerification::class)->payload($code, $version, $event, $generatedAt->format('d/m/Y H:i')),
            ])->setOption('isPhpEnabled', true)->setPaper('a4', 'portrait')->output();

            $safeReference = Str::slug(str_replace('/', '-', $reference));
            $filename = $safeReference.'-v'.$version.'.pdf';
            $path = 'actes/'.$kind.'/'.$model->getKey().'/'.$filename;
            Storage::disk(self::DISK)->put($path, $bytes);

            return GeneratedDocument::query()->create([
                'verification_code' => $code,
                'documentable_type' => $model->getMorphClass(),
                'documentable_id' => $model->getKey(),
                'kind' => $kind,
                'version' => $version,
                'business_reference' => $reference,
                'event' => $event,
                'path' => $path,
                'filename' => $filename,
                'sha256' => hash('sha256', $bytes),
                'size' => strlen($bytes),
                'snapshot' => $model->attributesToArray(),
                'generated_by' => $actor?->id,
                'supersedes_id' => $previous?->id,
            ]);
        });

        try {
            app(GedService::class)->verserActe($generated);
        } catch (Throwable $exception) {
            Log::error('Versement GED de l’acte impossible', [
                'generated_document_id' => $generated->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        return $generated;
    }

    /**
     * Archivage déclenché après une transition déjà validée : un échec de
     * génération ne doit pas annuler l’acte métier. Le document sera produit
     * à la première consultation.
     *
     * @param  array<string, mixed>  $viewData
     */
    public function archiveQuietly(Model $model, string $kind, string $reference, string $view, array $viewData, string $event, ?User $actor): ?GeneratedDocument
    {
        try {
            return $this->archive($model, $kind, $reference, $view, $viewData, $event, $actor);
        } catch (Throwable $exception) {
            Log::error('Archivage de l’acte officiel impossible', [
                'kind' => $kind,
                'reference' => $reference,
                'event' => $event,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function current(Model $model, string $kind): ?GeneratedDocument
    {
        return GeneratedDocument::query()
            ->where('documentable_type', $model->getMorphClass())
            ->where('documentable_id', $model->getKey())
            ->where('kind', $kind)
            ->orderByDesc('version')
            ->first();
    }

    public function version(Model $model, string $kind, int $version): ?GeneratedDocument
    {
        return GeneratedDocument::query()
            ->where('documentable_type', $model->getMorphClass())
            ->where('documentable_id', $model->getKey())
            ->where('kind', $kind)
            ->where('version', $version)
            ->first();
    }

    /**
     * Restitue le fichier archivé après contrôle d’intégrité : un fichier dont
     * l’empreinte ne correspond plus est refusé.
     */
    public function download(GeneratedDocument $document): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($document->path), 410, 'Le fichier archivé est introuvable.');
        $bytes = (string) $disk->get($document->path);
        abort_unless(hash_equals($document->sha256, hash('sha256', $bytes)), 409, 'L’intégrité du document archivé n’est plus garantie (empreinte différente).');

        return response()->streamDownload(fn () => print ($bytes), $document->filename, [
            'Content-Type' => 'application/pdf',
            'X-Document-Version' => (string) $document->version,
            'X-Document-Sha256' => $document->sha256,
            'X-Document-Verification' => $document->verification_code,
        ]);
    }
}
