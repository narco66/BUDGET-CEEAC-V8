<?php

namespace Tests\Feature;

use App\Domains\Tasks\Models\WorkflowTask;
use App\Domains\Tasks\Services\TaskProjector;
use App\Models\User;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * « Mes tâches » : délégation visible, aucune tâche orpheline, prise en
 * charge nominative, recherche, libellés, pagination.
 */
class MesTachesAmeliorationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_tache_ouverte_par_delegation_est_signalee_avec_son_titulaire(): void
    {
        $titulaire = User::factory()->create(['role' => 'directeur_budget', 'name' => 'Directrice du Budget', 'account_status' => 'actif']);
        $delegataire = User::factory()->create(['role' => 'expert_budget', 'account_status' => 'actif']);
        DB::table('admin_delegations')->insert([
            'delegant_id' => $titulaire->id,
            'delegataire_id' => $delegataire->id,
            'fonction' => 'Visa budgétaire',
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
            'motif' => 'Congé',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->tache('directeur_budget');

        $vue = $this->actingAs($delegataire)->getJson('/api/v1/taches')->assertOk()->json('data.0');
        $this->assertTrue($vue['deleguee']);
        $this->assertSame('Directrice du Budget', $vue['delegation']['titulaire']);

        $this->assertFalse($this->actingAs($titulaire)->getJson('/api/v1/taches')->assertOk()->json('data.0.deleguee'));
    }

    public function test_la_consolidation_du_suivi_n_est_jamais_orpheline(): void
    {
        $etape = new ReflectionMethod(TaskProjector::class, 'seStep');
        [$action, $role] = $etape->invoke(app(TaskProjector::class), 'valide', null, null);

        $this->assertSame('consolider', $action);
        $this->assertSame('directeur_budget', $role, 'Sans titulaire « responsable_se », le Directeur du Budget consolide.');
    }

    public function test_la_prise_en_charge_est_nominative_et_peut_etre_liberee(): void
    {
        $this->seed(ExpressionBesoinSeeder::class);
        $tache = WorkflowTask::query()->where('assigned_role', 'directeur')->where('status', 'a_traiter')->firstOrFail();
        $rita = User::query()->where('role', 'directeur')->where('organization_unit_id', $tache->organization_unit_id)->firstOrFail();
        $rita->forceFill(['name' => 'Rita OBAME'])->save();
        $collegue = User::factory()->create(['role' => 'directeur', 'organization_unit_id' => $tache->organization_unit_id, 'account_status' => 'actif']);

        $this->actingAs($rita)->postJson("/api/v1/taches/{$tache->id}/prendre")->assertOk()
            ->assertJsonPath('data.prise_par', 'Rita OBAME')
            ->assertJsonPath('data.prise_par_moi', true);

        $this->actingAs($collegue)->postJson("/api/v1/taches/{$tache->id}/prendre")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cette tâche est déjà prise en charge par Rita OBAME.');
        $this->actingAs($collegue)->getJson("/api/v1/taches/{$tache->id}")->assertOk()
            ->assertJsonPath('data.prise_par', 'Rita OBAME')
            ->assertJsonPath('data.peut_liberer', false);
        $this->actingAs($collegue)->postJson("/api/v1/taches/{$tache->id}/liberer")->assertForbidden();

        $this->actingAs($rita)->postJson("/api/v1/taches/{$tache->id}/liberer")->assertOk();
        $this->assertSame('a_traiter', $tache->fresh()->status);
        $this->assertNull($tache->fresh()->started_by);
        $this->assertDatabaseHas('audit_events', ['action' => 'tache.liberation', 'object_id' => (string) $tache->id]);
    }

    public function test_la_recherche_ignore_la_casse_et_les_libelles_sont_lisibles(): void
    {
        $comptable = User::factory()->create(['role' => 'comptable', 'account_status' => 'actif']);
        $this->tache('comptable', ['objet' => 'Atelier Régional', 'structure' => 'DSG · Direction des Systèmes', 'priority' => 'haute']);

        foreach (['atelier', 'ATELIER', 'Atelier'] as $terme) {
            $this->actingAs($comptable)->getJson('/api/v1/taches?q='.$terme)->assertOk()->assertJsonCount(1, 'data');
        }
        $this->actingAs($comptable)->getJson('/api/v1/taches?structure=dsg')->assertOk()->assertJsonCount(1, 'data');

        $ligne = $this->actingAs($comptable)->getJson('/api/v1/taches')->assertOk()->json('data.0');
        $this->assertSame('Comptable', $ligne['etape_libelle']);
        $this->assertSame('Comptable', $ligne['role_libelle']);
        $this->assertSame('Exécuter le règlement', $ligne['action_libelle']);
        $this->assertSame('Haute', $ligne['priorite_libelle']);
    }

    public function test_la_liste_est_paginee_et_les_brouillons_sont_comptes_a_part(): void
    {
        $comptable = User::factory()->create(['role' => 'comptable', 'account_status' => 'actif']);
        foreach (range(1, 7) as $rang) {
            $this->tache('comptable', ['dossier_reference' => 'PAY-PAGE-'.$rang]);
        }
        $this->tache('comptable', ['action' => 'completer', 'dossier_reference' => 'EB-BROUILLON']);
        $this->tache('comptable', ['status' => 'terminee', 'completed_at' => now(), 'completion_action' => 'etape_suivante']);

        $page = $this->actingAs($comptable)->getJson('/api/v1/taches?per_page=5')->assertOk();
        $page->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 8)->assertJsonPath('meta.last_page', 2);
        $page->assertJsonPath('tableau_de_bord.a_traiter', 7)->assertJsonPath('tableau_de_bord.brouillons', 1);

        $this->actingAs($comptable)->getJson('/api/v1/taches?vue=a_traiter')->assertOk()->assertJsonPath('meta.total', 7);
        $this->actingAs($comptable)->getJson('/api/v1/taches?vue=brouillons')->assertOk()->assertJsonPath('data.0.dossier', 'EB-BROUILLON');
        $this->actingAs($comptable)->getJson('/api/v1/taches/compteur')->assertOk()->assertJsonPath('nombre', 7);
        $this->actingAs($comptable)->getJson('/api/v1/taches?vue=terminees_aujourdhui')->assertOk()->assertJsonPath('meta.total', 1);
    }

    private function tache(string $role, array $extra = []): WorkflowTask
    {
        return WorkflowTask::query()->create([
            'reference' => 'TSK-TEST-'.uniqid(),
            'fingerprint' => hash('sha256', uniqid('', true)),
            'module' => 'paiement',
            'entity_type' => 'paiement',
            'entity_id' => random_int(100000, 999999),
            'dossier_reference' => 'PAY-TEST-'.$role,
            'subject' => 'Exécuter PAY-TEST',
            'action' => 'executer',
            'step' => $role,
            'assigned_role' => $role,
            'priority' => 'normale',
            'status' => 'a_traiter',
            'amount' => 1_000_000,
            'objet' => 'Atelier régional',
            'lien' => '/paiements/1',
            'assigned_at' => now(),
            ...$extra,
        ]);
    }
}
