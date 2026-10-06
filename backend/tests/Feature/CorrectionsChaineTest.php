<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Models\User;
use Database\Seeders\EngagementSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Database\Seeders\LiquidationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CorrectionsChaineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->seed(EngagementSeeder::class);
        $this->seed(LiquidationSeeder::class);
    }

    public function test_un_avoir_ne_reecrit_pas_la_liquidation_visee(): void
    {
        $liquidation = Liquidation::query()->where('status', 'transformee_ordonnancement')->where('montant_net', '>', 0)->firstOrFail();
        $net = (int) $liquidation->montant_net;
        $controleur = User::query()->where('role', 'controleur_financier')->firstOrFail();
        $initiateur = User::query()->where('role', 'initiateur')->firstOrFail();

        $this->actingAs($initiateur)
            ->postJson("/api/v1/liquidations/{$liquidation->id}/rectifier", [
                'kind' => 'avoir',
                'montant' => 1000,
                'motif' => 'Erreur de facture',
            ])->assertForbidden();

        $this->actingAs($controleur)
            ->postJson("/api/v1/liquidations/{$liquidation->id}/rectifier", [
                'kind' => 'avoir',
                'montant' => 1000,
                'motif' => 'Erreur de facture',
            ])->assertOk()
            ->assertJsonPath('data.montant_net', $net);

        $this->assertSame($net, (int) $liquidation->fresh()->montant_net);
        $this->assertDatabaseHas('liquidation_rectifications', [
            'liquidation_id' => $liquidation->id,
            'kind' => 'avoir',
            'amount' => 1000,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'liquidation.rectification']);
        $this->assertDatabaseHas('integration_outbox', ['event' => 'liquidation.rectifiee']);
    }

    public function test_un_avoir_aligne_un_ordonnancement_non_signe(): void
    {
        $liquidation = Liquidation::query()->where('status', 'transformee_ordonnancement')->where('montant_net', '>', 2000)->firstOrFail();
        $ordre = $liquidation->ordonnancement;
        $this->assertNotNull($ordre);
        $net = (int) $liquidation->montant_net;
        $ordre->forceFill(['status' => 'a_signer', 'montant' => $net])->save();
        $paiement = $ordre->paiement;
        if ($paiement !== null) {
            $paiement->forceFill(['status' => 'genere', 'montant' => $net, 'montant_paye' => 0])->save();
        }
        $controleur = User::query()->where('role', 'controleur_financier')->firstOrFail();

        $this->actingAs($controleur)
            ->postJson("/api/v1/liquidations/{$liquidation->id}/rectifier", [
                'kind' => 'avoir',
                'montant' => 1000,
                'motif' => 'Facture trop élevée',
            ])->assertOk()
            ->assertJsonPath('data.montant_net', $net);

        $this->assertSame($net - 1000, (int) $ordre->fresh()->montant);
        if ($paiement !== null) {
            $this->assertSame($net - 1000, (int) $paiement->fresh()->montant);
        }
    }

    public function test_un_avoir_ne_reecrit_pas_un_ordonnancement_signe(): void
    {
        $liquidation = Liquidation::query()->where('status', 'transformee_ordonnancement')->where('montant_net', '>', 2000)->firstOrFail();
        $ordre = $liquidation->ordonnancement;
        $this->assertNotNull($ordre);
        $montant = (int) $ordre->montant;
        $ordre->forceFill(['status' => 'signe'])->save();
        $paiement = $ordre->paiement;
        if ($paiement !== null) {
            $paiement->forceFill(['status' => 'cloture', 'montant_paye' => (int) $paiement->montant])->save();
            $montantPaye = (int) $paiement->montant;
        }
        $controleur = User::query()->where('role', 'controleur_financier')->firstOrFail();

        $this->actingAs($controleur)
            ->postJson("/api/v1/liquidations/{$liquidation->id}/rectifier", [
                'kind' => 'avoir',
                'montant' => 1000,
                'motif' => 'Avoir après signature',
            ])->assertOk();

        $this->assertSame($montant, (int) $ordre->fresh()->montant);
        $this->assertDatabaseHas('ordonnancements', [
            'liquidation_id' => $liquidation->id,
            'nature' => 'rectificatif',
            'montant' => 1000,
            'status' => 'a_signer',
        ]);
        if ($paiement !== null) {
            $this->assertSame($montantPaye, (int) $paiement->fresh()->montant);
            $this->assertSame(1000, (int) $paiement->fresh()->montant_a_recouvrer);
        }
    }

    public function test_un_besoin_peut_produire_deux_engagements(): void
    {
        $engagement = Engagement::query()->where('status', 'retourne')->firstOrFail();
        $montant = (int) $engagement->montant;
        $expert = User::query()->where('role', 'expert_budget')->firstOrFail();

        $this->actingAs($expert)
            ->postJson("/api/v1/engagements/{$engagement->id}/partiel", [
                'montant' => 1_000_000,
                'motif' => 'Première tranche',
            ])->assertOk()
            ->assertJsonPath('data.engagement_nature', 'partiel');

        $this->assertSame(1_000_000, (int) $engagement->fresh()->montant);
        $this->assertSame($montant, (int) Engagement::query()->where('expression_besoin_id', $engagement->expression_besoin_id)->sum('montant'));
    }

    public function test_un_avenant_ouvre_un_engagement_lie(): void
    {
        $engagement = Engagement::query()->whereNotNull('visa_reference')->firstOrFail();
        $expert = User::query()->where('role', 'expert_budget')->firstOrFail();
        $avant = Engagement::query()->where('expression_besoin_id', $engagement->expression_besoin_id)->count();

        $this->actingAs($expert)
            ->postJson("/api/v1/engagements/{$engagement->id}/avenant", [
                'montant' => 1000,
                'motif' => 'Prestation complémentaire',
            ])->assertOk()
            ->assertJsonPath('data.engagement_nature', 'avenant')
            ->assertJsonPath('data.parent_engagement_id', $engagement->id);

        $this->assertSame($avant + 1, Engagement::query()->where('expression_besoin_id', $engagement->expression_besoin_id)->count());
        $this->assertSame((int) $engagement->montant, (int) $engagement->fresh()->montant);
    }

    public function test_une_liquidation_peut_produire_deux_ordonnancements(): void
    {
        $ordre = Ordonnancement::query()->firstOrFail();
        $montant = max((int) $ordre->montant, 5_000);
        $ordre->forceFill(['status' => 'a_signer', 'nature' => 'normal', 'montant' => $montant, 'signed_at' => null])->save();
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();

        $this->actingAs($directeur)
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/fractionner", [
                'montant' => 2_000,
            ])->assertOk()
            ->assertJsonPath('data.ordre_nature', 'partiel');

        $this->assertSame(2_000, (int) $ordre->fresh()->montant);
        $this->assertSame($montant, (int) Ordonnancement::query()->where('liquidation_id', $ordre->liquidation_id)->sum('montant'));
    }

    public function test_un_perimetre_restreint_la_consultation_de_la_chaine(): void
    {
        $visible = Engagement::query()->with('expressionBesoin')->firstOrFail();
        $hidden = Engagement::query()
            ->where('id', '!=', $visible->id)
            ->whereHas('expressionBesoin', fn ($query) => $query->where('organization_unit_id', '!=', $visible->expressionBesoin->organization_unit_id))
            ->first();
        if ($hidden === null) {
            $this->markTestSkipped('Les engagements semés appartiennent à une seule structure.');
        }
        $user = User::factory()->create(['role' => 'expert_budget', 'email' => 'perimetre.chaine@ceeac.int']);
        DB::table('access_scopes')->insert([
            'user_id' => $user->id,
            'scope_type' => 'organization_unit',
            'scope_value' => (string) $visible->expressionBesoin->organization_unit_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->getJson('/api/v1/engagements/'.$visible->id)->assertOk();
        $this->actingAs($user)->getJson('/api/v1/engagements/'.$hidden->id)->assertForbidden();
        $this->actingAs($user)
            ->getJson('/api/v1/engagements?q='.urlencode($hidden->reference))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_la_liste_des_lignes_est_paginee(): void
    {
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();

        $response = $this->actingAs($directeur)
            ->getJson('/api/v1/lignes-budgetaires?per_page=1&page=1')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertGreaterThan(1, $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.current_page'));
    }

    public function test_un_interim_enregistre_un_mouvement_de_credit(): void
    {
        $line = BudgetLine::query()->firstOrFail();
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();
        $interim = User::factory()->create(['role' => 'expert_budget', 'email' => 'interim.budget@ceeac.int']);
        DB::table('substitutions')->insert([
            'titulaire_id' => $directeur->id,
            'interim_id' => $interim->id,
            'fonction' => 'directeur_budget',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($interim)
            ->postJson("/api/v1/lignes-budgetaires/{$line->id}/mouvements", [
                'kind' => 'gel',
                'montant' => 500,
                'motif' => 'Intérim du directeur',
                'acte' => 'NOTE-INT-01',
            ])->assertCreated();
    }

    public function test_un_gel_reduit_le_disponible_sans_ecraser_le_vote(): void
    {
        $line = BudgetLine::query()->firstOrFail();
        $vote = (int) $line->montant_vote;
        $before = $line->disponible();
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();

        $this->actingAs($directeur)
            ->postJson("/api/v1/lignes-budgetaires/{$line->id}/mouvements", [
                'kind' => 'gel',
                'montant' => 1000,
                'motif' => 'Réserve de précaution',
                'acte' => 'NOTE-2026-01',
            ])->assertCreated();

        $fresh = $line->fresh();
        $this->assertSame($vote, (int) $fresh->montant_vote);
        $this->assertSame($before - 1000, $fresh->disponible());
        $this->assertSame(1000, $fresh->montantGele());
    }

    public function test_le_tableau_de_chaine_expose_les_compteurs(): void
    {
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();

        $this->actingAs($directeur)
            ->getJson('/api/v1/chaine/tableau-de-bord')
            ->assertOk()
            ->assertJsonStructure(['data' => ['expressions', 'engagements', 'liquidations', 'ordonnancements', 'paiements', 'a_rapprocher', 'reste_a_payer']]);
    }

    public function test_le_pilotage_de_chaine_est_coherent_avec_les_soldes(): void
    {
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();

        $pilotage = $this->actingAs($directeur)
            ->getJson('/api/v1/chaine/tableau-de-bord')
            ->assertOk()
            ->assertJsonStructure(['data' => ['pilotage' => [
                'exercice' => ['annee', 'statut'],
                'execution' => ['revise', 'engage', 'liquide', 'ordonnance', 'paye', 'reste_a_payer', 'taux_engagement'],
                'maillons' => [['code', 'total', 'en_cours', 'aboutis', 'ecartes', 'en_retard']],
                'evolution',
                'lignes' => ['plus_engagees', 'tendues'],
                'alertes' => ['en_retard', 'transmissions_en_erreur', 'a_rapprocher'],
            ]]])
            ->json('data.pilotage');

        $this->assertSame(['EB', 'ENG', 'LIQ', 'ORD', 'PAY'], array_column($pilotage['maillons'], 'code'));
        foreach ($pilotage['maillons'] as $stage) {
            $this->assertSame($stage['total'], $stage['en_cours'] + $stage['aboutis'] + $stage['ecartes']);
        }
        $execution = $pilotage['execution'];
        $this->assertSame($execution['ordonnance'] - $execution['paye'], $execution['reste_a_payer']);
        if ($pilotage['evolution'] !== []) {
            $last = end($pilotage['evolution']);
            $this->assertSame($execution['engage'], $last['engage']);
            $this->assertSame($execution['paye'], $last['paye']);
        }
    }
}
