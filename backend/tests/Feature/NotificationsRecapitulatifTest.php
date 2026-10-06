<?php

namespace Tests\Feature;

use App\Domains\Tasks\Models\WorkflowTask;
use App\Models\User;
use App\Shared\Notifications\RecapitulatifQuotidien;
use App\Shared\Notifications\RecapitulatifService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Récapitulatif quotidien par courriel : envoyé à qui l’a activé et a des
 * dossiers urgents, une seule fois par jour.
 */
class NotificationsRecapitulatifTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_recapitulatif_part_vers_qui_a_un_dossier_urgent_et_une_seule_fois(): void
    {
        Notification::fake();
        $actif = User::factory()->create(['role' => 'controleur_financier', 'account_status' => 'actif', 'notifications_courriel' => 'quotidien']);
        $refus = User::factory()->create(['role' => 'controleur_financier', 'account_status' => 'actif', 'notifications_courriel' => 'aucun']);
        $sansUrgence = User::factory()->create(['role' => 'comptable', 'account_status' => 'actif', 'notifications_courriel' => 'quotidien']);
        $this->tache('controleur_financier', now()->subDays(3)->toDateString());
        $this->tache('comptable', now()->addDays(20)->toDateString(), 'normale');

        $this->assertSame(1, app(RecapitulatifService::class)->envoyer());

        Notification::assertSentTo($actif, RecapitulatifQuotidien::class, function (RecapitulatifQuotidien $avis) {
            $mail = $avis->toMail($avis);

            return $avis->totalTaches === 1
                && $avis->taches[0]['retard'] === true
                && str_contains((string) $mail->subject, '1 dossier attend votre action dont 1 en retard');
        });
        Notification::assertNotSentTo($refus, RecapitulatifQuotidien::class);
        Notification::assertNotSentTo($sansUrgence, RecapitulatifQuotidien::class);

        $this->assertSame(0, app(RecapitulatifService::class)->envoyer(), 'Pas de second envoi le même jour.');
    }

    public function test_chacun_regle_sa_preference(): void
    {
        $user = User::factory()->create(['role' => 'comptable', 'account_status' => 'actif']);

        $this->actingAs($user)->getJson('/api/v1/notifications/preferences')
            ->assertOk()
            ->assertJsonPath('data.recapitulatif', 'quotidien');
        $this->actingAs($user)->putJson('/api/v1/notifications/preferences', ['recapitulatif' => 'chaque_heure'])
            ->assertUnprocessable();
        $this->actingAs($user)->putJson('/api/v1/notifications/preferences', ['recapitulatif' => 'aucun'])
            ->assertOk()
            ->assertJsonPath('data.recapitulatif', 'aucun');

        $this->assertSame('aucun', $user->fresh()->notifications_courriel);
        $this->assertDatabaseHas('audit_events', ['action' => 'notifications.preference', 'object_id' => (string) $user->id]);
    }

    private function tache(string $role, string $echeance, string $priorite = 'critique'): void
    {
        WorkflowTask::query()->create([
            'reference' => 'TSK-RECAP-'.uniqid(),
            'fingerprint' => hash('sha256', uniqid('', true)),
            'module' => 'engagement',
            'entity_type' => 'engagement',
            'entity_id' => random_int(1000, 999999),
            'dossier_reference' => 'ENG-TEST-'.$role,
            'subject' => 'Viser ENG-TEST',
            'action' => 'viser',
            'step' => $role,
            'assigned_role' => $role,
            'priority' => $priorite,
            'status' => 'a_traiter',
            'amount' => 2_500_000,
            'objet' => 'Achat de matériel',
            'lien' => '/engagements/1',
            'due_on' => $echeance,
            'assigned_at' => now(),
        ]);
    }
}
