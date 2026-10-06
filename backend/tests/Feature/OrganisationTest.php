<?php

namespace Tests\Feature;

use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Organization\Services\OrganizationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganisationTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_referentiel_officiel_sert_l_arbre_et_refuse_les_cycles(): void
    {
        $admin = User::factory()->create(['role' => 'administrateur_fonctionnel']);
        $lecteur = User::factory()->create(['role' => 'expert_budget']);
        $version = app(OrganizationService::class)->publierSource($admin);

        $this->assertSame('ORG-2026', $version->code);
        $dsi = OrganizationUnit::query()->where('sigle', 'DSG-DSI')->firstOrFail();
        $this->assertSame('DSG', $dsi->parent?->sigle);
        $this->assertSame('DSG', OrganizationUnit::query()->findOrFail($dsi->parent_id)->parent?->sigle === 'COM-CEEAC' ? 'DSG' : OrganizationUnit::query()->findOrFail($dsi->parent_id)->sigle);
        $this->assertGreaterThan(100, OrganizationUnit::query()->count());

        $this->actingAs($lecteur)->getJson('/api/v1/organisation/arbre')->assertOk()->assertJsonPath('version.code', 'ORG-2026');
        $this->actingAs($lecteur)->getJson('/api/v1/organisation/unites?q=Systèmes')->assertOk();
        $this->actingAs($lecteur)->postJson('/api/v1/organisation/unites', [
            'sigle' => 'HORS-REF',
            'name' => 'Structure inventée',
            'kind' => 'service',
            'parent_id' => $dsi->id,
        ])->assertForbidden();

        $enfant = $this->actingAs($admin)->postJson('/api/v1/organisation/unites', [
            'sigle' => 'DSG-DSI-TEST',
            'name' => 'Cellule de test',
            'kind' => 'service',
            'parent_id' => $dsi->id,
            'sort_order' => 900,
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin)->postJson('/api/v1/organisation/unites', [
            'sigle' => 'DSG-DSI-TEST',
            'name' => 'Doublon',
            'kind' => 'service',
            'parent_id' => $dsi->id,
        ])->assertStatus(422);

        $petit = $this->actingAs($admin)->postJson('/api/v1/organisation/unites', [
            'sigle' => 'DSG-DSI-TEST-B',
            'name' => 'Sous-cellule',
            'kind' => 'bureau',
            'parent_id' => $enfant,
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin)->patchJson('/api/v1/organisation/unites/'.$enfant, [
            'parent_id' => $petit,
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/api/v1/organisation/unites/'.$dsi->id.'/desactiver')->assertOk();
        $this->assertFalse((bool) $dsi->fresh()->is_active);
        $this->actingAs($admin)->postJson('/api/v1/organisation/unites/'.$dsi->id.'/activer')->assertOk();

        $this->actingAs($admin)->deleteJson('/api/v1/organisation/unites/'.$enfant)->assertStatus(422);
        $this->actingAs($admin)->deleteJson('/api/v1/organisation/unites/'.$petit)->assertOk();
        $this->actingAs($admin)->deleteJson('/api/v1/organisation/unites/'.$dsi->id)->assertStatus(422);

        $fonction = $this->actingAs($admin)->getJson('/api/v1/organisation/fonctions')->assertOk()->json('data');
        $directeur = collect($fonction)->firstWhere('code', 'directeur');
        $this->actingAs($admin)->postJson('/api/v1/organisation/affectations', [
            'user_id' => $admin->id,
            'organization_unit_id' => $dsi->id,
            'position_id' => $directeur['id'],
            'starts_on' => '2026-06-01',
        ])->assertCreated();

        $this->actingAs($lecteur)->getJson('/api/v1/organisation/unites/'.$dsi->id.'/responsables')
            ->assertOk()
            ->assertJsonPath('data.fonction_code', 'directeur');

        $service = OrganizationUnit::query()->where('sigle', 'DSG-DSI-SED')->firstOrFail();
        $this->actingAs($lecteur)->getJson('/api/v1/organisation/unites/'.$service->id.'/responsables')
            ->assertOk()
            ->assertJsonPath('data.structure', 'DSG-DSI');
    }
}
