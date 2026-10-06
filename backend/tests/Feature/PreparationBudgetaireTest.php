<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetCampaign;
use App\Domains\Budget\Models\BudgetDossier;
use App\Domains\Budget\Models\BudgetDossierLine;
use App\Domains\Budget\Models\BudgetHypothesis;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Database\Seeders\EngagementSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreparationBudgetaireTest extends TestCase
{
    use RefreshDatabase;

    private User $expert;

    private User $directeur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->seed(EngagementSeeder::class);
        $this->expert = User::query()->where('email', 'blaise.essono@ceeac.int')->firstOrFail();
        $this->directeur = User::query()->where('email', 'directeur.budget@ceeac.int')->firstOrFail();
    }

    public function test_le_cycle_enregistre_les_montants_et_transmet_une_seule_fois(): void
    {
        $this->actingAs($this->expert)->postJson('/api/v1/preparation/ouvrir')->assertCreated();
        $exercice = Exercice::query()->where('statut', 'preparation')->firstOrFail();
        $structure = OrganizationUnit::query()->where('is_technical', false)->firstOrFail();

        $campagne = $this->actingAs($this->expert)->postJson('/api/v1/preparation/campagnes', [
            'code' => 'CAMP-TEST',
            'label' => 'Campagne de test',
            'exercice_id' => $exercice->id,
            'description' => 'Cadrage',
            'date_ouverture' => $exercice->annee.'-01-01',
            'date_cloture' => $exercice->annee.'-03-31',
            'structures' => [$structure->id],
            'responsable_id' => $this->expert->id,
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->expert)->postJson('/api/v1/preparation/campagnes/'.$campagne.'/etapes', [
            'ordre' => 1,
            'label' => 'Collecte',
            'echeance' => $exercice->annee.'-02-15',
        ])->assertCreated();

        $hypothese = $this->actingAs($this->expert)->postJson('/api/v1/preparation/campagnes/'.$campagne.'/hypotheses', [
            'code' => 'INFL',
            'label' => 'Inflation',
            'categorie' => 'macro',
            'valeur' => '3',
            'unite' => '%',
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->expert)->postJson('/api/v1/preparation/hypotheses/'.$hypothese.'/publier')->assertOk();
        $this->actingAs($this->expert)->patchJson('/api/v1/preparation/hypotheses/'.$hypothese, [
            'code' => 'INFL',
            'label' => 'Inflation',
            'categorie' => 'macro',
            'valeur' => '4',
            'unite' => '%',
        ])->assertOk();
        $this->assertSame('3', BudgetHypothesis::query()->find($hypothese)->valeur);
        $this->assertSame(2, BudgetHypothesis::query()->where('code', 'INFL')->count());

        $this->actingAs($this->expert)->postJson('/api/v1/preparation/campagnes/'.$campagne.'/enveloppes', [
            'organization_unit_id' => $structure->id,
            'classification' => 'fonctionnement',
            'montant' => 500000,
            'statut' => 'actif',
        ])->assertCreated();

        $this->actingAs($this->expert)->postJson('/api/v1/preparation/campagnes/'.$campagne.'/ouvrir')->assertOk();

        $dossier = $this->actingAs($this->expert)->postJson('/api/v1/preparation/dossiers', [
            'campaign_id' => $campagne,
            'organization_unit_id' => $structure->id,
            'titre' => 'Fonctionnement courant',
            'justification' => 'Besoins de service',
        ])->assertCreated()->json('data.id');

        $ligne = $this->actingAs($this->expert)->postJson('/api/v1/preparation/dossiers/'.$dossier.'/lignes', [
            'classification' => 'fonctionnement',
            'code' => '999901',
            'label' => 'Fournitures',
            'quantite' => 2,
            'cout_unitaire' => 100000,
            'unite' => 'lot',
        ])->assertCreated();
        $this->assertSame(200000, $ligne->json('data.montant'));
        $ligneId = $ligne->json('data.id');

        $this->actingAs($this->expert)->postJson('/api/v1/preparation/lignes/'.$ligneId.'/details', [
            'designation' => 'Ramettes',
            'quantite' => 3,
            'cout_unitaire' => 50000,
        ])->assertCreated();
        $this->assertSame(150000, (int) BudgetDossierLine::query()->find($ligneId)->montant);

        $this->actingAs($this->expert)->postJson('/api/v1/preparation/lignes/'.$ligneId.'/periodes', [
            'periode' => 'T1',
            'montant' => 150000,
        ])->assertCreated();
        $this->actingAs($this->expert)->postJson('/api/v1/preparation/lignes/'.$ligneId.'/periodes', [
            'periode' => 'T2',
            'montant' => 1,
        ])->assertStatus(422);

        $this->actingAs($this->expert)->postJson('/api/v1/preparation/dossiers/'.$dossier.'/soumettre')->assertOk();
        $this->actingAs($this->expert)->patchJson('/api/v1/preparation/dossiers/'.$dossier, [
            'titre' => 'Interdit',
        ])->assertStatus(422);

        $this->actingAs($this->directeur)->postJson('/api/v1/preparation/arbitrages', [
            'dossier_id' => $dossier,
            'line_id' => $ligneId,
            'montant_retenu' => 120000,
            'decision' => 'retenu',
            'motif' => 'Ajustement au plafond',
        ])->assertCreated();
        $this->assertSame(120000, (int) BudgetDossierLine::query()->find($ligneId)->montant_retenu);
        $this->assertSame(150000, (int) BudgetDossierLine::query()->find($ligneId)->montant);

        $version = $this->actingAs($this->expert)->postJson('/api/v1/preparation/campagnes/'.$campagne.'/versions', [
            'libelle' => 'Projet v1',
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->expert)->postJson('/api/v1/preparation/versions/'.$version.'/soumettre')->assertOk();
        $this->actingAs($this->expert)->postJson('/api/v1/preparation/versions/'.$version.'/valider')->assertStatus(422);
        $this->actingAs($this->directeur)->postJson('/api/v1/preparation/versions/'.$version.'/valider')->assertOk();
        $this->actingAs($this->expert)->postJson('/api/v1/preparation/versions/'.$version.'/adopter')->assertStatus(422);
        $this->actingAs($this->directeur)->postJson('/api/v1/preparation/versions/'.$version.'/adopter')->assertOk();
        $this->actingAs($this->directeur)->postJson('/api/v1/preparation/versions/'.$version.'/adopter')->assertOk();

        $this->assertSame(1, BudgetLine::query()->where('exercice_id', $exercice->id)->where('code', '999901')->count());
        $this->assertSame(120000, (int) BudgetLine::query()->where('code', '999901')->value('montant_vote'));
        $this->assertSame('executoire', $exercice->fresh()->statut);
        $this->actingAs($this->directeur)->patchJson('/api/v1/preparation/campagnes/'.$campagne, [
            'code' => 'CAMP-TEST',
            'label' => 'Campagne de test',
            'exercice_id' => $exercice->id,
        ])->assertStatus(422);

        $this->assertDatabaseHas('workflow_tasks', [
            'entity_type' => 'budget_dossier',
            'entity_id' => $dossier,
            'status' => 'terminee',
        ]);
    }

    public function test_un_visiteur_sans_role_ne_lit_pas_les_campagnes(): void
    {
        $visiteur = User::factory()->create(['role' => '']);
        $this->actingAs($visiteur)->getJson('/api/v1/preparation/campagnes')->assertStatus(422);
    }

    public function test_le_plafond_bloque_le_depassement_et_le_brouillon_se_supprime(): void
    {
        $this->actingAs($this->expert)->postJson('/api/v1/preparation/ouvrir')->assertCreated();
        $exercice = Exercice::query()->where('statut', 'preparation')->firstOrFail();
        $structure = OrganizationUnit::query()->where('is_technical', false)->firstOrFail();
        $campagne = $this->actingAs($this->expert)->postJson('/api/v1/preparation/campagnes', [
            'code' => 'CAMP-PLAF',
            'label' => 'Plafonds',
            'exercice_id' => $exercice->id,
            'date_ouverture' => $exercice->annee.'-01-01',
            'date_cloture' => $exercice->annee.'-06-30',
            'structures' => [$structure->id],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->expert)->postJson('/api/v1/preparation/campagnes/'.$campagne.'/enveloppes', [
            'organization_unit_id' => $structure->id,
            'classification' => 'fonctionnement',
            'montant' => 10000,
            'statut' => 'actif',
        ])->assertCreated();
        $this->actingAs($this->expert)->postJson('/api/v1/preparation/campagnes/'.$campagne.'/ouvrir')->assertOk();
        $dossier = $this->actingAs($this->expert)->postJson('/api/v1/preparation/dossiers', [
            'campaign_id' => $campagne,
            'organization_unit_id' => $structure->id,
            'titre' => 'Trop élevé',
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->expert)->postJson('/api/v1/preparation/dossiers/'.$dossier.'/lignes', [
            'classification' => 'fonctionnement',
            'code' => '888801',
            'label' => 'Dépassement',
            'quantite' => 1,
            'cout_unitaire' => 20000,
        ])->assertStatus(422);

        $this->actingAs($this->expert)->deleteJson('/api/v1/preparation/dossiers/'.$dossier)->assertOk();
        $this->assertNull(BudgetDossier::query()->find($dossier));

        $this->actingAs($this->expert)->deleteJson('/api/v1/preparation/campagnes/'.$campagne)->assertStatus(422);
        $this->assertNotNull(BudgetCampaign::query()->find($campagne));
    }
}
