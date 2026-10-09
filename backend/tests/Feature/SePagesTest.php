<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Monitoring\Models\MonitoringPeriod;
use App\Domains\Monitoring\Models\PerformanceVariance;
use App\Domains\Monitoring\Models\SeRisk;
use App\Domains\Monitoring\Services\VarianceDossierService;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\PAP\Models\PapEnrichment;
use App\Models\User;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Écrans de la maquette docs/maquette-SE alimentés par des données réelles.
 */
class SePagesTest extends TestCase
{
    use RefreshDatabase;

    private User $clarisse;

    private User $directeur;

    private User $responsable;

    private User $etranger;

    private PapEnrichment $activite;

    private BudgetLine $ligne;

    private MonitoringPeriod $periode;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 10:00:00');
        $this->seed(ExpressionBesoinSeeder::class);
        $this->clarisse = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $this->directeur = User::query()->where('email', 'jp.okombi@ceeac.int')->firstOrFail();
        $this->etranger = User::query()->where('email', 'dsi.initiateur@ceeac.int')->firstOrFail();
        $this->ligne = BudgetLine::query()->where('code', '203232')->firstOrFail();
        $this->ligne->update(['montant_vote' => 100_000_000]);
        $this->activite = PapEnrichment::query()->where('budget_line_id', $this->ligne->id)->firstOrFail();
        $this->responsable = User::factory()->create(['role' => 'chef_service', 'organization_unit_id' => $this->ligne->organization_unit_id, 'password' => 'password']);
        $this->activite->update([
            'responsible_user_id' => $this->responsable->id,
            'date_debut' => '2026-04-01',
            'actual_start' => '2026-04-14',
            'date_fin' => '2026-12-31',
        ]);
        $this->periode = MonitoringPeriod::query()->create([
            'exercice_year' => 2026, 'code' => '2026-T4', 'label' => 'T4 2026', 'frequency' => 'trimestrielle',
            'opens_on' => '2026-10-01', 'closes_on' => '2026-12-31', 'status' => 'ouverte',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_le_tableau_de_bord_presente_les_blocs_de_la_maquette_avec_filtres(): void
    {
        $this->engager(80_000_000);

        $board = $this->actingAs($this->directeur)->getJson('/api/v1/suivi/pilotage')->assertOk()->json('data');

        foreach (['filtres', 'bandeau', 'execution', 'indicateurs', 'evolution', 'structures', 'attention', 'activites'] as $bloc) {
            $this->assertArrayHasKey($bloc, $board);
        }
        $this->assertSame(1, $board['bandeau']['activites']);
        $this->assertEquals(80.0, $board['execution']['engage']);
        $this->assertSame('critique', $board['execution']['niveau']);
        $this->assertArrayHasKey('attention_meta', $board);
        $this->assertSame('ecart', $board['attention'][0]['type']);
        $this->assertStringContainsString('Financier > physique', $board['attention'][0]['motif']);
        $this->assertCount(11, $board['evolution']);
        $this->assertEquals(80.0, end($board['evolution'])['engage']);

        $this->actingAs($this->directeur)->getJson('/api/v1/suivi/pilotage?type=hors_pap')->assertOk()->assertJsonPath('data.bandeau.activites', 0);
        $this->actingAs($this->etranger)->getJson('/api/v1/suivi/pilotage')->assertOk()->assertJsonPath('data.bandeau.activites', 0);
    }

    public function test_une_activite_sans_responsable_recoit_le_directeur_de_sa_structure(): void
    {
        $this->activite->update(['responsible_user_id' => null]);

        $board = $this->actingAs($this->directeur)->getJson('/api/v1/suivi/pilotage')->assertOk()->json('data');

        $this->assertSame($this->directeur->id, $this->activite->fresh()->responsible_user_id);
        $this->assertTrue(collect($board['filtres']['responsables'])->contains('id', $this->directeur->id));
    }

    public function test_le_directeur_designe_une_personne_comme_responsable(): void
    {
        $this->activite->update(['responsible_user_id' => null]);

        $this->actingAs($this->directeur)
            ->patchJson('/api/v1/suivi/activites/'.$this->activite->id.'/pilotage', [
                'responsible_user_id' => $this->responsable->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.responsable_id', $this->responsable->id);

        $this->assertSame($this->responsable->id, $this->activite->fresh()->responsible_user_id);
    }

    public function test_le_directeur_designe_le_responsable_d_un_indicateur(): void
    {
        $indicator = Indicator::query()->create([
            'pap_enrichment_id' => $this->activite->id,
            'code' => 'IND-RESP',
            'label' => 'Indicateur à personne',
            'type' => 'quantitatif',
            'direction' => 'croissant',
            'status' => 'actif',
            'responsible_role' => 'directeur',
        ]);

        $this->actingAs($this->directeur)
            ->patchJson('/api/v1/suivi/indicateurs/'.$indicator->id.'/responsable', [
                'responsible_user_id' => $this->responsable->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.responsable_id', $this->responsable->id);

        $this->assertSame($this->responsable->id, $indicator->fresh()->responsible_user_id);
    }

    public function test_le_referentiel_des_causes_contient_celles_de_la_maquette(): void
    {
        $codes = collect($this->actingAs($this->directeur)->getJson('/api/v1/suivi/referentiels/cause')->assertOk()->json('data'))->pluck('code');

        foreach (['contractuelle', 'rh', 'fournisseur', 'autre'] as $code) {
            $this->assertTrue($codes->contains($code), $code);
        }
    }

    public function test_la_liste_des_ecarts_paginee_ouvre_le_dossier_critique_une_seule_fois(): void
    {
        $this->engager(80_000_000);

        $page = $this->actingAs($this->directeur)->getJson('/api/v1/suivi/ecarts?per_page=1')->assertOk();
        $page->assertJsonPath('meta.per_page', 1);
        $this->assertGreaterThanOrEqual(1, $page->json('meta.total'));
        $this->assertCount(1, $page->json('data'));
        $this->assertSame(
            $page->json('meta.total'),
            $this->actingAs($this->directeur)->getJson('/api/v1/suivi/ecarts?per_page=1')->json('meta.total'),
        );
    }

    public function test_la_file_de_saisie_est_paginee(): void
    {
        $taches = $this->activite->tasks()->orderBy('id')->limit(2)->get();
        $this->assertCount(2, $taches);
        foreach ($taches as $index => $tache) {
            $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/realisations', [
                'pap_enrichment_id' => $this->activite->id,
                'pap_task_id' => $tache->id,
                'monitoring_period_id' => $this->periode->id,
                'method' => 'quantitative',
                'quantity' => ($index + 1) * 10,
                'planned' => 100,
            ])->assertCreated();
        }

        $page = $this->actingAs($this->clarisse)->getJson('/api/v1/suivi/saisies?per_page=1')->assertOk();
        $page->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->assertCount(1, $page->json('data'));
        $this->assertCount(1, $this->actingAs($this->clarisse)->getJson('/api/v1/suivi/saisies?per_page=1&page=2')->json('data'));
    }

    public function test_la_fiche_360_calcule_poids_contributions_et_statuts_des_taches(): void
    {
        $taches = $this->activite->tasks()->orderBy('position')->get();
        $this->assertGreaterThanOrEqual(2, $taches->count());
        $taches[0]->update(['weight' => 1, 'starts_on' => '2026-04-01', 'ends_on' => '2026-05-01', 'unit' => 'atelier']);
        $taches[1]->update(['weight' => 3, 'starts_on' => '2026-06-01', 'ends_on' => '2026-10-31', 'unit' => 'module']);
        foreach ($taches->slice(2) as $tache) {
            $tache->update(['weight' => 0]);
        }
        $this->realiser($taches[0]->id, 1, 1);
        $this->realiser($taches[1]->id, 4, 10);

        $fiche = $this->actingAs($this->clarisse)->getJson("/api/v1/suivi/activites/{$this->activite->id}/fiche")->assertOk()->json('data');
        $lignes = collect($fiche['taches'])->keyBy('id');

        $this->assertEquals(25, $lignes[$taches[0]->id]['poids']);
        $this->assertEquals(75, $lignes[$taches[1]->id]['poids']);
        $this->assertSame('atteint', $lignes[$taches[0]->id]['statut']);
        $this->assertEquals(30, $lignes[$taches[1]->id]['contribution']);
        $this->assertEquals(4, $lignes[$taches[1]->id]['realise']);
        $this->assertEquals(55, $fiche['avancement_pondere']);
        $this->assertSame(13, $fiche['bandeau']['retard_demarrage']);
        $this->assertSame(45, $fiche['bandeau']['jours_restants']);
        $this->assertArrayHasKey('appreciation', $fiche['bandeau']);
    }

    public function test_le_gantt_projette_les_retards_par_dependance_et_les_revisions_sont_validees_par_un_tiers(): void
    {
        [$t1, $t2] = $this->activite->tasks()->orderBy('position')->take(2)->get()->all();
        $t1->update(['starts_on' => '2026-09-01', 'ends_on' => '2026-10-31', 'baseline_starts_on' => '2026-09-01', 'baseline_ends_on' => '2026-10-31', 'actual_start' => '2026-09-01', 'code' => 'T1']);
        $t2->update(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'baseline_starts_on' => '2026-11-01', 'baseline_ends_on' => '2026-11-30', 'depends_on_id' => $t1->id, 'code' => 'T2']);
        $this->realiser($t1->id, 1, 2);

        $gantt = $this->actingAs($this->clarisse)->getJson("/api/v1/suivi/activites/{$this->activite->id}/gantt")->assertOk()->json('data');
        $lignes = collect($gantt['taches'])->keyBy('code');

        $this->assertSame('T1', $lignes['T2']['depend_de']);
        $this->assertSame('FD', $lignes['T2']['type_dependance']);
        $this->assertGreaterThan(0, $lignes['T1']['retard_fin']);
        $this->assertGreaterThan(0, $lignes['T2']['retard_fin']);
        $this->assertNotEmpty($gantt['impacts']);
        $this->assertNotEmpty($gantt['colonnes']);
        $this->assertNotNull($gantt['aujourdhui']);

        $revision = $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/activites/{$this->activite->id}/plannings", [
            'motif' => 'Glissement de T1',
            'taches' => [['id' => $t2->id, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/plannings/{$revision}/valider")->assertUnprocessable();
        $this->actingAs($this->directeur)->postJson("/api/v1/suivi/plannings/{$revision}/valider")->assertOk()->assertJsonPath('data.status', 'validee');

        $t2->refresh();
        $this->assertSame('2026-12-01', $t2->starts_on->toDateString());
        $this->assertSame('2026-11-01', $t2->baseline_starts_on->toDateString(), 'Le planning initial reste visible pour comparaison.');
        $this->actingAs($this->clarisse)->getJson("/api/v1/suivi/activites/{$this->activite->id}/gantt")->assertJsonPath('data.revisions.versions', 2);
    }

    public function test_une_fin_reelle_tardive_decale_la_tache_suivante(): void
    {
        [$premiere, $suivante] = $this->activite->tasks()->orderBy('position')->take(2)->get()->all();
        $premiere->update([
            'starts_on' => '2026-01-01', 'ends_on' => '2026-01-10',
            'baseline_starts_on' => '2026-01-01', 'baseline_ends_on' => '2026-01-10',
            'actual_start' => null, 'actual_end' => null, 'depends_on_id' => null,
        ]);
        $suivante->update([
            'starts_on' => '2026-01-11', 'ends_on' => '2026-01-20',
            'baseline_starts_on' => '2026-01-11', 'baseline_ends_on' => '2026-01-20',
            'actual_end' => null, 'depends_on_id' => $premiere->id,
        ]);

        $this->patchJson("/api/v1/suivi/taches/{$premiere->id}", ['actual_end' => '2026-01-15'])->assertUnauthorized();

        $this->actingAs($this->clarisse)
            ->patchJson("/api/v1/suivi/taches/{$premiere->id}", ['actual_end' => '2026-01-15'])
            ->assertOk();

        $suivante->refresh();
        $this->assertSame('2026-01-16', $suivante->starts_on->toDateString());
        $this->assertSame('2026-01-25', $suivante->ends_on->toDateString());
        $this->assertSame('2026-01-11', $suivante->baseline_starts_on->toDateString());
        $this->assertSame('2026-01-20', $suivante->baseline_ends_on->toDateString());

        $lignes = collect($this->actingAs($this->clarisse)->getJson("/api/v1/suivi/activites/{$this->activite->id}/gantt")->json('data.taches'))->keyBy('id');
        $this->assertSame('2026-01-25', $lignes[$suivante->id]['fin_courante']);
        $this->assertSame('2026-01-20', $lignes[$suivante->id]['fin_initiale']);

        $this->actingAs($this->clarisse)
            ->patchJson("/api/v1/suivi/taches/{$premiere->id}", ['actual_end' => '2026-01-15'])
            ->assertOk();
        $this->assertSame('2026-01-25', $suivante->fresh()->ends_on->toDateString());
    }

    public function test_un_planning_valide_ne_se_modifie_que_par_la_hierarchie(): void
    {
        $tache = $this->activite->tasks()->orderBy('position')->firstOrFail();
        $tache->update([
            'starts_on' => '2026-05-01', 'ends_on' => '2026-05-31',
            'baseline_starts_on' => '2026-05-01', 'baseline_ends_on' => '2026-05-31',
            'actual_start' => null, 'actual_end' => null,
        ]);

        // L’acteur constate le réel, mais ne déplace pas un planning validé.
        $this->actingAs($this->clarisse)
            ->patchJson("/api/v1/suivi/taches/{$tache->id}", ['ends_on' => '2026-06-30'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['planning']);
        $this->actingAs($this->clarisse)
            ->patchJson("/api/v1/suivi/taches/{$tache->id}", ['actual_start' => '2026-05-04'])
            ->assertOk();

        // La hiérarchie peut corriger directement ; la référence initiale reste intacte.
        $this->actingAs($this->directeur)
            ->patchJson("/api/v1/suivi/taches/{$tache->id}", ['starts_on' => '2026-05-01', 'ends_on' => '2026-06-30'])
            ->assertOk();
        $tache->refresh();
        $this->assertSame('2026-06-30', $tache->ends_on->toDateString());
        $this->assertSame('2026-05-31', $tache->baseline_ends_on->toDateString());

        // Une tâche encore sans référence se planifie par son acteur (planification initiale).
        $libre = $this->activite->tasks()->orderBy('position')->skip(1)->firstOrFail();
        $libre->update(['baseline_starts_on' => null, 'baseline_ends_on' => null]);
        $this->actingAs($this->clarisse)
            ->patchJson("/api/v1/suivi/taches/{$libre->id}", ['starts_on' => '2026-07-01', 'ends_on' => '2026-07-31'])
            ->assertOk();
    }

    public function test_le_gantt_expose_synthese_droits_et_chemin_critique(): void
    {
        [$premiere, $suivante] = $this->activite->tasks()->orderBy('position')->take(2)->get()->all();
        $premiere->update(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'actual_start' => '2026-10-01', 'actual_end' => null, 'progress_percent' => 40, 'depends_on_id' => null]);
        $suivante->update(['starts_on' => '2026-11-01', 'ends_on' => '2026-12-31', 'actual_start' => null, 'actual_end' => null, 'depends_on_id' => $premiere->id]);

        $donnees = $this->actingAs($this->clarisse)->getJson("/api/v1/suivi/activites/{$this->activite->id}/gantt?echelle=semaines")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'origine', 'jours', 'colonnes', 'periodes', 'barre_activite' => ['prevue', 'projetee'],
                'synthese' => ['avancement', 'taches', 'taches_terminees', 'taches_en_retard', 'jalons', 'jalons_franchis', 'fin_prevue', 'fin_projetee', 'glissement'],
                'droits' => ['planifier', 'constater', 'proposer', 'valider_revision'],
            ]])
            ->assertJsonPath('data.droits.planifier', false)
            ->json('data');

        $this->assertGreaterThan(0, $donnees['jours']);
        $taches = collect($donnees['taches'])->keyBy('id');
        $this->assertTrue($taches[$suivante->id]['critique'], 'La tâche qui finit le plus tard est sur le chemin critique.');
        $this->assertTrue($taches[$premiere->id]['critique'], 'Sa tâche préalable aussi.');
        $this->assertSame($premiere->id, $taches[$suivante->id]['depend_de_id']);

        $this->actingAs($this->directeur)->getJson("/api/v1/suivi/activites/{$this->activite->id}/gantt")->assertJsonPath('data.droits.planifier', true);
    }

    public function test_une_activite_sans_tache_ni_date_renvoie_un_gantt_vide_exploitable(): void
    {
        $ligneLibre = BudgetLine::query()->whereDoesntHave('enrichment')->firstOrFail();
        $vide = PapEnrichment::query()->create([
            'budget_line_id' => $ligneLibre->id,
            'activite' => 'Activité sans planification',
        ]);

        $transverse = User::factory()->create(['role' => 'directeur_budget', 'password' => 'password']);
        $this->actingAs($transverse)->getJson("/api/v1/suivi/activites/{$vide->id}/gantt")
            ->assertOk()
            ->assertJsonPath('data.jours', 0)
            ->assertJsonPath('data.taches', [])
            ->assertJsonPath('data.synthese.taches', 0)
            ->assertJsonPath('data.activite.gar_noeud_id', null);
    }

    public function test_un_jalon_se_franchit_avec_une_preuve(): void
    {
        $jalon = $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/activites/{$this->activite->id}/jalons", ['label' => 'TDR validés', 'planned_on' => '2026-04-15'])->assertCreated()->json('data.id');
        $this->actingAs($this->clarisse)->getJson("/api/v1/suivi/activites/{$this->activite->id}/fiche")->assertJsonPath('data.jalons.0.statut', 'en_retard');
        $this->actingAs($this->clarisse)->patchJson("/api/v1/suivi/jalons/{$jalon}", ['achieved_on' => '2026-04-14'])->assertUnprocessable();
        $this->actingAs($this->clarisse)->patchJson("/api/v1/suivi/jalons/{$jalon}", ['achieved_on' => '2026-04-14', 'proof_label' => 'PV comité'])->assertOk();
        $this->actingAs($this->clarisse)->getJson("/api/v1/suivi/activites/{$this->activite->id}/fiche")
            ->assertJsonPath('data.jalons.0.statut', 'franchi')
            ->assertJsonPath('data.jalons.0.preuve', 'PV comité');
    }

    public function test_ecart_critique_alerte_explication_probleme_et_action_corrective(): void
    {
        $this->engager(80_000_000);
        $this->assertGreaterThanOrEqual(1, app(VarianceDossierService::class)->generateAlerts());
        $this->assertSame(0, app(VarianceDossierService::class)->generateAlerts());
        $ecart = PerformanceVariance::query()->where('pap_enrichment_id', $this->activite->id)->firstOrFail();
        $this->assertMatchesRegularExpression('/^EC-2026-\d{3}$/', $ecart->reference);
        $this->assertGreaterThan(0, $this->responsable->notifications()->count());
        $risque = SeRisk::query()->create([
            'reference' => 'RSK-2026-0044', 'pap_enrichment_id' => $this->activite->id, 'description' => 'Autorisations de déplacement',
            'category' => 'externe', 'probability' => 3, 'impact' => 3, 'responsible_role' => 'chef_service', 'status' => 'ouvert',
        ]);

        $this->actingAs($this->directeur)->postJson("/api/v1/suivi/ecarts/{$ecart->id}/relancer")->assertOk();
        $this->actingAs($this->directeur)->postJson("/api/v1/suivi/ecarts/{$ecart->id}/escalader")->assertOk();
        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/ecarts/{$ecart->id}/explication", ['texte' => 'Avance de démarrage', 'causes' => ['financiere']])
            ->assertUnprocessable()->assertJsonValidationErrors('action');
        $dossier = $this->actingAs($this->responsable)->postJson("/api/v1/suivi/ecarts/{$ecart->id}/explication", [
            'texte' => 'Avance de démarrage de 40 % versée ; missions reportées.',
            'interpretations' => ['avance', 'engagement_anticipe'],
            'causes' => ['financiere', 'administrative'],
        ])->assertOk()->json('data');
        $this->assertSame('financier_superieur', $dossier['sens']);
        $this->assertSame(['avance', 'engagement_anticipe'], $dossier['explication']['interpretations']);

        $this->actingAs($this->responsable)->postJson("/api/v1/suivi/ecarts/{$ecart->id}/probleme", [
            'nature' => 'Missions de terrain reportées', 'impact' => 'Rapport décalé de deux mois', 'occurred_on' => '2026-10-23', 'se_risk_id' => $risque->id,
        ])->assertCreated();
        $this->assertSame('survenu', $risque->fresh()->status);

        $dossier = $this->actingAs($this->directeur)->postJson("/api/v1/suivi/ecarts/{$ecart->id}/action-corrective", [
            'anomaly' => 'Écart physique / financier', 'cause' => 'Autorisations non obtenues',
            'description' => 'Basculer deux missions en entretiens à distance', 'responsible_label' => 'Directeur de la DAPPS',
            'decided_on' => '2026-10-24', 'due_on' => '2026-11-10',
        ])->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^PB-2026-\d{3}$/', $dossier['probleme']['reference']);
        $this->assertSame('RSK-2026-0044', $dossier['probleme']['risque']['reference']);
        $this->assertMatchesRegularExpression('/^AC-2026-\d{3}$/', $dossier['action']['reference']);
        $this->assertTrue($dossier['action']['en_retard']);
        $this->assertSame(6, $dossier['action']['jours_retard']);
        $this->assertSame(['fait', 'fait', 'fait', 'fait', 'fait', 'en_retard', 'a_venir'], array_column($dossier['chronologie'], 'etat'));
        $this->assertSame(80_000_000, $dossier['finances']['engage']);
        $this->assertGreaterThanOrEqual(3, count($dossier['notifications']));
        $this->assertStringStartsWith('Relance', $dossier['notifications'][1]['message']);

        $this->actingAs($this->etranger)->getJson("/api/v1/suivi/ecarts/{$ecart->id}/dossier")->assertForbidden();
    }

    public function test_synthese_matrice_des_risques_et_decisions(): void
    {
        SeRisk::query()->create([
            'reference' => 'RSK-2026-0001', 'pap_enrichment_id' => $this->activite->id, 'description' => 'Risque majeur',
            'category' => 'technique', 'probability' => 4, 'impact' => 3, 'responsible_role' => 'directeur', 'status' => 'ouvert',
        ]);

        $synthese = $this->actingAs($this->directeur)->getJson('/api/v1/suivi/synthese-executive')->assertOk()->json('data');
        $this->assertSame('jour', $synthese['situation']['type']);
        $tresProbable = collect($synthese['risques']['lignes'])->firstWhere('probabilite', 'Très probable');
        $this->assertSame(1, $tresProbable['cases'][2]['nombre']);
        $this->assertSame('critique', $tresProbable['cases'][2]['niveau']);
        $this->assertSame(1, $synthese['kpis']['risques_critiques']);

        $decision = $this->actingAs($this->directeur)->postJson('/api/v1/suivi/decisions', [
            'description' => 'Arbitrer le report de la mise en service', 'responsible_label' => 'Commissaire DATI',
            'due_on' => '2026-12-15', 'priority' => 'haute',
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->directeur)->getJson('/api/v1/suivi/synthese-executive')->assertJsonPath('data.decisions.attendues', 1);
        $this->actingAs($this->directeur)->postJson("/api/v1/suivi/decisions/{$decision}/decider")->assertUnprocessable();
        $commissaire = User::factory()->create(['role' => 'commissaire', 'password' => 'password']);
        $this->actingAs($commissaire)->postJson("/api/v1/suivi/decisions/{$decision}/ajourner")->assertUnprocessable();
        $this->actingAs($commissaire)->postJson("/api/v1/suivi/decisions/{$decision}/decider", ['note' => 'Report validé'])->assertOk()->assertJsonPath('data.status', 'decidee');

        $apresDecision = $this->actingAs($this->directeur)->getJson('/api/v1/suivi/synthese-executive')->assertOk();
        $apresDecision->assertJsonPath('data.decisions.attendues', 0)->assertJsonPath('data.decisions.peut_decider', false);
        $prise = collect($apresDecision->json('data.decisions.lignes'))->firstWhere('id', $decision);
        $this->assertSame('decidee', $prise['statut']);
        $this->assertSame('Report validé', $prise['note']);

        $this->actingAs($commissaire)->postJson("/api/v1/suivi/decisions/{$decision}/mettre_en_oeuvre")->assertOk()->assertJsonPath('data.status', 'mise_en_oeuvre');
        $suivie = collect($this->actingAs($commissaire)->getJson('/api/v1/suivi/synthese-executive')->json('data.decisions.lignes'))->firstWhere('id', $decision);
        $this->assertSame('mise_en_oeuvre', $suivie['statut']);
        $this->assertTrue($this->actingAs($commissaire)->getJson('/api/v1/suivi/synthese-executive')->json('data.decisions.peut_decider'));
    }

    public function test_la_synthese_figee_relit_le_rapport_publie(): void
    {
        $budget = User::factory()->create(['role' => 'directeur_budget', 'password' => 'password']);
        $id = $this->actingAs($this->directeur)->postJson('/api/v1/suivi/rapports-performance', ['kind' => 'trimestriel'])->assertCreated()->json('data.id');
        $this->actingAs($this->directeur)->postJson("/api/v1/suivi/rapports-performance/{$id}/soumettre")->assertOk();
        $this->actingAs($budget)->postJson("/api/v1/suivi/rapports-performance/{$id}/valider")->assertOk();
        $this->actingAs($budget)->postJson("/api/v1/suivi/rapports-performance/{$id}/publier")->assertOk();
        $this->engager(80_000_000);

        $jour = $this->actingAs($this->directeur)->getJson('/api/v1/suivi/synthese-executive')->json('data');
        $figee = $this->actingAs($this->directeur)->getJson("/api/v1/suivi/synthese-executive?situation={$id}")->assertOk()->json('data');

        $this->assertSame('figee', $figee['situation']['type']);
        $this->assertEquals(0, $figee['kpis']['financier']);
        $this->assertEquals(80, $jour['kpis']['financier']);
        $this->assertSame($id, $jour['situations_figees'][0]['rapport_id']);
    }

    public function test_saisie_ratio_apercu_brouillon_et_circuit_a_quatre_niveaux(): void
    {
        $indicateur = Indicator::query()->create([
            'pap_enrichment_id' => $this->activite->id, 'code' => 'IND-DENER-01', 'label' => 'Projets suivis électroniquement',
            'type' => 'produit', 'direction' => 'croissant', 'unit' => '%', 'frequency' => 'trimestrielle',
            'numerator_label' => 'Projets suivis', 'denominator_label' => 'Projets actifs', 'baseline_value' => 0, 'baseline_on' => '2025-12-31',
        ]);
        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/indicateurs/{$indicateur->id}/cibles", ['monitoring_period_id' => $this->periode->id, 'value' => 60])->assertCreated();

        $contexte = $this->actingAs($this->clarisse)->getJson("/api/v1/suivi/indicateurs/{$indicateur->id}/saisie")->assertOk()->json('data');
        $this->assertSame('2026-T4', $contexte['periode']['code']);
        $this->assertSame(45, $contexte['periode']['jours']);
        $this->assertTrue($contexte['indicateur']['ratio']);
        $this->assertSame(['Saisie', 'Validation responsable', 'Validation hiérarchique', 'Consolidation'], array_column($contexte['circuit'], 'etape'));

        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/indicateurs/{$indicateur->id}/apercu", [
            'monitoring_period_id' => $this->periode->id, 'numerator' => 5, 'denominator' => 20,
        ])->assertOk()
            ->assertJsonPath('data.valeur', 25)
            ->assertJsonPath('data.formule', '5 / 20 × 100')
            ->assertJsonPath('data.taux', 41.67)
            ->assertJsonPath('data.statut', 'en_retard');

        $mesure = $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/mesures', [
            'indicator_id' => $indicateur->id, 'monitoring_period_id' => $this->periode->id, 'numerator' => 4, 'denominator' => 20,
        ])->assertCreated()->assertJsonPath('data.value', 20)->json('data.id');
        $this->actingAs($this->clarisse)->patchJson("/api/v1/suivi/mesures/{$mesure}", ['numerator' => 5, 'denominator' => 20, 'justification' => 'Formation décalée'])
            ->assertOk()->assertJsonPath('data.value', 25);
        $this->actingAs($this->directeur)->patchJson("/api/v1/suivi/mesures/{$mesure}", ['numerator' => 9, 'denominator' => 20])->assertUnprocessable();

        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/mesures/{$mesure}/soumettre")->assertOk();
        $this->preuve('mesure', $mesure);
        $this->actingAs($this->directeur)->postJson("/api/v1/suivi/mesures/{$mesure}/valider")->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->actingAs($this->responsable)->postJson("/api/v1/suivi/mesures/{$mesure}/valider")->assertOk()->assertJsonPath('data.status', 'valide_responsable');
        $this->actingAs($this->responsable)->postJson("/api/v1/suivi/mesures/{$mesure}/valider")->assertUnprocessable();
        $this->actingAs($this->directeur)->postJson("/api/v1/suivi/mesures/{$mesure}/valider")->assertOk()->assertJsonPath('data.status', 'valide');
        $se = User::factory()->create(['role' => 'responsable_se', 'password' => 'password']);
        $this->actingAs($se)->postJson("/api/v1/suivi/mesures/{$mesure}/consolider")->assertOk()->assertJsonPath('data.status', 'consolide');

        $reponse = $this->actingAs($this->clarisse)->getJson("/api/v1/suivi/indicateurs/{$indicateur->id}/saisie")->assertOk();
        $etats = array_column($reponse->json('data.circuit'), 'etat');
        $this->assertSame(['fait', 'fait', 'fait', 'fait'], $etats);
        $controles = collect($reponse->json('data.controles'))->pluck('ok', 'code');
        $this->assertTrue($controles['valeur'] && $controles['plage'] && $controles['doublon'] && $controles['preuve']);
        $this->assertFalse($controles['source']);
        $reponse->assertJsonPath('data.historique.0.valeur', 25)->assertJsonPath('data.historique.0.statut', 'consolide');
    }

    private function realiser(int $tacheId, float $quantite, float $prevu): void
    {
        $id = $this->actingAs($this->clarisse)->postJson('/api/v1/suivi/realisations', [
            'pap_enrichment_id' => $this->activite->id,
            'pap_task_id' => $tacheId,
            'monitoring_period_id' => $this->periode->id,
            'method' => 'quantitative',
            'quantity' => $quantite,
            'planned' => $prevu,
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->clarisse)->postJson("/api/v1/suivi/realisations/{$id}/soumettre")->assertOk();
        $this->preuve('realisation', $id);
        $this->actingAs($this->responsable)->postJson("/api/v1/suivi/realisations/{$id}/valider")->assertOk();
        $this->actingAs($this->directeur)->postJson("/api/v1/suivi/realisations/{$id}/valider")->assertOk();
    }

    private function preuve(string $type, int $id): void
    {
        $this->actingAs($this->clarisse)->post('/api/v1/suivi/preuves', [
            'type' => $type,
            'id' => $id,
            'category' => 'preuve',
            'fichier' => UploadedFile::fake()->create('preuve.pdf', 10, 'application/pdf'),
        ])->assertCreated();
    }

    public function test_le_taux_des_activites_suivies_n_est_pas_dilue_par_le_pap_inactif(): void
    {
        $this->engager(80_000_000);
        $inactive = $this->ligne->replicate();
        $inactive->code = '209999';
        $inactive->montant_vote = 900_000_000;
        $inactive->officiel = false;
        $inactive->save();
        $activity = $this->activite->replicate();
        $activity->code = 'ACT-209999';
        $activity->budget_line_id = $inactive->id;
        $activity->save();

        $board = $this->actingAs($this->directeur)->getJson('/api/v1/suivi/pilotage')->assertOk()->json('data');

        $this->assertSame(2, $board['bandeau']['activites']);
        $this->assertEquals(8.0, $board['execution']['engage']);
        $this->assertSame(1, $board['execution_suivie']['activites']);
        $this->assertEquals(80.0, $board['execution_suivie']['engage']);
        $this->assertEquals(80.0, end($board['evolution'])['engage_suivi']);
    }

    private function engager(int $montant): void
    {
        $besoin = ExpressionBesoin::query()->create([
            'reference' => 'EB/2026/DATI/000999',
            'exercice_id' => $this->ligne->exercice_id,
            'organization_unit_id' => $this->ligne->organization_unit_id,
            'initiator_id' => $this->clarisse->id,
            'budget_line_id' => $this->ligne->id,
            'nature' => $this->ligne->nature,
            'objet' => 'Contrat d’évaluation',
            'status' => EbStatus::Transformee,
            'workflow_step' => 'clos',
            'montant' => $montant,
        ]);
        Engagement::query()->create([
            'reference' => 'ENG-SE-000999',
            'expression_besoin_id' => $besoin->id,
            'budget_line_id' => $this->ligne->id,
            'montant' => $montant,
            'status' => EngagementStatus::TransformeLiquidation,
            'visa_reference' => 'VISA-SE-000999',
        ]);
    }
}
