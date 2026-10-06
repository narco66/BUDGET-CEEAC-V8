<?php

namespace Database\Seeders;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\EngEvent;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EngagementSeeder extends Seeder
{
    public function run(): void
    {
        $exercice = Exercice::query()->where('annee', 2026)->firstOrFail();
        $parent = OrganizationUnit::query()->where('sigle', 'SG')->firstOrFail();
        $budget = OrganizationUnit::query()->firstOrCreate(
            ['sigle' => 'DB'],
            ['parent_id' => $parent->id, 'name' => 'Direction du Budget', 'kind' => 'direction', 'is_technical' => false],
        );

        $this->actor($budget, 'Blaise ESSONO', 'blaise.essono@ceeac.int', 'Expert Budget', 'expert_budget', 'BE');
        $this->actor($budget, 'Chef de Service Budget', 'chef.budget@ceeac.int', 'Chef de Service Budget', 'chef_budget', 'CB');
        $this->actor($budget, 'Directeur du Budget', 'directeur.budget@ceeac.int', 'Directeur du Budget', 'directeur_budget', 'DB');
        $this->actor($budget, 'Contrôleur Financier', 'controleur.financier@ceeac.int', 'Contrôleur Financier', 'controleur_financier', 'CF');

        $this->closeExisting();

        $this->open(
            '000455', 'DMC', '410189', 12_000_000, 'ENG-2026-000455',
            EngagementStatus::EnInstruction, 'expert_budget', 'Expert Budget',
            'CABINET AUDIT-FORM', 'Formation en gestion budgétaire axée sur les résultats',
            'Reçu pour instruction',
        );
        $this->open(
            '000457', 'DATI', '203232', 4_600_000, 'ENG-2026-000457',
            EngagementStatus::EnControle, 'controleur_financier', 'Contrôleur Financier',
            'TECHNOSYS AFRIQUE SARL', 'Mise en place du système de suivi régional des projets énergétiques',
            'Transmis au Contrôleur Financier',
        );
        $this->open(
            '000461', 'DRH', '430234', 40_000_000, 'ENG-2026-000461',
            EngagementStatus::AValider, 'directeur_budget', 'Directeur du Budget',
            'INFOLOG SERVICES', 'Formation en gestion budgétaire axée sur les résultats',
            'Présenté au Directeur du Budget',
        );
        $this->open(
            '000452', 'DAJ', '220345', 6_000_000, 'ENG-2026-000452',
            EngagementStatus::Retourne, 'expert_budget', 'Expert Budget',
            'EDITIONS JURIDIQUES', 'Abonnements aux bases de données juridiques',
            'Retourné : pièce d’identité du bénéficiaire manquante',
        );
        $this->open(
            '000445', 'DMC', '410189', 5_000_000, 'ENG-2026-000445',
            EngagementStatus::Rejete, 'clos', '—',
            'CABINET CONSEIL', 'Étude complémentaire hors programmation',
            'Rejeté : hors programmation annuelle',
        );
    }

    private function closeExisting(): void
    {
        $engagement = Engagement::query()->where('reference', 'ENG-2026-003891')->first();
        if ($engagement === null || $engagement->visa_reference !== null) {
            return;
        }

        $engagement->forceFill([
            'status' => EngagementStatus::TransformeLiquidation,
            'workflow_step' => 'clos',
            'expected_actor_label' => 'LIQ-2026-000001',
            'beneficiary_name' => 'FORUM INTEGRATION SARL',
            'last_action' => 'Visé VISA-2026-000001',
            'visa_reference' => 'VISA-2026-000001',
            'vised_at' => now(),
            'liquidation_reference' => 'LIQ-2026-000001',
            'reserved_at' => now(),
            'due_on' => null,
        ])->save();

        Liquidation::query()->firstOrCreate(
            ['engagement_id' => $engagement->id],
            ['reference' => 'LIQ-2026-000001', 'montant' => $engagement->montant],
        );
    }

    private function open(
        string $seq,
        string $sigle,
        string $lineCode,
        int $amount,
        string $reference,
        EngagementStatus $status,
        string $step,
        string $actor,
        string $beneficiary,
        string $objet,
        string $lastAction,
    ): void {
        if (Engagement::query()->where('reference', $reference)->exists()) {
            return;
        }

        $line = BudgetLine::query()->where('code', $lineCode)->firstOrFail();
        $initiator = User::query()->where('email', strtolower($sigle).'.initiateur@ceeac.int')->first()
            ?? User::query()->where('role', 'initiateur')->firstOrFail();
        $exercice = Exercice::query()->where('annee', 2026)->firstOrFail();

        $eb = ExpressionBesoin::query()->create([
            'reference' => sprintf('EB/2026/%s/%s', $sigle, $seq),
            'exercice_id' => $exercice->id,
            'organization_unit_id' => $line->organization_unit_id,
            'initiator_id' => $initiator->id,
            'budget_line_id' => $line->id,
            'nature' => $line->nature,
            'objet' => $objet,
            'justification' => 'Besoin approuvé et transmis automatiquement en engagement.',
            'status' => EbStatus::Transformee,
            'workflow_step' => 'clos',
            'expected_actor_label' => $reference,
            'montant' => $amount,
            'approved_at' => now(),
        ]);
        $eb->lines()->create([
            'position' => 1,
            'designation' => $objet,
            'quantite' => 1,
            'unite' => 'forfait',
            'prix_unitaire' => $amount,
            'montant' => $amount,
            'beneficiaire' => $beneficiary,
        ]);
        $eb->imputations()->create([
            'budget_line_id' => $line->id,
            'montant' => $amount,
        ]);
        $eb->documents()->create([
            'uploaded_by' => $initiator->id,
            'type' => 'Note justificative',
            'original_name' => 'note-justificative.pdf',
        ]);

        $engagement = Engagement::query()->create([
            'reference' => $reference,
            'expression_besoin_id' => $eb->id,
            'budget_line_id' => $line->id,
            'montant' => $amount,
            'status' => $status,
            'workflow_step' => $step,
            'expected_actor_label' => $actor,
            'beneficiary_name' => $beneficiary,
            'last_action' => $lastAction,
            'due_on' => $status->reservesCredit() && $status !== EngagementStatus::TransformeLiquidation ? now()->addDays(5)->toDateString() : null,
            'reserved_at' => now(),
            'return_motif' => $status === EngagementStatus::Retourne ? 'Pièce d’identité du bénéficiaire manquante' : null,
            'rejection_motif' => $status === EngagementStatus::Rejete ? 'Hors programmation annuelle' : null,
        ]);

        EngEvent::query()->create([
            'engagement_id' => $engagement->id,
            'action' => 'generation',
            'to_status' => $status->value,
            'observations' => 'Hérité de '.$eb->reference,
        ]);
    }

    private function actor(OrganizationUnit $unit, string $name, string $email, string $function, string $role, string $initials): void
    {
        User::query()->firstOrCreate(
            ['email' => $email],
            [
                'organization_unit_id' => $unit->id,
                'name' => $name,
                'function_title' => $function,
                'role' => $role,
                'initials' => $initials,
                'password' => Hash::make('password'),
            ],
        );
    }
}
