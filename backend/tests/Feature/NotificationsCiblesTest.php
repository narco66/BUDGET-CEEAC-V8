<?php

namespace Tests\Feature;

use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Needs\Notifications\EbWorkflowNotification;
use App\Domains\Tasks\Models\WorkflowTask;
use App\Models\User;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationsCiblesTest extends TestCase
{
    use RefreshDatabase;

    private User $lecteur;

    private ExpressionBesoin $eb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->eb = ExpressionBesoin::query()->with('initiator')->firstOrFail();
        $this->lecteur = $this->eb->initiator ?? User::query()->whereNotNull('role')->firstOrFail();
    }

    public function test_une_notification_eb_ouvre_la_fiche_et_marque_la_lecture(): void
    {
        $this->lecteur->notify(new EbWorkflowNotification($this->eb, 'soumise pour validation'));
        $id = $this->lecteur->notifications()->latest()->firstOrFail()->id;
        $avant = $this->lecteur->unreadNotifications()->count();

        $this->actingAs($this->lecteur)
            ->getJson('/api/v1/notifications/'.$id)
            ->assertOk()
            ->assertJsonPath('data.lue', false)
            ->assertJsonPath('data.cible.chemin', '/expressions-besoin/'.$this->eb->id);

        $this->assertNull($this->lecteur->notifications()->find($id)?->read_at);

        $this->actingAs($this->lecteur)
            ->postJson('/api/v1/notifications/'.$id.'/ouvrir')
            ->assertOk()
            ->assertJsonPath('chemin', '/expressions-besoin/'.$this->eb->id)
            ->assertJsonPath('non_lues', $avant - 1);

        $this->actingAs($this->lecteur)
            ->postJson('/api/v1/notifications/'.$id.'/ouvrir')
            ->assertOk()
            ->assertJsonPath('non_lues', $avant - 1);

        $this->assertSame(1, $this->lecteur->notifications()->whereKey($id)->whereNotNull('read_at')->count());
    }

    public function test_la_notification_d_un_autre_utilisateur_est_introuvable(): void
    {
        $this->lecteur->notify(new EbWorkflowNotification($this->eb, 'validée'));
        $id = $this->lecteur->notifications()->latest()->firstOrFail()->id;
        $autre = User::factory()->create(['role' => 'comptable']);

        $this->actingAs($autre)->getJson('/api/v1/notifications/'.$id)->assertNotFound();
        $this->actingAs($autre)->postJson('/api/v1/notifications/'.$id.'/ouvrir')->assertNotFound();
        $this->actingAs($autre)->getJson('/api/v1/notifications')->assertOk()->assertJsonMissing(['id' => $id]);
    }

    public function test_un_utilisateur_sans_droit_ne_recoit_pas_le_chemin(): void
    {
        $exclu = User::factory()->create(['role' => null]);
        $id = $this->deposer($exclu, [
            'expression_besoin_id' => $this->eb->id,
            'message' => 'Ancien avis',
            'reference' => $this->eb->reference,
        ]);

        $this->actingAs($exclu)
            ->postJson('/api/v1/notifications/'.$id.'/ouvrir')
            ->assertForbidden()
            ->assertJsonPath('liste', null)
            ->assertJsonMissingPath('chemin');

        $this->assertNull($exclu->notifications()->find($id)?->read_at);
    }

    public function test_un_dossier_absent_reste_signale_sans_navigation(): void
    {
        $id = $this->deposer($this->lecteur, [
            'engagement_id' => 999999,
            'message' => 'Engagement retiré',
            'reference' => 'ENG-ABSENT',
        ]);

        $this->actingAs($this->lecteur)
            ->postJson('/api/v1/notifications/'.$id.'/ouvrir')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Le dossier lié à cette notification n’existe plus.');

        $this->assertNull($this->lecteur->notifications()->find($id)?->read_at);
    }

    public function test_une_tache_deja_traitee_s_ouvre_en_consultation(): void
    {
        $tache = WorkflowTask::query()->create([
            'reference' => 'TSK-NOTIF-1',
            'fingerprint' => hash('sha256', 'notif-tache'),
            'module' => 'eb',
            'entity_type' => 'expression_besoin',
            'entity_id' => $this->eb->id,
            'dossier_reference' => $this->eb->reference,
            'subject' => 'Validation',
            'action' => 'valider',
            'assigned_role' => 'initiateur',
            'assigned_user_id' => $this->lecteur->id,
            'status' => 'terminee',
            'completed_at' => now(),
            'completion_action' => 'validee',
            'lien' => '/expressions-besoin/'.$this->eb->id,
            'assigned_at' => now(),
        ]);
        $id = $this->deposer($this->lecteur, [
            'message' => 'Tâche traitée',
            'lien' => '/taches/'.$tache->id,
        ]);

        $this->actingAs($this->lecteur)
            ->postJson('/api/v1/notifications/'.$id.'/ouvrir')
            ->assertOk()
            ->assertJsonPath('chemin', '/taches/'.$tache->id);

        $this->assertSame('terminee', $tache->fresh()->status);
    }

    public function test_les_anciens_formats_et_les_liens_interdits(): void
    {
        $historique = $this->deposer($this->lecteur, [
            'expression_besoin_id' => $this->eb->id,
            'message' => 'Format antérieur sans objet cible',
        ]);
        $this->actingAs($this->lecteur)
            ->postJson('/api/v1/notifications/'.$historique.'/ouvrir')
            ->assertOk()
            ->assertJsonPath('chemin', '/expressions-besoin/'.$this->eb->id);

        foreach (['/suivi/saisie', '/suivi/synthese', '/suivi/rapports', '/suivi/ecarts', '/recettes/rapprochements'] as $lien) {
            $id = $this->deposer($this->lecteur, ['message' => 'Écran '.$lien, 'lien' => '/'.ltrim($lien, '/')]);
            $this->actingAs($this->lecteur)
                ->postJson('/api/v1/notifications/'.$id.'/ouvrir')
                ->assertOk()
                ->assertJsonPath('chemin', '/'.ltrim($lien, '/'));
        }

        foreach (['https://evil.example/dossier', '//evil.example', '/expressions-besoin/1/../../admin', '/inconnu/4'] as $lien) {
            $id = $this->deposer($this->lecteur, ['message' => 'Ne pas suivre '.$lien, 'lien' => $lien]);
            $reponse = $this->actingAs($this->lecteur)
                ->postJson('/api/v1/notifications/'.$id.'/ouvrir')
                ->assertOk()
                ->assertJsonPath('chemin', null);
            $this->assertStringNotContainsString('evil.example', $reponse->getContent());
        }
    }

    public function test_le_marquage_groupe_et_le_filtre_sont_idempotents(): void
    {
        $this->deposer($this->lecteur, ['message' => 'Sans dossier']);
        $this->lecteur->notify(new EbWorkflowNotification($this->eb, 'en attente'));

        $this->actingAs($this->lecteur)->postJson('/api/v1/notifications/lues')->assertOk()->assertJsonPath('non_lues', 0);
        $this->actingAs($this->lecteur)->postJson('/api/v1/notifications/lues')->assertOk()->assertJsonPath('non_lues', 0);

        $id = $this->deposer($this->lecteur, ['message' => 'À relire']);
        $this->actingAs($this->lecteur)->postJson('/api/v1/notifications/'.$id.'/lire')->assertOk();
        $this->actingAs($this->lecteur)->postJson('/api/v1/notifications/'.$id.'/lire')->assertOk()->assertJsonPath('data.lue', true);
        $this->actingAs($this->lecteur)->postJson('/api/v1/notifications/'.$id.'/non-lue')->assertOk()->assertJsonPath('data.lue', false);

        $this->lecteur->notify(new EbWorkflowNotification($this->eb, 'de nouveau en attente'));
        $this->actingAs($this->lecteur)
            ->getJson('/api/v1/notifications?lu=non_lues&type=expression_besoin')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'expression_besoin');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deposer(User $user, array $data): string
    {
        $id = (string) Str::uuid();
        $user->notifications()->create([
            'id' => $id,
            'type' => 'historique',
            'data' => $data,
        ]);

        return $id;
    }
}
