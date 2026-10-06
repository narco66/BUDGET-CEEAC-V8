<?php

namespace Tests\Feature;

use App\Domains\Administration\Models\UserSession;
use App\Models\User;
use App\Shared\Auth\Totp;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_requete_anonyme_est_refusee(): void
    {
        $this->getJson('/api/v1/expressions-besoin')->assertUnauthorized();
        $this->getJson('/api/v1/paiements')->assertUnauthorized();
        $this->getJson('/api/v1/admin/utilisateurs')->assertUnauthorized();
    }

    public function test_l_en_tete_x_actor_id_ne_permet_pas_de_s_authentifier(): void
    {
        $user = User::factory()->create(['role' => 'agent_comptable']);

        $this->withHeader('X-Actor-Id', (string) $user->id)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_l_en_tete_x_actor_id_est_ignore_hors_mode_demonstration(): void
    {
        config(['gesbudep.demo_impersonation' => false]);
        $initiateur = User::factory()->create(['role' => 'initiateur']);
        $agent = User::factory()->create(['role' => 'agent_comptable']);

        $this->actingAs($initiateur)
            ->withHeader('X-Actor-Id', (string) $agent->id)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $initiateur->id);

        $this->actingAs($initiateur)
            ->postJson('/api/v1/acteurs/courant', ['user_id' => $agent->id])
            ->assertForbidden();
    }

    public function test_le_mode_demonstration_permet_de_changer_d_acteur(): void
    {
        config(['gesbudep.demo_impersonation' => true]);
        $initiateur = User::factory()->create(['role' => 'initiateur']);
        $agent = User::factory()->create(['role' => 'agent_comptable']);

        $this->actingAs($initiateur)
            ->withHeader('X-Actor-Id', (string) $agent->id)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $agent->id);
    }

    public function test_la_connexion_spa_ouvre_une_session_et_trace_l_evenement(): void
    {
        $user = User::factory()->create(['email' => 'agent@ceeac.int', 'password' => 'secret-123']);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/api/v1/auth/login', ['email' => 'agent@ceeac.int', 'password' => 'secret-123'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('token');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('user_sessions', ['user_id' => $user->id, 'revoked_at' => null]);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $user->id, 'action' => 'auth.connexion']);
    }

    public function test_un_client_api_recoit_un_jeton_revocable(): void
    {
        $user = User::factory()->create(['email' => 'api@ceeac.int', 'password' => 'secret-123']);

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'api@ceeac.int', 'password' => 'secret-123'])
            ->assertOk()
            ->json('token');
        $this->assertNotEmpty($token);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);

        UserSession::query()->where('user_id', $user->id)->update(['revoked_at' => now()]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_un_mot_de_passe_errone_est_refuse_puis_bloque(): void
    {
        config(['gesbudep.login.max_attempts' => 3]);
        User::factory()->create(['email' => 'agent@ceeac.int', 'password' => 'secret-123']);

        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['email' => 'agent@ceeac.int', 'password' => 'faux'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'agent@ceeac.int', 'password' => 'secret-123'])
            ->assertStatus(429);
        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.echec']);
    }

    public function test_un_compte_desactive_ne_peut_pas_se_connecter(): void
    {
        User::factory()->create(['email' => 'ancien@ceeac.int', 'password' => 'secret-123', 'account_status' => 'desactive']);

        $this->postJson('/api/v1/auth/login', ['email' => 'ancien@ceeac.int', 'password' => 'secret-123'])
            ->assertForbidden();
        $this->assertGuest();
    }

    public function test_un_compte_a_double_facteur_exige_un_code(): void
    {
        User::factory()->create([
            'email' => 'mfa@ceeac.int',
            'password' => 'secret-123',
            'mfa_required' => true,
            'role' => 'expert_budget',
        ]);

        $pending = $this->postJson('/api/v1/auth/login', [
            'email' => 'mfa@ceeac.int',
            'password' => 'secret-123',
        ])->assertStatus(202)->json('secret');
        $this->assertGuest();
        $this->assertIsString($pending);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'mfa@ceeac.int',
            'password' => 'secret-123',
            'secret' => $pending,
            'code' => Totp::code($pending, intdiv(time(), 30)),
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'mfa@ceeac.int',
            'password' => 'secret-123',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    }
}
