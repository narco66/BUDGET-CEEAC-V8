<?php

namespace Tests\Feature;

use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Tasks\Models\WorkflowTask;
use App\Models\User;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MesTachesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
    }

    public function test_la_boite_ne_contient_que_les_taches_du_role(): void
    {
        $clarisse = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();

        $response = $this->actingAs($clarisse)
            ->getJson('/api/v1/taches')
            ->assertOk()
            ->assertJsonStructure(['tableau_de_bord' => ['a_traiter', 'urgentes', 'en_retard', 'retournees']]);

        foreach ($response->json('data') as $task) {
            $this->assertSame('initiateur', $task['role']);
        }

        $eb = ExpressionBesoin::query()->where('workflow_step', 'directeur')->firstOrFail();
        $directeur = User::query()->where('role', 'directeur')->where('organization_unit_id', $eb->organization_unit_id)->firstOrFail();
        $this->actingAs($directeur)
            ->getJson('/api/v1/taches')
            ->assertOk()
            ->assertJsonFragment(['dossier' => $eb->reference]);
    }

    public function test_une_etape_terminee_cloture_la_tache_et_ouvre_la_suivante(): void
    {
        $eb = ExpressionBesoin::query()->where('workflow_step', 'directeur')->whereNotIn('status', ['approuvee', 'rejetee', 'annulee', 'transformee_engagement'])->firstOrFail();
        $directeur = User::query()->where('role', 'directeur')->where('organization_unit_id', $eb->organization_unit_id)->firstOrFail();

        $this->actingAs($directeur)
            ->getJson('/api/v1/taches?q='.$eb->reference)
            ->assertOk()
            ->assertJsonFragment(['dossier' => $eb->reference]);

        $eb->forceFill(['status' => 'transformee_engagement', 'workflow_step' => 'clos'])->save();

        $this->actingAs($directeur)
            ->getJson('/api/v1/taches?q='.$eb->reference)
            ->assertOk()
            ->assertJsonMissing(['dossier' => $eb->reference]);

        $this->assertDatabaseHas('workflow_tasks', [
            'dossier_reference' => $eb->reference,
            'status' => 'terminee',
        ]);
    }

    public function test_un_tiers_ne_peut_pas_commenter_la_tache(): void
    {
        $eb = ExpressionBesoin::query()->where('workflow_step', 'directeur')->firstOrFail();
        $directeur = User::query()->where('role', 'directeur')->where('organization_unit_id', $eb->organization_unit_id)->firstOrFail();
        $clarisse = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $this->actingAs($directeur)->getJson('/api/v1/taches')->assertOk();
        $task = WorkflowTask::query()->where('dossier_reference', $eb->reference)->where('status', '!=', 'terminee')->firstOrFail();

        $this->actingAs($directeur)
            ->postJson("/api/v1/taches/{$task->id}/commentaires", ['body' => 'Pièce complémentaire ajoutée.'])
            ->assertOk();

        $this->actingAs($clarisse)
            ->postJson("/api/v1/taches/{$task->id}/commentaires", ['body' => 'Intrusion'])
            ->assertForbidden();

        $this->assertDatabaseHas('audit_events', ['action' => 'tache.commentaire', 'object_id' => (string) $task->id]);

        $this->actingAs($directeur)
            ->postJson("/api/v1/taches/{$task->id}/prendre")
            ->assertOk()
            ->assertJsonPath('data.statut', 'en_cours');

        $this->actingAs($directeur)->getJson('/api/v1/taches')->assertOk();
        $this->assertSame('en_cours', $task->fresh()->status);
    }

    public function test_les_retards_remontent_dans_le_filtre(): void
    {
        $eb = ExpressionBesoin::query()->where('workflow_step', 'directeur')->firstOrFail();
        $directeur = User::query()->where('role', 'directeur')->where('organization_unit_id', $eb->organization_unit_id)->firstOrFail();
        $this->actingAs($directeur)->getJson('/api/v1/taches')->assertOk();
        $eb->forceFill(['due_on' => now()->subDays(3)->toDateString()])->save();

        $this->actingAs($directeur)->getJson('/api/v1/taches')->assertOk();
        $fresh = WorkflowTask::query()->where('dossier_reference', $eb->reference)->where('status', '!=', 'terminee')->firstOrFail();
        $this->assertSame('critique', $fresh->priority);

        $this->actingAs($directeur)
            ->getJson('/api/v1/taches?vue=retard')
            ->assertOk()
            ->assertJsonFragment(['dossier' => $fresh->dossier_reference]);
    }

    public function test_la_tache_suit_la_transaction_du_dossier_et_le_rejet_est_trace(): void
    {
        $eb = ExpressionBesoin::query()->where('workflow_step', 'directeur')->whereNotIn('status', ['approuvee', 'rejetee', 'annulee', 'transformee_engagement'])->firstOrFail();
        $directeur = User::query()->where('role', 'directeur')->where('organization_unit_id', $eb->organization_unit_id)->firstOrFail();
        $task = WorkflowTask::query()->where('dossier_reference', $eb->reference)->where('status', '!=', 'terminee')->firstOrFail();

        $this->assertTrue(
            $directeur->notifications()->get()->contains(fn ($notice) => str_contains((string) ($notice->data['message'] ?? ''), 'nécessite votre'))
        );

        $before = DB::table('notifications')->count();
        DB::beginTransaction();
        $task->delete();
        // Échéance dépassée, distincte de celle du jeu de démonstration : le test ne dépend pas du jour où il s’exécute.
        $retard = now()->subDay();
        if ($eb->due_on !== null && $retard->isSameDay($eb->due_on)) {
            $retard = $retard->subDay();
        }
        $eb->forceFill(['due_on' => $retard->toDateString()])->save();
        $this->assertGreaterThan($before, DB::table('notifications')->count());
        DB::rollBack();
        $this->assertSame($before, DB::table('notifications')->count());

        $eb->refresh();
        $eb->forceFill(['status' => 'rejetee', 'workflow_step' => 'clos'])->save();
        $this->assertDatabaseHas('workflow_tasks', [
            'dossier_reference' => $eb->reference,
            'status' => 'terminee',
            'completion_action' => 'rejetee',
        ]);
    }

    public function test_la_fiche_le_filtre_a_traiter_et_la_vue_unite_sont_en_lecture_seule(): void
    {
        $eb = ExpressionBesoin::query()->where('workflow_step', 'directeur')->firstOrFail();
        $directeur = User::query()->where('role', 'directeur')->where('organization_unit_id', $eb->organization_unit_id)->firstOrFail();
        $task = WorkflowTask::query()->where('dossier_reference', $eb->reference)->where('status', '!=', 'terminee')->firstOrFail();

        $this->actingAs($directeur)
            ->getJson('/api/v1/taches/'.$task->id)
            ->assertOk()
            ->assertJsonStructure(['data' => ['banniere', 'chronologie', 'documents', 'finances', 'type', 'peut_agir', 'exercice']])
            ->assertJsonPath('data.peut_agir', true)
            ->assertJsonPath('data.type', 'EB');

        $pending = $this->actingAs($directeur)->getJson('/api/v1/taches?vue=a_traiter')->assertOk()->json('data');
        foreach ($pending as $row) {
            $this->assertSame('a_traiter', $row['statut']);
        }

        $budget = User::query()->where('role', 'secretaire_general')->firstOrFail();
        $unit = $this->actingAs($budget)
            ->getJson('/api/v1/taches?perimetre=unite')
            ->assertOk()
            ->assertJsonPath('tableau_de_bord.vue_unite', true)
            ->json('data');
        $foreign = collect($unit)->first(fn (array $row) => $row['peut_agir'] === false);
        $this->assertNotNull($foreign);
        $this->actingAs($budget)->postJson('/api/v1/taches/'.$foreign['id'].'/prendre')->assertForbidden();
        $this->actingAs($budget)->getJson('/api/v1/taches/'.$foreign['id'])->assertOk();
    }

    public function test_une_echeance_lointaine_est_faible_et_la_relance_ne_se_repete_pas(): void
    {
        $eb = ExpressionBesoin::query()->where('workflow_step', 'directeur')->firstOrFail();
        $eb->forceFill(['due_on' => now()->addDays(30)->toDateString()])->save();
        $task = WorkflowTask::query()->where('dossier_reference', $eb->reference)->where('status', '!=', 'terminee')->firstOrFail();
        $this->assertSame('faible', $task->priority);

        $task->forceFill([
            'assigned_at' => now()->subDays(2),
            'due_on' => now()->addDays(2)->toDateString(),
            'reminder_level' => 0,
            'escalation_level' => 0,
        ])->save();

        Artisan::call('taches:relances');
        $task->refresh();
        $this->assertSame(1, $task->reminder_level);
        $this->assertSame(1, $task->escalation_level);

        Artisan::call('taches:relances');
        $task->refresh();
        $this->assertSame(1, $task->reminder_level);
        $this->assertSame(1, $task->escalation_level);
    }
}
