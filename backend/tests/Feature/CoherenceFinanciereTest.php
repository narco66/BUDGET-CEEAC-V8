<?php

namespace Tests\Feature;

use App\Domains\Budget\Services\BudgetBalanceService;
use App\Domains\Commitments\Models\Paiement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Une seule source pour les agrégats financiers ; les incohérences sont relevées, jamais masquées. */
class CoherenceFinanciereTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_les_tableaux_de_bord_reprennent_la_source_unique(): void
    {
        $execution = app(BudgetBalanceService::class)->execution();
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();
        $secretaire = User::query()->where('role', 'secretaire_general')->firstOrFail();

        $paiements = $this->actingAs($comptable)->getJson('/api/v1/paiements')->assertOk()->json('tableau_de_bord.execution');
        $ordres = $this->actingAs($secretaire)->getJson('/api/v1/ordonnancements')->assertOk()->json('tableau_de_bord.execution');

        foreach (['vote', 'engage', 'liquide', 'ordonnance', 'paye'] as $cle) {
            $this->assertSame($execution[$cle], $paiements[$cle], 'Paiements · '.$cle);
            $this->assertSame($execution[$cle], $ordres[$cle], 'Ordonnancements · '.$cle);
        }
        $this->assertSame(
            (int) DB::table('paiement_executions')->where('status', '!=', 'rejetee')->sum('montant'),
            $execution['paye'],
        );
    }

    public function test_un_paye_superieur_a_l_ordonnance_est_inscrit_au_registre(): void
    {
        $paiement = Paiement::query()->firstOrFail();
        DB::table('paiements')->where('id', $paiement->id)->update(['montant_paye' => (int) $paiement->montant + 1]);
        $auditeur = User::query()->where('role', 'auditeur')->firstOrFail();

        $codes = collect($this->actingAs($auditeur)->getJson('/api/v1/controles/anomalies')->assertOk()->json('data'))->pluck('code');

        $this->assertContains('PAY-SUP-ORD-'.$paiement->reference, $codes);
    }
}
