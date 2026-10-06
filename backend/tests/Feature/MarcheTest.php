<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Procurement\Models\Marche;
use App\Domains\Suppliers\Models\Tiers;
use App\Models\User;
use Database\Seeders\AdministrationSeeder;
use Database\Seeders\EngagementSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Database\Seeders\TiersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarcheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->seed(EngagementSeeder::class);
        $this->seed(TiersSeeder::class);
        $this->seed(AdministrationSeeder::class);
    }

    public function test_un_projet_de_marche_se_consulte_se_modifie_et_se_supprime(): void
    {
        $expert = $this->expert();
        $titulaire = Tiers::query()->where('status', 'actif')->firstOrFail();
        $id = $this->creer($expert);

        $this->actingAs($expert)->getJson('/api/v1/marches/'.$id)
            ->assertOk()
            ->assertJsonPath('data.statut_libelle', 'Projet')
            ->assertJsonPath('data.actions.modifier', true)
            ->assertJsonPath('data.actions.supprimer', true)
            ->assertJsonPath('data.transitions', ['notifie', 'resilie']);

        $this->actingAs($expert)->putJson('/api/v1/marches/'.$id, [
            'objet' => 'Fourniture de serveurs et onduleurs',
            'montant' => 15000000,
            'procedure' => 'appel_offres',
            'tiers_id' => $titulaire->id,
        ])->assertOk()
            ->assertJsonPath('data.titulaire', $titulaire->raison_sociale)
            ->assertJsonPath('data.montant', 15000000);

        $this->actingAs($expert)->getJson('/api/v1/marches?q=onduleurs')->assertOk()->assertJsonPath('meta.total', 1);

        $this->actingAs($expert)->deleteJson('/api/v1/marches/'.$id)->assertOk();
        $this->assertDatabaseMissing('marches', ['id' => $id]);
    }

    public function test_le_statut_suit_le_cycle_et_fige_le_contenu(): void
    {
        $expert = $this->expert();
        $id = $this->creer($expert);

        $this->actingAs($expert)->postJson('/api/v1/marches/'.$id.'/statut', ['statut' => 'notifie'])
            ->assertStatus(422)->assertJsonValidationErrors(['notified_on']);
        $this->actingAs($expert)->postJson('/api/v1/marches/'.$id.'/statut', ['statut' => 'clos'])
            ->assertStatus(422)->assertJsonValidationErrors(['statut']);
        $this->actingAs($expert)->postJson('/api/v1/marches/'.$id.'/statut', ['statut' => 'notifie', 'notified_on' => '2026-10-01'])
            ->assertOk()->assertJsonPath('data.notifie_le', '2026-10-01');

        $this->actingAs($expert)->putJson('/api/v1/marches/'.$id, ['objet' => 'Autre', 'montant' => 1, 'procedure' => 'consultation'])
            ->assertStatus(422)->assertJsonValidationErrors(['statut']);
        $this->actingAs($expert)->deleteJson('/api/v1/marches/'.$id)->assertStatus(422);

        $this->actingAs($expert)->postJson('/api/v1/marches/'.$id.'/statut', ['statut' => 'resilie'])
            ->assertStatus(422)->assertJsonValidationErrors(['motif']);
        $this->actingAs($expert)->postJson('/api/v1/marches/'.$id.'/statut', ['statut' => 'resilie', 'motif' => 'Défaillance du titulaire'])
            ->assertOk()->assertJsonPath('data.statut', 'resilie')->assertJsonPath('data.transitions', []);

        $this->assertDatabaseHas('audit_events', ['object_type' => 'marche', 'object_id' => (string) $id, 'action' => 'marche.statut', 'motif' => 'Défaillance du titulaire']);
    }

    public function test_un_lecteur_ne_modifie_pas_le_registre(): void
    {
        $id = $this->creer($this->expert());
        $lecteur = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();

        $this->actingAs($lecteur)->getJson('/api/v1/marches/'.$id)->assertOk()->assertJsonPath('data.actions.modifier', false)->assertJsonPath('data.transitions', []);
        $this->actingAs($lecteur)->putJson('/api/v1/marches/'.$id, ['objet' => 'X', 'montant' => 1, 'procedure' => 'consultation'])->assertStatus(422);
        $this->actingAs($lecteur)->deleteJson('/api/v1/marches/'.$id)->assertStatus(422);
        $this->assertTrue(Marche::query()->whereKey($id)->exists());
    }

    public function test_l_administrateur_fonctionnel_tient_le_registre(): void
    {
        $admin = User::query()->where('email', 'herve.bouka@ceeac.int')->firstOrFail();
        $id = $this->creer($admin);

        $this->actingAs($admin)->getJson('/api/v1/marches')->assertOk()->assertJsonPath('peut_creer', true);
        $this->actingAs($admin)->putJson('/api/v1/marches/'.$id, ['objet' => 'Maintenance du parc', 'montant' => 900000, 'procedure' => 'gre_a_gre'])->assertOk();
        $this->actingAs($admin)->deleteJson('/api/v1/marches/'.$id)->assertOk();

        $habilitations = User::query()->where('email', 'amina.oko@ceeac.int')->firstOrFail();
        $this->actingAs($habilitations)->getJson('/api/v1/marches')->assertOk()->assertJsonPath('peut_creer', false);
    }

    private function creer(User $expert): int
    {
        return (int) $this->actingAs($expert)->postJson('/api/v1/marches', [
            'exercice_id' => Exercice::query()->where('annee', 2026)->value('id'),
            'objet' => 'Fourniture de serveurs',
            'montant' => 12000000,
            'procedure' => 'consultation',
        ])->assertCreated()->json('data.id');
    }

    private function expert(): User
    {
        return User::query()->where('email', 'blaise.essono@ceeac.int')->firstOrFail();
    }
}
