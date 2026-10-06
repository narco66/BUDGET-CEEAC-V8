<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Planning\Models\GarVersion;
use App\Domains\Suppliers\Models\Tiers;
use App\Models\User;
use App\Shared\Support\NumberingService;
use Database\Seeders\EngagementSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EngagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->seed(EngagementSeeder::class);
    }

    public function test_la_liste_calcule_les_indicateurs_depuis_les_dossiers(): void
    {
        $expert = User::query()->where('email', 'blaise.essono@ceeac.int')->firstOrFail();

        $this->actingAs($expert)
            ->getJson('/api/v1/engagements')
            ->assertOk()
            ->assertJsonPath('tableau_de_bord.total', 6)
            ->assertJsonFragment(['reference' => 'ENG-2026-000457']);
    }

    public function test_un_credit_insuffisant_bloque_la_validation(): void
    {
        $director = User::query()->where('email', 'directeur.budget@ceeac.int')->firstOrFail();
        $engagement = Engagement::query()->where('reference', 'ENG-2026-000461')->firstOrFail();
        $engagement->forceFill(['montant' => 50_000_000])->save();

        $this->actingAs($director)
            ->postJson("/api/v1/engagements/{$engagement->id}/transmettre")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credit']);
    }

    public function test_le_visa_verrouille_l_engagement_et_cree_la_liquidation(): void
    {
        $controller = User::query()->where('email', 'controleur.financier@ceeac.int')->firstOrFail();
        $engagement = Engagement::query()->where('reference', 'ENG-2026-000457')->firstOrFail();
        $exerciceId = $engagement->expressionBesoin->exercice_id;
        $version = GarVersion::query()->create([
            'exercice_id' => $exerciceId,
            'numero' => 9,
            'statut' => GarVersion::PUBLIE,
            'author_id' => $controller->id,
        ]);
        Exercice::query()->whereKey($exerciceId)->update(['gar_version_id' => $version->id]);

        $this->actingAs($controller)
            ->postJson("/api/v1/engagements/{$engagement->id}/viser", [
                'observations' => 'Visa conforme au crédit disponible.',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', 'transforme_liquidation')
            ->assertJsonPath('data.verrouille', true)
            ->assertJsonPath('data.gar_version_id', $version->id);

        $this->assertDatabaseHas('liquidations', [
            'engagement_id' => $engagement->id,
        ]);
    }

    public function test_le_retour_exige_un_motif(): void
    {
        $chef = User::query()->where('email', 'chef.budget@ceeac.int')->firstOrFail();
        $engagement = Engagement::query()->where('reference', 'ENG-2026-000455')->firstOrFail();

        $this->actingAs(User::query()->where('email', 'blaise.essono@ceeac.int')->firstOrFail())
            ->postJson("/api/v1/engagements/{$engagement->id}/transmettre", ['observations' => 'Instruction complète.'])
            ->assertOk()
            ->assertJsonPath('data.etape', 'chef_budget');

        $engagement->refresh();

        $this->actingAs($chef)
            ->postJson("/api/v1/engagements/{$engagement->id}/retourner", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['motif']);

        $this->actingAs($chef)
            ->postJson("/api/v1/engagements/{$engagement->id}/retourner", [
                'motif' => 'Bénéficiaire incomplet',
                'observations' => 'Préciser le RCCM.',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', 'retourne');
    }

    public function test_les_pieces_attendues_viennent_du_referentiel(): void
    {
        $expert = User::query()->where('email', 'blaise.essono@ceeac.int')->firstOrFail();
        $engagement = Engagement::query()->where('reference', 'ENG-2026-000457')->firstOrFail();

        $labels = $this->actingAs($expert)
            ->getJson("/api/v1/engagements/{$engagement->id}")
            ->assertOk()
            ->json('data.pieces_attendues');

        $this->assertContains('TDR', $labels);
        $this->assertContains('Bon de commande', $labels);
    }

    public function test_l_instruction_rattache_un_tiers_actif(): void
    {
        $expert = User::query()->where('email', 'blaise.essono@ceeac.int')->firstOrFail();
        $engagement = Engagement::query()->where('reference', 'ENG-2026-000455')->firstOrFail();
        $tiers = Tiers::query()->create([
            'code' => 'T-TEST',
            'type' => 'fournisseur',
            'raison_sociale' => 'Cabinet Test',
            'nom_normalise' => 'cabinet test',
            'nif' => 'NIF-TEST',
            'rccm' => 'RCCM-TEST',
            'status' => 'actif',
            'created_by' => $expert->id,
        ]);

        $this->actingAs($expert)
            ->patchJson("/api/v1/engagements/{$engagement->id}", ['tiers_id' => $tiers->id])
            ->assertOk()
            ->assertJsonPath('data.beneficiaire', 'Cabinet Test')
            ->assertJsonPath('data.beneficiaire_nif', 'NIF-TEST')
            ->assertJsonPath('data.tiers_id', $tiers->id);
    }

    public function test_deux_references_successives_sont_distinctes(): void
    {
        $service = app(NumberingService::class);
        $first = $service->nextEngagement(2026);
        $second = $service->nextEngagement(2026);

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^ENG-2026-\d{6}$/', $second);
    }

    public function test_l_expert_joint_une_piece_obligatoire_avant_le_visa(): void
    {
        Storage::fake();
        $expert = User::query()->where('role', 'expert_budget')->firstOrFail();
        $initiateur = User::query()->where('role', 'initiateur')->firstOrFail();
        $ouvert = Engagement::query()->where('reference', 'ENG-2026-000455')->firstOrFail();
        $vise = Engagement::query()->whereNotNull('visa_reference')->firstOrFail();
        $montant = (int) $ouvert->montant;
        $fichier = UploadedFile::fake()->create('bon-de-commande.pdf', 20, 'application/pdf');

        $this->post('/api/v1/engagements/'.$ouvert->id.'/pieces', [
            'type' => 'Bon de commande',
            'fichier' => $fichier,
        ], ['Accept' => 'application/json'])->assertUnauthorized();

        $this->actingAs($initiateur)
            ->post('/api/v1/engagements/'.$ouvert->id.'/pieces', [
                'type' => 'Bon de commande',
                'fichier' => $fichier,
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->actingAs($expert)
            ->post('/api/v1/engagements/'.$vise->id.'/pieces', [
                'type' => 'Bon de commande',
                'fichier' => $fichier,
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->actingAs($expert)
            ->post('/api/v1/engagements/'.$ouvert->id.'/pieces', [
                'type' => 'Bon de commande',
                'fichier' => $fichier,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonFragment(['type' => 'Bon de commande', 'nom' => 'bon-de-commande.pdf']);

        $this->assertSame($montant, (int) $ouvert->fresh()->montant);
    }
}
