<?php

namespace Tests\Feature;

use App\Domains\Revenues\Models\RevenueOrder;
use App\Domains\Revenues\Notifications\RevenueAlerte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Conservation : seules les notifications lues anciennes sont purgées.
 * Alertes recettes : cible structurée vers le titre.
 */
class NotificationsConservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_purge_ne_retire_que_les_notifications_lues_anciennes(): void
    {
        $user = User::factory()->create();
        $ancienneLue = $this->notification($user, now()->subDays(120), now()->subDays(100));
        $ancienneNonLue = $this->notification($user, now()->subDays(120), null);
        $recenteLue = $this->notification($user, now()->subDays(10), now()->subDays(5));

        $this->artisan('notifications:purger')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['id' => $ancienneLue]);
        $this->assertDatabaseHas('notifications', ['id' => $ancienneNonLue]);
        $this->assertDatabaseHas('notifications', ['id' => $recenteLue]);
        $this->assertDatabaseHas('audit_events', ['action' => 'notifications.purge']);
    }

    public function test_une_alerte_recette_cible_le_titre(): void
    {
        $order = new RevenueOrder;
        $order->forceFill(['id' => 42, 'reference' => 'REC-2026-000042']);
        $data = (new RevenueAlerte($order, 'REC-2026-000042 : échéance proche.'))->toArray(new User);

        $this->assertSame(['type' => 'titre', 'id' => 42], $data['cible']);
        $this->assertSame('recettes', $data['module']);
        $this->assertSame('REC-2026-000042', $data['reference']);
    }

    private function notification(User $user, mixed $creee, mixed $lue): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Domains\\Tasks\\Notifications\\TaskAssigned',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode(['message' => 'Essai', 'lien' => '/taches/1']),
            'read_at' => $lue,
            'created_at' => $creee,
            'updated_at' => $creee,
        ]);

        return $id;
    }
}
