<?php

namespace Database\Seeders;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Enums\LiquidationStatus;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Models\User;
use Illuminate\Database\Seeder;

class LiquidationSeeder extends Seeder
{
    public function run(): void
    {
        $this->closeFirstLiquidation();
        $this->reshapeVisedTechnosys();

        $this->born('000901', 'DPS', '420156', 4_600_000, 'ENG-2026-000901', 'LIQ/2026/000201', 'TECHNOSYS AFRIQUE SARL', 'Équipements — système de suivi énergétique', [
            'status' => LiquidationStatus::EnPreparation,
            'workflow_step' => 'initiateur',
            'service_fait_reserves' => 'Un poste livré non conforme, écarté du décompte.',
            'montant_accepte' => 4_150_000,
            'invoice_number' => 'FAC-TS-2026-0877',
            'invoice_date' => '2026-10-27',
            'montant_ht' => 4_600_000,
            'taxes' => 0,
            'montant_ttc' => 4_600_000,
            'montant_brut' => 4_150_000,
            'retenue_garantie' => 0,
            'penalite' => 24_900,
            'montant_net' => 4_125_100,
            'montant' => 4_125_100,
            'last_action' => 'Facture enregistrée',
        ]);

        $this->successive();

        $this->born('000902', 'DAJ', '220345', 2_900_000, 'ENG-2026-000902', 'LIQ/2026/000203', 'AUTO-LOC SERVICES', 'Location de véhicules — mission régionale', [
            'status' => LiquidationStatus::Generee,
            'workflow_step' => 'initiateur',
            'last_action' => 'Générée au visa de l’engagement',
            'due_on' => now()->subDay()->toDateString(),
        ]);

        $this->born('000903', 'DSI', '310101', 18_300_000, 'ENG-2026-000903', 'LIQ/2026/000204', 'SECURNET GABON', 'Licences logicielles de sécurité — renouvellement', [
            'status' => LiquidationStatus::Complement,
            'workflow_step' => 'initiateur',
            'service_fait_at' => now()->subDays(4),
            'montant_accepte' => 18_300_000,
            'invoice_number' => 'FAC-SN-2026-014',
            'invoice_date' => '2026-10-20',
            'montant_ht' => 18_300_000,
            'montant_ttc' => 18_300_000,
            'montant_brut' => 18_300_000,
            'montant_net' => 18_300_000,
            'montant' => 18_300_000,
            'return_motif' => 'PV de réception manquant',
            'last_action' => 'Complément demandé : PV de réception',
        ]);

        $this->born('000904', 'DPL', '310456', 3_250_000, 'ENG-2026-000904', 'LIQ/2026/000205', 'PAPETERIE MODERNE', 'Fournitures de bureau — acompte', [
            'status' => LiquidationStatus::TransformeeOrdonnancement,
            'workflow_step' => 'clos',
            'expected_actor_label' => 'ORD-2026-000090',
            'service_fait_at' => now()->subDays(10),
            'montant_accepte' => 3_250_000,
            'invoice_number' => 'FAC-PM-2026-014',
            'invoice_date' => '2026-10-12',
            'montant_ht' => 3_250_000,
            'montant_ttc' => 3_250_000,
            'montant_brut' => 3_250_000,
            'montant_net' => 3_250_000,
            'montant' => 3_250_000,
            'visa_reference' => 'VLQ-2026-000090',
            'vised_at' => now()->subDays(8),
            'ordonnancement_reference' => 'ORD-2026-000090',
            'last_action' => 'Visée VLQ-2026-000090',
            'due_on' => null,
        ]);

        $this->born('000905', 'DPL', '310456', 3_250_000, 'ENG-2026-000905', 'LIQ/2026/000206', 'PAPETERIE MODERNE', 'Fournitures de bureau — T3', [
            'status' => LiquidationStatus::EnPreparation,
            'workflow_step' => 'initiateur',
            'service_fait_at' => now()->subDay(),
            'montant_accepte' => 3_250_000,
            'invoice_number' => 'FAC-PM-2026-014',
            'invoice_date' => '2026-10-12',
            'montant_ht' => 3_250_000,
            'montant_ttc' => 3_250_000,
            'montant_brut' => 3_250_000,
            'montant_net' => 3_250_000,
            'montant' => 3_250_000,
            'doublon' => true,
            'last_action' => 'Doublon de facture détecté',
        ]);

        $this->completeDemoRecords();
    }

    private function completeDemoRecords(): void
    {
        $references = [
            'LIQ-2026-000001',
            'LIQ/2026/000201',
            'LIQ/2026/000202',
            'LIQ/2026/000203',
            'LIQ/2026/000204',
            'LIQ/2026/000205',
            'LIQ/2026/000206',
        ];

        Liquidation::query()
            ->with(['engagement.expressionBesoin', 'ordonnancement'])
            ->whereIn('reference', $references)
            ->get()
            ->each(function (Liquidation $liquidation): void {
                $engagement = $liquidation->engagement;
                $need = $engagement?->expressionBesoin;

                if ($need !== null) {
                    if (! $need->lines()->exists()) {
                        $need->lines()->create([
                            'position' => 1,
                            'designation' => $need->objet,
                            'quantite' => 1,
                            'unite' => 'forfait',
                            'prix_unitaire' => $need->montant,
                            'montant' => $need->montant,
                            'beneficiaire' => $engagement->beneficiary_name,
                        ]);
                    }

                    if (! $need->imputations()->exists()) {
                        $need->imputations()->create([
                            'budget_line_id' => $need->budget_line_id,
                            'montant' => $need->montant,
                        ]);
                    }

                    if (! $need->documents()->exists()) {
                        $need->documents()->create([
                            'uploaded_by' => $need->initiator_id,
                            'type' => 'Note justificative',
                            'original_name' => 'note-justificative.pdf',
                            'confidentialite' => 'interne',
                        ]);
                    }

                    if (! $need->events()->exists()) {
                        $need->events()->create([
                            'actor_id' => $need->initiator_id,
                            'action' => 'creation',
                            'to_status' => $need->status->value,
                        ]);
                    }
                }

                if ($engagement !== null && ! $engagement->events()->exists()) {
                    $engagement->events()->create([
                        'action' => 'generation',
                        'to_status' => $engagement->status->value,
                        'observations' => 'Engagement de démonstration généré depuis '.$engagement->expressionBesoin?->reference.'.',
                    ]);
                }

                if (! $liquidation->events()->exists()) {
                    $liquidation->events()->create([
                        'action' => 'generation',
                        'to_status' => $liquidation->status->value,
                        'observations' => 'Liquidation de démonstration générée depuis '.$engagement?->reference.'.',
                    ]);
                }

                $order = $liquidation->ordonnancement;
                if ($order !== null && ! $order->events()->exists()) {
                    $order->events()->create([
                        'action' => 'generation',
                        'to_status' => $order->status->value,
                        'observations' => 'Ordonnancement de démonstration généré depuis '.$liquidation->reference.'.',
                    ]);
                }
            });
    }

    private function closeFirstLiquidation(): void
    {
        $liquidation = Liquidation::query()->where('reference', 'LIQ-2026-000001')->first();
        if ($liquidation === null || $liquidation->visa_reference !== null) {
            return;
        }

        $liquidation->forceFill([
            'status' => LiquidationStatus::TransformeeOrdonnancement,
            'workflow_step' => 'clos',
            'expected_actor_label' => 'ORD-2026-000001',
            'fournisseur' => $liquidation->engagement?->beneficiary_name,
            'service_fait_at' => now()->subDays(6),
            'montant_accepte' => 228_000_000,
            'invoice_number' => 'FAC-FORUM-2026-001',
            'invoice_date' => '2026-10-18',
            'montant_ht' => 228_000_000,
            'montant_ttc' => 228_000_000,
            'montant_brut' => 228_000_000,
            'montant_net' => 228_000_000,
            'montant' => 228_000_000,
            'visa_reference' => 'VLQ-2026-000001',
            'vised_at' => now()->subDays(4),
            'ordonnancement_reference' => 'ORD-2026-000001',
            'last_action' => 'Visée VLQ-2026-000001',
            'due_on' => null,
        ])->save();

        Ordonnancement::query()->firstOrCreate(
            ['liquidation_id' => $liquidation->id],
            ['reference' => 'ORD-2026-000001', 'montant' => 228_000_000],
        );
    }

    private function reshapeVisedTechnosys(): void
    {
        $liquidation = Liquidation::query()->where('reference', 'LIQ-2026-000002')->first();
        if ($liquidation === null || $liquidation->invoice_number !== null) {
            return;
        }

        $liquidation->forceFill([
            'status' => LiquidationStatus::EnPreparation,
            'workflow_step' => 'initiateur',
            'fournisseur' => 'TECHNOSYS AFRIQUE SARL',
            'service_fait_at' => now()->subDays(2),
            'service_fait_reserves' => 'Un poste livré non conforme, écarté du décompte.',
            'montant_accepte' => 4_150_000,
            'invoice_number' => 'FAC-TS-2026-0877',
            'invoice_date' => '2026-10-27',
            'montant_ht' => 4_600_000,
            'montant_ttc' => 4_600_000,
            'montant_brut' => 4_150_000,
            'penalite' => 24_900,
            'montant_net' => 4_125_100,
            'montant' => 4_125_100,
            'last_action' => 'Facture enregistrée',
        ])->save();
    }

    private function successive(): void
    {
        if (Liquidation::query()->where('reference', 'LIQ/2026/000202')->exists()) {
            return;
        }

        $engagement = Engagement::query()->where('reference', 'ENG-2026-003891')->first();
        if ($engagement === null) {
            return;
        }

        Liquidation::query()->create([
            'reference' => 'LIQ/2026/000202',
            'engagement_id' => $engagement->id,
            'montant' => 111_120_000,
            'status' => LiquidationStatus::EnControle,
            'workflow_step' => 'controleur_financier',
            'expected_actor_label' => 'Contrôleur Financier',
            'fournisseur' => $engagement->beneficiary_name,
            'service_fait_at' => now()->subDays(2),
            'montant_accepte' => 111_120_000,
            'invoice_number' => 'FAC-FORUM-2026-002',
            'invoice_date' => '2026-10-26',
            'montant_ht' => 111_120_000,
            'montant_ttc' => 111_120_000,
            'montant_brut' => 111_120_000,
            'montant_net' => 111_120_000,
            'last_action' => 'Transmise au Contrôleur Financier',
            'due_on' => now()->addDays(2)->toDateString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $liquidation
     */
    private function born(string $seq, string $sigle, string $lineCode, int $amount, string $engagementReference, string $liquidationReference, string $beneficiary, string $objet, array $liquidation): void
    {
        if (Liquidation::query()->where('reference', $liquidationReference)->exists()) {
            return;
        }

        $line = BudgetLine::query()->where('code', $lineCode)->firstOrFail();
        $initiator = User::query()->where('email', strtolower($sigle).'.initiateur@ceeac.int')->firstOrFail();
        $exercice = Exercice::query()->where('annee', 2026)->firstOrFail();

        $eb = ExpressionBesoin::query()->create([
            'reference' => sprintf('EB/2026/%s/%s', $sigle, $seq),
            'exercice_id' => $exercice->id,
            'organization_unit_id' => $line->organization_unit_id,
            'initiator_id' => $initiator->id,
            'budget_line_id' => $line->id,
            'nature' => $line->nature,
            'objet' => $objet,
            'justification' => 'Besoin approuvé, engagé et transmis en liquidation.',
            'status' => EbStatus::Transformee,
            'workflow_step' => 'clos',
            'expected_actor_label' => $engagementReference,
            'montant' => $amount,
            'approved_at' => now(),
        ]);

        $engagement = Engagement::query()->create([
            'reference' => $engagementReference,
            'expression_besoin_id' => $eb->id,
            'budget_line_id' => $line->id,
            'montant' => $amount,
            'status' => EngagementStatus::TransformeLiquidation,
            'workflow_step' => 'clos',
            'expected_actor_label' => $liquidationReference,
            'beneficiary_name' => $beneficiary,
            'visa_reference' => 'VISA-2026-'.$seq,
            'vised_at' => now()->subDays(3),
            'liquidation_reference' => $liquidationReference,
            'last_action' => 'Visé et transmis en liquidation',
            'reserved_at' => now()->subDays(3),
        ]);

        Liquidation::query()->create(array_merge([
            'reference' => $liquidationReference,
            'engagement_id' => $engagement->id,
            'montant' => 0,
            'fournisseur' => $beneficiary,
            'expected_actor_label' => $initiator->name,
            'due_on' => now()->addDays(5)->toDateString(),
            'certified_by' => isset($liquidation['service_fait_at']) ? $initiator->id : null,
        ], $liquidation));

        if (($liquidation['ordonnancement_reference'] ?? null) !== null) {
            Ordonnancement::query()->firstOrCreate(
                ['reference' => $liquidation['ordonnancement_reference']],
                ['liquidation_id' => Liquidation::query()->where('reference', $liquidationReference)->value('id'), 'montant' => $liquidation['montant_net'] ?? $amount],
            );
        }
    }
}
