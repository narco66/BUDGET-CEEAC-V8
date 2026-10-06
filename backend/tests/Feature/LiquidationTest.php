<?php

namespace Tests\Feature;

use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Models\User;
use Database\Seeders\EngagementSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Database\Seeders\LiquidationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiquidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->seed(EngagementSeeder::class);
        $this->seed(LiquidationSeeder::class);
    }

    public function test_un_engagement_expose_toutes_ses_liquidations(): void
    {
        $expert = User::query()->where('email', 'blaise.essono@ceeac.int')->firstOrFail();
        $engagement = Engagement::query()->where('reference', 'ENG-2026-003891')->firstOrFail();

        $this->actingAs($expert)
            ->getJson("/api/v1/engagements/{$engagement->id}")
            ->assertOk()
            ->assertJsonPath('data.liquidation', 'LIQ-2026-000001')
            ->assertJsonFragment(['reference' => 'LIQ-2026-000001'])
            ->assertJsonFragment(['reference' => 'LIQ/2026/000202']);
    }

    public function test_la_liste_calcule_les_indicateurs(): void
    {
        $initiator = User::query()->where('email', 'dps.initiateur@ceeac.int')->firstOrFail();

        $this->actingAs($initiator)
            ->getJson('/api/v1/liquidations')
            ->assertOk()
            ->assertJsonPath('tableau_de_bord.total', 7)
            ->assertJsonFragment(['reference' => 'LIQ/2026/000201']);
    }

    public function test_le_seeder_complete_les_dossiers_de_demo_sur_toute_la_chaine(): void
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

        $liquidations = Liquidation::query()
            ->with('engagement.expressionBesoin')
            ->whereIn('reference', $references)
            ->get();

        $this->assertCount(7, $liquidations);
        foreach ($liquidations as $liquidation) {
            $need = $liquidation->engagement?->expressionBesoin;

            $this->assertNotNull($need);
            $this->assertGreaterThan(0, $need->lines()->count());
            $this->assertGreaterThan(0, $need->imputations()->count());
            $this->assertGreaterThan(0, $need->documents()->count());
            $this->assertGreaterThan(0, $need->events()->count());
            $this->assertGreaterThan(0, $liquidation->engagement->events()->count());
            $this->assertGreaterThan(0, $liquidation->events()->count());
        }

        $this->assertSame(0, Engagement::query()->whereIn('reference', [
            'ENG-2026-000901', 'ENG-2026-000902', 'ENG-2026-000903',
            'ENG-2026-000904', 'ENG-2026-000905', 'ENG-2026-003891',
        ])->doesntHave('events')->count());
        $this->assertSame(0, Ordonnancement::query()->whereIn('reference', [
            'ORD-2026-000001', 'ORD-2026-000090',
        ])->doesntHave('events')->count());
    }

    public function test_le_service_fait_la_facture_et_le_visa_creent_l_ordonnancement(): void
    {
        $initiator = User::query()->where('email', 'daj.initiateur@ceeac.int')->firstOrFail();
        $controller = User::query()->where('email', 'controleur.financier@ceeac.int')->firstOrFail();
        $liquidation = Liquidation::query()->where('reference', 'LIQ/2026/000203')->firstOrFail();

        $this->actingAs($initiator)
            ->postJson("/api/v1/liquidations/{$liquidation->id}/certifier", [
                'montant_accepte' => 9_000_000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['montant_accepte']);

        $this->actingAs($initiator)
            ->postJson("/api/v1/liquidations/{$liquidation->id}/certifier", [
                'montant_accepte' => 2_900_000,
                'reserves' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.service_fait', 'Certifié');

        $this->actingAs($initiator)
            ->postJson("/api/v1/liquidations/{$liquidation->id}/facture", [
                'numero' => 'FAC-AL-2026-010',
                'date' => '2026-10-28',
                'montant_ht' => 2_900_000,
                'taxes' => 123_456,
                'retenue' => 0,
                'penalite' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.taxes', 123_456)
            ->assertJsonPath('data.montant_net', 2_900_000);

        $this->assertDatabaseHas('liquidations', ['id' => $liquidation->id, 'taxes' => 123_456]);

        $this->actingAs($initiator)
            ->postJson("/api/v1/liquidations/{$liquidation->id}/soumettre")
            ->assertOk()
            ->assertJsonPath('data.statut', 'en_controle');

        $this->actingAs($controller)
            ->postJson("/api/v1/liquidations/{$liquidation->id}/viser", [
                'observations' => 'Service fait et facture conformes.',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', 'transformee_ordonnancement');

        $this->assertDatabaseHas('ordonnancements', [
            'liquidation_id' => $liquidation->id,
        ]);
    }

    public function test_une_facture_exige_un_montant_de_taxes_explicite(): void
    {
        $initiator = User::query()->where('email', 'daj.initiateur@ceeac.int')->firstOrFail();
        $liquidation = Liquidation::query()->where('reference', 'LIQ/2026/000203')->firstOrFail();

        $this->actingAs($initiator)
            ->postJson("/api/v1/liquidations/{$liquidation->id}/certifier", ['montant_accepte' => 2_900_000])
            ->assertOk();

        $this->actingAs($initiator)
            ->postJson("/api/v1/liquidations/{$liquidation->id}/facture", [
                'numero' => 'FAC-AL-2026-010',
                'date' => '2026-10-28',
                'montant_ht' => 2_900_000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['taxes']);
    }

    public function test_un_doublon_de_facture_bloque_la_soumission(): void
    {
        $initiator = User::query()->where('email', 'dpl.initiateur@ceeac.int')->firstOrFail();
        $liquidation = Liquidation::query()->where('reference', 'LIQ/2026/000206')->firstOrFail();

        $this->actingAs($initiator)
            ->postJson("/api/v1/liquidations/{$liquidation->id}/soumettre")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['facture']);
    }

    public function test_la_fiche_expose_le_paiement_reel_et_les_natures_du_referentiel(): void
    {
        $initiator = User::query()->where('email', 'dps.initiateur@ceeac.int')->firstOrFail();
        $liquidation = Liquidation::query()->where('reference', 'LIQ/2026/000201')->firstOrFail();

        $data = $this->actingAs($initiator)
            ->getJson("/api/v1/liquidations/{$liquidation->id}")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('paiement', $data);
        $this->assertContains('Travaux', $data['natures_prestation']);
    }
}
