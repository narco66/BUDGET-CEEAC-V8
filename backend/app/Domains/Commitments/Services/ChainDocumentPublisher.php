<?php

namespace App\Domains\Commitments\Services;

use App\Models\User;
use App\Shared\Documents\GeneratedDocument;
use App\Shared\Documents\OfficialDocumentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Émet un acte archivé sans valider l’étape métier. Un échec de génération
 * après une transition déjà commise est journalisé et repris à la consultation.
 */
class ChainDocumentPublisher
{
    public function __construct(
        private readonly OfficialDocumentService $documents,
        private readonly ChainActePresenter $presenter,
    ) {}

    public function emit(Model $model, string $kind, string $reference, string $event, ?User $actor, bool $quietly = true): ?GeneratedDocument
    {
        try {
            $acte = $this->presenter->present($kind, $model);
        } catch (ValidationException $exception) {
            if ($quietly) {
                return null;
            }

            throw $exception;
        }

        $arguments = [$model, $kind, $reference, 'pdf.acte', ['acte' => $acte], $event, $actor];

        return $quietly
            ? $this->documents->archiveQuietly(...$arguments)
            : $this->documents->archive(...$arguments);
    }

    public function current(Model $model, string $kind, int $version = 0): ?GeneratedDocument
    {
        return $version > 0
            ? $this->documents->version($model, $kind, $version)
            : $this->documents->current($model, $kind);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function index(Model $model): array
    {
        return GeneratedDocument::query()
            ->with('generatedBy')
            ->where('documentable_type', $model->getMorphClass())
            ->where('documentable_id', $model->getKey())
            ->orderByDesc('id')
            ->get()
            ->map(fn (GeneratedDocument $document): array => $document->summary())
            ->all();
    }

    /**
     * @return list<string>
     */
    public function emissibles(Model $model): array
    {
        $archives = GeneratedDocument::query()
            ->where('documentable_type', $model->getMorphClass())
            ->where('documentable_id', $model->getKey())
            ->pluck('kind')
            ->all();

        return array_values(array_diff($this->presenter->kindsFor($model), $archives));
    }
}
