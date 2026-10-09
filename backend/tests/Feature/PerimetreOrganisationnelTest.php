<?php

namespace Tests\Feature;

use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un utilisateur limité à un périmètre organisationnel ne voit, en liste comme
 * par l’URL d’une fiche, que les dossiers de ce périmètre (pas d’IDOR).
 */
class PerimetreOrganisationnelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_la_fiche_et_la_liste_respectent_le_meme_perimetre(): void
    {
        $engagement = Engagement::query()->with('expressionBesoin')->whereHas('expressionBesoin')->firstOrFail();
        $autreUnite = $engagement->expressionBesoin->organization_unit_id;
        $lecteur = User::query()->where('role', 'auditeur')->firstOrFail();
        $uniteDuLecteur = DB::table('organization_units')->where('id', '!=', $autreUnite)->value('id');
        DB::table('access_scopes')->insert([
            'user_id' => $lecteur->id,
            'scope_type' => 'organization_unit',
            'scope_value' => (string) $uniteDuLecteur,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($lecteur)->getJson('/api/v1/expressions-besoin/'.$engagement->expression_besoin_id)->assertForbidden();
        $this->actingAs($lecteur)->getJson('/api/v1/engagements/'.$engagement->id)->assertForbidden();

        $references = collect($this->actingAs($lecteur)->getJson('/api/v1/engagements?per_page=100')->assertOk()->json('data'))->pluck('reference');
        $this->assertNotContains($engagement->reference, $references);

        $paiement = Paiement::query()->firstOrFail();
        $unitePaiement = $paiement->ordonnancement?->liquidation?->engagement?->expressionBesoin?->organization_unit_id;
        if ($unitePaiement !== $uniteDuLecteur) {
            $this->actingAs($lecteur)->getJson('/api/v1/paiements/'.$paiement->id)->assertForbidden();
            $this->assertNotContains(
                $paiement->reference,
                collect($this->actingAs($lecteur)->getJson('/api/v1/paiements')->assertOk()->json('data'))->pluck('reference'),
            );
        }
    }

    public function test_l_initiateur_garde_l_acces_a_son_propre_dossier(): void
    {
        $eb = ExpressionBesoin::query()->whereNotNull('initiator_id')->firstOrFail();
        $initiateur = User::query()->findOrFail($eb->initiator_id);
        DB::table('access_scopes')->insert([
            'user_id' => $initiateur->id,
            'scope_type' => 'organization_unit',
            'scope_value' => (string) DB::table('organization_units')->where('id', '!=', $eb->organization_unit_id)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($initiateur)->getJson('/api/v1/expressions-besoin/'.$eb->id)->assertOk();
    }

    public function test_sans_perimetre_declare_la_consultation_reste_transverse(): void
    {
        $engagement = Engagement::query()->firstOrFail();
        $controleur = User::query()->where('role', 'controleur_financier')->firstOrFail();

        $this->actingAs($controleur)->getJson('/api/v1/engagements/'.$engagement->id)->assertOk();
    }
}
