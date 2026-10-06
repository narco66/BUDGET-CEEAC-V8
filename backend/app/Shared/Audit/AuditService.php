<?php

namespace App\Shared\Audit;

use App\Domains\Administration\Models\AuditEvent;
use App\Domains\Administration\Models\AuditHold;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuditService
{
    /** @var array<string, class-string<Model>> */
    public const CIBLES = [
        'expression_besoin' => ExpressionBesoin::class,
        'engagement' => Engagement::class,
        'liquidation' => Liquidation::class,
        'ordonnancement' => Ordonnancement::class,
        'paiement' => Paiement::class,
    ];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $context
     */
    public function enregistrer(
        ?User $actor,
        string $action,
        string $objectType,
        ?string $objectId,
        ?array $before = null,
        ?array $after = null,
        ?string $motif = null,
        string $result = 'succes',
        ?string $correlationId = null,
        ?int $causationId = null,
        ?string $reference = null,
        ?int $exerciseYear = null,
        ?int $organizationUnitId = null,
        ?string $sensitivity = null,
        ?array $context = null,
        ?string $sourceTable = null,
        ?int $sourceId = null,
        ?\DateTimeInterface $occurredAt = null,
        bool $contexteHistorique = false,
    ): AuditEvent {
        $avant = $this->nettoyer($before);
        $apres = $this->nettoyer($after);
        $connu = $actor !== null && ! $contexteHistorique;

        return AuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'occurred_at' => $occurredAt ?? now()->utc(),
            'actor_id' => $actor?->id,
            'actor_type' => $actor === null ? 'systeme' : 'utilisateur',
            'actor_name' => $connu ? $actor->name : null,
            'role' => $connu ? $actor->role : null,
            'habilitation' => $connu ? [
                'role_principal' => $actor->role,
                'roles_tenus' => $actor->heldRoleCodes(),
            ] : null,
            'organization_unit_id' => $organizationUnitId ?? ($connu ? $actor?->organization_unit_id : null),
            'exercise_year' => $exerciseYear,
            'module' => strstr($action, '.', true) ?: $action,
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'entity_reference' => $reference,
            'before' => $avant,
            'after' => $apres,
            'changed_fields' => $this->differences($avant, $apres),
            'motif' => $motif,
            'result' => $result,
            'ip' => request()?->ip(),
            'correlation_id' => $correlationId ?? $this->correlation(),
            'causation_id' => $causationId,
            'request_id' => $this->requete(),
            'sensitivity' => $sensitivity ?? $this->sensibilite($action),
            'channel' => app()->runningInConsole() ? 'console' : 'http',
            'user_agent' => Str::limit((string) request()?->userAgent(), 180, ''),
            'source_table' => $sourceTable,
            'source_id' => $sourceId,
            'context' => $this->contexte($connu ? $actor : null, $context),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    public function liste(User $lecteur, array $filtres): array
    {
        $page = max(1, (int) ($filtres['page'] ?? 1));
        $taille = min(500, max(1, (int) ($filtres['per_page'] ?? 25)));
        $requete = $this->visibles($lecteur)
            ->when(($filtres['q'] ?? '') !== '', function ($query) use ($filtres) {
                $terme = '%'.mb_strtolower((string) $filtres['q']).'%';
                $query->where(function ($inner) use ($terme) {
                    $inner->whereRaw('lower(action) like ?', [$terme])
                        ->orWhereRaw('lower(coalesce(actor_name, \'\')) like ?', [$terme])
                        ->orWhereRaw('lower(coalesce(entity_reference, \'\')) like ?', [$terme])
                        ->orWhereRaw('lower(coalesce(motif, \'\')) like ?', [$terme]);
                });
            })
            ->when(($filtres['module'] ?? '') !== '', fn ($query) => $query->where('module', $filtres['module']))
            ->when(($filtres['resultat'] ?? '') !== '', fn ($query) => $query->where('result', $filtres['resultat']))
            ->when(($filtres['action'] ?? '') !== '', fn ($query) => $query->where('action', $filtres['action']))
            ->when(($filtres['correlation'] ?? '') !== '', fn ($query) => $query->where('correlation_id', $filtres['correlation']))
            ->when(($filtres['du'] ?? '') !== '', fn ($query) => $query->whereRaw('coalesce(occurred_at, created_at) >= ?', [$filtres['du']]))
            ->when(($filtres['au'] ?? '') !== '', fn ($query) => $query->whereRaw('coalesce(occurred_at, created_at) <= ?', [$filtres['au']]))
            ->when(($filtres['unite'] ?? '') !== '', fn ($query) => $query->where('organization_unit_id', (int) $filtres['unite']))
            ->orderByDesc('id');
        $total = (clone $requete)->count();
        $lignes = $requete->forPage($page, $taille)->get();

        return [
            'data' => $lignes->map(fn (AuditEvent $event) => $this->resume($lecteur, $event))->all(),
            'meta' => [
                'total' => $total,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $taille)),
                'per_page' => $taille,
                'fuseau' => config('audit.fuseau'),
            ],
            'indicateurs' => [
                'total' => $this->visibles($lecteur)->count(),
                'refus' => $this->visibles($lecteur)->where('result', 'refus')->count(),
                'echecs' => $this->visibles($lecteur)->where('result', 'echec')->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fiche(User $lecteur, AuditEvent $event): array
    {
        abort_unless($this->voit($lecteur, $event), 403, 'Cet événement n’est pas accessible.');
        $masquer = $this->masquer($lecteur, $event);

        return [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'quand' => $this->afficher($event->occurred_at ?? $event->created_at),
            'quand_utc' => ($event->occurred_at ?? $event->created_at)?->utc()->toIso8601String(),
            'enregistre_le' => $this->afficher($event->created_at),
            'fuseau' => config('audit.fuseau'),
            'acteur' => $event->actor_name ?: $event->actor?->name,
            'acteur_type' => $event->actor_type,
            'role' => $event->role,
            'habilitation' => $event->habilitation,
            'module' => $event->module,
            'action' => $event->action,
            'resultat' => $event->result,
            'objet' => $event->object_type,
            'objet_id' => $event->object_id,
            'reference' => $event->entity_reference,
            'motif' => $event->motif,
            'differences' => $masquer ? [] : ($event->changed_fields ?: $this->differences($event->before, $event->after)),
            'avant' => $masquer ? null : $event->before,
            'apres' => $masquer ? null : $event->after,
            'masque' => $masquer,
            'correlation' => $event->correlation_id,
            'cause' => $event->causation_id,
            'requete' => $event->request_id,
            'sensibilite' => $event->sensitivity,
            'contexte_incomplet' => ($event->context['reprise'] ?? false) === true && $event->role === null,
            'lies' => AuditEvent::query()
                ->where('correlation_id', $event->correlation_id)
                ->whereNotNull('correlation_id')
                ->whereKeyNot($event->id)
                ->orderBy('id')
                ->limit(20)
                ->get()
                ->filter(fn (AuditEvent $lie) => $this->voit($lecteur, $lie))
                ->map(fn (AuditEvent $lie) => ['id' => $lie->id, 'action' => $lie->action, 'quand' => $this->afficher($lie->occurred_at ?? $lie->created_at)])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function chronologie(User $lecteur, string $type, int $id): array
    {
        $cible = $this->cible($type, $id);
        abort_unless($lecteur->can('view', $cible), 403);
        $maillons = $this->chaine($type, $id);
        $events = AuditEvent::query()->where(function ($query) use ($maillons) {
            foreach ($maillons as $maillon) {
                $query->orWhere(fn ($inner) => $inner->where('object_type', $maillon['type'])->where('object_id', (string) $maillon['id']));
            }
        })->orderBy('id')->limit(200)->get();

        return [
            'fuseau' => config('audit.fuseau'),
            'maillons' => $maillons,
            'evenements' => $events->filter(fn (AuditEvent $event) => $this->voit($lecteur, $event))->map(fn (AuditEvent $event) => $this->resume($lecteur, $event))->values()->all(),
        ];
    }

    public function rectifier(User $actor, AuditEvent $event, string $motif): AuditEvent
    {
        return $this->enregistrer(
            $actor,
            'audit.rectifier',
            $event->object_type,
            $event->object_id,
            null,
            ['evenement' => $event->id, 'action' => $event->action],
            $motif,
            'succes',
            $event->correlation_id,
            $event->id,
            $event->entity_reference,
        );
    }

    public function geler(User $actor, string $scope, ?string $type, ?string $id, string $motif): AuditHold
    {
        if (AuditHold::query()->where('scope', $scope)->where('object_type', $type)->where('object_id', $id)->whereNull('lifted_at')->exists()) {
            throw ValidationException::withMessages(['gel' => 'Un gel est déjà actif sur ce périmètre.']);
        }
        $hold = AuditHold::query()->create([
            'scope' => $scope,
            'object_type' => $type,
            'object_id' => $id,
            'motif' => $motif,
            'created_by' => $actor->id,
        ]);
        $this->enregistrer($actor, 'audit.gel', 'audit_hold', (string) $hold->id, null, ['scope' => $scope], $motif, 'succes', null, null, null, null, null, 'sensible');

        return $hold;
    }

    public function leverGel(User $actor, AuditHold $hold, string $motif): AuditHold
    {
        if ($hold->lifted_at !== null) {
            throw ValidationException::withMessages(['gel' => 'Ce gel est déjà levé.']);
        }
        $hold->forceFill(['lifted_at' => now(), 'lifted_by' => $actor->id])->save();
        $this->enregistrer($actor, 'audit.lever_gel', 'audit_hold', (string) $hold->id, ['leve' => false], ['leve' => true], $motif, 'succes', null, null, null, null, null, 'sensible');

        return $hold;
    }

    public function purgeAutorisee(): bool
    {
        return false;
    }

    /**
     * @return array{repris: int, ignores: int}
     */
    public function reprendreHistoriques(): array
    {
        $cartes = [
            'eb_events' => ['colonne' => 'expression_besoin_id', 'type' => 'expression_besoin'],
            'eng_events' => ['colonne' => 'engagement_id', 'type' => 'engagement'],
            'liq_events' => ['colonne' => 'liquidation_id', 'type' => 'liquidation'],
            'ord_events' => ['colonne' => 'ordonnancement_id', 'type' => 'ordonnancement'],
            'pay_events' => ['colonne' => 'paiement_id', 'type' => 'paiement'],
        ];
        $repris = 0;
        $ignores = 0;
        foreach ($cartes as $table => $carte) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->orderBy('id')->get() as $ligne) {
                if (AuditEvent::query()->where('source_table', $table)->where('source_id', $ligne->id)->exists()) {
                    $ignores++;

                    continue;
                }
                $acteur = $ligne->actor_id ? User::query()->find($ligne->actor_id) : null;
                $this->enregistrer(
                    $acteur,
                    Str::limit((string) $ligne->action, 64, ''),
                    $carte['type'],
                    (string) $ligne->{$carte['colonne']},
                    isset($ligne->from_status) ? ['statut' => $ligne->from_status] : null,
                    isset($ligne->to_status) ? ['statut' => $ligne->to_status] : null,
                    $ligne->motif ?? null,
                    'succes',
                    null,
                    null,
                    null,
                    null,
                    null,
                    'interne',
                    ['reprise' => true, 'source' => $table, 'contexte' => 'role_et_habilitation_absents_de_la_source'],
                    $table,
                    (int) $ligne->id,
                    $ligne->created_at ? \Illuminate\Support\Carbon::parse((string) $ligne->created_at) : null,
                    true,
                );
                $repris++;
            }
        }

        return ['repris' => $repris, 'ignores' => $ignores];
    }

    private function visibles(User $lecteur)
    {
        $query = AuditEvent::query();
        if (! $this->voitSensible($lecteur)) {
            $query->where(function ($inner) {
                $inner->whereNull('sensitivity')->orWhere('sensitivity', 'interne');
            });
        }

        return $query;
    }

    private function voit(User $lecteur, AuditEvent $event): bool
    {
        if ($this->voitSensible($lecteur)) {
            return true;
        }

        return $event->sensitivity === null || $event->sensitivity === 'interne';
    }

    private function voitSensible(User $lecteur): bool
    {
        return $lecteur->role === 'auditeur' || $lecteur->role === 'administrateur_habilitations';
    }

    private function masquer(User $lecteur, AuditEvent $event): bool
    {
        return ! $this->voitSensible($lecteur) && in_array($event->sensitivity, ['sensible', 'tres_sensible'], true);
    }

    /**
     * @param  array<string, mixed>|null  $valeur
     * @return array<string, mixed>|null
     */
    private function nettoyer(?array $valeur): ?array
    {
        if ($valeur === null) {
            return null;
        }
        $interdits = config('audit.sensibles');
        $propre = [];
        foreach ($valeur as $cle => $item) {
            if (is_string($cle) && in_array(strtolower($cle), $interdits, true)) {
                continue;
            }
            $propre[$cle] = is_array($item) ? $this->nettoyer($item) : $item;
        }

        return $propre;
    }

    /**
     * @param  array<string, mixed>|null  $avant
     * @param  array<string, mixed>|null  $apres
     * @return list<array{champ: string, avant: mixed, apres: mixed}>
     */
    private function differences(?array $avant, ?array $apres): array
    {
        $cles = array_unique(array_merge(array_keys($avant ?? []), array_keys($apres ?? [])));
        $lignes = [];
        foreach ($cles as $cle) {
            $gauche = $avant[$cle] ?? null;
            $droite = $apres[$cle] ?? null;
            if ($gauche != $droite) {
                $lignes[] = ['champ' => (string) $cle, 'avant' => $gauche, 'apres' => $droite];
            }
        }

        return $lignes;
    }

    private function sensibilite(string $action): string
    {
        if (str_starts_with($action, 'auth.') || str_starts_with($action, 'permission.') || str_starts_with($action, 'habilitation.') || str_starts_with($action, 'audit.')) {
            return 'sensible';
        }

        return 'interne';
    }

    private function requete(): ?string
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return null;
        }
        $existant = request()->attributes->get('audit_request_id');
        if (is_string($existant) && $existant !== '') {
            return $existant;
        }
        $entete = request()->headers->get('X-Request-Id');
        $identifiant = is_string($entete) && strlen($entete) <= 64 ? $entete : (string) Str::uuid();
        request()->attributes->set('audit_request_id', $identifiant);

        return $identifiant;
    }

    private function correlation(): string
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return (string) Str::uuid();
        }
        $existant = request()->attributes->get('audit_correlation_id');
        if (is_string($existant) && $existant !== '') {
            return $existant;
        }
        $identifiant = (string) Str::uuid();
        request()->attributes->set('audit_correlation_id', $identifiant);

        return $identifiant;
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>|null
     */
    private function contexte(?User $actor, ?array $context): ?array
    {
        $interim = null;
        if ($actor !== null) {
            $interim = DB::table('substitutions')
                ->where('interim_id', $actor->id)
                ->where('status', 'active')
                ->whereDate('starts_on', '<=', now()->toDateString())
                ->whereDate('ends_on', '>=', now()->toDateString())
                ->first(['titulaire_id', 'fonction']);
        }
        $base = $context ?? [];
        if ($interim !== null) {
            $base['interim'] = ['titulaire_id' => $interim->titulaire_id, 'fonction' => $interim->fonction];
        }

        return $base === [] ? null : $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function resume(User $lecteur, AuditEvent $event): array
    {
        return [
            'id' => $event->id,
            'quand' => $this->afficher($event->occurred_at ?? $event->created_at),
            'acteur' => $event->actor_name ?: $event->actor?->name,
            'role' => $event->role,
            'module' => $event->module,
            'action' => $event->action,
            'objet' => $event->object_type,
            'objet_id' => $event->object_id,
            'reference' => $event->entity_reference,
            'resultat' => $event->result,
            'motif' => $event->motif,
            'sensibilite' => $this->voitSensible($lecteur) ? $event->sensitivity : null,
        ];
    }

    private function afficher(mixed $instant): ?string
    {
        if ($instant === null) {
            return null;
        }
        $date = $instant instanceof \DateTimeInterface ? \Illuminate\Support\Carbon::instance(Carbon::parse($instant)) : \Illuminate\Support\Carbon::parse((string) $instant);

        return $date->timezone((string) config('audit.fuseau'))->format('d/m/Y H:i:s');
    }

    private function cible(string $type, int $id): Model
    {
        $classe = self::CIBLES[$type] ?? null;
        if ($classe === null) {
            throw ValidationException::withMessages(['type' => 'Ce dossier n’a pas de chronologie d’audit.']);
        }
        $modele = $classe::query()->find($id);
        if ($modele === null) {
            throw ValidationException::withMessages(['id' => 'Le dossier est introuvable.']);
        }

        return $modele;
    }

    /**
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
}
