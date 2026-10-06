<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Revenues\Models\MemberState;
use App\Domains\Revenues\Models\RevenueCategory;
use App\Domains\Revenues\Models\RevenueForecast;
use App\Domains\Revenues\Models\RevenueOrder;
use App\Domains\Revenues\Models\RevenuePaymentMode;
use App\Domains\Revenues\Models\RevenueReceipt;
use App\Domains\Tasks\Models\WorkflowTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecettesTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_cycle_encaisse_partiellement_puis_solde_la_creance(): void
    {
        [$exercice, $categorie, $expert, $expert2, $directeur, $comptable, $agent, $chef] = $this->acteurs();

        $prevision = $this->actingAs($expert)->postJson('/api/v1/recettes/previsions', [
            'exercice_id' => $exercice->id,
            'category_id' => $categorie->id,
            'label' => 'Contributions 2026',
            'montant' => 10000000,
            'source_label' => 'États membres',
        ])->assertCreated()->json('data.code');
        $forecastId = RevenueForecast::query()->where('code', $prevision)->value('id');
        $this->actingAs($expert)->postJson('/api/v1/recettes/previsions/'.$forecastId.'/soumettre')->assertOk();
        $this->actingAs($expert)->postJson('/api/v1/recettes/previsions/'.$forecastId.'/valider')->assertStatus(403);
        $this->actingAs($directeur)->postJson('/api/v1/recettes/previsions/'.$forecastId.'/valider')->assertOk();

        $orderId = $this->actingAs($expert)->postJson('/api/v1/recettes/titres', [
            'exercice_id' => $exercice->id,
            'category_id' => $categorie->id,
            'forecast_id' => $forecastId,
            'debtor_type' => 'partenaire',
            'debtor_label' => 'Banque africaine de développement',
            'montant' => 10000000,
            'echeance' => '2026-06-30',
            'motif' => 'Appel de fonds partenariat',
        ])->assertCreated()->json('data.id');

        $this->actingAs($expert)->postJson('/api/v1/recettes/titres/'.$orderId.'/soumettre')->assertOk();
        $this->assertTrue(WorkflowTask::query()->where('entity_type', 'revenue_order')->where('entity_id', $orderId)->where('status', '!=', 'terminee')->exists());
        $this->actingAs($expert)->postJson('/api/v1/recettes/titres/'.$orderId.'/verifier')->assertStatus(422);
        $this->actingAs($expert2)->postJson('/api/v1/recettes/titres/'.$orderId.'/verifier')->assertOk();
        $this->actingAs($directeur)->postJson('/api/v1/recettes/titres/'.$orderId.'/valider')->assertOk();
        $this->actingAs($comptable)->postJson('/api/v1/recettes/titres/'.$orderId.'/prendre-en-charge')->assertOk();

        $this->actingAs($agent)->postJson('/api/v1/recettes/encaissements', [
            'recu_le' => '2026-03-01',
            'montant' => 4000000,
            'mode' => 'virement',
            'allocations' => [['order_id' => $orderId, 'montant' => 4000000]],
        ])->assertCreated();

        $order = RevenueOrder::query()->findOrFail($orderId);
        $this->assertSame('partiellement_encaisse', $order->statut);
        $this->assertSame(6000000, $order->solde());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $expert->id]);

        $this->actingAs($agent)->postJson('/api/v1/recettes/encaissements', [
            'recu_le' => '2026-03-02',
            'montant' => 7000000,
            'mode' => 'virement',
            'allocations' => [['order_id' => $orderId, 'montant' => 7000000]],
        ])->assertStatus(422);

        $this->actingAs($agent)->postJson('/api/v1/recettes/encaissements', [
            'recu_le' => '2026-03-02',
            'montant' => 6000000,
            'mode' => 'virement',
            'reference_bancaire' => 'VIR-2026-44',
            'banque' => 'BEAC',
            'allocations' => [['order_id' => $orderId, 'montant' => 6000000]],
        ])->assertCreated();

        $order->refresh();
        $this->assertSame('solde', $order->statut);
        $this->assertSame(0, $order->solde());

        $tableau = $this->actingAs($expert)->getJson('/api/v1/recettes/tableau?exercice_id='.$exercice->id)->assertOk()->json('kpi');
        $this->assertSame(10000000, $tableau['encaisse']);
        $this->assertEquals(100, $tableau['taux']);

        $receiptId = RevenueReceipt::query()->value('id');
        $this->actingAs($agent)->postJson('/api/v1/recettes/encaissements/'.$receiptId.'/rapprocher', ['statut' => 'rapproche'])->assertForbidden();
        $this->actingAs($chef)->postJson('/api/v1/recettes/encaissements/'.$receiptId.'/rapprocher', ['statut' => 'rapproche'])->assertOk();

        $this->actingAs($expert)->get('/api/v1/recettes/etats?exercice_id='.$exercice->id)->assertOk();
        $this->actingAs($expert)->get('/api/v1/recettes/titres/'.$orderId.'/document?kind=titre')->assertOk();
    }

    public function test_un_trop_percu_est_trace_sans_solder_au_dela_du_titre(): void
    {
        [, $categorie, $expert, $expert2, $directeur, $comptable, $agent] = $this->acteurs();
        $orderId = $this->titreRecouvrable($categorie, $expert, $expert2, $directeur, $comptable, 5000000);

        $this->actingAs($agent)->postJson('/api/v1/recettes/encaissements', [
            'recu_le' => '2026-04-01',
            'montant' => 6500000,
            'mode' => 'virement',
            'allocations' => [['order_id' => $orderId, 'montant' => 5000000]],
            'trop_percu' => ['kind' => 'avance', 'montant' => 1500000, 'order_id' => $orderId],
        ])->assertCreated();

        $order = RevenueOrder::query()->findOrFail($orderId);
        $this->assertSame(5000000, (int) $order->montant_encaisse);
        $this->assertSame('solde', $order->statut);
        $this->assertDatabaseHas('revenue_adjustments', ['order_id' => $orderId, 'kind' => 'avance', 'montant' => 1500000]);
    }

    public function test_une_prevision_se_consulte_se_modifie_puis_s_annule_selon_son_statut(): void
    {
        [$exercice, $categorie, $expert, , $directeur, , , , $initiateur] = $this->acteurs();

        $this->actingAs($initiateur)->postJson('/api/v1/recettes/previsions', [
            'exercice_id' => $exercice->id,
            'category_id' => $categorie->id,
            'label' => 'Interdit',
            'montant' => 1000,
        ])->assertForbidden();

        $cree = $this->actingAs($expert)->postJson('/api/v1/recettes/previsions', [
            'exercice_id' => $exercice->id,
            'category_id' => $categorie->id,
            'label' => 'Produits de documentation',
            'montant' => 12000000,
            'source_label' => 'Ventes',
        ])->assertCreated()->json('data');

        $this->actingAs($expert)->patchJson('/api/v1/recettes/previsions/'.$cree['id'], [
            'label' => 'Produits de documentation révisés',
            'montant' => 9000000,
        ])->assertOk();
        $this->assertSame(9000000, (int) RevenueForecast::query()->findOrFail($cree['id'])->montant);

        $fiche = $this->actingAs($expert)->getJson('/api/v1/recettes/previsions/'.$cree['id'])->assertOk();
        $fiche->assertJsonPath('data.code', $cree['code']);
        $fiche->assertJsonPath('data.montant', 9000000);
        $this->assertNotEmpty($fiche->json('data.historique'));

        $liste = $this->actingAs($expert)->getJson('/api/v1/recettes/previsions?q=documentation')->assertOk();
        $liste->assertJsonPath('data.0.id', $cree['id']);
        $this->assertSame(0, $liste->json('data.0.realise'));

        $brouillon = $this->actingAs($expert)->postJson('/api/v1/recettes/previsions', [
            'exercice_id' => $exercice->id,
            'category_id' => $categorie->id,
            'label' => 'Brouillon jetable',
            'montant' => 1000,
        ])->assertCreated()->json('data.id');
        $this->actingAs($expert)->deleteJson('/api/v1/recettes/previsions/'.$brouillon)->assertOk();
        $this->assertDatabaseMissing('revenue_forecasts', ['id' => $brouillon]);

        $this->actingAs($expert)->postJson('/api/v1/recettes/previsions/'.$cree['id'].'/soumettre')->assertOk();
        $this->assertTrue(WorkflowTask::query()->where('entity_type', 'revenue_forecast')->where('entity_id', $cree['id'])->where('status', '!=', 'terminee')->exists());
        $this->actingAs($expert)->patchJson('/api/v1/recettes/previsions/'.$cree['id'], ['montant' => 1000])->assertStatus(422);
        $this->actingAs($expert)->deleteJson('/api/v1/recettes/previsions/'.$cree['id'])->assertStatus(422);
        $this->actingAs($directeur)->postJson('/api/v1/recettes/previsions/'.$cree['id'].'/annuler', ['motif' => 'Montant à revoir'])->assertOk();
        $this->assertSame('annule', RevenueForecast::query()->findOrFail($cree['id'])->statut);
        $this->assertFalse(WorkflowTask::query()->where('entity_type', 'revenue_forecast')->where('entity_id', $cree['id'])->where('status', '!=', 'terminee')->exists());
    }

    public function test_une_recette_herite_d_une_prevision_validee_et_reste_verrouillee_apres_prise_en_charge(): void
    {
        [$exercice, $categorie, $expert, $expert2, $directeur, $comptable] = $this->acteurs();
        $forecastId = $this->actingAs($expert)->postJson('/api/v1/recettes/previsions', [
            'exercice_id' => $exercice->id,
            'category_id' => $categorie->id,
            'label' => 'Prévision liée',
            'montant' => 8000000,
        ])->assertCreated()->json('data.id');
        $this->actingAs($expert)->postJson('/api/v1/recettes/titres', [
            'exercice_id' => $exercice->id,
            'category_id' => $categorie->id,
            'forecast_id' => $forecastId,
            'debtor_type' => 'partenaire',
            'debtor_label' => 'Partenaire',
            'montant' => 8000000,
            'echeance' => '2026-06-30',
            'motif' => 'Trop tôt',
        ])->assertStatus(422);

        $this->actingAs($expert)->postJson('/api/v1/recettes/previsions/'.$forecastId.'/soumettre')->assertOk();
        $this->actingAs($directeur)->postJson('/api/v1/recettes/previsions/'.$forecastId.'/valider')->assertOk();
        $this->actingAs($directeur)->postJson('/api/v1/recettes/previsions/'.$forecastId.'/annuler', ['motif' => 'Déjà validée'])->assertStatus(422);
        $this->actingAs($expert)->deleteJson('/api/v1/recettes/previsions/'.$forecastId)->assertStatus(422);

        $orderId = $this->actingAs($expert)->postJson('/api/v1/recettes/titres', [
            'exercice_id' => $exercice->id,
            'category_id' => $categorie->id,
            'forecast_id' => $forecastId,
            'debtor_type' => 'partenaire',
            'debtor_label' => 'Partenaire',
            'montant' => 8000000,
            'echeance' => '2026-06-30',
            'motif' => 'Vente liée',
        ])->assertCreated()->json('data.id');
        $this->assertSame($forecastId, (int) RevenueOrder::query()->findOrFail($orderId)->forecast_id);

        $this->actingAs($expert)->patchJson('/api/v1/recettes/titres/'.$orderId, [
            'montant' => 7500000,
            'motif' => 'Vente liée révisée',
        ])->assertOk();
        $this->assertSame(7500000, (int) RevenueOrder::query()->findOrFail($orderId)->montant);

        $this->actingAs($expert)->postJson('/api/v1/recettes/titres/'.$orderId.'/soumettre')->assertOk();
        $this->actingAs($expert2)->postJson('/api/v1/recettes/titres/'.$orderId.'/verifier')->assertOk();
        $this->actingAs($directeur)->postJson('/api/v1/recettes/titres/'.$orderId.'/valider')->assertOk();
        $this->actingAs($comptable)->postJson('/api/v1/recettes/titres/'.$orderId.'/prendre-en-charge')->assertOk();
        $this->actingAs($expert)->patchJson('/api/v1/recettes/titres/'.$orderId, ['montant' => 1000])->assertStatus(422);

        $mode = $this->actingAs($directeur)->postJson('/api/v1/recettes/modes', [
            'code' => 'mobile',
            'label' => 'Paiement mobile',
        ])->assertCreated()->json('data.id');
        $this->actingAs($directeur)->patchJson('/api/v1/recettes/modes/'.$mode, [
            'code' => 'mobile',
            'label' => 'Paiement mobile',
            'active' => false,
        ])->assertOk();
        $this->assertFalse((bool) RevenuePaymentMode::query()->findOrFail($mode)->active);
    }

    public function test_un_initiateur_ne_cree_pas_de_titre_et_la_contribution_s_appelle(): void
    {
        [$exercice, $categorie, $expert, , , , , , $initiateur, $etat] = $this->acteurs();
        $this->actingAs($initiateur)->postJson('/api/v1/recettes/titres', [
            'exercice_id' => $exercice->id,
            'category_id' => $categorie->id,
            'debtor_type' => 'autre',
            'debtor_label' => 'Divers',
            'montant' => 1000,
            'echeance' => '2026-12-31',
            'motif' => 'Test',
        ])->assertForbidden();

        $contribution = $this->actingAs($expert)->postJson('/api/v1/recettes/contributions', [
            'exercice_id' => $exercice->id,
            'member_state_id' => $etat,
            'quote_part' => 1250,
            'montant_attendu' => 8000000,
            'echeance' => '2026-09-30',
        ])->assertCreated()->json('data.id');
        $this->actingAs($expert)->postJson('/api/v1/recettes/contributions/'.$contribution.'/appeler')->assertCreated();
        $this->actingAs($expert)->getJson('/api/v1/recettes/contributions?exercice_id='.$exercice->id)
            ->assertOk()
            ->assertJsonPath('data.0.statut', 'a_appeler');
    }

    /**
     * @return array{0: Exercice, 1: RevenueCategory, 2: User, 3: User, 4: User, 5: User, 6: User, 7: User, 8: User, 9: int}
     */
    private function acteurs(): array
    {
        $exercice = Exercice::query()->create([
            'annee' => 2026,
            'statut' => 'executoire',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
        ]);

        return [
            $exercice,
            RevenueCategory::query()->where('code', 'CST')->firstOrFail(),
            User::factory()->create(['role' => 'expert_budget']),
            User::factory()->create(['role' => 'expert_budget']),
            User::factory()->create(['role' => 'directeur_budget']),
            User::factory()->create(['role' => 'comptable']),
            User::factory()->create(['role' => 'agent_comptable']),
            User::factory()->create(['role' => 'chef_comptable']),
            User::factory()->create(['role' => 'initiateur']),
            (int) MemberState::query()->where('code', 'GA')->value('id'),
        ];
    }

    private function titreRecouvrable(RevenueCategory $categorie, User $expert, User $expert2, User $directeur, User $comptable, int $montant): int
    {
        $exercice = Exercice::query()->firstOrFail();
        $orderId = $this->actingAs($expert)->postJson('/api/v1/recettes/titres', [
            'exercice_id' => $exercice->id,
            'category_id' => $categorie->id,
            'debtor_type' => 'partenaire',
            'debtor_label' => 'Partenaire test',
            'montant' => $montant,
            'echeance' => '2026-08-31',
            'motif' => 'Titre de test',
        ])->assertCreated()->json('data.id');
        $this->actingAs($expert)->postJson('/api/v1/recettes/titres/'.$orderId.'/soumettre')->assertOk();
        $this->actingAs($expert2)->postJson('/api/v1/recettes/titres/'.$orderId.'/verifier')->assertOk();
        $this->actingAs($directeur)->postJson('/api/v1/recettes/titres/'.$orderId.'/valider')->assertOk();
        $this->actingAs($comptable)->postJson('/api/v1/recettes/titres/'.$orderId.'/prendre-en-charge')->assertOk();

        return (int) $orderId;
    }
}
