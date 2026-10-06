<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Enums\LiquidationStatus;
use App\Domains\Commitments\Enums\OrdonnancementStatus;
use App\Domains\Commitments\Enums\PaiementStatus;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Models\PaiementExecution;
use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Models\MonitoringPeriod;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\PAP\Models\PapEnrichment;
use App\Models\User;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuiviEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private User $clarisse;

    private User $directeur;

    private User $etranger;

    private User $responsable;

    private PapEnrichment $activite;

    private MonitoringPeriod $periode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->clarisse = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $this->directeur = User::query()->where('email', 'jp.okombi@ceeac.int')->firstOrFail();
        $this->etranger = User::query()->where('email', 'dsi.initiateur@ceeac.int')->firstOrFail();
        $ligne = BudgetLine::query()->where('code', '203232')->firstOrFail();
        $ligne->update(['montant_vote' => 100_000_000]);
        $this->activite = PapEnrichment::query()->where('budget_line_id', $ligne->id)->firstOrFail();
        $this->activite->update([
            'pilier' => 'Intégration',
            'axe' => 'Énergie',
            'produit' => 'Système régional',
            'sous_produit' => 'Suivi électronique',
        ]);
        $this->periode = MonitoringPeriod::query()->where('code', '2026-T1')->firstOrFail();
        $this->responsable = User::factory()->create(['role' => 'chef_service', 'organization_unit_id' => $ligne->organization_unit_id, 'password' => 'password']);
        $this->activite->update(['responsible_user_id' => $this->responsable->id]);
        $this->payer($ligne, 80_000_000);
    }

    public function test_collecte_soumission_validation_et_historisation(): void
    {
        $indicateur = $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/indicateurs', [
            'pap_enrichment_id' => $this->activite->id,
            'code' => 'IND-ENERGIE',
            'label' => 'Projets suivis',
            'type' => 'produit',
            'direction' => 'croissant',
            'unit' => 'projet',
            'baseline_value' => 2,
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/indicateurs/{$indicateur}/cibles", [
            'monitoring_period_id' => $this->periode->id,
            'value' => 10,
        ])->assertCreated();

        $mesure = $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/mesures', [
            'indicator_id' => $indicateur,
            'monitoring_period_id' => $this->periode->id,
            'value' => 8,
            'source' => 'Registre projets',
        ])->assertCreated()->json('data');

        $this->assertEquals(80, $mesure['attainment_rate']);

        $this->actingAs($this->clarisse)
            ->postJson("/api/v1/suivi/mesures/{$mesure['id']}/soumettre")
            ->assertOk()
            ->assertJsonPath('data.status', 'soumis');

        $this->actingAs($this->responsable)
            ->postJson("/api/v1/suivi/mesures/{$mesure['id']}/valider")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preuve');

        $this->actingAs($this->clarisse)->post('/api/v1/suivi/preuves', [
            'type' => 'mesure',
            'id' => $mesure['id'],
            'category' => 'rapport',
            'fichier' => UploadedFile::fake()->create('preuve.pdf', 12, 'application/pdf'),
        ])->assertCreated();

        $this->assertDatabaseHas('se_proofs', ['proofable_id' => $mesure['id'], 'category' => 'rapport']);

        $this->actingAs($this->responsable)
            ->postJson("/api/v1/suivi/mesures/{$mesure['id']}/valider")
            ->assertOk()
            ->assertJsonPath('data.status', 'valide_responsable');
        $this->actingAs($this->directeur)
            ->postJson("/api/v1/suivi/mesures/{$mesure['id']}/valider")
            ->assertOk()
            ->assertJsonPath('data.status', 'valide');

        $rectifiee = $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/mesures/{$mesure['id']}/rectifier", [
            'value' => 9,
            'motif' => 'Correction du registre',
        ])->assertCreated()->json('data');

        $this->assertSame(2, $rectifiee['version']);
        $this->assertNull(IndicatorMeasurement::query()->find($mesure['id'])->superseded_at, 'La version validée reste la référence tant que la rectification n’est pas validée.');
        $this->valider('mesure', $rectifiee['id']);
        $this->assertNotNull(IndicatorMeasurement::query()->find($mesure['id'])->superseded_at);
        $this->assertEquals(8, IndicatorMeasurement::query()->find($mesure['id'])->value);

        Indicator::query()->whereKey($indicateur)->update(['formula_version' => 2]);
        $this->assertSame(1, IndicatorMeasurement::query()->find($mesure['id'])->formula_version);
    }

    public function test_indicateur_decroissant_et_taches_ponderees(): void
    {
        $indicateur = $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/indicateurs', [
            'pap_enrichment_id' => $this->activite->id,
            'code' => 'IND-DELAI',
            'label' => 'Délai moyen',
            'type' => 'delai',
            'direction' => 'decroissant',
            'aggregation' => 'non_aggregatable',
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/indicateurs/{$indicateur}/cibles", [
            'monitoring_period_id' => $this->periode->id,
            'value' => 10,
        ])->assertCreated();

        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/mesures', [
            'indicator_id' => $indicateur,
            'monitoring_period_id' => $this->periode->id,
            'value' => 5,
        ])->assertCreated()->assertJsonPath('data.attainment_rate', 200);

        $this->getJson('/api/v1/suivi/indicateurs')->assertOk()->assertJsonFragment(['agrege' => false]);

        $taches = $this->activite->tasks()->orderBy('position')->take(3)->get();
        $poids = [1, 2, 1];
        $quantites = [0, 1, 1];
        $prevus = [1, 2, 1];
        foreach ($taches as $index => $tache) {
            $tache->update(['weight' => $poids[$index]]);
            $realisation = $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/realisations', [
                'pap_enrichment_id' => $this->activite->id,
                'pap_task_id' => $tache->id,
                'monitoring_period_id' => $this->periode->id,
                'method' => 'quantitative',
                'quantity' => $quantites[$index],
                'planned' => $prevus[$index],
            ])->assertCreated()->json('data.id');
            $this->valider('realisation', $realisation);
        }

        $this->actingAs($this->clarisse)
            ->getJson('/api/v1/suivi/activites/'.$this->activite->id)
            ->assertOk()
            ->assertJsonPath('data.physique', 50);
    }

    public function test_le_financier_vient_de_la_chaine_et_lecart_est_visible(): void
    {
        $realisation = $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/realisations', [
            'pap_enrichment_id' => $this->activite->id,
            'monitoring_period_id' => $this->periode->id,
            'method' => 'quantitative',
            'quantity' => 30,
            'planned' => 100,
        ])->assertCreated()->json('data.id');
        $this->valider('realisation', $realisation);

        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/realisations', [
            'pap_enrichment_id' => $this->activite->id,
            'monitoring_period_id' => $this->periode->id,
            'method' => 'quantitative',
            'quantity' => 30,
            'planned' => 100,
            'montant_paye' => 1,
        ])->assertUnprocessable();

        $this->assertSame(80_000_000, (int) Paiement::query()->sum('montant_paye'));

        $fiche = $this->actingAs($this->clarisse)
            ->getJson('/api/v1/suivi/activites/'.$this->activite->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(100_000_000, $fiche['finances']['budget_revise']);
        $this->assertSame(80_000_000, $fiche['finances']['paye']);
        $this->assertEquals(80, $fiche['financier']);
        $this->assertEquals(30, $fiche['physique']);
        $this->assertTrue($fiche['alerte']);

        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/ecarts', [
            'pap_enrichment_id' => $this->activite->id,
            'kind' => 'physique_financier',
            'cause_category' => 'technique',
            'cause' => 'Décaissement avant livraison',
            'responsible_role' => 'directeur',
        ])->assertCreated()->assertJsonPath('data.status', 'critique');

        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/mesures-correctives', [
            'description' => 'Replanifier la livraison',
        ])->assertUnprocessable()->assertJsonValidationErrors('responsible_role');

        $this->actingAs($this->etranger)
            ->getJson('/api/v1/suivi/activites/'.$this->activite->id)
            ->assertForbidden();
    }

    public function test_consolidation_drill_down_risque_et_recommandation(): void
    {
        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/risques', [
            'pap_enrichment_id' => $this->activite->id,
            'description' => 'Indisponibilité des points focaux',
            'category' => 'organisationnelle',
            'probability' => 4,
            'impact' => 4,
            'responsible_role' => 'directeur',
        ])->assertCreated()->assertJsonPath('data.criticite', 'critique');

        $this->actingAs($this->clarisse)
            ->getJson('/api/v1/suivi/tableau-de-bord')
            ->assertOk()
            ->assertJsonPath('data.risques_critiques', 1);

        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/recommandations', [
            'origin' => 'revue',
            'description' => 'Publier le rapport trimestriel',
            'responsible_role' => 'directeur',
            'due_on' => now()->subDay()->toDateString(),
        ])->assertCreated();

        $this->actingAs($this->clarisse)
            ->getJson('/api/v1/suivi/recommandations')
            ->assertOk()
            ->assertJsonFragment(['en_retard' => true]);

        $this->assertGreaterThanOrEqual(2, DB::table('notifications')->count());

        foreach (['pilier', 'axe', 'produit', 'activite'] as $niveau) {
            $groupe = collect($this->actingAs($this->clarisse)->getJson('/api/v1/suivi/consolidation?niveau='.$niveau)->assertOk()->json('data'))
                ->firstWhere('paye', 80_000_000);
            $this->assertNotNull($groupe);
        }

        $this->actingAs($this->clarisse)->getJson('/api/v1/suivi/rapports?format=csv')->assertOk();
        $this->actingAs($this->clarisse)->getJson('/api/v1/taches?module=se')->assertOk();
    }

    public function test_avancement_superieur_a_100_exige_une_justification(): void
    {
        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/realisations', [
            'pap_enrichment_id' => $this->activite->id,
            'monitoring_period_id' => $this->periode->id,
            'method' => 'quantitative',
            'quantity' => 120,
            'planned' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('exception_motif');
    }

    public function test_agregation_score_gantt_et_referentiels(): void
    {
        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/indicateurs', [
            'pap_enrichment_id' => $this->activite->id,
            'code' => 'IND-SUM-A',
            'label' => 'Projets A',
            'type' => 'produit',
            'direction' => 'croissant',
            'unit' => 'projet',
            'aggregation' => 'sum',
        ])->assertCreated();
        $second = $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/indicateurs', [
            'pap_enrichment_id' => $this->activite->id,
            'code' => 'IND-SUM-B',
            'label' => 'Projets B',
            'type' => 'produit',
            'direction' => 'croissant',
            'unit' => 'projet',
            'aggregation' => 'sum',
        ])->assertCreated()->json('data.id');
        $first = Indicator::query()->where('code', 'IND-SUM-A')->value('id');
        foreach ([[$first, 10], [$second, 30]] as [$id, $value]) {
            $mesure = $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/mesures', [
                'indicator_id' => $id,
                'monitoring_period_id' => $this->periode->id,
                'value' => $value,
            ])->assertCreated()->json('data.id');
            $this->valider('mesure', $mesure);
        }

        $groupe = collect($this->actingAs($this->clarisse)->getJson('/api/v1/suivi/indicateurs/agregation')->assertOk()->json('data'))
            ->first(fn (array $row) => $row['methode'] === 'sum' && $row['agrege'] === true);
        $this->assertEquals(40, $groupe['valeur']);
        $this->assertCount(2, $groupe['indicateurs']);

        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/ecarts', [
            'pap_enrichment_id' => $this->activite->id,
            'kind' => 'physique_financier',
            'cause_category' => 'inconnue',
            'responsible_role' => 'directeur',
        ])->assertUnprocessable();

        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/evaluations', [
            'subject' => 'Revue',
            'type' => 'finale',
            'evaluator_role' => 'directeur',
            'criteria' => ['inexistant'],
        ])->assertUnprocessable();

        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/referentiels/cause', [
            'code' => 'climat',
            'label' => 'Climat',
        ])->assertForbidden();

        $this->directeur->forceFill(['role' => 'directeur_budget'])->save();
        $this->actingAs($this->directeur)->postJson('/api/v1/suivi/referentiels/cause', [
            'code' => 'climat',
            'label' => 'Climat',
        ])->assertCreated();
        $this->actingAs($this->directeur)->putJson('/api/v1/suivi/referentiels/score', [
            'composantes' => [
                ['code' => 'physique', 'label' => 'Physique', 'weight' => 40],
                ['code' => 'financier', 'label' => 'Financier', 'weight' => 50],
            ],
        ])->assertUnprocessable();
        $this->actingAs($this->directeur)->putJson('/api/v1/suivi/referentiels/score', [
            'composantes' => [
                ['code' => 'physique', 'label' => 'Physique', 'weight' => 40],
                ['code' => 'financier', 'label' => 'Financier', 'weight' => 60],
            ],
        ])->assertOk();

        $realisation = $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/realisations', [
            'pap_enrichment_id' => $this->activite->id,
            'monitoring_period_id' => $this->periode->id,
            'method' => 'quantitative',
            'quantity' => 50,
            'planned' => 100,
        ])->assertCreated()->json('data.id');
        $this->valider('realisation', $realisation);

        $this->actingAs($this->clarisse)
            ->getJson('/api/v1/suivi/activites/'.$this->activite->id)
            ->assertOk()
            ->assertJsonPath('data.score.score', 68)
            ->assertJsonStructure(['data' => ['planification', 'jalons', 'livrables', 'preuves', 'historique', 'chaine']])
            ->assertJsonFragment(['reference' => 'PAY-SE-000777']);

        $taches = $this->activite->tasks()->orderBy('position')->take(2)->get();
        $this->actingAs($this->clarisse)->patchJson('/api/v1/suivi/taches/'.$taches[0]->id, [
            'starts_on' => '2026-04-01',
            'ends_on' => '2026-06-30',
        ])->assertOk();
        $this->actingAs($this->clarisse)->patchJson('/api/v1/suivi/taches/'.$taches[1]->id, [
            'starts_on' => '2026-05-01',
            'ends_on' => '2026-07-15',
            'actual_start' => '2026-05-20',
            'actual_end' => '2026-08-01',
            'depends_on_id' => $taches[0]->id,
        ])->assertOk();

        $ligne = collect($this->actingAs($this->clarisse)->getJson('/api/v1/suivi/gantt')->assertOk()->json('data.activites'))
            ->firstWhere('id', $this->activite->id);
        $seconde = collect($ligne['taches'])->firstWhere('id', $taches[1]->id);
        $this->assertNotNull($seconde['glissement']);
        $this->assertNotNull($seconde['prevu']);
        $this->assertNotNull($seconde['reel']);
    }

    /**
     * Circuit complet : l’auteur (Clarisse) soumet et joint une preuve, un
     * second acteur (le Directeur) valide.
     */
    private function valider(string $type, int $id): void
    {
        $base = $type === 'mesure' ? '/api/v1/suivi/mesures/' : '/api/v1/suivi/realisations/';
        $this->actingAs($this->clarisse)->postJson($base.$id.'/soumettre')->assertOk()->assertJsonPath('data.status', 'soumis');
        $this->actingAs($this->clarisse)->post('/api/v1/suivi/preuves', [
            'type' => $type,
            'id' => $id,
            'category' => 'rapport',
            'fichier' => UploadedFile::fake()->create('preuve-'.$id.'.pdf', 12, 'application/pdf'),
        ])->assertCreated();
        $this->actingAs($this->responsable)->postJson($base.$id.'/valider')->assertOk()->assertJsonPath('data.status', 'valide_responsable');
        $this->actingAs($this->directeur)->postJson($base.$id.'/valider')->assertOk()->assertJsonPath('data.status', 'valide');
    }

    private function payer(BudgetLine $ligne, int $montant): void
    {
        $besoin = ExpressionBesoin::query()->create([
            'reference' => 'EB/2026/DATI/000777',
            'exercice_id' => $ligne->exercice_id,
            'organization_unit_id' => $ligne->organization_unit_id,
            'initiator_id' => $this->clarisse->id,
            'budget_line_id' => $ligne->id,
            'nature' => $ligne->nature,
            'objet' => 'Paiement de test du suivi',
            'status' => EbStatus::Approuvee,
            'workflow_step' => 'clos',
            'montant' => $montant,
        ]);
        $engagement = Engagement::query()->create([
            'reference' => 'ENG-SE-000777',
            'expression_besoin_id' => $besoin->id,
            'budget_line_id' => $ligne->id,
            'montant' => $montant,
            'status' => EngagementStatus::TransformeLiquidation,
        ]);
        $liquidation = Liquidation::query()->create([
            'reference' => 'LIQ-SE-000777',
            'engagement_id' => $engagement->id,
            'montant' => $montant,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'visa_reference' => 'VLQ-SE-000777',
            'status' => LiquidationStatus::TransformeeOrdonnancement,
        ]);
        $ordre = Ordonnancement::query()->create([
            'reference' => 'ORD-SE-000777',
            'liquidation_id' => $liquidation->id,
            'montant' => $montant,
            'status' => OrdonnancementStatus::Signe,
        ]);
        $paiement = Paiement::query()->create([
            'reference' => 'PAY-SE-000777',
            'ordonnancement_id' => $ordre->id,
            'montant' => $montant,
            'montant_paye' => $montant,
            'status' => PaiementStatus::Cloture,
        ]);
        PaiementExecution::query()->create([
            'paiement_id' => $paiement->id,
            'rang' => 1,
            'montant' => $montant,
            'reference_reglement' => 'VIR-SE-000777',
            'status' => PaiementExecution::EXECUTEE,
            'idempotence_key' => 'PAY-EXEC-SE-000777',
        ]);
    }
}
