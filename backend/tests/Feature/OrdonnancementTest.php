<?php

namespace Tests\Feature;

use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Models\User;
use Database\Seeders\EngagementSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Database\Seeders\LiquidationSeeder;
use Database\Seeders\OrdonnancementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrdonnancementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->seed(EngagementSeeder::class);
        $this->seed(LiquidationSeeder::class);
        $this->seed(OrdonnancementSeeder::class);
    }

    public function test_le_seuil_designe_l_ordonnateur(): void
    {
        $secretaire = User::query()->where('role', 'secretaire_general')->firstOrFail();

        $this->actingAs($secretaire)
            ->getJson('/api/v1/ordonnancements/delegations?montant=4500000')
            ->assertOk()
            ->assertJsonPath('simulation.ordonnateur_role', 'secretaire_general')
            ->assertJsonPath('seuil_actif', 5000000);

        $this->actingAs($secretaire)
            ->getJson('/api/v1/ordonnancements/delegations?montant=6000000')
            ->assertOk()
            ->assertJsonPath('simulation.ordonnateur_role', 'ordonnateur');
    }

    public function test_une_delegation_sans_type_de_depense_utilise_le_defaut_de_la_base(): void
    {
        $president = User::query()->where('role', 'ordonnateur')->firstOrFail();

        $this->actingAs($president)
            ->postJson('/api/v1/ordonnancements/delegations', [
                'seuil_max' => 6000000,
                'debut' => '2026-10-02',
                'fin' => '2026-12-31',
                'document' => 'DEC-2026-01',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('ord_delegations', [
            'document' => 'DEC-2026-01',
            'type_depense' => 'Toutes natures',
        ]);
    }

    public function test_le_president_signe_et_cree_un_paiement(): void
    {
        $president = User::query()->where('role', 'ordonnateur')->firstOrFail();
        $ordre = Ordonnancement::query()->where('reference', 'ORD-2026-000001')->firstOrFail();

        $this->actingAs($president)
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'transforme_paiement');

        $this->assertDatabaseHas('paiements', ['ordonnancement_id' => $ordre->id]);
        $this->assertSame(1, Paiement::query()->where('ordonnancement_id', $ordre->id)->count());
    }

    public function test_la_reprise_est_idempotente(): void
    {
        $secretaire = User::query()->where('role', 'secretaire_general')->firstOrFail();
        $ordre = Ordonnancement::query()->where('reference', 'ORD-2026-000090')->firstOrFail();
        $ordre->forceFill(['fail_next_transmission' => true])->save();

        $this->actingAs($secretaire)
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'transmission_erreur');

        $this->actingAs($secretaire)
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/reprendre")
            ->assertOk()
            ->assertJsonPath('data.statut', 'transforme_paiement');

        $this->actingAs($secretaire)
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/reprendre")
            ->assertForbidden();

        $this->assertSame(1, Paiement::query()->where('ordonnancement_id', $ordre->id)->count());
    }
}
