<?php

namespace Tests\Feature;

use App\Domains\Administration\Models\AuditEvent;
use App\Domains\Administration\Services\SauvegardeVerifier;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Budget\Models\PeriodeBudgetaire;
use App\Domains\Tasks\Notifications\TaskAssigned;
use App\Models\User;
use App\Shared\Auth\Notifications\ReinitialisationMotDePasse;
use App\Shared\Support\ExerciceGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class P0SocleTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_reinitialisation_change_le_mot_de_passe_sans_le_journaliser(): void
    {
        Notification::fake();
        $user = User::factory()->create(['account_status' => 'actif', 'role' => 'initiateur']);

        $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => 'absent@ceeac.int'])->assertOk();
        Notification::assertNothingSent();

        $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => $user->email])->assertOk();
        $token = '';
        Notification::assertSentTo($user, ReinitialisationMotDePasse::class, function (ReinitialisationMotDePasse $notification) use (&$token) {
            $token = $notification->token;

            return $token !== '';
        });

        $this->postJson('/api/v1/auth/mot-de-passe/reinitialiser', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'BudgetCeeac26a',
            'password_confirmation' => 'BudgetCeeac26a',
        ])->assertOk();

        $this->assertTrue(Hash::check('BudgetCeeac26a', $user->fresh()->password));
        $journal = AuditEvent::query()->where('action', 'auth.mot_de_passe_reinitialise')->first();
        $this->assertNotNull($journal);
        $this->assertStringNotContainsString('BudgetCeeac26a', json_encode($journal->toArray()));
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'BudgetCeeac26a'])->assertOk();
    }

    public function test_une_periode_echue_se_ferme_et_la_periode_en_cours_reste_ouverte(): void
    {
        $exercice = Exercice::query()->create([
            'annee' => (int) now()->year,
            'statut' => 'executoire',
            'date_debut' => now()->startOfYear()->toDateString(),
            'date_fin' => now()->endOfYear()->toDateString(),
        ]);
        $directeur = User::factory()->create(['role' => 'directeur_budget', 'account_status' => 'actif']);
        $this->actingAs($directeur)->postJson('/api/v1/cloture/'.$exercice->id.'/periodes')->assertOk();

        $cible = PeriodeBudgetaire::query()->where('exercice_id', $exercice->id)->where('code', '!=', now()->format('Y-m'))->firstOrFail();
        $cible->forceFill([
            'starts_on' => now()->subDays(40)->toDateString(),
            'ends_on' => now()->subDay()->toDateString(),
        ])->save();
        $this->actingAs($directeur)->postJson('/api/v1/cloture/periodes/'.$cible->id.'/fermer', ['motif' => 'Mois écoulé'])->assertOk();
        $this->assertSame('ferme', $cible->fresh()->status);

        $courante = PeriodeBudgetaire::query()
            ->where('exercice_id', $exercice->id)
            ->whereDate('starts_on', '<=', now()->toDateString())
            ->whereDate('ends_on', '>=', now()->toDateString())
            ->firstOrFail();
        $this->actingAs($directeur)
            ->postJson('/api/v1/cloture/periodes/'.$courante->id.'/fermer', ['motif' => 'Interdit'])
            ->assertStatus(422);
        ExerciceGuard::assertOpen($exercice->fresh());

        $secretaire = User::factory()->create(['role' => 'secretaire_general', 'account_status' => 'actif']);
        $this->actingAs($secretaire)->postJson('/api/v1/cloture/periodes/'.$cible->id.'/rouvrir', ['motif' => 'Correction'])->assertOk();
        $this->assertSame('ouvert', $cible->fresh()->status);
    }

    public function test_les_etats_de_base_et_le_filtre_du_journal_sont_servis(): void
    {
        $exercice = Exercice::query()->create([
            'annee' => (int) now()->year,
            'statut' => 'executoire',
            'date_debut' => now()->startOfYear()->toDateString(),
            'date_fin' => now()->endOfYear()->toDateString(),
        ]);
        $auditeur = User::factory()->create(['role' => 'auditeur', 'account_status' => 'actif']);
        $this->actingAs($auditeur)->getJson('/api/v1/etats/base?exercice_id='.$exercice->id)
            ->assertOk()
            ->assertJsonPath('data.engagements_vises_non_transformes', 0);

        $auditeur->notify(new TaskAssigned('Message interne', '/taches/1'));
        $evenement = AuditEvent::query()->where('action', 'notification.envoyee')->first();
        $this->assertNotNull($evenement);
        $this->assertStringNotContainsString('Message interne', json_encode($evenement->after));

        $this->actingAs($auditeur)->getJson('/api/v1/admin/audit?du=2099-01-01')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($auditeur)->get('/api/v1/admin/audit/export?format=pdf')->assertOk();
    }

    public function test_la_verification_de_sauvegarde_ne_restaure_rien(): void
    {
        $repertoire = storage_path('framework/testing-sauvegardes');
        if (! is_dir($repertoire)) {
            mkdir($repertoire, 0777, true);
        }
        file_put_contents($repertoire.DIRECTORY_SEPARATOR.'invalide.dump', 'XXXXX');
        $service = app(SauvegardeVerifier::class);
        $this->assertFalse($service->verifier($repertoire)['valide']);
        file_put_contents($repertoire.DIRECTORY_SEPARATOR.'valide.dump', 'PGDMP contenu');
        touch($repertoire.DIRECTORY_SEPARATOR.'valide.dump', time() + 30);
        $this->assertTrue($service->verifier($repertoire)['valide']);
        $this->assertFileExists($repertoire.DIRECTORY_SEPARATOR.'valide.dump');
    }
}
