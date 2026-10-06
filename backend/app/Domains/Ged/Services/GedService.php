<?php

namespace App\Domains\Ged\Services;

use App\Domains\Administration\Models\SystemSetting;
use App\Domains\Administration\Services\AdministrationService;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Ged\Models\GedDocument;
use App\Domains\Ged\Models\GedLink;
use App\Domains\Ged\Models\GedVersion;
use App\Domains\Ged\Notifications\GedNotification;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Tasks\Models\WorkflowTask;
use App\Models\User;
use App\Shared\Audit\AuditService;
use App\Shared\Documents\GeneratedDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GedService
{
    /** @var array<string, class-string<Model>> */
    public const CIBLES = [
        'expression_besoin' => ExpressionBesoin::class,
        'engagement' => Engagement::class,
        'liquidation' => Liquidation::class,
        'ordonnancement' => Ordonnancement::class,
        'paiement' => Paiement::class,
    ];

    public function __construct(private AdministrationService $administration) {}

    /**
     * @return array<string, mixed>
     */
    public function referentiel(): array
    {
        $this->assurerCategories();

        return [
            'types' => DB::table('document_types')->where('active', true)->orderBy('label')->get(['id', 'code', 'label', 'operation', 'required', 'max_size_kb']),
            'categories' => DB::table('ged_categories')->orderBy('label')->get(['id', 'code', 'label']),
            'confidentialites' => config('ged.confidentialites'),
            'extensions' => config('ged.extensions'),
            'analyse_obligatoire' => (bool) config('ged.analyse_obligatoire'),
            'analyse_disponible' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    public function liste(User $user, array $filtres): array
    {
        $page = max(1, (int) ($filtres['page'] ?? 1));
        $taille = min(50, max(1, (int) ($filtres['per_page'] ?? 25)));
        $requete = $this->visibles($user)
            ->with(['currentVersion', 'links'])
            ->when(($filtres['q'] ?? '') !== '', function ($query) use ($filtres) {
                $terme = '%'.mb_strtolower((string) $filtres['q']).'%';
                $query->where(function ($inner) use ($terme) {
                    $inner->whereRaw('lower(title) like ?', [$terme])
                        ->orWhereRaw('lower(reference) like ?', [$terme])
                        ->orWhereHas('versions', fn ($versions) => $versions->whereRaw('lower(original_filename) like ?', [$terme])->orWhereRaw('lower(coalesce(extracted_text, \'\')) like ?', [$terme]));
                });
            })
            ->when(($filtres['statut'] ?? '') !== '', fn ($query) => $query->where('status', $filtres['statut']))
            ->when(($filtres['origine'] ?? '') !== '', fn ($query) => $query->where('origin', $filtres['origine']))
            ->when(($filtres['exercice'] ?? '') !== '', fn ($query) => $query->where('exercise_year', (int) $filtres['exercice']))
            ->when(($filtres['vue'] ?? '') === 'archives', fn ($query) => $query->where('status', 'archive'))
            ->when(($filtres['vue'] ?? '') === 'a_traiter', fn ($query) => $query->whereIn('status', ['a_verifier', 'rejete']))
            ->when(($filtres['vue'] ?? '') === 'miens', fn ($query) => $query->where('owner_user_id', $user->id))
            ->orderByDesc('id');

        $total = (clone $requete)->count();

        return [
            'data' => $requete->forPage($page, $taille)->get()->map(fn (GedDocument $document) => $this->resume($document))->all(),
            'meta' => [
                'total' => $total,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $taille)),
                'per_page' => $taille,
            ],
            'indicateurs' => [
                'total' => $this->visibles($user)->count(),
                'a_verifier' => $this->visibles($user)->where('status', 'a_verifier')->count(),
                'rejetes' => $this->visibles($user)->where('status', 'rejete')->count(),
                'archives' => $this->visibles($user)->where('status', 'archive')->count(),
                'quarantaine' => $this->visibles($user)->where('scan_status', 'quarantaine')->count(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function deposer(User $actor, UploadedFile $fichier, array $data): GedDocument
    {
        $cible = $this->cible((string) $data['entity_type'], (int) $data['entity_id']);
        $this->autoriserDepot($actor, $cible);
        $controle = $this->controlerFichier($fichier, isset($data['document_type_id']) ? (int) $data['document_type_id'] : null);
        $annee = (int) ($data['exercise_year'] ?? now()->year);
        $chemin = null;

        try {
            return DB::transaction(function () use ($actor, $fichier, $data, $controle, $annee, $cible, &$chemin) {
                $document = GedDocument::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'reference' => $this->reference($annee),
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'document_type_id' => $data['document_type_id'] ?? null,
                    'category_id' => $data['category_id'] ?? null,
                    'status' => 'depose',
                    'confidentiality' => $data['confidentiality'] ?? 'interne',
                    'owner_user_id' => $actor->id,
                    'organization_unit_id' => $cible->getAttribute('organization_unit_id'),
                    'exercise_year' => $annee,
                    'origin' => 'user_upload',
                    'scan_status' => $controle['scan'],
                ]);
                $chemin = $this->ecrire($document, 1, $controle['extension'], $controle['bytes']);
                $version = $this->version($document, 1, $chemin, $fichier->getClientOriginalName(), $controle, $actor, $data['motif'] ?? 'Dépôt initial');
                $document->forceFill(['current_version_id' => $version->id])->save();
                GedLink::query()->create([
                    'ged_document_id' => $document->id,
                    'entity_type' => $data['entity_type'],
                    'entity_id' => $cible->getKey(),
                    'relation' => 'propre',
                    'created_by' => $actor->id,
                ]);
                $this->administration->audit($actor, 'ged.deposer', 'ged_document', (string) $document->id, null, [
                    'reference' => $document->reference,
                    'sha256' => $controle['sha256'],
                    'scan' => $controle['scan'],
                ]);
                $this->ouvrirTache($document);

                return $document->fresh(['currentVersion', 'links']);
            });
        } catch (\Throwable $exception) {
            if (is_string($chemin)) {
                Storage::disk('local')->delete($chemin);
            }
            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function nouvelleVersion(User $actor, GedDocument $document, UploadedFile $fichier, array $data): GedDocument
    {
        $this->autoriserVoir($actor, $document);
        if ($document->deleted_at !== null || $document->frozen_at !== null || $document->status === 'archive') {
            throw ValidationException::withMessages(['document' => 'Ce document est archivé, gelé ou retiré. Une nouvelle version est refusée.']);
        }
        $controle = $this->controlerFichier($fichier, $document->document_type_id);
        $numero = ((int) $document->versions()->max('version_number')) + 1;
        $chemin = null;

        try {
            return DB::transaction(function () use ($actor, $document, $fichier, $data, $controle, $numero, &$chemin) {
                GedDocument::query()->whereKey($document->id)->lockForUpdate()->first();
                $chemin = $this->ecrire($document, $numero, $controle['extension'], $controle['bytes']);
                $document->versions()->update(['is_current' => false]);
                $version = $this->version($document, $numero, $chemin, $fichier->getClientOriginalName(), $controle, $actor, (string) ($data['motif'] ?? 'Nouvelle version'));
                $document->forceFill([
                    'current_version_id' => $version->id,
                    'status' => 'depose',
                    'scan_status' => $controle['scan'],
                ])->save();
                $this->administration->audit($actor, 'ged.version', 'ged_document', (string) $document->id, null, [
                    'version' => $numero,
                    'sha256' => $controle['sha256'],
                ], $data['motif'] ?? null);

                return $document->fresh(['currentVersion', 'links']);
            });
        } catch (\Throwable $exception) {
            if (is_string($chemin)) {
                Storage::disk('local')->delete($chemin);
            }
            throw $exception;
        }
    }

    public function telecharger(User $actor, GedDocument $document, ?int $versionId = null): StreamedResponse
    {
        $this->autoriserVoir($actor, $document);
        $version = $versionId === null
            ? $document->currentVersion
            : $document->versions()->whereKey($versionId)->first();
        if ($version === null) {
            throw ValidationException::withMessages(['version' => 'Cette version est introuvable.']);
        }
        if ($version->scan_status === 'quarantaine' || $document->scan_status === 'quarantaine') {
            abort(423, 'Ce fichier est en quarantaine et ne peut pas être téléchargé.');
        }
        $disk = Storage::disk($version->disk);
        abort_unless($disk->exists($version->path), 410, 'Le fichier est introuvable.');
        $bytes = (string) $disk->get($version->path);
        abort_unless(hash_equals($version->sha256, hash('sha256', $bytes)), 409, 'L’empreinte du fichier ne correspond plus.');
        $this->administration->audit($actor, 'ged.telecharger', 'ged_document', (string) $document->id, null, [
            'version' => $version->version_number,
        ]);

        return response()->streamDownload(fn () => print ($bytes), $version->original_filename, [
            'Content-Type' => $version->mime,
        ]);
    }

    public function decider(User $actor, GedDocument $document, string $decision, ?string $motif): GedDocument
    {
        $this->autoriserVoir($actor, $document);
        if (! $actor->holds('controleur_financier', 'administrateur_habilitations', 'administrateur_fonctionnel')) {
            throw ValidationException::withMessages(['document' => 'Cette décision est réservée au contrôle ou à l’administration.']);
        }
        if (in_array($decision, ['supprimer', 'purger'], true) && ! $actor->holds('administrateur_habilitations')) {
            throw ValidationException::withMessages(['document' => 'La suppression est réservée à l’administrateur des habilitations.']);
        }
        if ($document->frozen_at !== null && in_array($decision, ['archiver', 'supprimer'], true)) {
            throw ValidationException::withMessages(['document' => 'Un gel documentaire interdit cette action.']);
        }
        $avant = $document->status;
        $statut = match ($decision) {
            'valider' => 'valide',
            'rejeter' => 'rejete',
            'archiver' => 'archive',
            'supprimer' => 'annule',
            'restaurer' => 'depose',
            'geler' => $document->status,
            'purger' => 'purge',
            default => null,
        };
        if ($statut === null) {
            throw ValidationException::withMessages(['decision' => 'Décision inconnue.']);
        }
        if ($decision === 'purger') {
            if ($document->status !== 'annule' || $document->frozen_at !== null) {
                throw ValidationException::withMessages(['document' => 'La purge n’est possible qu’après une suppression logique, hors gel.']);
            }
            $this->administration->audit($actor, 'ged.purger', 'ged_document', (string) $document->id, ['statut' => $avant], ['statut' => 'purge'], $motif);
            foreach ($document->versions as $version) {
                if ($document->generated_document_id === null) {
                    Storage::disk($version->disk)->delete($version->path);
                }
            }
            $document->delete();

            return $document;
        }
        $document->forceFill([
            'status' => $statut,
            'archived_at' => $decision === 'archiver' ? now() : $document->archived_at,
            'deleted_at' => $decision === 'supprimer' ? now() : ($decision === 'restaurer' ? null : $document->deleted_at),
            'frozen_at' => $decision === 'geler' ? now() : $document->frozen_at,
        ])->save();
        $this->administration->audit($actor, 'ged.'.$decision, 'ged_document', (string) $document->id, ['statut' => $avant], ['statut' => $document->status], $motif);
        if (in_array($decision, ['valider', 'rejeter', 'archiver'], true)) {
            $this->cloturerTache($document, $decision);
        }
        if ($document->owner_user_id !== null && $document->owner_user_id !== $actor->id && in_array($decision, ['valider', 'rejeter'], true)) {
            User::query()->find($document->owner_user_id)?->notify(new GedNotification($document, $decision === 'valider' ? 'a été validé' : 'a été rejeté'));
        }

        return $document->fresh(['currentVersion', 'links']);
    }

    /**
     * @return array<string, mixed>
     */
    public function dossier(User $user, string $type, int $id): array
    {
        $cible = $this->cible($type, $id);
        abort_unless($user->can('view', $cible), 403);
        $chaine = $this->chaine($type, $id);
        $liens = GedLink::query()->where(function ($query) use ($chaine) {
            foreach ($chaine as $maillon) {
                $query->orWhere(fn ($inner) => $inner->where('entity_type', $maillon['type'])->where('entity_id', $maillon['id']));
            }
        })->get();
        $documents = GedDocument::query()->with(['currentVersion', 'links'])->whereIn('id', $liens->pluck('ged_document_id'))->whereNull('deleted_at')->get();
        $visibles = $documents->filter(fn (GedDocument $document) => $this->voit($user, $document))->values();

        return [
            'peut_deposer' => $this->peutDeposer($user, $cible),
            'completude' => $this->completude($type, $visibles),
            'maillons' => $chaine,
            'documents' => $visibles->map(function (GedDocument $document) use ($type, $id) {
                $resume = $this->resume($document);
                $resume['heritage'] = $document->links->contains(fn (GedLink $lien) => $lien->entity_type === $type && (int) $lien->entity_id === $id) ? 'propre' : 'herite';

                return $resume;
            })->all(),
        ];
    }

    public function verserActe(GeneratedDocument $acte): ?GedDocument
    {
        if (GedDocument::query()->where('generated_document_id', $acte->id)->exists()) {
            return GedDocument::query()->where('generated_document_id', $acte->id)->first();
        }
        $type = $this->typeCourt((string) $acte->documentable_type);
        if ($type === null) {
            return null;
        }

        return DB::transaction(function () use ($acte, $type) {
            $annee = (int) now()->year;
            $document = GedDocument::query()->create([
                'uuid' => (string) Str::uuid(),
                'reference' => $this->reference($annee),
                'title' => $acte->business_reference.' · '.$acte->kind,
                'status' => 'signe',
                'confidentiality' => 'interne',
                'owner_user_id' => $acte->generated_by,
                'exercise_year' => $annee,
                'origin' => 'system_generated',
                'generated_document_id' => $acte->id,
                'scan_status' => 'non_requis',
            ]);
            $version = GedVersion::query()->create([
                'ged_document_id' => $document->id,
                'version_number' => $acte->version,
                'disk' => 'local',
                'path' => $acte->path,
                'original_filename' => $acte->filename,
                'mime' => 'application/pdf',
                'extension' => 'pdf',
                'size' => $acte->size,
                'sha256' => $acte->sha256,
                'uploaded_by' => $acte->generated_by,
                'change_reason' => $acte->event,
                'is_current' => true,
                'is_signed' => true,
                'scan_status' => 'non_requis',
            ]);
            $document->forceFill(['current_version_id' => $version->id])->save();
            GedLink::query()->create([
                'ged_document_id' => $document->id,
                'entity_type' => $type,
                'entity_id' => $acte->documentable_id,
                'relation' => 'propre',
                'created_by' => $acte->generated_by,
            ]);

            return $document;
        });
    }

    /**
     * @return array{repris: int, anomalies: list<string>}
     */
    public function reprendrePieces(): array
    {
        $repris = 0;
        $anomalies = [];
        foreach (DB::table('eb_documents')->orderBy('id')->get() as $piece) {
            if (GedDocument::query()->where('source_table', 'eb_documents')->where('source_id', $piece->id)->exists()) {
                continue;
            }
            if ($piece->path === null || ! Storage::disk('local')->exists($piece->path)) {
                $anomalies[] = 'eb_documents#'.$piece->id.' sans fichier exploitable';

                continue;
            }
            $bytes = (string) Storage::disk('local')->get($piece->path);
            $document = GedDocument::query()->create([
                'uuid' => (string) Str::uuid(),
                'reference' => $this->reference((int) ($piece->created_at ? substr((string) $piece->created_at, 0, 4) : now()->year)),
                'title' => $piece->original_name,
                'status' => 'depose',
                'confidentiality' => in_array($piece->confidentialite, config('ged.confidentialites'), true) ? $piece->confidentialite : 'interne',
                'owner_user_id' => $piece->uploaded_by,
                'exercise_year' => (int) now()->year,
                'origin' => 'user_upload',
                'source_table' => 'eb_documents',
                'source_id' => $piece->id,
                'scan_status' => 'non_requis',
            ]);
            $version = GedVersion::query()->create([
                'ged_document_id' => $document->id,
                'version_number' => max(1, (int) $piece->version),
                'disk' => 'local',
                'path' => $piece->path,
                'original_filename' => $piece->original_name,
                'mime' => $piece->mime ?: 'application/octet-stream',
                'extension' => pathinfo($piece->original_name, PATHINFO_EXTENSION) ?: 'bin',
                'size' => strlen($bytes),
                'sha256' => $piece->sha256 ?: hash('sha256', $bytes),
                'uploaded_by' => $piece->uploaded_by,
                'change_reason' => 'Reprise de la pièce existante',
                'is_current' => true,
                'scan_status' => 'non_requis',
            ]);
            $document->forceFill(['current_version_id' => $version->id])->save();
            GedLink::query()->create([
                'ged_document_id' => $document->id,
                'entity_type' => 'expression_besoin',
                'entity_id' => $piece->expression_besoin_id,
                'relation' => 'propre',
                'created_by' => $piece->uploaded_by,
            ]);
            $repris++;
        }

        return ['repris' => $repris, 'anomalies' => $anomalies];
    }

    public function integrite(): int
    {
        $anomalies = 0;
        GedVersion::query()->where('is_current', true)->orderBy('id')->each(function (GedVersion $version) use (&$anomalies) {
            $disk = Storage::disk($version->disk);
            $present = $disk->exists($version->path);
            $empreinte = $present && hash_equals($version->sha256, hash('sha256', (string) $disk->get($version->path)));
            if ($present && $empreinte) {
                return;
            }
            $anomalies++;
            app(AuditService::class)->enregistrer(
                null,
                'ged.integrite',
                'ged_document',
                (string) $version->ged_document_id,
                null,
                ['present' => $present, 'empreinte' => $empreinte, 'version' => $version->version_number],
                'Contrôle d’intégrité',
                'echec',
            );
        });

        return $anomalies;
    }

    /**
     * @return array<string, mixed>
     */
    public function fiche(User $user, GedDocument $document): array
    {
        $this->autoriserVoir($user, $document);
        $document->load(['versions', 'links', 'currentVersion']);
        $doublon = null;
        $hash = $document->currentVersion?->sha256;
        if ($hash !== null) {
            $autre = GedVersion::query()->where('sha256', $hash)->where('ged_document_id', '!=', $document->id)->first();
            if ($autre !== null) {
                $cible = GedDocument::query()->find($autre->ged_document_id);
                if ($cible !== null && $this->voit($user, $cible)) {
                    $doublon = ['id' => $cible->id, 'reference' => $cible->reference];
                }
            }
        }

        return [
            'document' => $this->resume($document),
            'versions' => $document->versions->sortByDesc('version_number')->values()->map(fn (GedVersion $version) => [
                'id' => $version->id,
                'numero' => $version->version_number,
                'nom' => $version->original_filename,
                'mime' => $version->mime,
                'taille' => $version->size,
                'empreinte' => $version->sha256,
                'motif' => $version->change_reason,
                'courante' => $version->is_current,
                'signee' => $version->is_signed,
                'scan' => $version->scan_status,
                'le' => $version->created_at?->toDateTimeString(),
            ])->all(),
            'liens' => $document->links->map(fn (GedLink $lien) => [
                'type' => $lien->entity_type,
                'id' => $lien->entity_id,
                'relation' => $lien->relation,
            ])->all(),
            'historique' => DB::table('audit_events')->where('object_type', 'ged_document')->where('object_id', (string) $document->id)->orderByDesc('id')->limit(30)->get(['action', 'motif', 'result', 'created_at']),
            'doublon_visible' => $doublon,
        ];
    }

    public function assertSiBloquant(string $type, int $id): void
    {
        if (SystemSetting::query()->where('key', 'ged.bloquer_pieces')->value('value') !== '1') {
            return;
        }
        $identifiants = GedLink::query()->where('entity_type', $type)->where('entity_id', $id)->pluck('ged_document_id');
        $documents = GedDocument::query()->whereIn('id', $identifiants)->whereNull('deleted_at')->get();
        $manques = $this->completude($type, $documents)['manques'] ?? [];
        if ($manques !== []) {
            throw ValidationException::withMessages([
                'pieces' => 'Pièces obligatoires manquantes ou non recevables : '.implode(', ', $manques),
            ]);
        }
    }

    public function etiqueter(User $actor, GedDocument $document, string $label): GedDocument
    {
        $this->autoriserVoir($actor, $document);
        $propre = mb_substr(trim($label), 0, 64);
        if ($propre === '') {
            throw ValidationException::withMessages(['tag' => 'Le libellé du tag est vide.']);
        }
        $tagId = DB::table('ged_tags')->where('label', $propre)->value('id');
        if ($tagId === null) {
            $tagId = DB::table('ged_tags')->insertGetId(['label' => $propre, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('ged_document_tag')->insertOrIgnore([
            'ged_document_id' => $document->id,
            'ged_tag_id' => $tagId,
        ]);
        $this->administration->audit($actor, 'ged.etiqueter', 'ged_document', (string) $document->id, null, ['tag' => $propre]);

        return $document;
    }

    /**
     * @param  list<int>  $ids
     */
    public function exporter(User $user, array $ids): string
    {
        $documents = $this->visibles($user)->with('currentVersion')->whereIn('id', $ids)->where('scan_status', '!=', 'quarantaine')->get();
        $chemin = storage_path('app/ged-export-'.Str::uuid().'.zip');
        $archive = new \ZipArchive;
        if ($archive->open($chemin, \ZipArchive::CREATE) !== true) {
            throw ValidationException::withMessages(['export' => 'L’archive n’a pas pu être préparée.']);
        }
        $lignes = ['reference;version;date;statut;empreinte'];
        foreach ($documents as $document) {
            $version = $document->currentVersion;
            if ($version === null || ! Storage::disk($version->disk)->exists($version->path)) {
                continue;
            }
            $archive->addFromString($document->reference.'-v'.$version->version_number.'-'.$version->original_filename, (string) Storage::disk($version->disk)->get($version->path));
            $lignes[] = implode(';', [$document->reference, $version->version_number, (string) $document->created_at, $document->status, $version->sha256]);
        }
        $archive->addFromString('bordereau.csv', implode("\n", $lignes));
        $archive->close();
        $this->administration->audit($user, 'ged.exporter', 'ged_document', null, null, ['ids' => $documents->pluck('id')->all()]);

        return $chemin;
    }

    private function ouvrirTache(GedDocument $document): void
    {
        $fingerprint = 'ged:'.$document->id;
        if (WorkflowTask::query()->where('fingerprint', $fingerprint)->where('status', '!=', 'terminee')->exists()) {
            return;
        }
        $task = WorkflowTask::query()->create([
            'reference' => 'TMP-ged-'.$document->id,
            'fingerprint' => $fingerprint,
            'module' => 'ged',
            'entity_type' => 'ged_document',
            'entity_id' => $document->id,
            'dossier_reference' => $document->reference,
            'subject' => 'Vérifier le document '.$document->reference,
            'action' => 'verifier',
            'step' => 'controleur_financier',
            'assigned_role' => 'controleur_financier',
            'priority' => 'normale',
            'status' => 'a_traiter',
            'amount' => 0,
            'lien' => '/ged/'.$document->id,
            'assigned_at' => now(),
            'due_on' => now()->addDays(5)->toDateString(),
        ]);
        $task->forceFill(['reference' => sprintf('TSK-%d-%06d', now()->year, $task->id)])->save();
    }

    private function cloturerTache(GedDocument $document, string $action): void
    {
        WorkflowTask::query()->where('fingerprint', 'ged:'.$document->id)->where('status', '!=', 'terminee')->update([
            'status' => 'terminee',
            'completed_at' => now(),
            'completion_action' => $action,
        ]);
    }

    private function visibles(User $user)
    {
        $query = GedDocument::query();
        if (! $user->holds('administrateur_habilitations', 'auditeur')) {
            $query->whereNull('deleted_at');
        }
        $large = $user->holds('auditeur', 'administrateur_habilitations', 'administrateur_fonctionnel', 'controleur_financier', 'ordonnateur', 'secretaire_general', 'directeur_budget');
        if (! $large) {
            $perimetre = $user->organizationScopeIds();
            $query->where(function ($inner) use ($user, $perimetre) {
                $inner->where('owner_user_id', $user->id);
                if ($perimetre === null) {
                    $inner->orWhereIn('confidentiality', ['public', 'interne']);
                } else {
                    $inner->orWhereIn('organization_unit_id', $perimetre);
                }
            });
        }
        if (! $user->holds('auditeur', 'administrateur_habilitations', 'controleur_financier')) {
            $query->where(function ($inner) use ($user) {
                $inner->whereNotIn('confidentiality', ['confidentiel', 'tres_confidentiel'])
                    ->orWhere('owner_user_id', $user->id);
            });
        }

        return $query;
    }

    private function voit(User $user, GedDocument $document): bool
    {
        if ($document->deleted_at !== null && ! $user->holds('administrateur_habilitations', 'auditeur')) {
            return false;
        }

        return $this->visibles($user)->whereKey($document->id)->exists();
    }

    private function autoriserVoir(User $user, GedDocument $document): void
    {
        abort_unless($this->voit($user, $document), 403, 'Ce document n’est pas accessible.');
    }

    private function autoriserDepot(User $actor, Model $cible): void
    {
        if (! $this->peutDeposer($actor, $cible)) {
            throw ValidationException::withMessages(['document' => 'Vous ne pouvez pas déposer de pièce sur ce dossier.']);
        }
    }

    private function peutDeposer(User $user, Model $cible): bool
    {
        if ($cible instanceof ExpressionBesoin) {
            return $user->can('upload', $cible);
        }

        return $user->can('view', $cible) && $user->holds('controleur_financier', 'administrateur_habilitations', 'administrateur_fonctionnel', 'initiateur', 'comptable', 'agent_comptable');
    }

    private function cible(string $type, int $id): Model
    {
        $classe = self::CIBLES[$type] ?? null;
        if ($classe === null) {
            throw ValidationException::withMessages(['entity_type' => 'Ce rattachement n’est pas pris en charge.']);
        }
        $modele = $classe::query()->find($id);
        if ($modele === null) {
            throw ValidationException::withMessages(['entity_id' => 'Le dossier cible est introuvable.']);
        }

        return $modele;
    }

    /**
     * @return array{extension: string, mime: string, bytes: string, sha256: string, scan: string}
     */
    private function controlerFichier(UploadedFile $fichier, ?int $typeId): array
    {
        $extension = strtolower($fichier->getClientOriginalExtension());
        $autorises = config('ged.extensions');
        if (! in_array($extension, $autorises, true)) {
            throw ValidationException::withMessages(['fichier' => 'Ce format n’est pas accepté.']);
        }
        $contenu = (string) file_get_contents($fichier->getRealPath());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenu) ?: '';
        $attendus = config('ged.mimes')[$extension] ?? [];
        $mimeConforme = in_array($mime, $attendus, true);
        $tailleKo = (int) ceil(strlen($contenu) / 1024);
        $plafond = (int) config('ged.taille_max_ko');
        if ($typeId !== null) {
            $type = DB::table('document_types')->where('id', $typeId)->where('active', true)->first();
            if ($type === null) {
                throw ValidationException::withMessages(['document_type_id' => 'Ce type documentaire n’existe pas.']);
            }
            $plafond = min($plafond, (int) $type->max_size_kb);
        }
        if ($tailleKo > $plafond) {
            throw ValidationException::withMessages(['fichier' => 'Le fichier dépasse la taille autorisée.']);
        }
        $scan = 'non_requis';
        if (! $mimeConforme || (bool) config('ged.analyse_obligatoire')) {
            $scan = 'quarantaine';
        }

        return [
            'extension' => $extension,
            'mime' => $mime !== '' ? $mime : 'application/octet-stream',
            'bytes' => $contenu,
            'sha256' => hash('sha256', $contenu),
            'scan' => $scan,
        ];
    }

    /**
     * @param  array{extension: string, mime: string, bytes: string, sha256: string, scan: string}  $controle
     */
    private function version(GedDocument $document, int $numero, string $chemin, string $nom, array $controle, User $actor, string $motif): GedVersion
    {
        return GedVersion::query()->create([
            'ged_document_id' => $document->id,
            'version_number' => $numero,
            'disk' => 'local',
            'path' => $chemin,
            'original_filename' => Str::limit($nom, 180, ''),
            'mime' => $controle['mime'],
            'extension' => $controle['extension'],
            'size' => strlen($controle['bytes']),
            'sha256' => $controle['sha256'],
            'uploaded_by' => $actor->id,
            'change_reason' => $motif,
            'is_current' => true,
            'scan_status' => $controle['scan'],
            'extracted_text' => $this->texteExtrait($controle),
        ]);
    }

    /**
     * @param  array{extension: string, bytes: string}  $controle
     */
    private function texteExtrait(array $controle): ?string
    {
        if (! in_array($controle['extension'], ['txt', 'csv'], true)) {
            return null;
        }
        $texte = trim(mb_substr($controle['bytes'], 0, 20000));

        return $texte === '' ? null : $texte;
    }

    public function indexerTextes(): int
    {
        $nombre = 0;
        GedVersion::query()
            ->whereIn('extension', ['txt', 'csv'])
            ->whereNull('extracted_text')
            ->orderBy('id')
            ->each(function (GedVersion $version) use (&$nombre): void {
                $disk = Storage::disk($version->disk ?: 'local');
                if (! $disk->exists($version->path)) {
                    return;
                }
                $bytes = (string) $disk->get($version->path);
                if (! hash_equals((string) $version->sha256, hash('sha256', $bytes))) {
                    return;
                }
                $texte = trim(mb_substr($bytes, 0, 20000));
                if ($texte === '') {
                    return;
                }
                $version->forceFill(['extracted_text' => $texte])->save();
                $nombre++;
            });

        return $nombre;
    }

    private function ecrire(GedDocument $document, int $numero, string $extension, string $bytes): string
    {
        $chemin = 'ged/'.$document->uuid.'/v'.$numero.'-'.Str::lower(Str::random(16)).'.'.$extension;
        Storage::disk('local')->put($chemin, $bytes);

        return $chemin;
    }

    private function reference(int $annee): string
    {
        $dernier = GedDocument::query()->where('reference', 'like', 'DOC-'.$annee.'-%')->lockForUpdate()->orderByDesc('id')->value('reference');
        $suite = 1;
        if (is_string($dernier) && preg_match('/(\d+)$/', $dernier, $trouves)) {
            $suite = ((int) $trouves[1]) + 1;
        }

        return sprintf('DOC-%d-%06d', $annee, $suite);
    }

    /**
     * Ancêtres du dossier courant, puis ses descendants. Les engagements
     * frères d’une même expression ne sont pas repris.
     *
     * @return list<array{type: string, id: int}>
     */
    private function chaine(string $type, int $id): array
    {
        $maillons = array_merge($this->ascendants($type, $id), [['type' => $type, 'id' => $id]], $this->descendants($type, $id));
        $uniques = [];
        foreach ($maillons as $maillon) {
            $uniques[$maillon['type'].':'.$maillon['id']] = $maillon;
        }

        return array_values($uniques);
    }

    /**
     * @return list<array{type: string, id: int}>
     */
    private function ascendants(string $type, int $id): array
    {
        $parent = match ($type) {
            'engagement' => Engagement::query()->whereKey($id)->value('expression_besoin_id'),
            'liquidation' => Liquidation::query()->whereKey($id)->value('engagement_id'),
            'ordonnancement' => Ordonnancement::query()->whereKey($id)->value('liquidation_id'),
            'paiement' => Paiement::query()->whereKey($id)->value('ordonnancement_id'),
            default => null,
        };
        $typeParent = match ($type) {
            'engagement' => 'expression_besoin',
            'liquidation' => 'engagement',
            'ordonnancement' => 'liquidation',
            'paiement' => 'ordonnancement',
            default => null,
        };
        if ($parent === null || $typeParent === null) {
            return [];
        }

        return array_merge($this->ascendants($typeParent, (int) $parent), [['type' => $typeParent, 'id' => (int) $parent]]);
    }

    /**
     * @return list<array{type: string, id: int}>
     */
    private function descendants(string $type, int $id): array
    {
        $enfants = match ($type) {
            'expression_besoin' => Engagement::query()->where('expression_besoin_id', $id)->pluck('id')->map(fn ($enfant) => ['type' => 'engagement', 'id' => (int) $enfant]),
            'engagement' => Liquidation::query()->where('engagement_id', $id)->pluck('id')->map(fn ($enfant) => ['type' => 'liquidation', 'id' => (int) $enfant]),
            'liquidation' => Ordonnancement::query()->where('liquidation_id', $id)->pluck('id')->map(fn ($enfant) => ['type' => 'ordonnancement', 'id' => (int) $enfant]),
            'ordonnancement' => Paiement::query()->where('ordonnancement_id', $id)->pluck('id')->map(fn ($enfant) => ['type' => 'paiement', 'id' => (int) $enfant]),
            default => collect(),
        };
        $maillons = [];
        foreach ($enfants as $enfant) {
            $maillons[] = $enfant;
            $maillons = array_merge($maillons, $this->descendants($enfant['type'], $enfant['id']));
        }

        return $maillons;
    }

    /**
     * @param  Collection<int, GedDocument>  $documents
     * @return array<string, mixed>
     */
    private function completude(string $type, $documents): array
    {
        $exigences = DB::table('document_types')->where('operation', $type)->where('active', true)->where('required', true)->get();
        if ($exigences->isEmpty()) {
            return ['taux' => null, 'message' => 'Aucune exigence applicable.', 'manques' => []];
        }
        $recus = $documents->filter(fn (GedDocument $document) => $document->scan_status !== 'quarantaine' && in_array($document->status, ['depose', 'a_verifier', 'valide', 'signe'], true))->pluck('document_type_id');
        $manques = $exigences->filter(fn ($exigence) => ! $recus->contains($exigence->id))->pluck('label')->values()->all();
        $satisfaites = $exigences->count() - count($manques);

        return [
            'taux' => (int) floor(($satisfaites / $exigences->count()) * 100),
            'message' => $manques === [] ? 'Pièces requises présentes.' : 'Pièces manquantes.',
            'manques' => $manques,
            'bloquant' => SystemSetting::query()->where('key', 'ged.bloquer_pieces')->value('value') === '1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resume(GedDocument $document): array
    {
        return [
            'id' => $document->id,
            'reference' => $document->reference,
            'titre' => $document->title,
            'statut' => $document->status,
            'confidentialite' => $document->confidentiality,
            'origine' => $document->origin,
            'exercice' => $document->exercise_year,
            'scan' => $document->scan_status,
            'gele' => $document->frozen_at !== null,
            'version' => $document->currentVersion?->version_number,
            'taille' => $document->currentVersion?->size,
            'empreinte' => $document->currentVersion?->sha256,
            'le' => $document->created_at?->toDateTimeString(),
        ];
    }

    private function typeCourt(string $classe): ?string
    {
        foreach (self::CIBLES as $court => $modele) {
            if ($classe === $modele || $classe === (new $modele)->getMorphClass()) {
                return $court;
            }
        }

        return null;
    }

    private function assurerCategories(): void
    {
        foreach ([
            'depense' => 'Chaîne de dépense',
            'budget' => 'Budget',
            'acte' => 'Acte officiel',
            'justificatif' => 'Justificatif',
        ] as $code => $label) {
            DB::table('ged_categories')->insertOrIgnore([
                'code' => $code,
                'label' => $label,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
