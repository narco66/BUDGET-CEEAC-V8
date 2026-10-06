<?php

namespace Tests\Feature;

use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Notifications\OrdonnancementWorkflowNotification;
use App\Domains\Commitments\Notifications\PaiementWorkflowNotification;
use App\Models\User;
use Database\Seeders\AdministrationSeeder;
use Database\Seeders\EngagementSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Database\Seeders\LiquidationSeeder;
use Database\Seeders\OrdonnancementSeeder;
use Database\Seeders\PaiementSeeder;
use Database\Seeders\TiersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L’initiateur est informé du sort de son dossier après la liquidation :
 * signature de l’ordre, règlement partiel ou total, incidents.
 */
class NotificationsReglementTest extends TestCase
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

    public function test_l_initiateur_suit_la_signature_le_reglement_et_le_rejet_bancaire(): void
    {
        $ordre = Ordonnancement::query()->where('reference', 'ORD-2026-000001')->firstOrFail();
        $initiateur = $ordre->liquidation->engagement->expressionBesoin->initiator;
        $this->assertInstanceOf(User::class, $initiateur);

        $this->actingAs(User::query()->where('role', $ordre->ordonnateur_role)->firstOrFail())
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertOk();
        $this->assertTrue($this->recu($initiateur, OrdonnancementWorkflowNotification::class, 'signé et transmis'));

        $paiement = Paiement::query()->where('ordonnancement_id', $ordre->id)->firstOrFail();
        $this->autoriser($paiement);
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();
        $moitie = (int) floor($paiement->montant / 2);

        $this->actingAs($comptable)->post("/api/v1/paiements/{$paiement->id}/executer", [
            'montant' => $moitie,
            'reference' => 'VIR-INFO-1',
            'date_valeur' => now()->toDateString(),
            'preuve' => $this->avisBancaire(),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertTrue($this->recu($initiateur, PaiementWorkflowNotification::class, 'réglé partiellement'));

        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/rejet-bancaire", ['motif' => 'Compte clôturé'])->assertOk();
        $this->assertTrue($this->recu($initiateur, PaiementWorkflowNotification::class, 'rejeté par la banque : Compte clôturé'));
    }

    private function recu(User $user, string $classe, string $texte): bool
    {
        return $user->notifications()->where('type', $classe)->get()
            ->contains(fn ($notice) => str_contains((string) ($notice->data['message'] ?? ''), $texte));
    }

    private function autoriser(Paiement $paiement): void
    {
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/prendre-en-charge")->assertOk();
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/preparer", [
            'mode' => 'virement',
            'compte_bancaire_id' => $this->compteValide($paiement),
            'compte_ceeac' => 'CEEAC-01',
        ])->assertOk();
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/soumettre")->assertOk();
        $this->actingAs(User::query()->where('role', 'chef_comptable')->firstOrFail())->postJson("/api/v1/paiements/{$paiement->id}/valider")->assertOk();
        $this->actingAs(User::query()->where('role', 'agent_comptable')->firstOrFail())
            ->postJson("/api/v1/paiements/{$paiement->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertOk();
    }
}
