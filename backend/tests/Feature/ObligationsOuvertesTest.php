<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Models\PaiementExecution;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ObligationsOuvertesTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_reste_est_date_sans_etre_qualifie_arriere(): void
    {
        $this->travelTo('2026-10-05');
        [$exercice, $auditeur, $engagement] = $this->chaine();
        $this->ordre($engagement, 'LIQ-RECENT-77', 'ORD-RECENT-77', 800_000, 'signe', '2026-09-20');
        $this->ordre($engagement, 'LIQ-SANS-77', 'ORD-SANS-77', 100_000, 'signe', null);
        $solde = $this->ordre($engagement, 'LIQ-SOLDE-77', 'ORD-SOLDE-77', 200_000, 'transforme_paiement', '2026-01-02');
        $paiement = Paiement::query()->create([
            'reference' => 'PAY-SOLDE-77',
            'ordonnancement_id' => $solde->id,
            'montant' => 200_000,
            'montant_paye' => 200_000,
            'status' => 'cloture',
        ]);
        PaiementExecution::query()->create([
            'paiement_id' => $paiement->id,
            'rang' => 1,
            'montant' => 200_000,
            'reference_reglement' => 'REG-SOLDE',
            'date_valeur' => '2026-01-03',
            'status' => 'executee',
            'idempotence_key' => 'PAY-SOLDE-77',
        ]);
        $this->ordre($engagement, 'LIQ-REJET-77', 'ORD-REJET-77', 999_000, 'rejete', '2026-02-01');

        $this->getJson('/api/v1/chaine/obligations')->assertUnauthorized();

        $this->actingAs($auditeur)
            ->getJson('/api/v1/chaine/obligations?exercice_id='.$exercice->id)
            ->assertOk()
            ->assertJsonPath('data.seuil_enregistre', false)
            ->assertJsonPath('data.qualifiees', 0)
            ->assertJsonPath('data.montant_qualifie', 0)
            ->assertJsonPath('data.nombre', 3)
            ->assertJsonPath('data.reste', 2_600_000)
            ->assertJsonPath('data.tranches.0.code', '0_30')
            ->assertJsonPath('data.tranches.0.reste', 800_000)
            ->assertJsonPath('data.tranches.4.reste', 1_700_000)
            ->assertJsonPath('data.tranches.5.code', 'sans_signature')
            ->assertJsonPath('data.tranches.5.reste', 100_000)
            ->assertJsonPath('data.obligations.0.reference', 'ORD-TRES-77')
            ->assertJsonPath('data.obligations.0.reste', 1_700_000)
            ->assertJsonPath('data.obligations.0.qualifie', false)
            ->assertJsonMissing(['reference' => 'ORD-SOLDE-77'])
            ->assertJsonMissing(['reference' => 'ORD-REJET-77']);
    }

    public function test_un_perimetre_organisationnel_masque_les_restes(): void
    {
        [$exercice] = $this->chaine();
        $autre = OrganizationUnit::query()->create([
            'sigle' => 'AUTRE',
            'name' => 'Autre service',
            'kind' => 'service',
        ]);
        $borne = User::factory()->create(['role' => 'initiateur', 'account_status' => 'actif']);
        DB::table('access_scopes')->insert([
            'user_id' => $borne->id,
            'scope_type' => 'organization_unit',
            'scope_value' => (string) $autre->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($borne)
            ->getJson('/api/v1/chaine/obligations?exercice_id='.$exercice->id)
            ->assertOk()
            ->assertJsonPath('data.nombre', 0)
            ->assertJsonPath('data.reste', 0)
            ->assertJsonPath('data.qualifiees', 0);
    }

    /**
     * @return array{0: Exercice, 1: User, 2: Engagement}
     */
    private function chaine(): array
    {
        $exercice = Exercice::query()->create([
            'annee' => 2026,
            'statut' => 'executoire',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
        ]);
        $unite = OrganizationUnit::query()->create([
            'sigle' => 'DB',
            'name' => 'Direction du Budget',
            'kind' => 'direction',
        ]);
        $auditeur = User::factory()->create(['role' => 'auditeur', 'account_status' => 'actif']);
        $ligne = BudgetLine::query()->create([
            'exercice_id' => $exercice->id,
            'organization_unit_id' => $unite->id,
            'code' => 'LIGNE-OBL',
            'label' => 'Ligne des obligations',
            'nature' => 'hors_pap',
            'montant_vote' => 10_000_000,
        ]);
        $besoin = ExpressionBesoin::query()->create([
            'reference' => 'EB-OBL-77',
            'exercice_id' => $exercice->id,
            'organization_unit_id' => $unite->id,
            'initiator_id' => $auditeur->id,
            'budget_line_id' => $ligne->id,
            'nature' => 'hors_pap',
            'objet' => 'Obligation ouverte',
            'status' => 'transformee_engagement',
            'montant' => 3_200_000,
        ]);
        $engagement = Engagement::query()->create([
            'reference' => 'ENG-OBL-77',
            'expression_besoin_id' => $besoin->id,
            'budget_line_id' => $ligne->id,
            'montant' => 3_200_000,
            'status' => 'transforme_liquidation',
        ]);
        $ordre = $this->ordre($engagement, 'LIQ-TRES-77', 'ORD-TRES-77', 3_200_000, 'signe', '2026-03-15');
        $paiement = Paiement::query()->create([
            'reference' => 'PAY-TRES-77',
            'ordonnancement_id' => $ordre->id,
            'montant' => 3_200_000,
            'montant_paye' => 1_000_000,
            'status' => 'paye_partiel',
        ]);
        PaiementExecution::query()->create([
            'paiement_id' => $paiement->id,
            'rang' => 1,
            'montant' => 1_000_000,
            'reference_reglement' => 'REG-MARS',
            'date_valeur' => '2026-03-20',
            'status' => 'executee',
            'idempotence_key' => 'PAY-OBL-MARS',
        ]);
        PaiementExecution::query()->create([
            'paiement_id' => $paiement->id,
            'rang' => 2,
            'montant' => 500_000,
            'reference_reglement' => 'REG-HORS',
            'date_valeur' => '2025-12-01',
            'status' => 'executee',
            'idempotence_key' => 'PAY-OBL-HORS',
        ]);
        PaiementExecution::query()->create([
            'paiement_id' => $paiement->id,
            'rang' => 3,
            'montant' => 700_000,
            'reference_reglement' => 'REG-REJET',
            'date_valeur' => '2026-03-21',
            'status' => 'rejetee',
            'idempotence_key' => 'PAY-OBL-REJET',
        ]);

        return [$exercice, $auditeur, $engagement];
    }

    private function ordre(Engagement $engagement, string $liquidation, string $reference, int $montant, string $statut, ?string $signeLe): Ordonnancement
    {
        $acte = Liquidation::query()->create([
            'reference' => $liquidation,
            'engagement_id' => $engagement->id,
            'montant' => $montant,
            'status' => 'transformee_ordonnancement',
        ]);

        return Ordonnancement::query()->create([
            'reference' => $reference,
            'liquidation_id' => $acte->id,
            'montant' => $montant,
            'status' => $statut,
            'signed_at' => $signeLe,
        ]);
    }
}
