<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Tasks\Models\WorkflowTask;
use App\Domains\Tasks\Notifications\TaskAssigned;
use App\Domains\Tasks\Services\TaskAudience;
use App\Models\User;
use App\Shared\Notifications\RoleHolders;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Processus de notification : une tâche par action attendue, sans doublon,
 * adressée à ceux qui peuvent effectivement agir.
 */
class NotificationsCircuitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        Storage::fake();
    }

    public function test_une_soumission_produit_une_seule_notification_pour_le_valideur(): void
    {
        $clarisse = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $directeur = User::query()->where('email', 'jp.okombi@ceeac.int')->firstOrFail();
        $id = $this->brouillon($clarisse, 500_000);

        $this->assertSame(0, $clarisse->notifications()->count(), 'L’auteur n’est pas notifié de sa propre tâche.');
        $avant = $directeur->notifications()->count();

        $this->actingAs($clarisse)->postJson("/api/v1/expressions-besoin/{$id}/soumettre")->assertOk();

        $this->assertSame($avant + 1, $directeur->notifications()->count());
        $avis = $directeur->notifications()->latest()->first();
        $this->assertSame(TaskAssigned::class, $avis->type);
        $message = (string) $avis->data['message'];
        $this->assertStringContainsString('nécessite votre validation', $message, 'L’action attendue est nommée.');
        $this->assertStringContainsString('Atelier régional', $message, 'L’objet du dossier figure dans l’avis.');
        $this->assertStringContainsString('500 000 FCFA', $message, 'Le montant figure dans l’avis.');
        $this->assertStringContainsString('échéance le', $message);
    }

    public function test_un_retour_notifie_l_initiateur_une_fois_avec_le_motif(): void
    {
        $clarisse = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $directeur = User::query()->where('email', 'jp.okombi@ceeac.int')->firstOrFail();
        $id = $this->brouillon($clarisse, 500_000);
        $this->actingAs($clarisse)->postJson("/api/v1/expressions-besoin/{$id}/soumettre")->assertOk();
        $avant = $clarisse->notifications()->count();

        $this->actingAs($directeur)->postJson("/api/v1/expressions-besoin/{$id}/retourner", [
            'motif' => 'Préciser le lieu',
            'observations' => 'Lieu de l’atelier.',
        ])->assertOk();

        $this->assertSame($avant + 1, $clarisse->notifications()->count());
        $this->assertStringContainsString('Préciser le lieu', (string) $clarisse->notifications()->latest()->first()->data['message']);
    }

    public function test_le_directeur_de_la_direction_parente_recoit_et_voit_la_tache_d_un_service(): void
    {
        $direction = OrganizationUnit::query()->create(['sigle' => 'DIR-NOTIF', 'name' => 'Direction d’essai', 'kind' => 'direction', 'is_active' => true]);
        $service = OrganizationUnit::query()->create(['parent_id' => $direction->id, 'sigle' => 'SRV-NOTIF', 'name' => 'Service d’essai', 'kind' => 'service', 'is_active' => true]);
        $directeur = User::factory()->create(['role' => 'directeur', 'organization_unit_id' => $direction->id, 'account_status' => 'actif']);
        $autre = User::factory()->create(['role' => 'directeur', 'organization_unit_id' => OrganizationUnit::query()->create(['sigle' => 'DIR-AUTRE-N', 'name' => 'Autre', 'kind' => 'direction', 'is_active' => true])->id, 'account_status' => 'actif']);
        $tache = $this->tache('directeur', $service->id);

        $audience = app(TaskAudience::class);
        $destinataires = $audience->recipients($tache)->pluck('id');
        $this->assertTrue($destinataires->contains($directeur->id));
        $this->assertFalse($destinataires->contains($autre->id));
        $this->assertTrue($audience->covers($directeur, $tache, ['directeur']), 'Le directeur compétent voit et traite la tâche.');
        $this->assertFalse($audience->covers($autre, $tache, ['directeur']));

        $this->actingAs($directeur)->getJson('/api/v1/taches')->assertOk()->assertJsonFragment(['dossier' => 'EB/TEST/NOTIF']);
        $this->actingAs($autre)->getJson('/api/v1/taches')->assertOk()->assertJsonMissing(['dossier' => 'EB/TEST/NOTIF']);
    }

    public function test_la_lecture_des_taches_ne_les_reprojette_pas(): void
    {
        $directeur = User::query()->where('email', 'jp.okombi@ceeac.int')->firstOrFail();
        $avant = WorkflowTask::query()->max('updated_at');

        $this->actingAs($directeur)->getJson('/api/v1/taches/compteur')->assertOk();
        $this->actingAs($directeur)->getJson('/api/v1/taches')->assertOk();

        $this->assertSame($avant, WorkflowTask::query()->max('updated_at'), 'Consulter ne modifie aucune tâche.');
    }

    public function test_les_porteurs_effectifs_du_role_sont_prevenus_et_eux_seuls(): void
    {
        $cf = User::factory()->create(['role' => 'controleur_financier', 'account_status' => 'actif']);
        $interimaire = User::factory()->create(['role' => 'initiateur', 'account_status' => 'actif']);
        $habilite = User::factory()->create(['role' => 'initiateur', 'account_status' => 'actif']);
        $desactive = User::factory()->create(['role' => 'controleur_financier', 'account_status' => 'desactive']);

        DB::table('substitutions')->insert([
            'titulaire_id' => $cf->id,
            'interim_id' => $interimaire->id,
            'fonction' => 'controleur_financier',
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addDays(5)->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleId = DB::table('roles')->where('code', 'controleur_financier')->value('id')
            ?? DB::table('roles')->insertGetId(['code' => 'controleur_financier', 'label' => 'Contrôleur financier', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert([
            'user_id' => $habilite->id,
            'role_id' => $roleId,
            'status' => 'active',
            'starts_on' => now()->subDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $porteurs = app(RoleHolders::class)->query('controleur_financier')->pluck('id');
        $this->assertTrue($porteurs->contains($cf->id));
        $this->assertTrue($porteurs->contains($interimaire->id), 'L’intérimaire exerce le rôle : il est prévenu.');
        $this->assertTrue($porteurs->contains($habilite->id), 'L’habilité exerce le rôle : il est prévenu.');
        $this->assertFalse($porteurs->contains($desactive->id), 'Un compte désactivé n’est pas prévenu.');

        $tache = $this->tache('controleur_financier', null);
        $this->assertTrue(app(TaskAudience::class)->recipients($tache)->pluck('id')->contains($interimaire->id));
    }

    private function brouillon(User $auteur, int $montant): int
    {
        $ligne = BudgetLine::query()->where('code', '203232')->firstOrFail();
        $id = $this->actingAs($auteur)->postJson('/api/v1/expressions-besoin', ['budget_line_id' => $ligne->id])->assertCreated()->json('data.id');
        $this->actingAs($auteur)->patchJson("/api/v1/expressions-besoin/{$id}", [
            'objet' => 'Atelier régional',
            'justification' => 'Renforcement des capacités.',
            'lignes' => [['designation' => 'Forfait', 'quantite' => 1, 'unite' => 'forfait', 'prix_unitaire' => $montant]],
        ])->assertOk();
        $this->actingAs($auteur)->post("/api/v1/expressions-besoin/{$id}/documents", [
            'type' => 'Devis',
            'fichier' => UploadedFile::fake()->create('devis.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();

        return (int) $id;
    }

    private function tache(string $role, ?int $unitId): WorkflowTask
    {
        return WorkflowTask::query()->create([
            'reference' => 'TSK-TEST-'.uniqid(),
            'fingerprint' => hash('sha256', uniqid('', true)),
            'module' => 'eb',
            'entity_type' => 'expression_besoin',
            'entity_id' => 999999,
            'dossier_reference' => 'EB/TEST/NOTIF',
            'subject' => 'Valider EB/TEST/NOTIF',
            'action' => 'valider',
            'step' => $role,
            'assigned_role' => $role,
            'organization_unit_id' => $unitId,
            'priority' => 'normale',
            'status' => 'a_traiter',
            'amount' => 1000,
            'lien' => '/expressions-besoin/999999',
            'assigned_at' => now(),
        ]);
    }
}
