<?php

namespace Tests\Feature;

use App\Domains\Administration\Models\AuditEvent;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Models\User;
use App\Shared\Audit\AuditService;
use Database\Seeders\AdministrationSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditTraceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->seed(AdministrationSeeder::class);
    }

    public function test_le_journal_protege_le_secret_le_rollback_et_la_reprise(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();
        $service = app(AuditService::class);
        $event = $service->enregistrer($admin, 'audit.essai', 'user', (string) $admin->id, [
            'password' => 'secret-a-ne-pas-conserver',
            'nom' => 'avant',
        ], [
            'token' => 'jeton',
            'nom' => 'apres',
        ]);
        $this->assertSame('apres', $event->after['nom']);
        $this->assertArrayNotHasKey('password', $event->before ?? []);
        $this->assertArrayNotHasKey('token', $event->after ?? []);
        $this->assertNotNull($event->actor_name);
        $this->assertSame($admin->role, $event->role);
        $this->assertNotNull($event->occurred_at);

        try {
            DB::transaction(function () use ($service, $admin) {
                $service->enregistrer($admin, 'audit.rollback', 'user', (string) $admin->id);
                throw new \RuntimeException('annule');
            });
        } catch (\RuntimeException) {
            $service->enregistrer($admin, 'audit.refus', 'user', (string) $admin->id, null, null, 'Opération annulée', 'refus');
        }
        $this->assertDatabaseMissing('audit_events', ['action' => 'audit.rollback']);
        $this->assertDatabaseHas('audit_events', ['action' => 'audit.refus', 'result' => 'refus']);

        $this->expectException(LogicException::class);
        $event->forceFill(['motif' => 'réécriture'])->save();
    }

    public function test_la_reprise_n_invente_pas_le_role_et_le_gel_empeche_la_purge(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();
        $eb = ExpressionBesoin::query()->firstOrFail();
        DB::table('eb_events')->insert([
            'expression_besoin_id' => $eb->id,
            'actor_id' => $admin->id,
            'action' => 'creation',
            'from_status' => null,
            'to_status' => 'brouillon',
            'motif' => 'Source historique',
            'created_at' => '2026-06-01 08:00:00',
            'updated_at' => '2026-06-01 08:00:00',
        ]);
        $service = app(AuditService::class);
        $premier = $service->reprendreHistoriques();
        $this->assertGreaterThan(0, $premier['repris']);
        $repris = AuditEvent::query()->where('source_table', 'eb_events')->where('motif', 'Source historique')->firstOrFail();
        $this->assertNull($repris->role);
        $this->assertNull($repris->habilitation);
        $this->assertSame($admin->id, $repris->actor_id);
        $this->assertSame(0, $service->reprendreHistoriques()['repris']);

        $this->actingAs($admin)->postJson('/api/v1/admin/audit/gel', [
            'scope' => 'journal',
            'motif' => 'Contrôle en cours',
        ])->assertCreated();
        $this->artisan('audit:purger')->assertExitCode(1);
        $this->assertDatabaseHas('audit_events', ['action' => 'audit.gel']);

        $lecteur = User::query()->where('role', 'administrateur_fonctionnel')->first();
        if ($lecteur !== null) {
            $this->actingAs($lecteur)->getJson('/api/v1/admin/audit/export')->assertForbidden();
        }
        $this->actingAs($admin)->get('/api/v1/audit/chronologie?type=expression_besoin&id='.$eb->id)->assertOk();
    }
}
