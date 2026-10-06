<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Enums\PaiementStatus;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\OrdDelegation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Models\PaiementExecution;
use App\Domains\Commitments\Services\EngagementWorkflow;
use App\Domains\Commitments\Services\PaiementWorkflow;
use App\Domains\Needs\Enums\EbStatus;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Needs\Services\ExpressionBesoinWorkflow;
use App\Models\User;
use Database\Seeders\AdministrationSeeder;
use Database\Seeders\EngagementSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Database\Seeders\LiquidationSeeder;
use Database\Seeders\OrdonnancementSeeder;
use Database\Seeders\PaiementSeeder;
use Database\Seeders\TiersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Double clic, rejeu et validations concurrentes : chaque scénario rejoue
 * une transition avec une instance périmée du dossier, comme le ferait une
 * seconde requête partie avant la fin de la première.
 */
class IntegriteChaineTest extends TestCase
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

    public function test_une_double_validation_du_visa_ne_cree_qu_une_liquidation(): void
    {
        $controleur = User::query()->where('email', 'controleur.financier@ceeac.int')->firstOrFail();
        $stale = Engagement::query()->where('reference', 'ENG-2026-000457')->firstOrFail();
        $workflow = app(EngagementWorkflow::class);

        $workflow->vise(clone $stale, $controleur);

        try {
            $workflow->vise(clone $stale, $controleur);
            $this->fail('Le second visa aurait dû être refusé.');
        } catch (ValidationException) {
            $this->assertSame(1, Liquidation::query()->where('engagement_id', $stale->id)->count());
        }
    }

    public function test_une_double_transformation_de_l_eb_ne_cree_qu_un_engagement(): void
    {
        $ordonnateur = User::query()->where('role', 'ordonnateur')->firstOrFail();
        $eb = ExpressionBesoin::query()->whereDoesntHave('engagement')->firstOrFail();
        $eb->forceFill(['status' => EbStatus::Approuvee, 'workflow_step' => 'ordonnateur'])->save();
        $stale = $eb->fresh();
        $workflow = app(ExpressionBesoinWorkflow::class);

        $workflow->transform(clone $stale, $ordonnateur);
        $workflow->transform(clone $stale, $ordonnateur);

        $this->assertSame(1, Engagement::query()->where('expression_besoin_id', $eb->id)->count());
    }

    public function test_une_eb_transformee_ne_s_annule_pas_directement(): void
    {
        $ordonnateur = User::query()->where('role', 'ordonnateur')->firstOrFail();
        $engagement = Engagement::query()->where('reference', 'ENG-2026-000457')->firstOrFail();

        $this->actingAs($ordonnateur)
            ->postJson("/api/v1/expressions-besoin/{$engagement->expression_besoin_id}/annuler", ['motif' => 'Test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action']);
    }

    public function test_un_exercice_clos_bloque_le_visa_de_l_engagement(): void
    {
        Exercice::query()->update(['statut' => 'clos']);
        $controleur = User::query()->where('email', 'controleur.financier@ceeac.int')->firstOrFail();
        $engagement = Engagement::query()->where('reference', 'ENG-2026-000457')->firstOrFail();

        $this->actingAs($controleur)
            ->postJson("/api/v1/engagements/{$engagement->id}/viser")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['exercice']);
        $this->assertSame(0, Liquidation::query()->where('engagement_id', $engagement->id)->count());
    }

    public function test_deux_executions_concurrentes_ne_depassent_pas_le_net_ordonnance(): void
    {
        $paiement = $this->paiementAutorise();
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();
        $workflow = app(PaiementWorkflow::class);
        $montant = (int) $paiement->montant;

        $workflow->executer(clone $paiement, $comptable, $montant, 'VIR-A', now()->toDateString(), $this->avisBancaire());

        try {
            $workflow->executer(clone $paiement, $comptable, $montant, 'VIR-B', now()->toDateString(), $this->avisBancaire());
            $this->fail('La seconde exécution aurait dû être refusée.');
        } catch (ValidationException) {
            $paiement->refresh();
            $this->assertSame($montant, (int) $paiement->montant_paye);
            $this->assertSame(1, $paiement->executions()->count());
        }
    }

    public function test_le_rejeu_d_une_meme_execution_est_sans_effet(): void
    {
        $paiement = $this->paiementAutorise();
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();
        $moitie = (int) floor($paiement->montant / 2);

        foreach ([1, 2] as $attempt) {
            $this->actingAs($comptable)
                ->post("/api/v1/paiements/{$paiement->id}/executer", [
                    'montant' => $moitie,
                    'reference' => 'VIR-DOUBLE-CLIC',
                    'date_valeur' => now()->toDateString(),
                    'preuve' => $this->avisBancaire(),
                ])
                ->assertOk()
                ->assertJsonPath('data.montant_paye', $moitie);
        }

        $this->assertSame(1, PaiementExecution::query()->where('paiement_id', $paiement->id)->count());
    }

    public function test_une_reference_bancaire_ne_sert_qu_une_fois(): void
    {
        $premier = $this->paiementAutorise('ORD-2026-000001', 'signer');
        $second = $this->paiementAutorise('ORD-2026-000090', 'reprendre');
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();

        $this->actingAs($comptable)
            ->post("/api/v1/paiements/{$premier->id}/executer", ['montant' => $premier->montant, 'reference' => 'VIR-UNIQUE', 'date_valeur' => now()->toDateString(), 'preuve' => $this->avisBancaire()])
            ->assertOk();

        $this->actingAs($comptable)
            ->post("/api/v1/paiements/{$second->id}/executer", ['montant' => $second->montant, 'reference' => 'VIR-UNIQUE', 'date_valeur' => now()->toDateString(), 'preuve' => $this->avisBancaire()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference']);
    }

    public function test_un_rejet_bancaire_apres_execution_retablit_le_reste_et_impose_une_reemission(): void
    {
        $paiement = $this->paiementAutorise();
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();

        $this->actingAs($comptable)
            ->post("/api/v1/paiements/{$paiement->id}/executer", ['montant' => $paiement->montant, 'reference' => 'VIR-REJETE', 'date_valeur' => now()->toDateString(), 'preuve' => $this->avisBancaire()])
            ->assertOk()
            ->assertJsonPath('data.statut', 'a_rapprocher');

        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/rejet-bancaire", ['motif' => 'Compte clôturé'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'rejete_bancaire')
            ->assertJsonPath('data.montant_paye', 0)
            ->assertJsonPath('data.executions.0.statut', 'rejetee');

        $this->actingAs($comptable)
            ->post("/api/v1/paiements/{$paiement->id}/executer", ['montant' => $paiement->montant, 'reference' => 'VIR-DIRECT', 'date_valeur' => now()->toDateString(), 'preuve' => $this->avisBancaire()])
            ->assertStatus(422);

        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/reemettre", ['motif' => 'Nouveau RIB fourni'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'en_preparation');

        $this->autoriserPaiement($paiement->fresh());
        $this->actingAs($comptable)
            ->post("/api/v1/paiements/{$paiement->id}/executer", ['montant' => $paiement->montant, 'reference' => 'VIR-REEMIS', 'date_valeur' => now()->toDateString(), 'preuve' => $this->avisBancaire()])
            ->assertOk()
            ->assertJsonPath('data.statut', 'a_rapprocher')
            ->assertJsonPath('data.montant_paye', (int) $paiement->montant);

        $this->assertSame(2, PaiementExecution::query()->where('paiement_id', $paiement->id)->count());
        $this->assertDatabaseHas('paiement_executions', ['reference_reglement' => 'VIR-REJETE', 'status' => 'rejetee']);
    }

    public function test_une_suspension_peut_etre_levee(): void
    {
        $paiement = $this->paiementAutorise();
        $agent = User::query()->where('role', 'agent_comptable')->firstOrFail();

        $this->actingAs($agent)
            ->postJson("/api/v1/paiements/{$paiement->id}/suspendre", ['motif' => 'Vérification RIB'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'suspendu');

        $this->actingAs($agent)
            ->postJson("/api/v1/paiements/{$paiement->id}/lever-suspension", ['motif' => 'RIB confirmé'])
            ->assertOk()
            ->assertJsonPath('data.statut', PaiementStatus::Autorise->value);
    }

    public function test_une_delegation_expiree_reoriente_l_ordre_vers_le_president(): void
    {
        $secretaire = User::query()->where('role', 'secretaire_general')->firstOrFail();
        $ordre = Ordonnancement::query()->where('reference', 'ORD-2026-000001')->firstOrFail();
        $ordre->forceFill(['ordonnateur_role' => 'secretaire_general', 'montant' => 1_000_000])->save();
        OrdDelegation::query()->update(['active' => false]);

        $this->actingAs($secretaire)
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action']);

        $ordre->refresh();
        $this->assertSame('ordonnateur', $ordre->ordonnateur_role);
        $this->assertNull($ordre->signature_reference);
    }

    public function test_la_signature_de_l_ordre_exige_le_mot_de_passe_du_signataire(): void
    {
        $ordre = Ordonnancement::query()->where('reference', 'ORD-2026-000001')->firstOrFail();
        $signataire = User::query()->where('role', $ordre->ordonnateur_role)->firstOrFail();

        $this->actingAs($signataire)
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'mauvais'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mot_de_passe']);

        $this->assertNull($ordre->fresh()->signature_reference);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $signataire->id, 'action' => 'signature.echec', 'object_type' => 'ordonnancement']);
    }

    public function test_l_autorisation_du_paiement_exige_le_mot_de_passe_de_l_agent_comptable(): void
    {
        $ordre = Ordonnancement::query()->where('reference', 'ORD-2026-000001')->firstOrFail();
        $this->actingAs(User::query()->where('role', $ordre->ordonnateur_role)->firstOrFail())
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertOk();
        $paiement = Paiement::query()->where('ordonnancement_id', $ordre->id)->firstOrFail();
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/prendre-en-charge")->assertOk();
        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/preparer", ['mode' => 'virement', 'compte_bancaire_id' => $this->compteValide($paiement)])
            ->assertOk();
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/soumettre")->assertOk();
        $this->actingAs(User::query()->where('role', 'chef_comptable')->firstOrFail())->postJson("/api/v1/paiements/{$paiement->id}/valider")->assertOk();

        $this->actingAs(User::query()->where('role', 'agent_comptable')->firstOrFail())
            ->postJson("/api/v1/paiements/{$paiement->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'mauvais'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mot_de_passe']);

        $this->assertSame('a_signer', $paiement->fresh()->status->value);
    }

    private function paiementAutorise(string $reference = 'ORD-2026-000001', string $action = 'signer'): Paiement
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
        $this->actingAs(User::query()->where('role', 'comptable')->firstOrFail())
            ->postJson("/api/v1/paiements/{$paiement->id}/prendre-en-charge")
            ->assertOk();
        $this->autoriserPaiement($paiement);

        return $paiement->fresh();
    }

    private function autoriserPaiement(Paiement $paiement): void
    {
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();
        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/preparer", [
                'mode' => 'virement',
                'compte_bancaire_id' => $this->compteValide($paiement),
                'compte_ceeac' => 'CEEAC-01',
            ])
            ->assertOk();
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/soumettre")->assertOk();
        $this->actingAs(User::query()->where('role', 'chef_comptable')->firstOrFail())
            ->postJson("/api/v1/paiements/{$paiement->id}/valider")
            ->assertOk();
        $this->actingAs(User::query()->where('role', 'agent_comptable')->firstOrFail())
            ->postJson("/api/v1/paiements/{$paiement->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'autorise');
    }
}
