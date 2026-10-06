<?php

namespace App\Domains\Planning\Services;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\PAP\Models\PapTask;
use App\Domains\Planning\Models\GarNode;
use App\Domains\Planning\Models\GarVersion;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Chaîne GAR/RBM versionnée (CDC §5). Une version publiée est immuable.
 * La publication recopie les activités et les tâches sur le PAP déjà lu
 * par le suivi-évaluation, sans créer une seconde chaîne de résultats.
 */
class GarPlanService
{
    /**
     * @return array<string, mixed>
     */
    public function portrait(Exercice $exercice, User $actor): array
    {
        $versions = GarVersion::query()->where('exercice_id', $exercice->id)->orderByDesc('numero')->get();
        $courante = $versions->first(fn (GarVersion $version) => $version->statut === GarVersion::BROUILLON)
            ?? $versions->first(fn (GarVersion $version) => $version->statut === GarVersion::EN_VALIDATION)
            ?? $versions->first(fn (GarVersion $version) => $version->statut === GarVersion::VALIDE)
            ?? $versions->first(fn (GarVersion $version) => $version->statut === GarVersion::PUBLIE);

        return [
            'exercice' => ['id' => $exercice->id, 'annee' => $exercice->annee, 'version_en_vigueur' => $exercice->gar_version_id],
            'version' => $courante ? $this->versionPayload($courante) : null,
            'versions' => $versions->map(fn (GarVersion $version) => $this->versionPayload($version))->values(),
            'noeuds' => $courante ? $this->nodesOf($courante) : [],
            'structures' => OrganizationUnit::query()->orderBy('sigle')->get(['id', 'sigle', 'name'])->map(fn (OrganizationUnit $unit) => [
                'id' => $unit->id,
                'sigle' => $unit->sigle,
                'nom' => $unit->name,
            ])->values(),
            'types' => GarNode::TYPES,
            'droits' => $this->rights($actor, $exercice, $courante, $versions),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function creer(GarVersion $version, User $actor, array $data): GarNode
    {
        $this->assertEditor($actor);
        $this->assertDraft($version);
        $parent = $this->parentOrFail($version, $data['type'], $data['parent_id'] ?? null);
        $this->assertLine($version, $data['budget_line_id'] ?? null);
        $code = $data['code'] ?? $this->nextCode($version, $data['type']);
        $this->assertCode($version, $code, null);

        return DB::transaction(function () use ($version, $actor, $data, $parent, $code): GarNode {
            $node = GarNode::query()->create([
                'gar_version_id' => $version->id,
                'parent_id' => $parent?->id,
                'type' => $data['type'],
                'code' => $code,
                'libelle' => $data['libelle'],
                'position' => $data['position'] ?? ((int) GarNode::query()->where('parent_id', $parent?->id)->where('gar_version_id', $version->id)->max('position') + 1),
                'description' => $data['description'] ?? null,
                'objectifs' => $data['objectifs'] ?? null,
                'resultats_attendus' => $data['resultats_attendus'] ?? null,
                'organization_unit_id' => $data['organization_unit_id'] ?? null,
                'periode' => $data['periode'] ?? null,
                'date_debut' => $data['date_debut'] ?? null,
                'date_fin' => $data['date_fin'] ?? null,
                'indicateur' => $data['indicateur'] ?? null,
                'unite_mesure' => $data['unite_mesure'] ?? null,
                'cible' => $data['cible'] ?? null,
                'enveloppe' => $data['enveloppe'] ?? 0,
                'budget_line_id' => $data['budget_line_id'] ?? null,
                'statut' => 'brouillon',
            ]);
            $node->contributors()->sync($data['contributeurs'] ?? []);
            FinancialAudit::record($actor, 'gar.noeud.cree', 'gar_node', (string) $node->id, null, ['code' => $node->code, 'type' => $node->type]);

            return $node->fresh(['contributors']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function modifier(GarNode $node, User $actor, array $data): GarNode
    {
        $this->assertEditor($actor);
        $version = $node->version;
        $this->assertDraft($version);
        if ($node->statut === GarNode::ARCHIVE) {
            throw ValidationException::withMessages(['noeud' => 'Un nœud archivé ne se modifie pas.']);
        }
        if (array_key_exists('parent_id', $data) || array_key_exists('type', $data)) {
            $this->parentOrFail($version, $data['type'] ?? $node->type, $data['parent_id'] ?? $node->parent_id);
        }
        if (array_key_exists('budget_line_id', $data)) {
            $this->assertLine($version, $data['budget_line_id']);
        }
        if (array_key_exists('code', $data)) {
            $this->assertCode($version, (string) $data['code'], $node->id);
        }

        return DB::transaction(function () use ($node, $actor, $data): GarNode {
            $before = ['libelle' => $node->libelle, 'code' => $node->code];
            $node->fill(collect($data)->only([
                'code', 'libelle', 'position', 'description', 'objectifs', 'resultats_attendus',
                'organization_unit_id', 'periode', 'date_debut', 'date_fin', 'indicateur',
                'unite_mesure', 'cible', 'enveloppe', 'budget_line_id', 'parent_id',
            ])->all());
            $node->save();
            if (array_key_exists('contributeurs', $data)) {
                $node->contributors()->sync($data['contributeurs'] ?? []);
            }
            FinancialAudit::record($actor, 'gar.noeud.modifie', 'gar_node', (string) $node->id, $before, ['libelle' => $node->libelle, 'code' => $node->code], $data['justification'] ?? null);

            return $node->fresh(['contributors']);
        });
    }

    public function archiver(GarNode $node, User $actor, string $motif): GarNode
    {
        $this->assertEditor($actor);
        $this->assertDraft($node->version);
        $actifs = $node->children()->where('statut', '!=', GarNode::ARCHIVE)->count();
        if ($actifs > 0) {
            throw ValidationException::withMessages(['noeud' => 'Archivez d’abord les nœuds enfants.']);
        }
        $node->forceFill(['statut' => GarNode::ARCHIVE])->save();
        FinancialAudit::record($actor, 'gar.noeud.archive', 'gar_node', (string) $node->id, null, ['code' => $node->code], $motif);

        return $node->fresh();
    }

    public function initialiser(Exercice $exercice, User $actor): GarVersion
    {
        $this->assertEditor($actor);
        if (GarVersion::query()->where('exercice_id', $exercice->id)->exists()) {
            throw ValidationException::withMessages(['exercice' => 'Une chaîne GAR existe déjà pour cet exercice.']);
        }

        return DB::transaction(function () use ($exercice, $actor): GarVersion {
            $publiee = $this->importerPap($exercice, $actor);
            $brouillon = $this->copier($publiee, $actor, 'Brouillon ouvert à partir de la chaîne PAP en vigueur.');
            FinancialAudit::record($actor, 'gar.initialise', 'gar_version', (string) $brouillon->id, null, ['publiee' => $publiee->numero]);

            return $brouillon;
        });
    }

    public function soumettre(GarVersion $version, User $actor, string $justification): GarVersion
    {
        $this->assertEditor($actor);
        $this->assertDraft($version);
        $actifs = $version->nodes()->where('statut', '!=', GarNode::ARCHIVE);
        if (! (clone $actifs)->where('type', 'pilier')->exists() || ! (clone $actifs)->where('type', 'activite')->exists()) {
            throw ValidationException::withMessages(['version' => 'La chaîne doit contenir au moins un pilier et une activité.']);
        }
        $version->forceFill([
            'statut' => GarVersion::EN_VALIDATION,
            'author_id' => $actor->id,
            'justification' => $justification,
        ])->save();
        FinancialAudit::record($actor, 'gar.soumis', 'gar_version', (string) $version->id, null, ['numero' => $version->numero], $justification);

        return $version->fresh();
    }

    public function valider(GarVersion $version, User $actor): GarVersion
    {
        $this->assertValidator($actor, $version);
        if ($version->statut !== GarVersion::EN_VALIDATION) {
            throw ValidationException::withMessages(['version' => 'Seule une version soumise peut être validée.']);
        }
        $version->forceFill([
            'statut' => GarVersion::VALIDE,
            'validator_id' => $actor->id,
        ])->save();
        FinancialAudit::record($actor, 'gar.valide', 'gar_version', (string) $version->id, null, ['numero' => $version->numero]);

        return $version->fresh();
    }

    public function publier(GarVersion $version, User $actor, string $effectiveOn): GarVersion
    {
        if (! $actor->holds('directeur_budget') || $actor->id === $version->author_id) {
            throw ValidationException::withMessages(['action' => 'La publication est réservée au Directeur du Budget, distinct de l’auteur.']);
        }
        if ($version->statut !== GarVersion::VALIDE) {
            throw ValidationException::withMessages(['version' => 'Publiez une version validée.']);
        }

        return DB::transaction(function () use ($version, $actor, $effectiveOn): GarVersion {
            GarVersion::query()
                ->where('exercice_id', $version->exercice_id)
                ->where('statut', GarVersion::PUBLIE)
                ->update(['statut' => GarVersion::ARCHIVE]);
            $version->nodes()->where('statut', '!=', GarNode::ARCHIVE)->update(['statut' => 'publie']);
            $version->forceFill([
                'statut' => GarVersion::PUBLIE,
                'effective_on' => $effectiveOn,
                'published_at' => now(),
            ])->save();
            $version->exercice->forceFill(['gar_version_id' => $version->id])->save();
            $this->projeterPap($version);
            FinancialAudit::record($actor, 'gar.publie', 'gar_version', (string) $version->id, null, ['numero' => $version->numero, 'effet' => $effectiveOn]);

            return $version->fresh();
        });
    }

    public function avenant(GarVersion $version, User $actor, string $justification): GarVersion
    {
        $this->assertEditor($actor);
        if ($version->statut !== GarVersion::PUBLIE) {
            throw ValidationException::withMessages(['version' => 'L’avenant part de la version publiée.']);
        }
        if (GarVersion::query()->where('exercice_id', $version->exercice_id)->whereIn('statut', [GarVersion::BROUILLON, GarVersion::EN_VALIDATION, GarVersion::VALIDE])->exists()) {
            throw ValidationException::withMessages(['version' => 'Une version non publiée est déjà ouverte.']);
        }

        return DB::transaction(function () use ($version, $actor, $justification): GarVersion {
            $copie = $this->copier($version, $actor, $justification);
            FinancialAudit::record($actor, 'gar.avenant', 'gar_version', (string) $copie->id, ['source' => $version->numero], ['numero' => $copie->numero], $justification);

            return $copie;
        });
    }

    private function importerPap(Exercice $exercice, User $actor): GarVersion
    {
        $version = GarVersion::query()->create([
            'exercice_id' => $exercice->id,
            'numero' => 1,
            'statut' => GarVersion::PUBLIE,
            'effective_on' => $exercice->date_debut,
            'author_id' => $actor->id,
            'validator_id' => $actor->id,
            'justification' => 'Reprise de la chaîne PAP déjà rattachée aux lignes budgétaires.',
            'published_at' => now(),
        ]);
        $exercice->forceFill(['gar_version_id' => $version->id])->save();

        $enrichments = PapEnrichment::query()
            ->whereHas('budgetLine', fn ($query) => $query->where('exercice_id', $exercice->id))
            ->with(['tasks', 'budgetLine'])
            ->orderBy('id')
            ->get();
        $units = OrganizationUnit::query()->get()->keyBy(fn (OrganizationUnit $unit) => mb_strtolower($unit->name));
        $cache = [];
        foreach ($enrichments as $enrichment) {
            $pilier = $this->assurerNoeud($version, null, 'pilier', $enrichment->pilier ?: 'Non classé', $cache);
            $axe = $this->assurerNoeud($version, $pilier, 'axe', $enrichment->axe ?: 'Axe non précisé', $cache);
            $produit = $this->assurerNoeud($version, $axe, 'produit', $enrichment->produit ?: 'Produit non précisé', $cache);
            $parentActivite = $produit;
            if (filled($enrichment->sous_produit)) {
                $parentActivite = $this->assurerNoeud($version, $produit, 'sous_produit', $enrichment->sous_produit, $cache);
            }
            $activite = $this->assurerNoeud($version, $parentActivite, 'activite', $enrichment->activite ?: 'Activité '.$enrichment->id, $cache, (string) $enrichment->id);
            $unit = $units->get(mb_strtolower((string) $enrichment->unite_responsable));
            $activite->forceFill([
                'pap_enrichment_id' => $enrichment->id,
                'budget_line_id' => $enrichment->budget_line_id,
                'objectifs' => $enrichment->objectif_specifique ?: $enrichment->objectif_general,
                'resultats_attendus' => $enrichment->resultats_attendus,
                'organization_unit_id' => $unit?->id,
                'unite_responsable' => $enrichment->unite_responsable,
                'periode' => $enrichment->periode,
                'date_debut' => $enrichment->date_debut,
                'date_fin' => $enrichment->date_fin,
                'indicateur' => $enrichment->indicateur,
                'unite_mesure' => $enrichment->unite_mesure,
                'cible' => $enrichment->cible,
                'enveloppe' => (int) ($enrichment->budgetLine?->montant_vote ?? 0),
                'description' => $enrichment->observations,
                'statut' => 'publie',
            ])->save();
            foreach ($enrichment->tasks as $task) {
                GarNode::query()->create([
                    'gar_version_id' => $version->id,
                    'parent_id' => $activite->id,
                    'type' => 'tache',
                    'code' => $this->nextCode($version, 'tache'),
                    'libelle' => $task->label,
                    'position' => $task->position,
                    'pap_task_id' => $task->id,
                    'date_debut' => $task->starts_on,
                    'date_fin' => $task->ends_on,
                    'statut' => 'publie',
                ]);
            }
        }

        return $version;
    }

    /**
     * @param  array<string, GarNode>  $cache
     */
    private function assurerNoeud(GarVersion $version, ?GarNode $parent, string $type, string $libelle, array &$cache, string $distinct = ''): GarNode
    {
        $key = ($parent?->id ?? 0).'|'.$type.'|'.mb_strtolower(trim($libelle)).'|'.$distinct;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $node = GarNode::query()->create([
            'gar_version_id' => $version->id,
            'parent_id' => $parent?->id,
            'type' => $type,
            'code' => $this->nextCode($version, $type),
            'libelle' => trim($libelle),
            'position' => (int) GarNode::query()->where('gar_version_id', $version->id)->where('parent_id', $parent?->id)->max('position') + 1,
            'statut' => 'publie',
        ]);
        $cache[$key] = $node;

        return $node;
    }

    private function copier(GarVersion $source, User $actor, string $justification): GarVersion
    {
        $numero = (int) GarVersion::query()->where('exercice_id', $source->exercice_id)->max('numero') + 1;
        $copie = GarVersion::query()->create([
            'exercice_id' => $source->exercice_id,
            'numero' => $numero,
            'statut' => GarVersion::BROUILLON,
            'author_id' => $actor->id,
            'justification' => $justification,
            'source_version_id' => $source->id,
        ]);
        $map = [];
        $nodes = $source->nodes()->with('contributors')->orderBy('id')->get();
        foreach ($nodes as $node) {
            $created = $node->replicate(['id', 'created_at', 'updated_at']);
            $created->gar_version_id = $copie->id;
            $created->parent_id = $node->parent_id ? ($map[$node->parent_id] ?? null) : null;
            $created->statut = $node->statut === GarNode::ARCHIVE ? GarNode::ARCHIVE : 'brouillon';
            $created->save();
            $created->contributors()->sync($node->contributors->pluck('id'));
            $map[$node->id] = $created->id;
        }

        return $copie;
    }

    private function projeterPap(GarVersion $version): void
    {
        $nodes = $version->nodes()->with('parent')->get()->keyBy('id');
        foreach ($nodes->where('type', 'activite')->where('statut', '!=', GarNode::ARCHIVE) as $activite) {
            $chain = $this->ancetres($activite, $nodes);
            $enrichment = $activite->pap_enrichment_id
                ? PapEnrichment::query()->find($activite->pap_enrichment_id)
                : ($activite->budget_line_id ? PapEnrichment::query()->where('budget_line_id', $activite->budget_line_id)->first() : null);
            if ($enrichment === null && $activite->budget_line_id) {
                $enrichment = PapEnrichment::query()->create(['budget_line_id' => $activite->budget_line_id, 'status' => 'a_completer']);
            }
            if ($enrichment === null) {
                continue;
            }
            $enrichment->forceFill([
                'pilier' => $chain['pilier'] ?? $enrichment->pilier,
                'axe' => $chain['axe'] ?? $enrichment->axe,
                'produit' => $chain['produit'] ?? $enrichment->produit,
                'sous_produit' => $chain['sous_produit'] ?? $enrichment->sous_produit,
                'activite' => $activite->libelle,
                'objectif_specifique' => $activite->objectifs,
                'resultats_attendus' => $activite->resultats_attendus,
                'indicateur' => $activite->indicateur,
                'unite_mesure' => $activite->unite_mesure,
                'cible' => $activite->cible,
                'periode' => $activite->periode,
                'date_debut' => $activite->date_debut,
                'date_fin' => $activite->date_fin,
                'unite_responsable' => $activite->organizationUnit?->name ?? $activite->unite_responsable,
                'observations' => $activite->description,
            ])->save();
            if ($activite->pap_enrichment_id === null) {
                $activite->forceFill(['pap_enrichment_id' => $enrichment->id])->save();
            }
        }

        foreach ($nodes->where('type', 'tache')->where('statut', '!=', GarNode::ARCHIVE) as $tache) {
            $parent = $nodes->get($tache->parent_id);
            $enrichmentId = $parent?->pap_enrichment_id;
            if ($enrichmentId === null) {
                continue;
            }
            if ($tache->pap_task_id) {
                PapTask::query()->whereKey($tache->pap_task_id)->update([
                    'label' => $tache->libelle,
                    'position' => $tache->position,
                    'starts_on' => $tache->date_debut,
                    'ends_on' => $tache->date_fin,
                ]);

                continue;
            }
            $task = PapTask::query()->create([
                'pap_enrichment_id' => $enrichmentId,
                'position' => $tache->position,
                'label' => $tache->libelle,
                'proposed' => false,
                'validated' => true,
                'starts_on' => $tache->date_debut,
                'ends_on' => $tache->date_fin,
            ]);
            $tache->forceFill(['pap_task_id' => $task->id])->save();
        }
    }

    /**
     * @param  Collection<int, GarNode>  $nodes
     * @return array<string, string>
     */
    private function ancetres(GarNode $node, Collection $nodes): array
    {
        $chain = [];
        $current = $nodes->get($node->parent_id);
        while ($current instanceof GarNode) {
            $chain[$current->type] = $current->libelle;
            $current = $current->parent_id ? $nodes->get($current->parent_id) : null;
        }

        return $chain;
    }

    private function parentOrFail(GarVersion $version, string $type, mixed $parentId): ?GarNode
    {
        if (! in_array($type, GarNode::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Type de nœud inconnu.']);
        }
        $expected = GarNode::PARENTS[$type];
        if ($expected === null) {
            if ($parentId) {
                throw ValidationException::withMessages(['parent_id' => 'Un pilier n’a pas de parent.']);
            }

            return null;
        }
        $parent = GarNode::query()->where('gar_version_id', $version->id)->find($parentId);
        $allowed = (array) $expected;
        if ($parent === null || ! in_array($parent->type, $allowed, true) || $parent->statut === GarNode::ARCHIVE) {
            throw ValidationException::withMessages(['parent_id' => 'Le parent doit être un '.implode(' ou ', $allowed).' de cette version.']);
        }

        return $parent;
    }

    private function nextCode(GarVersion $version, string $type): string
    {
        $prefix = match ($type) {
            'pilier' => 'PIL',
            'axe' => 'AXE',
            'produit' => 'PRD',
            'sous_produit' => 'SPR',
            'activite' => 'ACT',
            default => 'TAC',
        };
        $max = 0;
        foreach (GarNode::query()->where('gar_version_id', $version->id)->where('code', 'like', $prefix.'-%')->pluck('code') as $code) {
            if (preg_match('/(\d+)$/', (string) $code, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return sprintf('%s-%03d', $prefix, $max + 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nodesOf(GarVersion $version): array
    {
        return $version->nodes()->with(['contributors:id,sigle,name', 'organizationUnit:id,sigle,name'])->get()->map(fn (GarNode $node) => [
            'id' => $node->id,
            'parent_id' => $node->parent_id,
            'type' => $node->type,
            'code' => $node->code,
            'libelle' => $node->libelle,
            'position' => $node->position,
            'description' => $node->description,
            'objectifs' => $node->objectifs,
            'resultats_attendus' => $node->resultats_attendus,
            'organization_unit_id' => $node->organization_unit_id,
            'unite' => $node->organizationUnit?->sigle,
            'unite_responsable' => $node->unite_responsable,
            'periode' => $node->periode,
            'date_debut' => $node->date_debut?->toDateString(),
            'date_fin' => $node->date_fin?->toDateString(),
            'indicateur' => $node->indicateur,
            'unite_mesure' => $node->unite_mesure,
            'cible' => $node->cible,
            'enveloppe' => (int) $node->enveloppe,
            'statut' => $node->statut,
            'ligne_id' => $node->budget_line_id,
            'pap_id' => $node->pap_enrichment_id,
            'contributeurs' => $node->contributors->map(fn (OrganizationUnit $unit) => [
                'id' => $unit->id,
                'sigle' => $unit->sigle,
            ])->values(),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function versionPayload(GarVersion $version): array
    {
        return [
            'id' => $version->id,
            'numero' => $version->numero,
            'statut' => $version->statut,
            'effet' => $version->effective_on?->toDateString(),
            'auteur_id' => $version->author_id,
            'valideur_id' => $version->validator_id,
            'justification' => $version->justification,
            'publie_le' => $version->published_at?->toDateTimeString(),
            'source_id' => $version->source_version_id,
            'modifiable' => $version->isEditable(),
        ];
    }

    /**
     * @param  Collection<int, GarVersion>  $versions
     * @return array<string, bool>
     */
    private function rights(User $actor, Exercice $exercice, ?GarVersion $courante, Collection $versions): array
    {
        $editeur = $actor->holds('expert_budget', 'directeur_budget');

        return [
            'initialiser' => $editeur && $versions->isEmpty(),
            'editer' => $editeur && $courante?->isEditable() === true,
            'soumettre' => $editeur && $courante?->statut === GarVersion::BROUILLON,
            'valider' => $actor->holds('directeur_budget', 'secretaire_general')
                && $courante?->statut === GarVersion::EN_VALIDATION
                && $actor->id !== $courante->author_id,
            'publier' => $actor->holds('directeur_budget')
                && $courante?->statut === GarVersion::VALIDE
                && $actor->id !== $courante->author_id,
            'avenant' => $editeur
                && $versions->contains(fn (GarVersion $version) => $version->statut === GarVersion::PUBLIE)
                && ! $versions->contains(fn (GarVersion $version) => in_array($version->statut, [GarVersion::BROUILLON, GarVersion::EN_VALIDATION, GarVersion::VALIDE], true)),
        ];
    }

    private function assertCode(GarVersion $version, string $code, ?int $ignoreId): void
    {
        $exists = GarNode::query()
            ->where('gar_version_id', $version->id)
            ->where('code', $code)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['code' => 'Ce code existe déjà dans la version.']);
        }
    }

    private function assertLine(GarVersion $version, mixed $lineId): void
    {
        if ($lineId === null || $lineId === '') {
            return;
        }
        $line = BudgetLine::query()->find($lineId);
        if ($line === null || (int) $line->exercice_id !== (int) $version->exercice_id) {
            throw ValidationException::withMessages(['budget_line_id' => 'La ligne doit appartenir à l’exercice de la chaîne.']);
        }
    }

    private function assertEditor(User $actor): void
    {
        if (! $actor->holds('expert_budget', 'directeur_budget')) {
            throw ValidationException::withMessages(['action' => 'La planification est réservée à l’expert Budget ou au Directeur du Budget.']);
        }
    }

    private function assertDraft(GarVersion $version): void
    {
        if (! $version->isEditable()) {
            throw ValidationException::withMessages(['version' => 'Une version publiée ou en validation est immuable. Ouvrez un avenant.']);
        }
    }

    private function assertValidator(User $actor, GarVersion $version): void
    {
        if (! $actor->holds('directeur_budget', 'secretaire_general') || $actor->id === $version->author_id) {
            throw ValidationException::withMessages(['action' => 'La validation est faite par un autre acteur : Directeur du Budget ou Secrétaire général.']);
        }
    }
}
