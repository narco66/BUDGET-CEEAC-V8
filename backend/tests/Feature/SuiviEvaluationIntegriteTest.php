<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Services\BudgetBalanceService;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Enums\LiquidationStatus;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Monitoring\Models\IndicatorMeasurement;
use App\Domains\Monitoring\Models\MonitoringPeriod;
use App\Domains\Monitoring\Models\PerformanceReport;
use App\Domains\Monitoring\Models\SeRecommendation;
use App\Domains\Monitoring\Services\FollowUpService;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\PAP\Models\PapEnrichment;
use App\Models\User;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use LogicException;
use Tests\TestCase;

class SuiviEvaluationIntegriteTest extends TestCase
{
    use RefreshDatabase;

    private User $clarisse;

    private User $directeur;

    private User $etranger;

    private User $responsable;

    private PapEnrichment $activite;

    private BudgetLine $ligne;

    private MonitoringPeriod $periode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->clarisse = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $this->directeur = User::query()->where('email', 'jp.okombi@ceeac.int')->firstOrFail();
        $this->etranger = User::query()->where('email', 'dsi.initiateur@ceeac.int')->firstOrFail();
        $this->ligne = BudgetLine::query()->where('code', '203232')->firstOrFail();
        $this->activite = PapEnrichment::query()->where('budget_line_id', $this->ligne->id)->firstOrFail();
        $this->periode = MonitoringPeriod::query()->where('code', '2026-T1')->firstOrFail();
        $this->responsable = User::factory()->create(['role' => 'chef_service', 'organization_unit_id' => $this->ligne->organization_unit_id, 'password' => 'password']);
        $this->activite->update(['responsible_user_id' => $this->responsable->id]);
    }

    public function test_le_financier_du_suivi_est_celui_du_service_central_des_soldes(): void
    {
        $this->liquidationNonVisee(5_000_000);
        $soldes = app(BudgetBalanceService::class)->forLine($this->ligne);

        $fiche = $this->actingAs($this->clarisse)->getJson('/api/v1/suivi/activites/'.$this->activite->id)->assertOk()->json('data.finances');

        foreach (['engage', 'liquide', 'ordonnance', 'paye', 'disponible'] as $cle) {
            $this->assertSame($soldes[$cle], $fiche[$cle], $cle);
        }
        $this->assertSame(0, $fiche['liquide'], 'Une liquidation non visée n’est pas du liquidé.');
    }

    public function test_une_realisation_non_validee_n_alimente_pas_le_taux_physique(): void
    {
        $tache = $this->activite->tasks()->firstOrFail();
        $id = $this->realisation(['pap_task_id' => $tache->id, 'quantity' => 40]);

        $this->assertEquals(0, $this->physique());
        $this->assertNull($tache->fresh()->progress_percent);

        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/realisations/{$id}/soumettre")->assertOk();
        $this->assertEquals(0, $this->physique());

        $this->preuve('realisation', $id);
        $this->deuxNiveaux('realisation', $id);
        $this->assertEquals(40.0, $tache->fresh()->progress_percent);
        $this->assertGreaterThan(0, $this->physique());
    }

    public function test_l_auteur_ne_valide_pas_sa_propre_saisie(): void
    {
        $mesure = $this->mesure($this->directeur, 5);
        $this->actingAs($this->directeur)->postJson("/api/v1/suivi/mesures/{$mesure}/soumettre")->assertOk();
        $this->preuve('mesure', $mesure, $this->directeur);

        $this->actingAs($this->directeur)
            ->postJson("/api/v1/suivi/mesures/{$mesure}/valider")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');
        $this->assertSame('soumis', IndicatorMeasurement::query()->find($mesure)->status);
    }

    public function test_une_seule_valeur_active_par_indicateur_et_periode_et_rejet_motive(): void
    {
        $mesure = $this->mesure($this->clarisse, 5);
        $indicateur = IndicatorMeasurement::query()->find($mesure)->indicator_id;

        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/mesures', [
            'indicator_id' => $indicateur,
            'monitoring_period_id' => $this->periode->id,
            'value' => 7,
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/mesures/{$mesure}/soumettre")->assertOk();
        $this->actingAs($this->responsable)->postJson("/api/v1/suivi/mesures/{$mesure}/rejeter")->assertUnprocessable();
        $this->actingAs($this->directeur)->postJson("/api/v1/suivi/mesures/{$mesure}/rejeter", ['motif' => 'Hors niveau'])->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->actingAs($this->responsable)
            ->postJson("/api/v1/suivi/mesures/{$mesure}/rejeter", ['motif' => 'Source non probante'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejete')
            ->assertJsonPath('data.rejection_motif', 'Source non probante');

        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/mesures', [
            'indicator_id' => $indicateur,
            'monitoring_period_id' => $this->periode->id,
            'value' => 7,
        ])->assertCreated();
    }

    public function test_une_valeur_validee_n_est_ni_modifiable_ni_supprimable(): void
    {
        $mesure = $this->mesure($this->clarisse, 5);
        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/mesures/{$mesure}/soumettre")->assertOk();
        $this->preuve('mesure', $mesure);
        $this->deuxNiveaux('mesure', $mesure);
        $row = IndicatorMeasurement::query()->findOrFail($mesure);

        try {
            $row->update(['value' => 999]);
            $this->fail('La modification d’une valeur validée aurait dû être refusée.');
        } catch (LogicException) {
            $this->assertEquals(5, $row->fresh()->value);
        }

        $this->expectException(LogicException::class);
        $row->fresh()->delete();
    }

    public function test_une_mesure_corrective_se_cloture_avec_preuve_et_garde_son_historique(): void
    {
        $action = $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/mesures-correctives', [
            'pap_enrichment_id' => $this->activite->id,
            'description' => 'Relancer le prestataire',
            'responsible_role' => $this->clarisse->role,
            'due_on' => now()->addWeek()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->clarisse)
            ->patchJson("/api/v1/suivi/mesures-correctives/{$action}", ['status' => 'en_cours', 'progress' => 50, 'comment' => 'Courrier envoyé'])
            ->assertOk()
            ->assertJsonPath('data.progress', 50);

        $this->actingAs($this->clarisse)
            ->patchJson("/api/v1/suivi/mesures-correctives/{$action}", ['status' => 'cloturee'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preuve');

        $this->preuve('mesure_corrective', $action);
        $this->actingAs($this->clarisse)
            ->patchJson("/api/v1/suivi/mesures-correctives/{$action}", ['status' => 'cloturee', 'comment' => 'Livraison reçue'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cloturee')
            ->assertJsonPath('data.progress', 100);

        $this->actingAs($this->clarisse)
            ->getJson("/api/v1/suivi/mesures-correctives/{$action}/historique")
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($this->etranger)->postJson('/api/v1/suivi/mesures-correctives', [
            'pap_enrichment_id' => $this->activite->id,
            'description' => 'Hors périmètre',
            'responsible_role' => 'directeur',
        ])->assertForbidden();
    }

    public function test_une_recommandation_echue_est_relancee_une_fois_par_jour_et_close_avec_preuve(): void
    {
        $id = $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/recommandations', [
            'origin' => 'Revue trimestrielle',
            'description' => 'Accélérer la passation',
            'responsible_role' => $this->clarisse->role,
            'due_on' => now()->subDays(3)->toDateString(),
            'pap_enrichment_id' => $this->activite->id,
        ])->assertCreated()->json('data.id');
        $this->assertTrue(SeRecommendation::query()->find($id)->late());

        $avant = $this->clarisse->notifications()->count();
        $this->assertSame(1, app(FollowUpService::class)->remindOverdue()['recommandations']);
        $this->assertSame(0, app(FollowUpService::class)->remindOverdue()['recommandations']);
        $this->assertGreaterThan($avant, $this->clarisse->notifications()->count());

        $this->actingAs($this->clarisse)
            ->patchJson("/api/v1/suivi/recommandations/{$id}", ['status' => 'realisee'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preuve');
        $this->preuve('recommandation', $id);
        $this->actingAs($this->clarisse)
            ->patchJson("/api/v1/suivi/recommandations/{$id}", ['status' => 'realisee'])
            ->assertOk()
            ->assertJsonPath('data.en_retard', false);

        $this->actingAs($this->etranger)
            ->getJson('/api/v1/suivi/recommandations')
            ->assertOk()
            ->assertJsonMissing(['id' => $id]);
    }

    public function test_le_rapport_de_performance_est_fige_valide_par_un_tiers_puis_publie_et_archive(): void
    {
        $rapport = $this->actingAs($this->directeur)->postJson('/api/v1/suivi/rapports-performance', [
            'kind' => 'trimestriel',
            'monitoring_period_id' => $this->periode->id,
        ])->assertCreated()->assertJsonPath('data.statut', 'brouillon')->json('data');
        $physiqueFige = $rapport['snapshot']['synthese']['physique'];

        $tache = $this->activite->tasks()->firstOrFail();
        $id = $this->realisation(['pap_task_id' => $tache->id, 'quantity' => 90]);
        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/realisations/{$id}/soumettre")->assertOk();
        $this->preuve('realisation', $id);
        $this->deuxNiveaux('realisation', $id);

        $this->actingAs($this->directeur)
            ->getJson("/api/v1/suivi/rapports-performance/{$rapport['id']}")
            ->assertOk()
            ->assertJsonPath('data.snapshot.synthese.physique', $physiqueFige);

        $this->actingAs($this->directeur)->postJson("/api/v1/suivi/rapports-performance/{$rapport['id']}/soumettre")->assertOk();
        $this->actingAs($this->directeur)
            ->postJson("/api/v1/suivi/rapports-performance/{$rapport['id']}/valider")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $budget = User::factory()->create(['role' => 'directeur_budget', 'password' => 'password']);
        $this->actingAs($budget)->postJson("/api/v1/suivi/rapports-performance/{$rapport['id']}/valider")->assertOk()->assertJsonPath('data.statut', 'valide');
        $this->actingAs($budget)->getJson("/api/v1/suivi/rapports-performance/{$rapport['id']}/pdf")->assertStatus(422);
        $this->actingAs($budget)->postJson("/api/v1/suivi/rapports-performance/{$rapport['id']}/publier")->assertOk()->assertJsonPath('data.statut', 'publie');

        $pdf = $this->actingAs($this->clarisse)->get("/api/v1/suivi/rapports-performance/{$rapport['id']}/pdf")->assertOk();
        $this->assertNotEmpty($pdf->headers->get('X-Document-Sha256'));
        $this->assertDatabaseHas('generated_documents', ['kind' => 'rapport_se', 'documentable_id' => $rapport['id']]);

        $this->expectException(LogicException::class);
        PerformanceReport::query()->find($rapport['id'])->update(['commentaire' => 'retouche']);
    }

    public function test_un_rapport_publie_se_corrige_par_une_nouvelle_version(): void
    {
        $budget = User::factory()->create(['role' => 'directeur_budget', 'password' => 'password']);
        $id = $this->actingAs($this->directeur)->postJson('/api/v1/suivi/rapports-performance', ['kind' => 'annuel'])->assertCreated()->json('data.id');
        $this->actingAs($this->directeur)->postJson("/api/v1/suivi/rapports-performance/{$id}/soumettre")->assertOk();
        $this->actingAs($budget)->postJson("/api/v1/suivi/rapports-performance/{$id}/valider")->assertOk();

        $this->actingAs($this->directeur)
            ->postJson("/api/v1/suivi/rapports-performance/{$id}/nouvelle-version", ['commentaire' => 'Intègre les données du T2'])
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.statut', 'brouillon')
            ->assertJsonPath('data.remplace', $id);

        $this->actingAs($this->etranger)->getJson("/api/v1/suivi/rapports-performance/{$id}")->assertForbidden();
    }

    public function test_l_evolution_ne_trace_que_les_valeurs_validees_avec_leur_statut_de_performance(): void
    {
        $t2 = MonitoringPeriod::query()->create([
            'exercice_year' => 2026, 'code' => '2026-T2', 'label' => 'Deuxième trimestre 2026', 'frequency' => 'trimestrielle',
            'opens_on' => '2026-04-01', 'closes_on' => '2026-06-30', 'status' => 'ouverte',
        ]);
        $t1Mesure = $this->mesure($this->clarisse, 9);
        $indicateur = IndicatorMeasurement::query()->find($t1Mesure)->indicator_id;
        foreach ([[$this->periode->id, 10], [$t2->id, 20]] as [$periode, $cible]) {
            $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/indicateurs/{$indicateur}/cibles", ['monitoring_period_id' => $periode, 'value' => $cible])->assertCreated();
        }
        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/mesures/{$t1Mesure}/soumettre")->assertOk();
        $this->preuve('mesure', $t1Mesure);
        $this->deuxNiveaux('mesure', $t1Mesure);
        $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/mesures', ['indicator_id' => $indicateur, 'monitoring_period_id' => $t2->id, 'value' => 5])->assertCreated();

        $evolution = $this->actingAs($this->clarisse)->getJson("/api/v1/suivi/indicateurs/{$indicateur}/evolution")->assertOk()->json('data');

        $this->assertSame(['2026-T1', '2026-T2'], array_column($evolution, 'periode'));
        $this->assertEquals(9, $evolution[0]['realise']);
        $this->assertEquals(90, $evolution[0]['taux']);
        $this->assertSame('en_bonne_voie', $evolution[0]['statut']);
        $this->assertNull($evolution[1]['realise'], 'Un brouillon n’apparaît pas dans la courbe.');
        $this->assertSame('non_renseigne', $evolution[1]['statut']);

        $this->actingAs($this->etranger)->getJson("/api/v1/suivi/indicateurs/{$indicateur}/evolution")->assertForbidden();
        $this->actingAs($this->etranger)->getJson('/api/v1/suivi/indicateurs')->assertOk()->assertJsonMissing(['id' => $indicateur]);
    }

    public function test_mes_taches_presente_les_donnees_a_valider_au_bon_acteur(): void
    {
        $id = $this->realisation(['quantity' => 20]);
        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/realisations/{$id}/soumettre")->assertOk();

        $this->actingAs($this->clarisse)
            ->getJson('/api/v1/suivi/saisies')
            ->assertOk()
            ->assertJsonFragment(['reference' => 'REA-'.$id, 'statut' => 'soumis'])
            ->assertJsonPath('data.0.actions.valider', false);
        $this->preuve('realisation', $id);
        $this->actingAs($this->directeur)
            ->getJson('/api/v1/suivi/saisies')
            ->assertOk()
            ->assertJsonPath('data.0.actions.valider', false);
        $this->actingAs($this->responsable)
            ->getJson('/api/v1/suivi/saisies')
            ->assertOk()
            ->assertJsonPath('data.0.actions.valider', true);
        $this->actingAs($this->etranger)
            ->getJson('/api/v1/suivi/saisies')
            ->assertOk()
            ->assertJsonMissing(['reference' => 'REA-'.$id]);

        $this->actingAs($this->directeur)
            ->getJson('/api/v1/taches?module=se')
            ->assertOk()
            ->assertJsonMissing(['dossier' => 'REA-'.$id]);
        $this->actingAs($this->responsable)
            ->getJson('/api/v1/taches?module=se')
            ->assertOk()
            ->assertJsonFragment(['dossier' => 'REA-'.$id, 'action' => 'valider', 'role' => 'responsable_activite']);
    }

    /**
     * Validation responsable puis validation hiérarchique par deux acteurs distincts.
     */
    private function deuxNiveaux(string $type, int $id): void
    {
        $base = $type === 'mesure' ? '/api/v1/suivi/mesures/' : '/api/v1/suivi/realisations/';
        $this->actingAs($this->responsable)->postJson($base.$id.'/valider')->assertOk()->assertJsonPath('data.status', 'valide_responsable');
        $this->actingAs($this->directeur)->postJson($base.$id.'/valider')->assertOk()->assertJsonPath('data.status', 'valide');
    }

    private function physique(): float
    {
        return (float) $this->actingAs($this->clarisse)->getJson('/api/v1/suivi/activites/'.$this->activite->id)->assertOk()->json('data.physique');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function realisation(array $extra): int
    {
        return $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/realisations', [
            'pap_enrichment_id' => $this->activite->id,
            'monitoring_period_id' => $this->periode->id,
            'method' => 'quantitative',
            'planned' => 100,
            ...$extra,
        ])->assertCreated()->json('data.id');
    }

    private function mesure(User $auteur, float $valeur): int
    {
        $indicateur = $this->actingAs($auteur)->postJson('/api/v1/suivi/indicateurs', [
            'pap_enrichment_id' => $this->activite->id,
            'code' => 'IND-INT-'.uniqid(),
            'label' => 'Ateliers tenus',
            'type' => 'produit',
            'direction' => 'croissant',
        ])->assertCreated()->json('data.id');

        return $this->actingAs($auteur)->postJson('/api/v1/suivi/mesures', [
            'indicator_id' => $indicateur,
            'monitoring_period_id' => $this->periode->id,
            'value' => $valeur,
        ])->assertCreated()->json('data.id');
    }

    private function preuve(string $type, int $id, ?User $auteur = null): void
    {
        $this->actingAs($auteur ?? $this->clarisse)->post('/api/v1/suivi/preuves', [
            'type' => $type,
            'id' => $id,
            'category' => 'rapport',
            'fichier' => UploadedFile::fake()->create('preuve.pdf', 10, 'application/pdf'),
        ])->assertCreated();
    }

    private function liquidationNonVisee(int $montant): void
    {
        $besoin = ExpressionBesoin::query()->create([
            'reference' => 'EB/2026/DATI/000888',
            'exercice_id' => $this->ligne->exercice_id,
            'organization_unit_id' => $this->ligne->organization_unit_id,
            'initiator_id' => $this->clarisse->id,
            'budget_line_id' => $this->ligne->id,
            'nature' => $this->ligne->nature,
            'objet' => 'Liquidation en préparation',
            'status' => EbStatus::Transformee,
            'workflow_step' => 'clos',
            'montant' => $montant,
        ]);
        $engagement = Engagement::query()->create([
            'reference' => 'ENG-SE-000888',
            'expression_besoin_id' => $besoin->id,
            'budget_line_id' => $this->ligne->id,
            'montant' => $montant,
            'status' => EngagementStatus::TransformeLiquidation,
            'visa_reference' => 'VISA-SE-000888',
        ]);
        Liquidation::query()->create([
            'reference' => 'LIQ-SE-000888',
            'engagement_id' => $engagement->id,
            'montant' => $montant,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'status' => LiquidationStatus::EnPreparation,
        ]);
    }
}
