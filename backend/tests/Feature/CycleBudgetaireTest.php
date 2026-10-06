<?php

namespace Tests\Feature;

use App\Domains\Administration\Models\WorkflowDefinition;
use App\Domains\Administration\Services\ChainWorkflowCatalog;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Needs\Services\ExpressionBesoinWorkflow;
use App\Models\User;
use Database\Seeders\EngagementSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CycleBudgetaireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->seed(EngagementSeeder::class);
    }

    public function test_la_definition_publiee_ordonne_la_chaine(): void
    {
        $eb = ExpressionBesoin::query()->whereHas('organizationUnit', fn ($query) => $query->where('is_technical', false))->firstOrFail();
        $avant = app(ExpressionBesoinWorkflow::class)->steps($eb);
        $this->assertSame(['initiateur', 'directeur', 'secretaire_general', 'ordonnateur'], $avant);

        $definition = WorkflowDefinition::query()->create([
            'code' => 'chaine-depense',
            'module' => 'depense',
            'label' => 'Chaîne',
        ]);
        $version = $definition->versions()->create(['version' => 1, 'status' => 'actif', 'effective_on' => '2026-01-01']);
        foreach (ChainWorkflowCatalog::CANONICAL as $index => [$code, $label, $role]) {
            $version->steps()->create(['ordre' => $code === 'eb.ordonnateur' ? 0 : $index + 10, 'code' => $code, 'label' => $label, 'actor_role' => $role]);
        }

        $this->assertSame('ordonnateur', app(ExpressionBesoinWorkflow::class)->steps($eb->fresh())[0]);
    }

    public function test_la_preparation_adopte_un_exercice_sans_que_l_auteur_decide(): void
    {
        $expert = User::query()->where('email', 'blaise.essono@ceeac.int')->firstOrFail();
        $directeur = User::query()->where('email', 'directeur.budget@ceeac.int')->firstOrFail();

        $this->actingAs($expert)->postJson('/api/v1/preparation/ouvrir')->assertCreated();
        $proposition = $this->actingAs($expert)->getJson('/api/v1/preparation')->json('propositions.0');
        $this->actingAs($expert)->postJson('/api/v1/preparation/propositions/'.$proposition['id'].'/soumettre')->assertOk();
        $this->actingAs($expert)->postJson('/api/v1/preparation/propositions/'.$proposition['id'].'/retenir', ['retenir' => true])->assertStatus(422);
        $this->actingAs($directeur)->postJson('/api/v1/preparation/propositions/'.$proposition['id'].'/retenir', ['retenir' => true])->assertOk();

        $exerciceId = Exercice::query()->where('statut', 'preparation')->value('id');
        $this->actingAs($expert)->postJson('/api/v1/preparation/'.$exerciceId.'/adopter')->assertStatus(422);
        $this->actingAs($directeur)->postJson('/api/v1/preparation/'.$exerciceId.'/adopter')->assertOk();

        $this->assertSame('executoire', Exercice::query()->find($exerciceId)->statut);
        $this->assertTrue(BudgetLine::query()->where('exercice_id', $exerciceId)->where('code', $proposition['code'])->exists());
    }

    public function test_un_marche_se_rattache_a_l_engagement(): void
    {
        $expert = User::query()->where('email', 'blaise.essono@ceeac.int')->firstOrFail();
        $exercice = Exercice::query()->where('annee', 2026)->firstOrFail();
        $engagement = Engagement::query()->firstOrFail();

        $marche = $this->actingAs($expert)->postJson('/api/v1/marches', [
            'exercice_id' => $exercice->id,
            'objet' => 'Fourniture de serveurs',
            'montant' => 12000000,
            'procedure' => 'consultation',
        ])->assertCreated()->json('data.id');

        $this->actingAs($expert)->postJson('/api/v1/marches/'.$marche.'/rattacher', [
            'engagement_id' => $engagement->id,
        ])->assertOk();

        $this->assertDatabaseHas('marches', ['id' => $marche, 'engagement_id' => $engagement->id, 'statut' => 'notifie']);
    }

    public function test_la_cloture_refuse_un_exercice_charge_et_ferme_un_exercice_vide(): void
    {
        $directeur = User::query()->where('email', 'directeur.budget@ceeac.int')->firstOrFail();
        $sg = User::query()->where('email', 'aline.moussavou@ceeac.int')->firstOrFail();
        $courant = Exercice::query()->where('annee', 2026)->firstOrFail();

        $this->actingAs($directeur)->postJson('/api/v1/cloture/'.$courant->id.'/demander', ['motif' => 'Fin de gestion'])->assertStatus(422);

        $vide = Exercice::query()->create([
            'annee' => 2024,
            'statut' => 'executoire',
            'date_debut' => '2024-01-01',
            'date_fin' => '2024-12-31',
        ]);
        $this->actingAs($directeur)->postJson('/api/v1/cloture/'.$vide->id.'/demander', ['motif' => 'Exercice sans dossier'])->assertCreated();
        $this->actingAs($directeur)->postJson('/api/v1/cloture/'.$vide->id.'/confirmer')->assertStatus(422);
        $this->actingAs($sg)->postJson('/api/v1/cloture/'.$vide->id.'/confirmer')->assertOk();
        $this->assertSame('clos', $vide->fresh()->statut);
    }

    public function test_la_connexion_institutionnelle_retrouve_le_compte(): void
    {
        config([
            'gesbudep.sso.enabled' => true,
            'gesbudep.sso.issuer' => 'https://idp.test',
            'gesbudep.sso.client_id' => 'gesbudep',
            'gesbudep.sso.client_secret' => 'secret',
            'gesbudep.sso.redirect' => 'http://localhost/api/v1/auth/sso/callback',
            'gesbudep.frontend_url' => 'http://localhost:5173',
        ]);
        Http::fake([
            'https://idp.test/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://idp.test/auth',
                'token_endpoint' => 'https://idp.test/token',
                'userinfo_endpoint' => 'https://idp.test/userinfo',
            ]),
            'https://idp.test/token' => Http::response(['access_token' => 'jeton']),
            'https://idp.test/userinfo' => Http::response(['email' => 'blaise.essono@ceeac.int']),
        ]);

        $this->get('/api/v1/auth/sso/rediriger')->assertRedirect();
        $state = session('sso_state');
        $this->assertIsString($state);

        $this->get('/api/v1/auth/sso/callback?state='.$state.'&code=xyz')
            ->assertRedirect('http://localhost:5173/taches');
        $this->assertAuthenticated();
        $this->assertSame('sso', User::query()->where('email', 'blaise.essono@ceeac.int')->value('identity_source'));
        $this->get('/api/v1/auth/sso')->assertOk()->assertJsonPath('actif', true);
    }
}
