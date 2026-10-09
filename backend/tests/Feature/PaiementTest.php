<?php

namespace Tests\Feature;

use App\Domains\Administration\Models\BusinessRule;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Models\PayLot;
use App\Models\User;
use Database\Seeders\AdministrationSeeder;
use Database\Seeders\CompleteDemonstrationChainSeeder;
use Database\Seeders\EngagementSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Database\Seeders\LiquidationSeeder;
use Database\Seeders\OrdonnancementSeeder;
use Database\Seeders\PaiementSeeder;
use Database\Seeders\TiersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaiementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->seed(EngagementSeeder::class);
        $this->seed(LiquidationSeeder::class);
        $this->seed(OrdonnancementSeeder::class);
        $this->seed(PaiementSeeder::class);
        $this->seed(TiersSeeder::class);
        $this->seed(AdministrationSeeder::class);
    }

    public function test_la_liste_expose_le_tableau_de_bord(): void
    {
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();

        $this->actingAs($comptable)
            ->getJson('/api/v1/paiements')
            ->assertOk()
            ->assertJsonPath('tableau_de_bord.total', 0)
            ->assertJsonPath('tableau_de_bord.paye', 0);
    }

    public function test_le_seeder_de_demo_complete_la_chaine_jusqu_au_paiement(): void
    {
        $this->seed(CompleteDemonstrationChainSeeder::class);
        $this->seed(PaiementSeeder::class);

        $order = Ordonnancement::query()->where('reference', 'ORD-2026-000001')->firstOrFail();
        $payment = Paiement::query()->where('ordonnancement_id', $order->id)->firstOrFail();

        $this->assertSame('transforme_paiement', $order->status->value);
        $this->assertSame('cloture', $payment->status->value);
        $this->assertSame(1, $payment->executions()->count());
    }

    public function test_le_circuit_execute_un_paiement_partiel_puis_le_rapproche(): void
    {
        $paiement = $this->autoriser('ORD-2026-000001', 'signer');
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();
        $moitie = (int) floor($paiement->montant / 2);

        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/executer", [
                'montant' => $moitie,
                'reference' => 'VIR-PARTIEL',
                'date_valeur' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preuve']);

        $this->actingAs($comptable)
            ->post("/api/v1/paiements/{$paiement->id}/executer", [
                'montant' => $moitie,
                'reference' => 'VIR-PARTIEL',
                'date_valeur' => now()->toDateString(),
                'preuve' => $this->avisBancaire(),
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', 'paye_partiel')
            ->assertJsonPath('data.executions.0.preuve', true);

        $this->actingAs($comptable)
            ->post("/api/v1/paiements/{$paiement->id}/executer", [
                'montant' => $paiement->montant - $moitie,
                'reference' => 'VIR-SOLDE',
                'date_valeur' => now()->toDateString(),
                'preuve' => $this->avisBancaire(),
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', 'a_rapprocher');

        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/rapprocher", ['reference' => 'REL-2026-000001'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'cloture');
    }

    /**
     * Scénario du cahier d’audit : un ordre réglé en trois tranches (3/10, 2/10,
     * 5/10), cumul et reste tenus à jour, dépassement refusé à chaque étape.
     */
    public function test_un_ordre_se_paie_en_trois_tranches_sans_jamais_depasser_l_ordonnance(): void
    {
        $paiement = $this->autoriser('ORD-2026-000001', 'signer');
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();
        $total = (int) $paiement->montant;
        $tranches = [intdiv($total * 3, 10), intdiv($total * 2, 10)];
        $tranches[] = $total - array_sum($tranches);

        $cumul = 0;
        foreach ($tranches as $rang => $montant) {
            $this->actingAs($comptable)
                ->post("/api/v1/paiements/{$paiement->id}/executer", [
                    'montant' => $total - $cumul + 1,
                    'reference' => 'VIR-DEPASSE-'.$rang,
                    'date_valeur' => now()->toDateString(),
                    'preuve' => $this->avisBancaire(),
                ], ['Accept' => 'application/json'])
                ->assertStatus(422);

            $cumul += $montant;
            $reponse = $this->actingAs($comptable)
                ->post("/api/v1/paiements/{$paiement->id}/executer", [
                    'montant' => $montant,
                    'reference' => 'VIR-TRANCHE-'.($rang + 1),
                    'date_valeur' => now()->toDateString(),
                    'preuve' => $this->avisBancaire(),
                ], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('data.montant_paye', $cumul)
                ->assertJsonPath('data.reste', $total - $cumul);

            $reponse->assertJsonPath('data.statut', $cumul < $total ? 'paye_partiel' : 'a_rapprocher');
            $this->assertCount($rang + 1, $reponse->json('data.executions'));
        }

        $this->actingAs($comptable)
            ->post("/api/v1/paiements/{$paiement->id}/executer", [
                'montant' => 1,
                'reference' => 'VIR-APRES-SOLDE',
                'date_valeur' => now()->toDateString(),
                'preuve' => $this->avisBancaire(),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame($total, (int) $paiement->fresh()->montant_paye);
        $this->assertSame(3, $paiement->fresh()->executions()->count());
    }

    public function test_le_rejet_bancaire_conserve_l_ordre_signe(): void
    {
        $paiement = $this->autoriser('ORD-2026-000090', 'reprendre');
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();

        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/rejet-bancaire", ['motif' => 'Compte bénéficiaire inconnu'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'rejete_bancaire');

        $ordre = Ordonnancement::query()->where('reference', 'ORD-2026-000090')->firstOrFail();
        $this->assertNotNull($ordre->signature_reference);
        $this->assertSame('transforme_paiement', $ordre->status->value);
        $this->assertSame(1, Paiement::query()->where('ordonnancement_id', $ordre->id)->count());
    }

    public function test_le_reglement_caisse_est_refuse_si_le_plafond_n_est_pas_configure(): void
    {
        $ordre = Ordonnancement::query()->where('reference', 'ORD-2026-000001')->firstOrFail();
        $signataire = User::query()->where('role', $ordre->ordonnateur_role)->firstOrFail();

        $this->actingAs($signataire)
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertOk();

        $paiement = Paiement::query()->where('ordonnancement_id', $ordre->id)->firstOrFail();
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/prendre-en-charge")->assertOk();
        BusinessRule::query()->where('code', 'plafond_caisse')->delete();

        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/preparer", ['mode' => 'caisse'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mode']);
    }

    public function test_un_lot_regroupe_deux_virements_autorises(): void
    {
        $premier = $this->autoriser('ORD-2026-000001', 'signer');
        $second = $this->autoriser('ORD-2026-000090', 'reprendre');
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();

        $this->actingAs($comptable)
            ->postJson('/api/v1/paiements/lots', [
                'libelle' => 'Virements du jour',
                'paiements' => [$premier->id, $second->id],
            ])
            ->assertCreated()
            ->assertJsonPath('reference', 'LOT-PAY-2026-000001');

        $lotId = PayLot::query()->value('id');

        $this->actingAs($comptable)
            ->post("/api/v1/paiements/lots/{$lotId}/executer", [
                'reference' => 'LOT-VIR-01',
                'date_valeur' => now()->toDateString(),
                'preuve' => $this->avisBancaire(),
            ])
            ->assertOk()
            ->assertJsonPath('statut', 'execute');

        $this->assertSame('a_rapprocher', $premier->fresh()->status->value);
        $this->assertSame('a_rapprocher', $second->fresh()->status->value);
    }

    private function autoriser(string $reference, string $action): Paiement
    {
        $ordre = Ordonnancement::query()->where('reference', $reference)->firstOrFail();
        $signataire = User::query()->where('role', $ordre->ordonnateur_role)->firstOrFail();
        if ($action === 'reprendre') {
            $ordre->forceFill(['fail_next_transmission' => true])->save();
            $this->actingAs($signataire)
                ->postJson("/api/v1/ordonnancements/{$ordre->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
                ->assertOk()
                ->assertJsonPath('data.statut', 'transmission_erreur');
        }
        $payload = $action === 'signer' ? ['confirmation' => true, 'mot_de_passe' => 'password'] : [];

        $this->actingAs($signataire)
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/{$action}", $payload)
            ->assertOk();

        $paiement = Paiement::query()->where('ordonnancement_id', $ordre->id)->firstOrFail();
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();
        $chef = User::query()->where('role', 'chef_comptable')->firstOrFail();
        $agent = User::query()->where('role', 'agent_comptable')->firstOrFail();

        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/prendre-en-charge")
            ->assertOk();

        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/preparer", ['mode' => 'caisse'])
            ->assertStatus(422);

        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/preparer", [
                'mode' => 'virement',
                'compte_bancaire_id' => $this->compteValide($paiement),
                'compte_ceeac' => 'CEEAC-01',
            ])
            ->assertOk();

        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/soumettre")
            ->assertOk();

        $this->actingAs($chef)
            ->postJson("/api/v1/paiements/{$paiement->id}/valider")
            ->assertOk();

        $this->actingAs($agent)
            ->postJson("/api/v1/paiements/{$paiement->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'autorise');

        return $paiement->fresh();
    }
}
