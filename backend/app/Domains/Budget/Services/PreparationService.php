<?php

namespace App\Domains\Budget\Services;

use App\Domains\Budget\Models\BudgetCampaign;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\BudgetProposal;
use App\Domains\Budget\Models\Exercice;
use App\Models\User;
use App\Shared\Audit\FinancialAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreparationService
{
    /**
     * @return array<string, mixed>
     */
    public function portrait(User $actor): array
    {
        $exercice = Exercice::query()->where('statut', 'preparation')->orderByDesc('annee')->first();
        $propositions = $exercice === null
            ? []
            : BudgetProposal::query()->where('exercice_id', $exercice->id)->with('organizationUnit')->orderBy('code')->get()
                ->map(fn (BudgetProposal $row): array => [
                    'id' => $row->id,
                    'code' => $row->code,
                    'libelle' => $row->label,
                    'structure' => $row->organizationUnit?->sigle,
                    'nature' => $row->nature->value,
                    'montant' => (int) $row->montant_propose,
                    'statut' => $row->statut,
                    'auteur_id' => $row->author_id,
                ])->all();

        $campagne = $exercice === null
            ? null
            : BudgetCampaign::query()->where('exercice_id', $exercice->id)->first(['id', 'code', 'statut']);

        return [
            'exercice' => $exercice === null ? null : [
                'id' => $exercice->id,
                'annee' => $exercice->annee,
                'statut' => $exercice->statut,
            ],
            'campagne' => $campagne === null ? null : [
                'id' => $campagne->id,
                'code' => $campagne->code,
                'statut' => $campagne->statut,
            ],
            'source' => Exercice::query()->whereIn('statut', ['ouvert', 'executoire'])->orderByDesc('annee')->first(['id', 'annee', 'statut']),
            'propositions' => $propositions,
            'droits' => [
                'ouvrir' => $actor->holds('expert_budget', 'directeur_budget') && $exercice === null,
                'editer' => $actor->holds('expert_budget', 'directeur_budget') && $exercice !== null,
                'adopter' => $actor->holds('directeur_budget') && $exercice !== null && $campagne === null,
            ],
        ];
    }

    public function ouvrir(User $actor): Exercice
    {
        if (! $actor->holds('expert_budget', 'directeur_budget')) {
            throw ValidationException::withMessages(['action' => 'La préparation est ouverte par l’expert Budget ou le Directeur du Budget.']);
        }
        if (Exercice::query()->where('statut', 'preparation')->exists()) {
            throw ValidationException::withMessages(['exercice' => 'Une préparation est déjà ouverte.']);
        }
        $source = Exercice::query()->whereIn('statut', ['ouvert', 'executoire'])->orderByDesc('annee')->first();
        $annee = (int) ($source?->annee ?? now()->year) + 1;
        if (Exercice::query()->where('annee', $annee)->exists()) {
            throw ValidationException::withMessages(['exercice' => "L’exercice {$annee} existe déjà."]);
        }

        return DB::transaction(function () use ($actor, $source, $annee): Exercice {
            $exercice = Exercice::query()->create([
                'annee' => $annee,
                'statut' => 'preparation',
                'date_debut' => "{$annee}-01-01",
                'date_fin' => "{$annee}-12-31",
            ]);
            if ($source !== null) {
                foreach ($source->budgetLines()->orderBy('code')->get() as $line) {
                    BudgetProposal::query()->create([
                        'exercice_id' => $exercice->id,
                        'organization_unit_id' => $line->organization_unit_id,
                        'code' => $line->code,
                        'label' => $line->label,
                        'nature' => $line->nature,
                        'montant_propose' => (int) $line->montant_vote,
                        'statut' => 'brouillon',
                        'author_id' => $actor->id,
                    ]);
                }
            }
            FinancialAudit::record($actor, 'budget.preparation.ouverte', 'exercice', (string) $exercice->id, null, ['annee' => $annee]);

            return $exercice;
        });
    }

    public function ajuster(BudgetProposal $proposal, User $actor, int $montant): BudgetProposal
    {
        $this->assertDraftExercice($proposal);
        if (! $actor->holds('expert_budget', 'directeur_budget')) {
            throw ValidationException::withMessages(['action' => 'Seul le budget peut ajuster une proposition.']);
        }
        if ($proposal->statut !== 'brouillon') {
            throw ValidationException::withMessages(['proposition' => 'Seule une proposition en brouillon se modifie.']);
        }
        $proposal->forceFill(['montant_propose' => $montant, 'author_id' => $actor->id])->save();

        return $proposal;
    }

    public function soumettre(BudgetProposal $proposal, User $actor): BudgetProposal
    {
        $this->assertDraftExercice($proposal);
        if (! $actor->holds('expert_budget', 'directeur_budget')) {
            throw ValidationException::withMessages(['action' => 'Seul le budget peut soumettre une proposition.']);
        }
        if ($proposal->statut !== 'brouillon' || (int) $proposal->montant_propose < 1) {
            throw ValidationException::withMessages(['proposition' => 'Soumettez un brouillon d’un montant positif.']);
        }
        $proposal->forceFill(['statut' => 'soumis', 'author_id' => $actor->id])->save();

        return $proposal;
    }

    public function retenir(BudgetProposal $proposal, User $actor, bool $retenir): BudgetProposal
    {
        $this->assertDraftExercice($proposal);
        if (! $actor->holds('directeur_budget') || $actor->id === $proposal->author_id) {
            throw ValidationException::withMessages(['action' => 'La retenue est décidée par un Directeur du Budget qui n’est pas l’auteur.']);
        }
        if ($proposal->statut !== 'soumis' && ! ($retenir === false && $proposal->statut === 'retenu')) {
            throw ValidationException::withMessages(['proposition' => 'Seule une proposition soumise peut être retenue.']);
        }
        $proposal->forceFill(['statut' => $retenir ? 'retenu' : 'ecarte'])->save();

        return $proposal;
    }

    public function adopter(Exercice $exercice, User $actor): Exercice
    {
        if ($exercice->statut !== 'preparation' || ! $actor->holds('directeur_budget')) {
            throw ValidationException::withMessages(['exercice' => 'Seul un exercice en préparation est adopté par le Directeur du Budget.']);
        }
        if (BudgetCampaign::query()->where('exercice_id', $exercice->id)->exists()) {
            throw ValidationException::withMessages([
                'exercice' => 'Cet exercice a une campagne de préparation. L’adoption se fait depuis la version de campagne.',
            ]);
        }
        $retenues = BudgetProposal::query()->where('exercice_id', $exercice->id)->where('statut', 'retenu')->get();
        if ($retenues->isEmpty()) {
            throw ValidationException::withMessages(['exercice' => 'Retenez au moins une proposition avant l’adoption.']);
        }
        if ($retenues->contains(fn (BudgetProposal $row): bool => $row->author_id === $actor->id)) {
            throw ValidationException::withMessages(['exercice' => 'L’auteur d’une proposition retenue ne peut pas adopter le budget.']);
        }

        return DB::transaction(function () use ($exercice, $actor, $retenues): Exercice {
            foreach ($retenues as $proposal) {
                $code = $proposal->code;
                $line = BudgetLine::query()->create([
                    'exercice_id' => $exercice->id,
                    'organization_unit_id' => $proposal->organization_unit_id,
                    'code' => $code,
                    'label' => $proposal->label,
                    'nature' => $proposal->nature,
                    'chapitre' => substr($code, 0, 2),
                    'article' => substr($code, 2, 2),
                    'paragraphe' => substr($code, 4, 2),
                    'nature_depense' => $proposal->nature->value === 'pap' ? 'Investissement' : 'Fonctionnement',
                    'montant_vote' => (int) $proposal->montant_propose,
                    'ajustements' => 0,
                ]);
                $proposal->forceFill(['budget_line_id' => $line->id])->save();
            }
            $exercice->forceFill(['statut' => 'executoire'])->save();
            FinancialAudit::record($actor, 'budget.preparation.adoptee', 'exercice', (string) $exercice->id, null, [
                'annee' => $exercice->annee,
                'lignes' => $retenues->count(),
            ]);

            return $exercice;
        });
    }

    private function assertDraftExercice(BudgetProposal $proposal): void
    {
        if ($proposal->exercice?->statut !== 'preparation') {
            throw ValidationException::withMessages(['proposition' => 'L’exercice n’est plus en préparation.']);
        }
    }
}
