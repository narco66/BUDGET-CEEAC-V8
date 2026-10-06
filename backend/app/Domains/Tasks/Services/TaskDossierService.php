<?php

namespace App\Domains\Tasks\Services;

use App\Domains\Administration\Models\AuditEvent;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Services\BudgetBalanceService;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Monitoring\Models\CorrectiveAction;
use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Models\PhysicalAchievement;
use App\Domains\Monitoring\Models\SeProof;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\Tasks\Models\WorkflowTask;
use App\Shared\Documents\GeneratedDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Contexte du dossier affiché dans la fiche, sans quitter la tâche.
 */
class TaskDossierService
{
    /** Codes d’audit écrits sans accents, rétablis pour l’affichage. */
    private const ACCENTS = [
        'creer' => 'créer', 'cree' => 'créé', 'creee' => 'créée', 'generer' => 'générer', 'decider' => 'décider',
        'verifie' => 'vérifié', 'valide' => 'validé', 'validee' => 'validée', 'enregistre' => 'enregistré',
        'affecte' => 'affecté', 'prevision' => 'prévision', 'realisation' => 'réalisation', 'cloturer' => 'clôturer',
        'etape' => 'étape', 'retourne' => 'retourné', 'rejete' => 'rejeté', 'encaisse' => 'encaissé', 'echeance' => 'échéance',
    ];

    public function __construct(private readonly BudgetBalanceService $balances) {}

    /**
     * @return array<string, mixed>
     */
    public function present(WorkflowTask $task): array
    {
        $expression = $this->expression($task);
        [$line, $pap] = $this->lineAndPap($task, $expression);
        // Hors chaîne de dépense, la chronologie vient du journal d’audit du dossier.
        $timeline = $expression !== null ? $this->timeline($expression) : $this->auditTimeline($task);
        $last = $timeline === [] ? null : $timeline[array_key_last($timeline)];

        return [
            'banniere' => [
                'etape' => TaskWording::etape($task->step),
                'derniere_action' => $last['action'] ?? null,
                'dernier_acteur' => $last['acteur'] ?? null,
                'action_requise' => TaskWording::action($task->action),
                'acteur_attendu' => $task->assignee?->name ?? TaskWording::role($task->assigned_role),
            ],
            'chronologie' => $timeline,
            'documents' => $expression ? $this->documents($expression) : $this->preuves($task),
            'finances' => $this->finances($line),
            'pap' => $this->pap($pap),
        ];
    }

    private function expression(WorkflowTask $task): ?ExpressionBesoin
    {
        $with = ['exercice', 'budgetLine.enrichment', 'documents', 'events.actor'];

        return match ($task->entity_type) {
            'expression_besoin' => ExpressionBesoin::query()->with($with)->find($task->entity_id),
            'engagement' => Engagement::query()->with(['expressionBesoin' => fn ($query) => $query->with($with)])->find($task->entity_id)?->expressionBesoin,
            'liquidation' => Liquidation::query()->with(['engagement.expressionBesoin' => fn ($query) => $query->with($with)])->find($task->entity_id)?->engagement?->expressionBesoin,
            'ordonnancement' => Ordonnancement::query()->with(['liquidation.engagement.expressionBesoin' => fn ($query) => $query->with($with)])->find($task->entity_id)?->liquidation?->engagement?->expressionBesoin,
            'paiement' => Paiement::query()->with(['ordonnancement.liquidation.engagement.expressionBesoin' => fn ($query) => $query->with($with)])->find($task->entity_id)?->ordonnancement?->liquidation?->engagement?->expressionBesoin,
            default => null,
        };
    }

    /**
     * @return array{0: ?BudgetLine, 1: ?PapEnrichment}
     */
    private function lineAndPap(WorkflowTask $task, ?ExpressionBesoin $expression): array
    {
        if ($expression?->budgetLine !== null) {
            return [$expression->budgetLine, $expression->budgetLine->enrichment];
        }

        $activity = match ($task->entity_type) {
            'indicator_measurement' => IndicatorMeasurement::query()->with('indicator.activity.budgetLine')->find($task->entity_id)?->indicator?->activity,
            'physical_achievement' => PhysicalAchievement::query()->with('activity.budgetLine')->find($task->entity_id)?->activity,
            'corrective_action' => PapEnrichment::query()->with('budgetLine')->find(CorrectiveAction::query()->whereKey($task->entity_id)->value('pap_enrichment_id')),
            default => null,
        };

        return [$activity?->budgetLine, $activity];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function timeline(?ExpressionBesoin $expression): array
    {
        if ($expression === null) {
            return [];
        }

        $rows = [];
        foreach ($expression->events as $event) {
            $rows[] = $this->event('Expression de besoin', $event);
        }

        $engagements = Engagement::query()
            ->with([
                'events.actor',
                'liquidations.events.actor',
                'liquidations.ordonnancement.events.actor',
                'liquidations.ordonnancement.paiement.events.actor',
            ])
            ->where('expression_besoin_id', $expression->id)
            ->get();

        foreach ($engagements as $engagement) {
            foreach ($engagement->events as $event) {
                $rows[] = $this->event('Engagement', $event);
            }
            foreach ($engagement->liquidations as $liquidation) {
                foreach ($liquidation->events as $event) {
                    $rows[] = $this->event('Liquidation', $event);
                }
                $ordre = $liquidation->ordonnancement;
                if ($ordre === null) {
                    continue;
                }
                foreach ($ordre->events as $event) {
                    $rows[] = $this->event('Ordonnancement', $event);
                }
                if ($ordre->paiement !== null) {
                    foreach ($ordre->paiement->events as $event) {
                        $rows[] = $this->event('Paiement', $event);
                    }
                }
            }
        }

        usort($rows, fn (array $left, array $right): int => strcmp((string) $left['le'], (string) $right['le']));

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $circuit, Model $event): array
    {
        return [
            'circuit' => $circuit,
            'action' => $event->getAttribute('action'),
            'de' => $event->getAttribute('from_status'),
            'vers' => $event->getAttribute('to_status'),
            'motif' => $event->getAttribute('motif'),
            'acteur' => $event->relationLoaded('actor') ? ($event->getRelation('actor')?->name ?? 'Système') : 'Système',
            'systeme' => $event->getAttribute('actor_id') === null,
            'le' => $event->getAttribute('created_at')?->toDateTimeString(),
        ];
    }

    /**
     * Événements du journal d’audit propres au dossier (recettes, suivi,
     * préparation budgétaire), dans l’ordre chronologique.
     *
     * @return list<array<string, mixed>>
     */
    private function auditTimeline(WorkflowTask $task): array
    {
        $type = match ($task->entity_type) {
            'budget_dossier' => 'dossier',
            default => (string) $task->entity_type,
        };

        return AuditEvent::query()
            ->with('actor')
            ->where('object_type', $type)
            ->where('object_id', (string) $task->entity_id)
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (AuditEvent $event) => [
                'circuit' => match ($task->module) {
                    'se' => 'Suivi-évaluation',
                    'recette' => 'Recettes',
                    'preparation' => 'Préparation budgétaire',
                    default => Str::ucfirst((string) $task->module),
                },
                'action' => $this->libelleAudit((string) $event->action),
                'de' => null,
                'vers' => null,
                'motif' => $event->motif,
                'acteur' => $event->actor?->name ?? 'Système',
                'systeme' => $event->actor_id === null,
                'le' => ($event->occurred_at ?? $event->created_at)?->toDateTimeString(),
            ])
            ->all();
    }

    /** « se.mesure.valider » → « Mesure · valider » ; « titre_verifie » → « Titre verifie ». */
    private function libelleAudit(string $action): string
    {
        $parts = array_values(array_filter(explode('.', $action), fn (string $part) => ! in_array($part, ['se', 'budget'], true)));

        $texte = str_replace('_', ' ', implode(' · ', $parts));
        $texte = preg_replace_callback('/\b[a-z]+\b/u', fn (array $mot) => self::ACCENTS[$mot[0]] ?? $mot[0], $texte) ?? $texte;

        return Str::ucfirst($texte);
    }

    /**
     * Preuves jointes aux saisies du suivi-évaluation.
     *
     * @return list<array<string, mixed>>
     */
    private function preuves(WorkflowTask $task): array
    {
        $classe = match ($task->entity_type) {
            'indicator_measurement' => IndicatorMeasurement::class,
            'physical_achievement' => PhysicalAchievement::class,
            'corrective_action' => CorrectiveAction::class,
            default => null,
        };
        if ($classe === null) {
            return [];
        }

        return SeProof::query()
            ->where('proofable_type', $classe)
            ->where('proofable_id', $task->entity_id)
            ->orderBy('id')
            ->get()
            ->map(fn (SeProof $proof) => [
                'nom' => basename((string) $proof->path),
                'type' => 'Preuve · '.str_replace('_', ' ', (string) $proof->category),
                'empreinte' => $proof->sha256,
                'code' => null,
                'le' => $proof->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documents(ExpressionBesoin $expression): array
    {
        $rows = [];
        foreach ($expression->documents as $document) {
            $rows[] = [
                'nom' => $document->original_name,
                'type' => $document->type,
                'empreinte' => $document->sha256,
                'code' => null,
                'le' => $document->created_at?->toDateTimeString(),
            ];
        }

        $owners = [ExpressionBesoin::class => [$expression->id]];
        $engagements = Engagement::query()->where('expression_besoin_id', $expression->id)->get(['id']);
        if ($engagements->isNotEmpty()) {
            $owners[Engagement::class] = $engagements->pluck('id')->all();
            $liquidations = Liquidation::query()->whereIn('engagement_id', $owners[Engagement::class])->get(['id']);
            if ($liquidations->isNotEmpty()) {
                $owners[Liquidation::class] = $liquidations->pluck('id')->all();
                $ordres = Ordonnancement::query()->whereIn('liquidation_id', $owners[Liquidation::class])->get(['id']);
                if ($ordres->isNotEmpty()) {
                    $owners[Ordonnancement::class] = $ordres->pluck('id')->all();
                    $paiements = Paiement::query()->whereIn('ordonnancement_id', $owners[Ordonnancement::class])->pluck('id');
                    if ($paiements->isNotEmpty()) {
                        $owners[Paiement::class] = $paiements->all();
                    }
                }
            }
        }

        $generated = GeneratedDocument::query()
            ->where(function ($query) use ($owners) {
                foreach ($owners as $type => $ids) {
                    $query->orWhere(fn ($inner) => $inner->where('documentable_type', $type)->whereIn('documentable_id', $ids));
                }
            })
            ->orderBy('id')
            ->get();

        foreach ($generated as $document) {
            $rows[] = [
                'nom' => $document->filename,
                'type' => $document->kind,
                'empreinte' => $document->sha256,
                'code' => $document->verification_code,
                'le' => $document->created_at?->toDateTimeString(),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, int>|null
     */
    private function finances(?BudgetLine $line): ?array
    {
        if ($line === null) {
            return null;
        }

        $card = $this->balances->forLines([$line->id])[$line->id] ?? null;
        if ($card === null) {
            return null;
        }

        return [
            'revise' => $card['revise'],
            'engage' => $card['engage'],
            'liquide' => $card['liquide'],
            'ordonnance' => $card['ordonnance'],
            'paye' => $card['paye'],
            'disponible' => $card['disponible'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pap(?PapEnrichment $pap): ?array
    {
        if ($pap === null) {
            return null;
        }

        return [
            'activite' => $pap->activite,
            'indicateur' => $pap->indicateur,
            'beneficiaires' => $pap->beneficiaires,
            'unite_responsable' => $pap->unite_responsable,
            'cible' => $pap->cible,
            'debut' => $pap->date_debut?->toDateString(),
            'fin' => $pap->date_fin?->toDateString(),
        ];
    }
}
