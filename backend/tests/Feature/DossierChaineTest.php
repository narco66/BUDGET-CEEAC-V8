<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DossierChaineTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_recherche_retrouve_le_dossier_depuis_le_paiement(): void
    {
        [$besoin, $auditeur] = $this->chaine();

        $this->getJson('/api/v1/chaine/dossier?q=PAY-DOSSIER-77')->assertUnauthorized();

        $this->actingAs($auditeur)
            ->getJson('/api/v1/chaine/dossier?q=a')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($auditeur)
            ->getJson('/api/v1/chaine/dossier?q=xylophonechaine')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'EB/DOSSIER/77')
            ->assertJsonPath('data.0.id', $besoin->id);

        $this->actingAs($auditeur)
            ->getJson('/api/v1/chaine/dossier?q=PAY-DOSSIER-77')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'EB/DOSSIER/77');

        $this->actingAs($auditeur)
            ->getJson('/api/v1/chaine/dossier/'.$besoin->id)
            ->assertOk()
            ->assertJsonPath('data.engagements.0.reference', 'ENG-DOSSIER-77')
            ->assertJsonPath('data.engagements.0.liquidations.0.reference', 'LIQ-DOSSIER-77')
            ->assertJsonPath('data.engagements.0.liquidations.0.ordonnancements.0.reference', 'ORD-DOSSIER-77')
            ->assertJsonPath('data.engagements.0.liquidations.0.ordonnancements.0.paiement.reference', 'PAY-DOSSIER-77')
            ->assertJsonPath('data.engagements.0.liquidations.0.ordonnancements.0.paiement.montant_paye', 3200000);
    }

    public function test_un_perimetre_organisationnel_masque_le_dossier(): void
    {
        [$besoin] = $this->chaine();
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

        $this->actingAs($borne)->getJson('/api/v1/chaine/dossier?q=PAY-DOSSIER-77')->assertOk()->assertJsonPath('data', []);
        $this->actingAs($borne)->getJson('/api/v1/chaine/dossier/'.$besoin->id)->assertNotFound();
    }

    /**
     * @return array{0: ExpressionBesoin, 1: User}
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
            'code' => 'LIGNE-77',
            'label' => 'Ligne de test',
            'nature' => 'hors_pap',
            'montant_vote' => 10_000_000,
        ]);
        $besoin = ExpressionBesoin::query()->create([
            'reference' => 'EB/DOSSIER/77',
            'exercice_id' => $exercice->id,
            'organization_unit_id' => $unite->id,
            'initiator_id' => $auditeur->id,
            'budget_line_id' => $ligne->id,
            'nature' => 'hors_pap',
            'objet' => 'Prestation xylophonechaine',
            'status' => 'transformee_engagement',
            'montant' => 4_000_000,
        ]);
        $engagement = Engagement::query()->create([
            'reference' => 'ENG-DOSSIER-77',
            'expression_besoin_id' => $besoin->id,
            'budget_line_id' => $ligne->id,
            'montant' => 4_000_000,
            'status' => 'transforme_liquidation',
        ]);
        $liquidation = Liquidation::query()->create([
            'reference' => 'LIQ-DOSSIER-77',
            'engagement_id' => $engagement->id,
            'montant' => 4_000_000,
            'montant_net' => 3_200_000,
            'status' => 'transformee_ordonnancement',
        ]);
        $ordre = Ordonnancement::query()->create([
            'reference' => 'ORD-DOSSIER-77',
            'liquidation_id' => $liquidation->id,
            'montant' => 3_200_000,
            'status' => 'transforme_paiement',
        ]);
        Paiement::query()->create([
            'reference' => 'PAY-DOSSIER-77',
            'ordonnancement_id' => $ordre->id,
            'montant' => 3_200_000,
            'montant_paye' => 3_200_000,
            'status' => 'cloture',
            'titulaire' => 'Fournisseur dossier',
        ]);

        return [$besoin, $auditeur];
    }
}
