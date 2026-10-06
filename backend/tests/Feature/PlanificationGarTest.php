<?php

namespace Tests\Feature;

use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\Planning\Models\GarNode;
use App\Domains\Planning\Models\GarVersion;
use App\Models\User;
use Database\Seeders\EngagementSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanificationGarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->seed(EngagementSeeder::class);
    }

    public function test_l_initialisation_reprend_le_pap_dans_un_brouillon(): void
    {
        $expert = User::query()->where('email', 'blaise.essono@ceeac.int')->firstOrFail();

        $this->actingAs($expert)
            ->postJson('/api/v1/planification/initialiser')
            ->assertCreated()
            ->assertJsonPath('version.statut', 'brouillon')
            ->assertJsonPath('version.numero', 2);

        $this->assertTrue(
            collect($this->actingAs($expert)->getJson('/api/v1/planification')->json('noeuds'))
                ->contains(fn (array $node) => $node['type'] === 'pilier' && $node['libelle'] !== '')
        );
        $this->actingAs($expert)->postJson('/api/v1/planification/initialiser')->assertStatus(422);
    }

    public function test_la_publication_fige_la_version_et_recopie_l_activite_sur_le_pap(): void
    {
        $expert = User::query()->where('email', 'blaise.essono@ceeac.int')->firstOrFail();
        $directeur = User::query()->where('email', 'directeur.budget@ceeac.int')->firstOrFail();
        $this->actingAs($expert)->postJson('/api/v1/planification/initialiser')->assertCreated();

        $portrait = $this->actingAs($expert)->getJson('/api/v1/planification')->json();
        $versionId = $portrait['version']['id'];
        $activite = collect($portrait['noeuds'])->first(fn (array $node) => $node['type'] === 'activite' && $node['pap_id']);
        $this->assertNotNull($activite);

        $this->actingAs($expert)
            ->patchJson('/api/v1/planification/noeuds/'.$activite['id'], [
                'libelle' => 'Activité révisée GAR',
                'justification' => 'Précision du libellé',
            ])
            ->assertOk();

        $this->actingAs($expert)
            ->postJson("/api/v1/planification/versions/{$versionId}/soumettre", ['justification' => 'Chaîne prête pour validation'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'en_validation');

        $this->actingAs($expert)
            ->postJson("/api/v1/planification/versions/{$versionId}/valider")
            ->assertStatus(422);

        $this->actingAs($directeur)
            ->postJson("/api/v1/planification/versions/{$versionId}/valider")
            ->assertOk()
            ->assertJsonPath('data.statut', 'valide');

        $this->actingAs($directeur)
            ->postJson("/api/v1/planification/versions/{$versionId}/publier", ['date_effet' => '2026-01-01'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'publie');

        $this->assertSame('Activité révisée GAR', PapEnrichment::query()->find($activite['pap_id'])->activite);
        $this->actingAs($expert)
            ->patchJson('/api/v1/planification/noeuds/'.$activite['id'], ['libelle' => 'Interdit'])
            ->assertStatus(422);

        $this->actingAs($expert)
            ->postJson("/api/v1/planification/versions/{$versionId}/avenant", ['justification' => 'Ajustement après publication'])
            ->assertCreated()
            ->assertJsonPath('data.statut', 'brouillon');
    }

    public function test_un_nœud_s_archive_sans_etre_supprime_et_un_parent_occupe_reste(): void
    {
        $expert = User::query()->where('email', 'blaise.essono@ceeac.int')->firstOrFail();
        $this->actingAs($expert)->postJson('/api/v1/planification/initialiser')->assertCreated();
        $portrait = $this->actingAs($expert)->getJson('/api/v1/planification')->json();
        $versionId = $portrait['version']['id'];
        $activite = collect($portrait['noeuds'])->first(fn (array $node) => $node['type'] === 'activite');

        $cree = $this->actingAs($expert)
            ->postJson("/api/v1/planification/versions/{$versionId}/noeuds", [
                'type' => 'tache',
                'parent_id' => $activite['id'],
                'libelle' => 'Tâche ajoutée au plan',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($expert)
            ->postJson('/api/v1/planification/noeuds/'.$activite['id'].'/archiver', ['motif' => 'Plus au programme'])
            ->assertStatus(422);

        $this->actingAs($expert)
            ->postJson('/api/v1/planification/noeuds/'.$cree.'/archiver', ['motif' => 'Doublon de tâche'])
            ->assertOk();

        $this->assertSame(GarNode::ARCHIVE, GarNode::query()->find($cree)->statut);
        $this->assertSame(1, GarVersion::query()->where('statut', 'publie')->count());
    }

    public function test_les_unites_contributrices_sont_enregistrees(): void
    {
        $expert = User::query()->where('email', 'blaise.essono@ceeac.int')->firstOrFail();
        $this->actingAs($expert)->postJson('/api/v1/planification/initialiser')->assertCreated();
        $portrait = $this->actingAs($expert)->getJson('/api/v1/planification')->assertOk()->json();
        $node = collect($portrait['noeuds'])->first(fn (array $row) => $row['type'] === 'activite');
        $unitId = $portrait['structures'][0]['id'] ?? null;
        $this->assertNotNull($node);
        $this->assertNotNull($unitId);

        $this->actingAs($expert)
            ->patchJson('/api/v1/planification/noeuds/'.$node['id'], [
                'libelle' => $node['libelle'],
                'contributeurs' => [$unitId],
            ])->assertOk();

        $saved = collect($this->actingAs($expert)->getJson('/api/v1/planification')->json('noeuds'))
            ->firstWhere('id', $node['id']);
        $this->assertSame([$unitId], collect($saved['contributeurs'])->pluck('id')->all());
    }
}
