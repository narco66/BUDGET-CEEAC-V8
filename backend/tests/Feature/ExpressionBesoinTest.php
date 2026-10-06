<?php

namespace Tests\Feature;

use App\Domains\Budget\Enums\BudgetNature;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Needs\Services\ExpressionBesoinFichePresenter;
use App\Domains\Needs\Services\ExpressionBesoinWorkflow;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use App\Shared\Documents\DocumentVerification;
use App\Shared\Documents\GeneratedDocument;
use App\Shared\Documents\QrCode;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpressionBesoinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
    }

    public function test_la_liste_affiche_les_dossiers_et_le_tableau_de_bord(): void
    {
        $clarisse = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();

        $response = $this->actingAs($clarisse)
            ->getJson('/api/v1/expressions-besoin');

        $response->assertOk()
            ->assertJsonPath('tableau_de_bord.total', 8)
            ->assertJsonFragment(['reference' => 'EB/2026/DATI/000127']);
    }

    public function test_la_soumission_exige_une_justification_et_une_piece(): void
    {
        $clarisse = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $line = BudgetLine::query()->where('code', '203232')->firstOrFail();

        $created = $this->actingAs($clarisse)
            ->postJson('/api/v1/expressions-besoin', ['budget_line_id' => $line->id])
            ->assertCreated();

        $id = $created->json('data.id');

        $this->actingAs($clarisse)
            ->postJson("/api/v1/expressions-besoin/{$id}/soumettre")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['objet', 'justification', 'lignes', 'documents']);
    }

    public function test_le_retour_exige_un_motif_et_conserve_le_dossier(): void
    {
        $serge = User::query()->where('email', 'serge.mabika@ceeac.int')->firstOrFail();
        $eb = ExpressionBesoin::query()->where('reference', 'EB/2026/DATI/000127')->firstOrFail();

        $this->actingAs($serge)
            ->postJson("/api/v1/expressions-besoin/{$eb->id}/retourner", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['motif', 'observations']);

        $this->actingAs($serge)
            ->postJson("/api/v1/expressions-besoin/{$eb->id}/retourner", [
                'motif' => 'Justification insuffisante des coûts',
                'observations' => 'Aligner le tarif journalier sur la grille CEEAC.',
                'champs' => ['Description du besoin — justification'],
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', 'retournee');

        $this->assertDatabaseHas('eb_events', [
            'expression_besoin_id' => $eb->id,
            'action' => 'retour',
        ]);
    }

    public function test_un_credit_insuffisant_bloque_la_soumission(): void
    {
        $clarisse = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $line = BudgetLine::query()->where('code', '203232')->firstOrFail();

        $created = $this->actingAs($clarisse)
            ->postJson('/api/v1/expressions-besoin', ['budget_line_id' => $line->id])
            ->assertCreated();

        $id = $created->json('data.id');

        $this->actingAs($clarisse)
            ->patchJson("/api/v1/expressions-besoin/{$id}", [
                'objet' => 'Besoin excédant le crédit',
                'justification' => 'Test de disponibilité.',
                'lignes' => [[
                    'designation' => 'Forfait',
                    'quantite' => 1,
                    'unite' => 'forfait',
                    'prix_unitaire' => 900_000_000,
                ]],
            ])
            ->assertOk();

        $this->actingAs($clarisse)
            ->post('/api/v1/expressions-besoin/'.$id.'/documents', [
                'type' => 'Devis',
                'fichier' => UploadedFile::fake()->create('devis.pdf', 20, 'application/pdf'),
            ])
            ->assertOk();

        $this->actingAs($clarisse)
            ->postJson("/api/v1/expressions-besoin/{$id}/soumettre")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credit']);
    }

    public function test_un_perimetre_restreint_la_liste_des_besoins(): void
    {
        $clarisse = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $visible = ExpressionBesoin::query()->firstOrFail();
        $hidden = ExpressionBesoin::query()->where('organization_unit_id', '!=', $visible->organization_unit_id)->first();
        if ($hidden === null) {
            $this->markTestSkipped('Les besoins semés appartiennent à une seule structure.');
        }
        DB::table('access_scopes')->insert([
            'user_id' => $clarisse->id,
            'scope_type' => 'organization_unit',
            'scope_value' => (string) $visible->organization_unit_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($clarisse)
            ->getJson('/api/v1/expressions-besoin?q='.urlencode($hidden->reference))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($clarisse)
            ->getJson('/api/v1/expressions-besoin?q='.urlencode($visible->reference))
            ->assertOk()
            ->assertJsonPath('data.0.reference', $visible->reference);
    }

    public function test_lapercu_reproduit_le_modele_sans_archiver_le_brouillon(): void
    {
        config(['gesbudep.frontend_url' => 'https://budget.ceeac.example']);
        $clarisse = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $eb = ExpressionBesoin::query()->where('status', 'brouillon')->firstOrFail();
        $avant = GeneratedDocument::query()->count();

        $pdf = $this->actingAs($clarisse)
            ->get('/api/v1/expressions-besoin/'.$eb->id.'/apercu')
            ->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame($avant, GeneratedDocument::query()->count());

        $html = view('pdf.expression-besoin', [
            'fiche' => app(ExpressionBesoinFichePresenter::class)->present($eb),
        ])->render();
        $this->assertStringContainsString('FICHE D’EXPRESSION DE BESOIN', $html);
        $this->assertStringContainsString('BROUILLON', $html);
        $this->assertStringContainsString($eb->reference, $html);
        $this->assertStringNotContainsString('QR CODE DU MODÈLE', $html);
        $this->assertStringNotContainsString('MODÈLE DE PRÉSENTATION', $html);
        $this->assertStringNotContainsString('EB-2026-000079', $html);

        $url = app(DocumentVerification::class)->url('code-opaque');
        $this->assertSame('https://budget.ceeac.example/verifier/code-opaque', $url);
        $this->assertStringStartsWith("\x89PNG", app(QrCode::class)->png($url));
    }

    public function test_le_directeur_de_la_direction_couvre_le_service_et_le_hors_pap_part_des_moyens_generaux(): void
    {
        $departement = OrganizationUnit::query()->create([
            'sigle' => 'DEP-TECH',
            'name' => 'Département technique d’essai',
            'kind' => 'departement',
            'is_technical' => true,
            'is_active' => true,
        ]);
        $direction = OrganizationUnit::query()->create([
            'parent_id' => $departement->id,
            'sigle' => 'DIR-ESSAI',
            'name' => 'Direction d’essai',
            'kind' => 'direction',
            'is_technical' => true,
            'is_active' => true,
        ]);
        $service = OrganizationUnit::query()->create([
            'parent_id' => $direction->id,
            'sigle' => 'SRV-ESSAI',
            'name' => 'Service d’essai',
            'kind' => 'service',
            'is_technical' => true,
            'is_active' => true,
        ]);
        $autre = OrganizationUnit::query()->create([
            'sigle' => 'DIR-AUTRE',
            'name' => 'Autre direction',
            'kind' => 'direction',
            'is_active' => true,
        ]);
        $directeur = User::factory()->create([
            'role' => 'directeur',
            'organization_unit_id' => $direction->id,
            'account_status' => 'actif',
        ]);
        $intrus = User::factory()->create([
            'role' => 'directeur',
            'organization_unit_id' => $autre->id,
            'account_status' => 'actif',
        ]);
        $commissaire = User::factory()->create([
            'role' => 'commissaire',
            'organization_unit_id' => $departement->id,
            'account_status' => 'actif',
        ]);
        $workflow = app(ExpressionBesoinWorkflow::class);
        $besoin = new ExpressionBesoin(['workflow_step' => 'directeur']);
        $besoin->setRelation('organizationUnit', $service);
        $this->assertTrue($workflow->allows($directeur, $besoin));
        $this->assertFalse($workflow->allows($intrus, $besoin));

        $besoin->workflow_step = 'commissaire';
        $this->assertTrue($workflow->allows($commissaire, $besoin));
        $this->assertFalse($workflow->allows($directeur, $besoin));

        $smg = OrganizationUnit::query()->create([
            'sigle' => 'DSG-DRHMG-SMG',
            'name' => 'Service Moyens généraux',
            'kind' => 'service',
            'is_active' => true,
        ]);
        $agent = User::factory()->create([
            'role' => 'initiateur',
            'organization_unit_id' => $smg->id,
            'account_status' => 'actif',
            'email' => 'agent.smg@ceeac.int',
        ]);
        $source = BudgetLine::query()->where('code', '203232')->firstOrFail();
        $hors = BudgetLine::query()->create([
            'exercice_id' => $source->exercice_id,
            'organization_unit_id' => $service->id,
            'code' => 'HP-TEST',
            'label' => 'Besoin hors PAP',
            'nature' => BudgetNature::HorsPap,
            'chapitre' => '61',
            'article' => '01',
            'paragraphe' => '01',
            'nature_depense' => 'Fonctionnement',
            'montant_vote' => 1000,
            'ajustements' => 0,
        ]);
        $clarisse = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $this->actingAs($clarisse)
            ->postJson('/api/v1/expressions-besoin', ['budget_line_id' => $hors->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('budget_line_id');
        $this->actingAs($agent)
            ->postJson('/api/v1/expressions-besoin', ['budget_line_id' => $hors->id])
            ->assertCreated()
            ->assertJsonPath('data.nature', 'hors_pap');
    }
}
