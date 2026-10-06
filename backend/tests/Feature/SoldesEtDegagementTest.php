<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Services\BudgetBalanceService;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
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

class SoldesEtDegagementTest extends TestCase
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

    public function test_le_disponible_du_service_de_soldes_est_celui_des_controles(): void
    {
        $lines = BudgetLine::query()->get();
        $balances = app(BudgetBalanceService::class)->forLines($lines->pluck('id'));

        foreach ($lines as $line) {
            $this->assertSame($line->disponible(), $balances[$line->id]['disponible'], 'Ligne '.$line->code);
            $this->assertSame($balances[$line->id]['revise'] - $balances[$line->id]['engage'], $balances[$line->id]['reste_a_engager']);
        }
    }

    public function test_les_soldes_suivent_la_signature_et_le_paiement(): void
    {
        $ordre = Ordonnancement::query()->where('reference', 'ORD-2026-000001')->firstOrFail();
        $line = $ordre->liquidation->engagement->budgetLine;
        $service = app(BudgetBalanceService::class);
        $avant = $service->forLine($line);

        $this->actingAs(User::query()->where('role', $ordre->ordonnateur_role)->firstOrFail())
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertOk();
        $apresSignature = $service->forLine($line);
        $this->assertSame($avant['ordonnance'] + (int) $ordre->montant, $apresSignature['ordonnance']);

        $paiement = Paiement::query()->where('ordonnancement_id', $ordre->id)->firstOrFail();
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/prendre-en-charge")->assertOk();
        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/preparer", ['mode' => 'virement', 'compte_bancaire_id' => $this->compteValide($paiement)])
            ->assertOk();
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/soumettre")->assertOk();
        $this->actingAs(User::query()->where('role', 'chef_comptable')->firstOrFail())->postJson("/api/v1/paiements/{$paiement->id}/valider")->assertOk();
        $this->actingAs(User::query()->where('role', 'agent_comptable')->firstOrFail())
            ->postJson("/api/v1/paiements/{$paiement->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertOk();
        $this->actingAs($comptable)
            ->post("/api/v1/paiements/{$paiement->id}/executer", ['montant' => 1000, 'reference' => 'VIR-SOLDES', 'date_valeur' => now()->toDateString(), 'preuve' => $this->avisBancaire()])
            ->assertOk();

        $apresPaiement = $service->forLine($line);
        $this->assertSame($apresSignature['paye'] + 1000, $apresPaiement['paye']);
        $this->assertSame($apresPaiement['ordonnance'] - $apresPaiement['paye'], $apresPaiement['reste_a_payer']);

        $this->actingAs($comptable)
            ->getJson('/api/v1/lignes-budgetaires?q='.urlencode($line->code))
            ->assertOk()
            ->assertJsonPath('data.0.soldes.paye', $apresPaiement['paye']);
    }

    public function test_le_degagement_restitue_le_reliquat_au_disponible(): void
    {
        $engagement = $this->engagementVise();
        $directeur = User::query()->where('email', 'directeur.budget@ceeac.int')->firstOrFail();
        $disponibleAvant = $engagement->budgetLine->disponible();

        $this->actingAs($directeur)
            ->postJson("/api/v1/engagements/{$engagement->id}/degager", ['montant' => 250000, 'motif' => 'Prestation réduite', 'acte' => 'Note DB/2026/12'])
            ->assertOk()
            ->assertJsonPath('data.montant_degage', 250000)
            ->assertJsonPath('data.engage_net', (int) $engagement->montant - 250000)
            ->assertJsonPath('data.degagements.0.montant', 250000);

        $this->assertSame($disponibleAvant + 250000, $engagement->budgetLine->fresh()->disponible());
        $this->assertSame((int) $engagement->montant, (int) $engagement->fresh()->montant);
        $this->assertDatabaseHas('audit_events', ['action' => 'engagement.degagement', 'object_id' => (string) $engagement->id]);
    }

    public function test_le_degagement_ne_depasse_pas_la_part_non_liquidee(): void
    {
        $engagement = $this->engagementVise();
        $directeur = User::query()->where('email', 'directeur.budget@ceeac.int')->firstOrFail();

        $this->actingAs($directeur)
            ->postJson("/api/v1/engagements/{$engagement->id}/degager", ['montant' => (int) $engagement->montant + 1, 'motif' => 'Trop'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['montant']);

        $this->actingAs(User::query()->where('email', 'controleur.financier@ceeac.int')->firstOrFail())
            ->postJson("/api/v1/engagements/{$engagement->id}/degager", ['montant' => 1000, 'motif' => 'Hors rôle'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action']);
    }

    public function test_l_annulation_avant_visa_libere_le_credit(): void
    {
        $engagement = Engagement::query()->whereIn('status', [EngagementStatus::EnInstruction->value, EngagementStatus::AValider->value, EngagementStatus::EnControle->value])->firstOrFail();
        $disponibleAvant = $engagement->budgetLine->disponible();

        $this->actingAs(User::query()->where('email', 'directeur.budget@ceeac.int')->firstOrFail())
            ->postJson("/api/v1/engagements/{$engagement->id}/annuler", ['motif' => 'Besoin abandonné'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'annule');

        $this->assertSame($disponibleAvant + $engagement->montantNet(), $engagement->budgetLine->fresh()->disponible());
    }

    public function test_un_engagement_avec_liquidation_visee_ne_s_annule_pas(): void
    {
        $ordre = Ordonnancement::query()->where('reference', 'ORD-2026-000001')->firstOrFail();
        $engagement = $ordre->liquidation->engagement;

        $this->actingAs(User::query()->where('email', 'directeur.budget@ceeac.int')->firstOrFail())
            ->postJson("/api/v1/engagements/{$engagement->id}/annuler", ['motif' => 'Test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action']);
    }

    private function engagementVise(): Engagement
    {
        $engagement = Engagement::query()->where('reference', 'ENG-2026-000457')->firstOrFail();
        $this->actingAs(User::query()->where('email', 'controleur.financier@ceeac.int')->firstOrFail())
            ->postJson("/api/v1/engagements/{$engagement->id}/viser")
            ->assertOk();

        return $engagement->fresh();
    }
}
