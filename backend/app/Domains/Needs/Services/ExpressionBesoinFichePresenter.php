<?php

namespace App\Domains\Needs\Services;

use App\Domains\Budget\Enums\BudgetNature;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Models\EbEvent;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Organization\Models\OrganizationUnit;
use Illuminate\Support\Facades\DB;

/**
 * Données réelles de la fiche EB. Les mentions du modèle d’exemple
 * (instructions, montants de démonstration) n’y figurent pas.
 */
class ExpressionBesoinFichePresenter
{
    public function __construct(private readonly ExpressionBesoinWorkflow $workflow) {}

    /**
     * @return array<string, mixed>
     */
    public function present(ExpressionBesoin $eb): array
    {
        $eb->loadMissing([
            'exercice',
            'organizationUnit.parent',
            'initiator.organizationUnit',
            'budgetLine.enrichment.tasks',
            'lines.task',
            'imputations.budgetLine',
            'documents',
            'events.actor.organizationUnit',
            'engagement',
        ]);

        $line = $eb->budgetLine;
        $enrichment = $line?->enrichment;
        $pap = $eb->nature === BudgetNature::Pap;
        [$disponible, $solde] = $this->soldes($eb);
        $labels = DB::table('document_types')->where('operation', 'expression_besoin')->pluck('label', 'code');

        return [
            'reference' => $eb->reference,
            'exercice' => (string) ($eb->exercice?->annee ?? ''),
            'statut' => $eb->status?->label() ?? '',
            'brouillon' => ! in_array($eb->status, [EbStatus::Approuvee, EbStatus::Transformee], true),
            'structure' => $eb->organizationUnit?->name ?? 'Non renseignée',
            'rattachement' => $this->rattachement($eb->organizationUnit),
            'initiateur' => $eb->initiator?->name ?? 'Non renseigné',
            'fonction' => $eb->initiator?->function_title ?: 'Non renseignée',
            'date' => ($eb->submitted_at ?? $eb->created_at)?->format('d/m/Y') ?? 'Non renseignée',
            'priorite' => $this->priorite($eb->priorite),
            'classification' => $pap ? 'Inscrit au PAP' : 'Hors PAP',
            'imputation' => $line ? $line->code.' — '.$line->label : 'Non renseignée',
            'objet' => $eb->objet ?: 'Non renseigné',
            'justification' => $eb->justification ?: 'Non renseignée',
            'contexte' => $eb->contexte ?: null,
            'lignes' => $eb->lines->map(fn ($row): array => [
                'tache' => $row->task?->label,
                'designation' => $row->designation,
                'quantite' => $this->quantite($row->quantite),
                'unite' => $row->unite ?: 'Non renseignée',
                'prix' => $this->montant((int) $row->prix_unitaire),
                'montant' => $this->montant((int) $row->montant),
            ])->all(),
            'imputations' => $eb->imputations->map(fn ($row): array => [
                'code' => $row->budgetLine?->code ?? '',
                'libelle' => $row->budgetLine?->label ?? '',
                'montant' => $this->montant((int) $row->montant),
            ])->all(),
            'total' => $this->montant((int) $eb->montant),
            'disponible' => $disponible === null ? 'Non renseigné' : $this->montant($disponible).' XAF',
            'solde' => $solde === null ? 'Non renseigné' : $this->montant($solde).' XAF',
            'controle_le' => now()->format('d/m/Y H:i'),
            'pap' => $pap,
            'chaine' => $pap ? $this->chaine($enrichment) : 'Hors PAP : cette demande n’est pas rattachée à une chaîne GAR.',
            'activite' => $pap ? ($enrichment?->activite ?: 'Non renseignée') : 'Sans objet',
            'resultat' => $pap ? ($eb->resultats_attendus ?: $enrichment?->resultats_attendus ?: 'Non renseigné') : 'Sans objet',
            'indicateur' => $pap ? ($enrichment?->indicateur ?: 'Non renseigné') : 'Sans objet',
            'unite' => $pap ? ($enrichment?->unite_responsable ?: $eb->organizationUnit?->name ?: 'Non renseignée') : ($eb->organizationUnit?->name ?: 'Non renseignée'),
            'periode_lieu' => $this->periodeLieu($eb, $enrichment?->periode, $enrichment?->localisation),
            'pieces' => $eb->documents->map(fn ($document): array => [
                'intitule' => (string) ($labels[$document->type] ?? $document->original_name),
                'reference' => $document->original_name,
                'date' => $document->created_at?->format('d/m/Y') ?? 'Non renseignée',
                'identifiant' => (string) $document->id,
            ])->all(),
            'decisions' => $eb->events
                ->filter(fn (EbEvent $event): bool => $event->action !== 'creation')
                ->map(fn (EbEvent $event): array => [
                    'etape' => $this->decision($event->action),
                    'decision' => $this->decision($event->action),
                    'acteur' => $event->actor?->name ?? 'Non renseigné',
                    'fonction' => $event->actor?->function_title ?: 'Non renseignée',
                    'structure' => $event->actor?->organizationUnit?->structureLabel() ?? 'Non renseignée',
                    'date' => $event->created_at?->format('d/m/Y H:i') ?? 'Non renseignée',
                    'observation' => $event->observations ?: ($event->motif ?: '—'),
                    'visa' => 'Décision électronique enregistrée',
                ])->values()->all(),
            'attente' => $this->attente($eb),
            'logo' => $this->logo(),
        ];
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function soldes(ExpressionBesoin $eb): array
    {
        $line = $eb->budgetLine;
        if ($line === null) {
            return [null, null];
        }
        $apres = $line->disponible(null);
        $consomme = 0;
        if (in_array($eb->status?->value, EbStatus::reserving(), true)) {
            $consomme = (int) $eb->imputations->where('budget_line_id', $line->id)->sum('montant');
        }
        $engagement = $eb->engagement;
        if ($engagement !== null && ! in_array($engagement->status, [EngagementStatus::Rejete, EngagementStatus::Annule], true)) {
            $consomme = (int) $engagement->montant - (int) $engagement->montant_degage;
        }

        $avant = $apres + $consomme;

        return [$avant, $avant - (int) $eb->montant];
    }

    private function rattachement(?OrganizationUnit $unit): string
    {
        if ($unit === null) {
            return 'Non renseigné';
        }
        $noms = [];
        $cursor = $unit->parent;
        $guard = 0;
        while ($cursor !== null && $guard < 12) {
            array_unshift($noms, $cursor->sigle.' · '.$cursor->name);
            $cursor = $cursor->parent;
            $guard++;
        }

        return $noms === [] ? 'Non renseigné' : implode(' → ', $noms);
    }

    private function priorite(?string $priorite): string
    {
        return match ($priorite) {
            'basse' => 'Priorité basse',
            'haute' => 'Priorité haute',
            'normale' => 'Priorité moyenne',
            default => 'Priorité non renseignée',
        };
    }

    private function chaine(mixed $enrichment): string
    {
        if ($enrichment === null) {
            return 'Non renseignée';
        }
        $parts = array_filter([
            $enrichment->pilier ? 'Pilier '.$enrichment->pilier : null,
            $enrichment->axe ? 'Axe '.$enrichment->axe : null,
            $enrichment->produit ? 'Produit '.$enrichment->produit : null,
            $enrichment->sous_produit ? 'Sous-produit '.$enrichment->sous_produit : null,
            $enrichment->activite ? 'Activité '.$enrichment->activite : null,
            $enrichment->tasks?->pluck('label')->filter()->implode(' ; ') ?: null,
        ]);

        return $parts === [] ? 'Non renseignée' : implode(' → ', $parts);
    }

    private function periodeLieu(ExpressionBesoin $eb, ?string $periode, ?string $lieu): string
    {
        $fromLines = $eb->lines->map(function ($row): ?string {
            $bits = array_filter([$row->periode, $row->lieu]);

            return $bits === [] ? null : implode(' · ', $bits);
        })->filter()->unique()->implode(' ; ');
        $bits = array_filter([$periode, $lieu, $fromLines]);

        return $bits === [] ? 'Non renseigné' : implode(' · ', $bits);
    }

    /**
     * @return list<array{etape: string, libelle: string}>
     */
    private function attente(ExpressionBesoin $eb): array
    {
        if (! in_array($eb->status?->value, EbStatus::awaiting(), true) && $eb->status !== EbStatus::Brouillon && $eb->status !== EbStatus::Retournee) {
            return [];
        }
        $steps = $this->workflow->steps($eb);
        $current = array_search($eb->workflow_step, $steps, true);
        if ($current === false) {
            return [];
        }

        return collect($steps)->slice($eb->status === EbStatus::Brouillon || $eb->status === EbStatus::Retournee ? 0 : (int) $current)->map(fn (string $step): array => [
            'etape' => $step,
            'libelle' => $this->workflow->stepLabel($step, $eb->organizationUnit).' — en attente',
        ])->values()->all();
    }

    private function decision(string $action): string
    {
        return match ($action) {
            'soumission' => 'Soumission',
            'validation' => 'Validation',
            'approbation' => 'Approbation',
            'retour' => 'Retour pour correction',
            'rejet' => 'Rejet',
            'annulation' => 'Annulation',
            'transformation' => 'Transformation en engagement',
            'duplication' => 'Duplication',
            default => $action,
        };
    }

    private function montant(int $amount): string
    {
        return number_format($amount, 0, ',', ' ');
    }

    private function quantite(mixed $quantity): string
    {
        $text = rtrim(rtrim(number_format((float) $quantity, 2, ',', ' '), '0'), ',');

        return $text === '' ? '0' : $text;
    }

    private function logo(): string
    {
        $path = resource_path('images/logo-ceeac.png');
        if (! is_file($path)) {
            return '';
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
    }
}
