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

class TresorerieRealiseeTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_mois_additionne_le_signe_et_le_decaisse_sans_plan(): void
    {
        [$exercice, $auditeur] = $this->chaine();

        $this->getJson('/api/v1/chaine/tresorerie')->assertUnauthorized();

        $this->actingAs($auditeur)
            ->getJson('/api/v1/chaine/tresorerie?exercice_id='.$exercice->id)
            ->assertOk()
            ->assertJsonPath('data.plan_enregistre', false)
            ->assertJsonPath('data.mois.2.libelle', 'Mars')
            ->assertJsonPath('data.mois.2.ordonnance', 3200000)
            ->assertJsonPath('data.mois.2.decaisse', 1000000)
            ->assertJsonPath('data.mois.2.ecart', 2200000)
            ->assertJsonPath('data.total_decaisse', 1000000)
            ->assertJsonPath('data.hors_exercice.decaisse', 500000)
            ->assertJsonPath('data.rejets', 700000)
            ->assertJsonPath('data.decaissements.0.montant', 1000000);
    }

    public function test_un_perimetre_organisationnel_masque_les_montants(): void
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
            ->getJson('/api/v1/chaine/tresorerie?exercice_id='.$exercice->id)
            ->assertOk()
            ->assertJsonPath('data.total_ordonnance', 0)
            ->assertJsonPath('data.total_decaisse', 0);
    }

    /**
     * @return array{0: Exercice, 1: User}
     */
    private function chaine(): array
    {
        $annee = (int) now()->year;
        $exercice = Exercice::query()->create([
            'annee' => $annee,
            'statut' => 'executoire',
            'date_debut' => $annee.'-01-01',
            'date_fin' => $annee.'-12-31',
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
            'code' => 'LIGNE-TRES',
            'label' => 'Ligne de trésorerie',
            'nature' => 'hors_pap',
            'montant_vote' => 10_000_000,
        ]);
        $besoin = ExpressionBesoin::query()->create([
            'reference' => 'EB-TRES-77',
            'exercice_id' => $exercice->id,
            'organization_unit_id' => $unite->id,
            'initiator_id' => $auditeur->id,
            'budget_line_id' => $ligne->id,
            'nature' => 'hors_pap',
            'objet' => 'Décaissement de mars',
            'status' => 'transformee_engagement',
            'montant' => 3_200_000,
        ]);
        $engagement = Engagement::query()->create([
            'reference' => 'ENG-TRES-77',
            'expression_besoin_id' => $besoin->id,
            'budget_line_id' => $ligne->id,
            'montant' => 3_200_000,
            'status' => 'transforme_liquidation',
        ]);
        $liquidation = Liquidation::query()->create([
            'reference' => 'LIQ-TRES-77',
            'engagement_id' => $engagement->id,
            'montant' => 3_200_000,
            'status' => 'transformee_ordonnancement',
        ]);
        $ordre = Ordonnancement::query()->create([
            'reference' => 'ORD-TRES-77',
            'liquidation_id' => $liquidation->id,
            'montant' => 3_200_000,
            'status' => 'signe',
            'signed_at' => $annee.'-03-15 10:00:00',
        ]);
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
            'date_valeur' => $annee.'-03-20',
            'status' => 'executee',
            'idempotence_key' => 'PAY-TRES-MARS',
        ]);
        PaiementExecution::query()->create([
            'paiement_id' => $paiement->id,
            'rang' => 2,
            'montant' => 500_000,
            'reference_reglement' => 'REG-HORS',
            'date_valeur' => ($annee - 1).'-12-01',
            'status' => 'executee',
            'idempotence_key' => 'PAY-TRES-HORS',
        ]);
        PaiementExecution::query()->create([
            'paiement_id' => $paiement->id,
            'rang' => 3,
            'montant' => 700_000,
            'reference_reglement' => 'REG-REJET',
            'date_valeur' => $annee.'-03-21',
            'status' => 'rejetee',
            'idempotence_key' => 'PAY-TRES-REJET',
        ]);

        return [$exercice, $auditeur];
    }
}
