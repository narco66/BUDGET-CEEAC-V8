<?php

namespace Tests\Feature;

use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Suppliers\Models\Tiers;
use App\Domains\Suppliers\Models\TiersBankAccount;
use App\Domains\Suppliers\Services\TiersService;
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

class TiersTest extends TestCase
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

    public function test_la_fiche_tiers_est_unique(): void
    {
        $comptable = $this->user('comptable');

        $this->actingAs($comptable)
            ->postJson('/api/v1/tiers', ['type' => 'fournisseur', 'raison_sociale' => 'Nouvelle Société Test SARL', 'nif' => 'nif-777'])
            ->assertCreated()
            ->assertJsonPath('data.nif', 'NIF-777')
            ->assertJsonPath('data.statut', 'actif');

        $this->actingAs($comptable)
            ->postJson('/api/v1/tiers', ['type' => 'fournisseur', 'raison_sociale' => 'NOUVELLE SOCIETE TEST'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['raison_sociale']);

        $this->actingAs($comptable)
            ->postJson('/api/v1/tiers', ['type' => 'fournisseur', 'raison_sociale' => 'Autre société', 'nif' => 'NIF-777'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nif']);

        $this->actingAs(User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail())
            ->postJson('/api/v1/tiers', ['type' => 'fournisseur', 'raison_sociale' => 'Hors rôle'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action']);
    }

    public function test_un_compte_est_valide_par_un_second_acteur(): void
    {
        $tiers = Tiers::query()->firstOrFail();
        $comptable = $this->user('comptable');
        $chef = $this->user('chef_comptable');

        $compteId = $this->actingAs($comptable)
            ->postJson("/api/v1/tiers/{$tiers->id}/comptes", ['banque' => 'UBA', 'numero' => 'GA21 0000 1111', 'titulaire' => $tiers->raison_sociale])
            ->assertCreated()
            ->json('data.comptes.0.id');
        $this->assertDatabaseHas('tiers_bank_accounts', ['id' => $compteId, 'status' => 'en_attente', 'numero' => 'GA2100001111']);

        $this->actingAs($comptable)->postJson("/api/v1/tiers/comptes/{$compteId}/valider")->assertStatus(422);

        $agent = $this->user('agent_comptable');
        TiersBankAccount::query()->whereKey($compteId)->update(['created_by' => $agent->id]);
        $this->actingAs($agent)
            ->postJson("/api/v1/tiers/comptes/{$compteId}/valider")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action']);

        $this->actingAs($chef)->postJson("/api/v1/tiers/comptes/{$compteId}/valider")->assertOk();
        $this->assertDatabaseHas('tiers_bank_accounts', ['id' => $compteId, 'status' => 'valide', 'validated_by' => $chef->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'tiers.compte_validation', 'object_id' => (string) $compteId]);
    }

    public function test_le_paiement_n_accepte_qu_un_compte_valide_du_bon_tiers(): void
    {
        $paiement = $this->paiementPrisEnCharge();
        $comptable = $this->user('comptable');
        $expected = app(TiersService::class)->expectedTiers($paiement);
        $autre = Tiers::query()->whereKeyNot($expected->id)->firstOrFail();
        $compteAutre = $autre->bankAccounts()->firstOrFail();
        $enAttente = $expected->bankAccounts()->create(['banque' => 'UBA', 'numero' => 'EN-ATTENTE-1', 'titulaire' => 'X', 'status' => TiersBankAccount::EN_ATTENTE]);

        foreach ([null, $compteAutre->id, $enAttente->id] as $compteId) {
            $this->actingAs($comptable)
                ->postJson("/api/v1/paiements/{$paiement->id}/preparer", ['mode' => 'virement', 'compte_bancaire_id' => $compteId, 'banque' => 'Pirate', 'compte' => 'FR76-PIRATE'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['compte_bancaire_id']);
        }

        $valide = $expected->bankAccounts()->where('status', TiersBankAccount::VALIDE)->firstOrFail();
        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/preparer", ['mode' => 'virement', 'compte_bancaire_id' => $valide->id])
            ->assertOk()
            ->assertJsonPath('data.statut', 'en_preparation');
        $this->assertDatabaseHas('paiements', ['id' => $paiement->id, 'compte' => $valide->numero, 'tiers_bank_account_id' => $valide->id]);
    }

    public function test_l_agent_comptable_ne_peut_autoriser_un_paiement_vers_un_compte_qu_il_a_valide(): void
    {
        $paiement = $this->paiementPrisEnCharge();
        $agent = $this->user('agent_comptable');
        $compte = app(TiersService::class)->eligibleAccounts($paiement)->first();
        $compte->forceFill(['validated_by' => $agent->id])->save();

        $this->jusquaSignature($paiement, $compte->id);
        $this->actingAs($agent)
            ->postJson("/api/v1/paiements/{$paiement->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action']);
    }

    public function test_un_tiers_suspendu_apres_preparation_bloque_l_autorisation(): void
    {
        $paiement = $this->paiementPrisEnCharge();
        $compte = app(TiersService::class)->eligibleAccounts($paiement)->first();
        $this->jusquaSignature($paiement, $compte->id);

        $this->actingAs($this->user('agent_comptable'))
            ->postJson("/api/v1/tiers/{$compte->tiers_id}/statut", ['statut' => 'suspendu', 'motif' => 'Contentieux'])
            ->assertOk();

        $this->actingAs($this->user('agent_comptable'))
            ->postJson("/api/v1/paiements/{$paiement->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action']);
        $this->assertSame('a_signer', $paiement->fresh()->status->value);
    }

    public function test_un_compte_recemment_valide_est_signale_en_vigilance(): void
    {
        $paiement = $this->paiementPrisEnCharge();
        $compte = app(TiersService::class)->eligibleAccounts($paiement)->first();
        $compte->forceFill(['validated_at' => now()->subDays(3)])->save();

        $this->actingAs($this->user('comptable'))
            ->postJson("/api/v1/paiements/{$paiement->id}/preparer", ['mode' => 'virement', 'compte_bancaire_id' => $compte->id])
            ->assertOk();

        $this->assertTrue((bool) $paiement->fresh()->compte_modifie);
        $this->actingAs($this->user('comptable'))
            ->getJson("/api/v1/paiements/{$paiement->id}/comptes-eligibles")
            ->assertOk()
            ->assertJsonPath('data.0.vigilance', true);
    }

    private function paiementPrisEnCharge(): Paiement
    {
        $ordre = Ordonnancement::query()->where('reference', 'ORD-2026-000001')->firstOrFail();
        $this->actingAs($this->user($ordre->ordonnateur_role))
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])
            ->assertOk();
        $paiement = Paiement::query()->where('ordonnancement_id', $ordre->id)->firstOrFail();
        $this->actingAs($this->user('comptable'))->postJson("/api/v1/paiements/{$paiement->id}/prendre-en-charge")->assertOk();

        return $paiement->fresh();
    }

    private function jusquaSignature(Paiement $paiement, int $compteId): void
    {
        $comptable = $this->user('comptable');
        $this->actingAs($comptable)
            ->postJson("/api/v1/paiements/{$paiement->id}/preparer", ['mode' => 'virement', 'compte_bancaire_id' => $compteId])
            ->assertOk();
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/soumettre")->assertOk();
        $this->actingAs($this->user('chef_comptable'))->postJson("/api/v1/paiements/{$paiement->id}/valider")->assertOk();
    }

    public function test_la_fiche_tiers_se_modifie_sans_creer_de_doublon(): void
    {
        $comptable = $this->user('comptable');
        $tiers = $this->actingAs($comptable)
            ->postJson('/api/v1/tiers', ['type' => 'fournisseur', 'raison_sociale' => 'Société Modifiable SARL', 'nif' => 'NIF-901'])
            ->assertCreated()->json('data');
        $autre = Tiers::query()->whereKeyNot($tiers['id'])->whereNotNull('nif')->firstOrFail();

        $this->actingAs($comptable)
            ->putJson('/api/v1/tiers/'.$tiers['id'], ['type' => 'consultant', 'raison_sociale' => 'Société Modifiée SARL', 'nif' => 'nif-901', 'telephone' => '+241 01 02 03', 'adresse' => 'Libreville'])
            ->assertOk()
            ->assertJsonPath('data.raison_sociale', 'Société Modifiée SARL')
            ->assertJsonPath('data.type', 'consultant')
            ->assertJsonPath('data.telephone', '+241 01 02 03');

        $this->actingAs($comptable)
            ->putJson('/api/v1/tiers/'.$tiers['id'], ['type' => 'fournisseur', 'raison_sociale' => 'Société Modifiée SARL', 'nif' => $autre->nif])
            ->assertStatus(422)->assertJsonValidationErrors(['nif']);
        $this->actingAs($comptable)
            ->putJson('/api/v1/tiers/'.$tiers['id'], ['type' => 'fournisseur', 'raison_sociale' => $autre->raison_sociale])
            ->assertStatus(422)->assertJsonValidationErrors(['raison_sociale']);

        $this->assertDatabaseHas('audit_events', ['object_type' => 'tiers', 'object_id' => (string) $tiers['id'], 'action' => 'tiers.modification']);
    }

    public function test_seul_un_tiers_jamais_utilise_se_supprime(): void
    {
        $agent = $this->user('agent_comptable');
        $neuf = $this->actingAs($this->user('comptable'))
            ->postJson('/api/v1/tiers', ['type' => 'fournisseur', 'raison_sociale' => 'Tiers éphémère'])
            ->assertCreated()->json('data.id');
        $utilise = TiersBankAccount::query()->firstOrFail()->tiers_id;

        $this->actingAs($this->user('comptable'))->deleteJson('/api/v1/tiers/'.$neuf)->assertStatus(422)->assertJsonValidationErrors(['action']);
        $this->actingAs($agent)->deleteJson('/api/v1/tiers/'.$utilise)->assertStatus(422)->assertJsonValidationErrors(['tiers']);
        $this->actingAs($agent)->deleteJson('/api/v1/tiers/'.$neuf)->assertOk();

        $this->assertDatabaseMissing('tiers', ['id' => $neuf]);
        $this->assertDatabaseHas('tiers', ['id' => $utilise]);
    }

    private function user(string $role): User
    {
        return User::query()->where('role', $role)->firstOrFail();
    }
}
