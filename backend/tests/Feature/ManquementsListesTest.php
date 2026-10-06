<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\AnnualClose;
use App\Domains\Budget\Models\BudgetCampaign;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Monitoring\Models\MonitoringPeriod;
use App\Domains\Monitoring\Services\MonitoringService;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\PAP\Models\PapTask;
use App\Domains\Suppliers\Models\Tiers;
use App\Models\User;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ManquementsListesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        User::factory()->create(['role' => 'directeur_budget', 'email' => 'directeur.test@ceeac.int']);
        User::factory()->create(['role' => 'expert_budget', 'email' => 'expert.test@ceeac.int']);
    }

    public function test_une_periode_de_collecte_peut_etre_consolidee(): void
    {
        $period = MonitoringPeriod::query()->firstOrFail();
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();
        $expert = User::query()->where('role', 'expert_budget')->firstOrFail();

        $this->actingAs($expert)
            ->getJson('/api/v1/suivi/periodes')
            ->assertOk()
            ->assertJsonPath('data.0.peut_consolider', false);

        $ouvertes = $this->actingAs($directeur)->getJson('/api/v1/suivi/periodes')->assertOk()->json('data');
        $this->assertTrue(collect($ouvertes)->firstWhere('id', $period->id)['peut_consolider']);

        $this->actingAs($directeur)
            ->postJson('/api/v1/suivi/periodes/'.$period->id.'/consolider')
            ->assertOk()
            ->assertJsonPath('data.statut', 'consolidee');

        $closes = $this->actingAs($directeur)->getJson('/api/v1/suivi/periodes')->assertOk()->json('data');
        $this->assertFalse(collect($closes)->firstWhere('id', $period->id)['peut_consolider']);
    }

    public function test_un_indicateur_sans_mesure_est_relance_a_j7(): void
    {
        $period = MonitoringPeriod::query()->firstOrFail();
        $period->forceFill(['status' => 'ouverte', 'closes_on' => today()->addDays(7)])->save();
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();
        Indicator::query()->create([
            'code' => 'IND-TEST-J7',
            'label' => 'Indicateur à renseigner',
            'type' => 'quantitatif',
            'direction' => 'croissant',
            'status' => 'actif',
            'responsible_user_id' => $directeur->id,
        ]);

        $this->artisan('suivi:relances')->assertSuccessful();

        $this->assertDatabaseHas('indicator_collection_reminders', [
            'monitoring_period_id' => $period->id,
            'horizon' => 7,
        ]);
    }

    public function test_un_retard_decale_les_taches_dependantes(): void
    {
        $line = BudgetLine::query()->whereDoesntHave('enrichment')->firstOrFail();
        $activity = PapEnrichment::query()->create(['budget_line_id' => $line->id, 'status' => 'a_completer']);
        $parent = PapTask::query()->create([
            'pap_enrichment_id' => $activity->id,
            'label' => 'Cadrage',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-01-10',
        ]);
        $child = PapTask::query()->create([
            'pap_enrichment_id' => $activity->id,
            'label' => 'Atelier',
            'starts_on' => '2026-01-11',
            'ends_on' => '2026-01-20',
            'depends_on_id' => $parent->id,
        ]);
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();

        app(MonitoringService::class)->schedule($directeur, $parent, ['actual_end' => '2026-01-15']);

        $child->refresh();
        $this->assertSame('2026-01-16', $child->starts_on->toDateString());
        $this->assertSame('2026-01-25', $child->ends_on->toDateString());
    }

    public function test_l_import_bloque_une_ligne_officielle(): void
    {
        $line = BudgetLine::query()->firstOrFail();
        $vote = (int) $line->montant_vote;
        $line->forceFill(['officiel' => true])->save();
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();

        $this->getJson('/api/v1/imports/preparation')->assertUnauthorized();

        $this->actingAs($directeur)
            ->postJson('/api/v1/imports/preparation', [
                'fichier' => 'essai.csv',
                'contenu' => "code,montant\n{$line->code},999\nINCONNU-999,1000",
            ])->assertCreated()
            ->assertJsonPath('data.lignes.0.verdict', 'bloque')
            ->assertJsonPath('data.lignes.0.code', $line->code)
            ->assertJsonPath('data.lignes.1.verdict', 'en_attente');

        $lots = $this->actingAs($directeur)->getJson('/api/v1/imports/preparation')->assertOk()->json('data');
        $this->assertSame($line->code, $lots[0]['lignes'][0]['code']);
        $this->assertSame('bloque', $lots[0]['lignes'][0]['verdict']);
        $this->assertSame('en_attente', $lots[0]['lignes'][1]['verdict']);
        $this->assertSame(0, BudgetLine::query()->where('code', 'INCONNU-999')->count());
        $this->assertSame($vote, (int) $line->fresh()->montant_vote);

        $expert = User::query()->where('role', 'expert_budget')->firstOrFail();
        $this->actingAs($expert)->getJson('/api/v1/imports/preparation')->assertUnprocessable();
    }

    public function test_une_anomalie_ne_reecrit_pas_la_ligne(): void
    {
        $line = BudgetLine::query()->firstOrFail();
        $vote = (int) $line->montant_vote;
        $line->forceFill(['officiel' => false])->save();
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();

        $this->actingAs($directeur)
            ->getJson('/api/v1/controles/anomalies')
            ->assertOk()
            ->assertJsonFragment(['code' => 'LIGNES-HORS-IMPORT']);

        $this->assertSame($vote, (int) $line->fresh()->montant_vote);
    }

    public function test_la_revue_des_acces_se_confirme_sans_imposer_le_mfa(): void
    {
        $admin = User::factory()->create(['role' => 'administrateur_fonctionnel', 'email' => 'revue.acces@ceeac.int']);

        $this->getJson('/api/v1/habilitations/revues')->assertUnauthorized();
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();
        $this->actingAs($directeur)->getJson('/api/v1/habilitations/revues')->assertUnprocessable();

        $this->actingAs($admin)->getJson('/api/v1/habilitations/revues')->assertOk()->assertJsonPath('data.reference', null);

        $reference = $this->actingAs($admin)
            ->postJson('/api/v1/habilitations/revues')
            ->assertCreated()
            ->json('data.reference');
        $this->actingAs($admin)->postJson('/api/v1/habilitations/revues')->assertUnprocessable();

        $ouverte = $this->actingAs($admin)->getJson('/api/v1/habilitations/revues')->assertOk()->json('data');
        $this->assertSame($reference, $ouverte['reference']);
        $this->assertTrue($ouverte['peut_confirmer']);
        $this->assertSame('en_attente', collect($ouverte['lignes'])->firstWhere('id', $admin->id)['decision']);

        $this->actingAs($admin)
            ->postJson('/api/v1/habilitations/revues/'.$reference.'/confirmer')
            ->assertOk()
            ->assertJsonPath('data.decision', 'confirme');
        $this->assertFalse((bool) $admin->fresh()->mfa_required);

        $suivie = $this->actingAs($admin)->getJson('/api/v1/habilitations/revues')->assertOk()->json('data');
        $this->assertFalse($suivie['peut_confirmer']);
        $this->assertSame('confirme', collect($suivie['lignes'])->firstWhere('id', $admin->id)['decision']);
    }

    public function test_l_instantane_d_execution_est_ecrit(): void
    {
        Storage::fake('local');

        $this->artisan('rapports:execution')->assertSuccessful();

        $this->assertNotEmpty(Storage::disk('local')->allFiles('rapports'));

        $this->getJson('/api/v1/rapports/execution')->assertUnauthorized();

        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();
        $this->actingAs($directeur)
            ->getJson('/api/v1/rapports/execution')
            ->assertOk()
            ->assertJsonPath('data.0.volumes.expressions', DB::table('expression_besoins')->count());
    }

    public function test_l_archivage_ne_clot_pas_un_exercice_executoire(): void
    {
        $exercice = Exercice::query()->where('statut', 'executoire')->firstOrFail();
        $secretaire = User::query()->where('role', 'secretaire_general')->firstOrFail();

        $this->actingAs($secretaire)
            ->postJson('/api/v1/cloture/'.$exercice->id.'/archiver', ['reference' => 'ARCH-TEST'])
            ->assertStatus(422);

        $this->assertSame('executoire', $exercice->fresh()->statut);

        $clos = Exercice::query()->create([
            'annee' => 2024,
            'statut' => 'clos',
            'date_debut' => '2024-01-01',
            'date_fin' => '2024-12-31',
        ]);
        AnnualClose::query()->create([
            'exercice_id' => $clos->id,
            'statut' => 'clos',
            'motif' => 'Clôture de test',
            'demandeur_id' => User::query()->where('role', 'directeur_budget')->value('id'),
            'validateur_id' => $secretaire->id,
        ]);

        $avant = collect($this->actingAs($secretaire)->getJson('/api/v1/cloture')->assertOk()->json('exercices'));
        $this->assertFalse($avant->firstWhere('id', $exercice->id)['peut_archiver']);
        $this->assertTrue($avant->firstWhere('id', $clos->id)['peut_archiver']);

        $this->actingAs($secretaire)
            ->postJson('/api/v1/cloture/'.$clos->id.'/archiver', ['reference' => 'ARCH-2024'])
            ->assertOk()
            ->assertJsonPath('data.archive', 'ARCH-2024')
            ->assertJsonPath('data.statut_exercice', 'clos');
        $this->assertSame('executoire', $exercice->fresh()->statut);
    }

    public function test_deux_tiers_se_fusionnent_et_une_piece_de_conformite_est_datee(): void
    {
        $expert = User::query()->where('role', 'expert_budget')->firstOrFail();
        $gestionnaire = User::factory()->create(['role' => 'agent_comptable', 'email' => 'tiers.gestion@ceeac.int']);
        $cible = Tiers::query()->create([
            'code' => 'TIE-CIBLE',
            'type' => 'fournisseur',
            'raison_sociale' => 'Cible',
            'nom_normalise' => 'cible',
            'status' => 'actif',
        ]);
        $doublon = Tiers::query()->create([
            'code' => 'TIE-DOUBLON',
            'type' => 'fournisseur',
            'raison_sociale' => 'Doublon',
            'nom_normalise' => 'doublon',
            'status' => 'actif',
        ]);

        $this->actingAs($expert)
            ->postJson('/api/v1/tiers/'.$cible->id.'/conformite', [
                'kind' => 'attestation fiscale',
                'reference' => 'ATTEST-TEST',
                'expires_on' => '2026-12-31',
            ])->assertCreated();
        $this->actingAs($expert)
            ->postJson('/api/v1/tiers/'.$doublon->id.'/conformite', [
                'kind' => 'attestation fiscale',
                'reference' => 'ATTEST-EXPIREE',
                'expires_on' => '2020-01-01',
            ])->assertCreated();

        $avant = $this->actingAs($gestionnaire)->getJson('/api/v1/tiers/'.$doublon->id)->assertOk()->json('data');
        $this->assertTrue($avant['conformites'][0]['expiree']);
        $this->assertTrue(collect($avant['cibles'])->contains(fn (array $row): bool => $row['id'] === $cible->id));
        $this->assertFalse(collect($avant['cibles'])->contains(fn (array $row): bool => $row['id'] === $doublon->id));

        $this->actingAs($gestionnaire)
            ->postJson('/api/v1/tiers/'.$doublon->id.'/fusionner', ['cible_id' => $cible->id])
            ->assertOk();

        $this->assertSame('archive', $doublon->fresh()->status);
        $this->assertSame($cible->id, $doublon->fresh()->merged_into_id);
        $this->assertDatabaseHas('tiers_compliance_documents', [
            'tiers_id' => $cible->id,
            'reference' => 'ATTEST-TEST',
        ]);
        $apres = $this->actingAs($gestionnaire)->getJson('/api/v1/tiers/'.$cible->id)->assertOk()->json('data');
        $this->assertFalse(collect($apres['conformites'])->firstWhere('reference', 'ATTEST-TEST')['expiree']);
        $this->assertTrue(collect($apres['conformites'])->firstWhere('reference', 'ATTEST-EXPIREE')['expiree']);
        $this->assertFalse(collect($apres['cibles'])->contains(fn (array $row): bool => $row['id'] === $doublon->id));
    }

    public function test_la_recherche_documentaire_repond(): void
    {
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();

        $this->actingAs($directeur)
            ->getJson('/api/v1/documents/recherche?q=visa')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_une_campagne_empeche_le_second_chemin_d_adoption(): void
    {
        $exercice = Exercice::query()->create([
            'annee' => 2027,
            'statut' => 'preparation',
            'date_debut' => '2027-01-01',
            'date_fin' => '2027-12-31',
        ]);
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();
        BudgetCampaign::query()->create([
            'code' => 'CAMP-TEST',
            'label' => 'Campagne de test',
            'exercice_id' => $exercice->id,
            'author_id' => $directeur->id,
        ]);

        $this->actingAs($directeur)
            ->postJson('/api/v1/preparation/'.$exercice->id.'/adopter')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['exercice']);
        $this->assertSame('preparation', $exercice->fresh()->statut);
    }
}
