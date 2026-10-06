<?php

namespace Tests\Feature;

use App\Domains\Administration\Models\BusinessRule;
use App\Domains\Administration\Models\NumberSequence;
use App\Domains\Administration\Models\SodRule;
use App\Domains\Administration\Models\WorkflowDefinition;
use App\Domains\Administration\Models\WorkflowVersion;
use App\Domains\Administration\Services\GestionHabilitations;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use App\Shared\Auth\Http\AuthController;
use Database\Seeders\AdministrationSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->seed(AdministrationSeeder::class);
    }

    public function test_un_administrateur_cree_un_utilisateur_journalise(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();
        $unit = User::query()->where('role', 'initiateur')->value('organization_unit_id');

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/utilisateurs', [
                'nom' => 'NGOUA',
                'prenom' => 'Brice',
                'email' => 'brice.ngoua@ceeac.int',
                'organization_unit_id' => $unit,
                'fonction' => 'Gestionnaire',
                'role' => 'initiateur',
                'initiales' => 'BN',
                'password' => 'password',
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'brice.ngoua@ceeac.int')
            ->assertJsonPath('data.statut', 'actif');

        $this->assertDatabaseHas('audit_events', ['action' => 'utilisateur.creer']);
    }

    public function test_le_perimetre_d_un_compte_se_definit_sans_imposer_le_mfa(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();
        $compte = User::query()->where('role', 'initiateur')->firstOrFail();
        $unite = (int) $compte->organization_unit_id;

        $this->putJson('/api/v1/admin/utilisateurs/'.$compte->id.'/perimetre', ['unites' => [$unite]])->assertUnauthorized();
        $this->actingAs($compte)->putJson('/api/v1/admin/utilisateurs/'.$compte->id.'/perimetre', ['unites' => [$unite]])->assertForbidden();

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/utilisateurs/'.$compte->id.'/perimetre', ['unites' => [$unite]])
            ->assertOk()
            ->assertJsonPath('data.perimetre.0', $unite);
        $this->assertSame([$unite], $compte->fresh()->organizationScopeIds());
        $this->assertFalse((bool) $compte->fresh()->mfa_required);

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/utilisateurs/'.$compte->id.'/perimetre', ['unites' => []])
            ->assertOk()
            ->assertJsonPath('data.perimetre', []);
        $this->assertNull($compte->fresh()->organizationScopeIds());
    }

    public function test_le_formulaire_utilisateur_peut_charger_les_roles_et_structures_actifs(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/roles')
            ->assertOk()
            ->assertJsonFragment(['code' => 'initiateur']);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/structures')
            ->assertOk()
            ->assertJsonFragment(['kind' => 'direction']);
    }

    public function test_la_creation_utilisateur_refuse_un_role_absent_du_referentiel(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();
        $unit = User::query()->where('role', 'initiateur')->value('organization_unit_id');

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/utilisateurs', [
                'nom' => 'NGOUA',
                'prenom' => 'Brice',
                'email' => 'brice.ngoua@ceeac.int',
                'organization_unit_id' => $unit,
                'fonction' => 'Gestionnaire',
                'role' => 'role-inexistant',
                'initiales' => 'BN',
                'password' => 'un-secret-temporaire',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'brice.ngoua@ceeac.int']);
    }

    public function test_deux_roles_incompatibles_sont_refuses(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();
        $ordonnateur = User::query()->where('role', 'ordonnateur')->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/utilisateurs/{$ordonnateur->id}/roles", ['role' => 'comptable'])
            ->assertStatus(422);

        $this->assertSame('ordonnateur', $ordonnateur->fresh()->role);
    }

    public function test_un_compte_desactive_ne_se_connecte_plus_mais_garde_son_historique(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();
        $initiateur = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $dossiers = ExpressionBesoin::query()->where('initiator_id', $initiateur->id)->count();
        $this->assertGreaterThan(0, $dossiers);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/utilisateurs/{$initiateur->id}/desactiver", ['motif' => 'Mutation'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'desactive');

        $this->actingAs($initiateur->fresh())
            ->getJson('/api/v1/acteurs')
            ->assertUnauthorized();

        $this->assertSame($dossiers, ExpressionBesoin::query()->where('initiator_id', $initiateur->id)->count());
        $this->assertDatabaseHas('users', ['id' => $initiateur->id, 'account_status' => 'desactive']);
    }

    public function test_une_delegation_n_est_effective_que_sur_sa_periode(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();
        $delegant = User::query()->where('role', 'ordonnateur')->firstOrFail();
        $delegataire = User::query()->where('role', 'secretaire_general')->firstOrFail();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/delegations', [
                'delegant_id' => $delegant->id,
                'delegataire_id' => $delegataire->id,
                'fonction' => 'Signature des ordres',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(10)->toDateString(),
                'motif' => 'Absence',
            ])
            ->assertCreated()
            ->assertJsonPath('effective', true);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/delegations', [
                'delegant_id' => $delegant->id,
                'delegataire_id' => $delegataire->id,
                'fonction' => 'Signature passée',
                'starts_on' => now()->subDays(20)->toDateString(),
                'ends_on' => now()->subDay()->toDateString(),
                'motif' => 'Échue',
            ])
            ->assertCreated()
            ->assertJsonPath('statut', 'expiree')
            ->assertJsonPath('effective', false);
    }

    public function test_un_interim_accorde_le_role_du_titulaire_puis_se_cloture(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();
        $titulaire = User::query()->where('role', 'initiateur')->firstOrFail();
        $interimaire = User::query()->where('role', 'secretaire_general')->firstOrFail();
        $controleur = User::factory()->create([
            'role' => 'controleur_financier',
            'email' => 'controleur.interim@ceeac.int',
        ]);
        $payload = [
            'titulaire_id' => $titulaire->id,
            'interim_id' => $interimaire->id,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(5)->toDateString(),
        ];

        $this->postJson('/api/v1/admin/interims', $payload)->assertUnauthorized();
        $this->actingAs($interimaire)->postJson('/api/v1/admin/interims', $payload)->assertForbidden();
        $this->actingAs($admin)
            ->postJson('/api/v1/admin/interims', [
                ...$payload,
                'interim_id' => $controleur->id,
            ])
            ->assertUnprocessable();

        $created = $this->actingAs($admin)
            ->postJson('/api/v1/admin/interims', $payload)
            ->assertCreated()
            ->assertJsonPath('data.effectif', true)
            ->json('data');
        $this->assertTrue($interimaire->fresh()->holds('initiateur'));
        $this->assertFalse((bool) $interimaire->fresh()->mfa_required);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/interims/'.$created['id'].'/cloturer')
            ->assertOk()
            ->assertJsonPath('data.statut', 'cloture');
        $this->assertFalse($interimaire->fresh()->holds('initiateur'));
    }

    public function test_un_workflow_publie_ouvre_une_nouvelle_version(): void
    {
        $admin = User::query()->where('role', 'administrateur_fonctionnel')->firstOrFail();
        $created = $this->actingAs($admin)
            ->postJson('/api/v1/admin/workflows', [
                'code' => 'visa-test',
                'module' => 'engagement',
                'label' => 'Visa test',
                'steps' => [['code' => 'viser', 'label' => 'Viser', 'actor_role' => 'controleur_financier']],
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/workflows/{$created['id']}/publier")
            ->assertOk()
            ->assertJsonPath('statut', 'actif');

        $version = WorkflowVersion::query()->where('workflow_definition_id', $created['id'])->firstOrFail();

        $this->actingAs($admin)
            ->putJson("/api/v1/admin/workflows/versions/{$version->id}", [
                'steps' => [['code' => 'viser', 'label' => 'Modifier en place', 'actor_role' => 'controleur_financier']],
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/workflows/{$created['id']}/versions")
            ->assertCreated()
            ->assertJsonPath('version', 2)
            ->assertJsonPath('statut', 'brouillon');
    }

    public function test_un_workflow_sans_acteur_ne_se_publie_pas(): void
    {
        $admin = User::query()->where('role', 'administrateur_fonctionnel')->firstOrFail();
        $created = $this->actingAs($admin)
            ->postJson('/api/v1/admin/workflows', [
                'code' => 'sans-acteur',
                'module' => 'paiement',
                'label' => 'Sans acteur',
                'steps' => [['code' => 'payer', 'label' => 'Payer']],
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/workflows/{$created['id']}/publier")
            ->assertStatus(422);

        $this->assertSame('brouillon', WorkflowDefinition::query()->find($created['id'])->versions()->value('status'));
    }

    public function test_deux_seuils_qui_se_chevauchent_sont_refuses(): void
    {
        $admin = User::query()->where('role', 'administrateur_fonctionnel')->firstOrFail();
        $payload = [
            'operation' => 'ordonnancement',
            'actor_role' => 'secretaire_general',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
        ];

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/seuils', $payload + ['code' => 'ORD-A', 'min_amount' => 0, 'max_amount' => 5000000])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/seuils', $payload + ['code' => 'ORD-B', 'min_amount' => 4000000, 'max_amount' => 9000000])
            ->assertStatus(422);
    }

    public function test_une_nomenclature_utilisee_ne_se_supprime_pas(): void
    {
        $admin = User::query()->where('role', 'administrateur_fonctionnel')->firstOrFail();
        $line = BudgetLine::query()->whereHas('imputations')->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/nomenclature/{$line->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('budget_lines', ['id' => $line->id]);
    }

    public function test_un_parametre_critique_conserve_l_historique(): void
    {
        $admin = User::query()->where('role', 'administrateur_fonctionnel')->firstOrFail();

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/parametres/devise', ['value' => 'XAF'])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/parametres/devise', ['value' => 'XAF', 'motif' => 'Confirmation de la devise institutionnelle'])
            ->assertOk()
            ->assertJsonPath('historique.0.motif', 'Confirmation de la devise institutionnelle');
    }

    public function test_une_regle_metier_est_modifiable_et_journalisee(): void
    {
        $admin = User::query()->where('role', 'administrateur_fonctionnel')->firstOrFail();
        $rule = BusinessRule::query()->where('code', 'plafond_caisse')->firstOrFail();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/parametres')
            ->assertOk()
            ->assertJsonFragment(['code' => 'plafond_caisse']);

        $this->actingAs($admin)
            ->putJson("/api/v1/admin/regles-metier/{$rule->id}", [
                'value' => 750000,
                'active' => true,
                'motif' => 'Actualisation du plafond autorisée',
            ])
            ->assertOk()
            ->assertJsonPath('data.value', '750000');

        $this->assertDatabaseHas('business_rules', ['id' => $rule->id, 'value' => '750000']);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'regle_metier.modifier',
            'object_id' => (string) $rule->id,
            'motif' => 'Actualisation du plafond autorisée',
        ]);
    }

    public function test_un_initiateur_ne_peut_pas_administrer(): void
    {
        $initiateur = User::query()->where('role', 'initiateur')->firstOrFail();

        $this->actingAs($initiateur)
            ->getJson('/api/v1/admin/utilisateurs')
            ->assertForbidden()
            ->assertJsonPath('message', 'Cet écran est réservé aux administrateurs et à l’auditeur.');
    }

    public function test_un_secret_d_integration_n_est_jamais_restitue(): void
    {
        $admin = User::query()->where('role', 'administrateur_fonctionnel')->firstOrFail();

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/admin/integrations', [
                'name' => 'Banque test',
                'system' => 'banque',
                'environment' => 'recette',
                'endpoint' => 'https://banque.example/api',
                'auth_mode' => 'token',
                'secret' => 'super-secret-token',
            ])
            ->assertCreated()
            ->assertJsonPath('secret_renseigne', true);

        $this->assertStringNotContainsString('super-secret-token', $response->getContent());

        $listed = $this->actingAs($admin)->getJson('/api/v1/admin/integrations')->assertOk();
        $this->assertStringNotContainsString('super-secret-token', $listed->getContent());
    }

    public function test_une_session_revoquee_est_immédiatement_invalide(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();
        $initiateur = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $sessionId = $this->actingAs($admin)
            ->postJson('/api/v1/admin/sessions', ['user_id' => $initiateur->id])
            ->assertCreated()
            ->json('id');

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/sessions/{$sessionId}/revoquer")
            ->assertOk();

        $this->actingAs($initiateur)
            ->withSession([AuthController::SESSION_KEY => $sessionId])
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/v1/acteurs')
            ->assertUnauthorized();
    }

    public function test_la_reouverture_d_un_exercice_exige_un_motif_et_un_audit(): void
    {
        $admin = User::query()->where('role', 'administrateur_fonctionnel')->firstOrFail();
        $exercice = Exercice::query()->create([
            'annee' => 2099,
            'statut' => 'clos',
            'date_debut' => '2099-01-01',
            'date_fin' => '2099-12-31',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/exercices/{$exercice->id}/rouvrir", [])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/exercices/{$exercice->id}/rouvrir", ['motif' => 'Correction d’une écriture résiduelle'])
            ->assertOk()
            ->assertJsonPath('statut', 'ouvert');

        $this->assertDatabaseHas('audit_events', ['action' => 'exercice.rouvrir', 'motif' => 'Correction d’une écriture résiduelle']);
    }

    public function test_une_sequence_consommee_ne_recule_pas(): void
    {
        $admin = User::query()->where('role', 'administrateur_fonctionnel')->firstOrFail();
        $sequence = NumberSequence::query()->where('code', 'PAY')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/numerotation/{$sequence->id}", ['last_value' => 4])
            ->assertOk()
            ->assertJsonPath('last_value', 4);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/numerotation/{$sequence->id}", ['last_value' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('last_value');

        $this->assertSame(4, $sequence->fresh()->last_value);
    }

    public function test_un_administrateur_ne_s_eleve_pas_et_le_dernier_reste_actif(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();

        $this->postJson('/api/v1/admin/habilitations/verifier', [])->assertUnauthorized();

        $initiateur = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $this->actingAs($initiateur)
            ->postJson('/api/v1/admin/habilitations/verifier', ['user_id' => $admin->id, 'action' => 'engagement.viser'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/utilisateurs/{$admin->id}/roles", ['role' => 'ordonnateur'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
        $this->assertFalse($admin->fresh()->holds('ordonnateur'));
        $this->assertDatabaseHas('audit_events', ['action' => 'role.autoelevation_refusee', 'object_id' => (string) $admin->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/utilisateurs/{$admin->id}/desactiver", ['motif' => 'Départ'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motif');
        $this->assertSame('actif', $admin->fresh()->account_status);
    }

    public function test_la_verification_explique_le_droit_sans_executer_l_action(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();
        $initiateur = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $controleur = User::factory()->create([
            'role' => 'controleur_financier',
            'email' => 'controleur.verification@ceeac.int',
            'account_status' => 'actif',
        ]);
        $ordres = Ordonnancement::query()->count();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/habilitations/verifier', ['user_id' => $controleur->id, 'action' => 'engagement.viser'])
            ->assertOk()
            ->assertJsonPath('data.autorise', true);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/habilitations/verifier', ['user_id' => $initiateur->id, 'action' => 'engagement.viser'])
            ->assertOk()
            ->assertJsonPath('data.autorise', false);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/habilitations/verifier', ['user_id' => $initiateur->id, 'action' => 'action.inexistante'])
            ->assertOk()
            ->assertJsonPath('data.autorise', false);

        $garde = OrganizationUnit::query()->firstOrFail();
        $autre = OrganizationUnit::query()->create([
            'sigle' => 'HORS-PERIM',
            'name' => 'Structure hors périmètre',
            'kind' => 'service',
            'is_active' => true,
        ]);
        $this->actingAs($admin)
            ->putJson("/api/v1/admin/utilisateurs/{$initiateur->id}/perimetre", ['unites' => [$garde->id]])
            ->assertOk();
        $this->actingAs($admin)
            ->postJson('/api/v1/admin/habilitations/verifier', [
                'user_id' => $initiateur->id,
                'action' => 'eb.consulter',
                'organization_unit_id' => $autre->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.autorise', false);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/utilisateurs/{$initiateur->id}/roles", ['role' => 'expert_budget'])
            ->assertOk();
        $this->assertTrue($initiateur->fresh()->holds('expert_budget'));

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/utilisateurs/{$initiateur->id}/roles/retirer", ['role' => 'expert_budget'])
            ->assertOk();
        $this->assertFalse($initiateur->fresh()->holds('expert_budget'));

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/utilisateurs/{$initiateur->id}/roles/retirer", ['role' => 'initiateur'])
            ->assertStatus(422);

        $secretaire = User::query()->where('role', 'secretaire_general')->firstOrFail();
        $signature = $this->actingAs($admin)
            ->postJson('/api/v1/admin/habilitations/verifier', [
                'user_id' => $secretaire->id,
                'action' => 'ordonnancement.signer',
                'montant' => 1000,
            ])
            ->assertOk()
            ->json('data');
        $this->assertIsBool($signature['autorise']);
        $this->assertNotEmpty($signature['raisons']);
        $this->assertSame($ordres, Ordonnancement::query()->count());
    }

    public function test_la_matrice_accorde_et_retire_un_droit_de_chaine(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();
        $auditeur = User::query()->where('role', 'auditeur')->firstOrFail();
        $initiateur = User::query()->where('role', 'initiateur')->firstOrFail();
        $ligne = BudgetLine::query()->where('code', '203232')->firstOrFail();

        $this->actingAs($initiateur)
            ->postJson('/api/v1/admin/roles/auditeur/permissions', [
                'permission' => 'eb.creer',
                'accorder' => true,
                'motif' => 'Tentative hors habilitations',
            ])
            ->assertForbidden();

        $this->actingAs($auditeur)
            ->postJson('/api/v1/expressions-besoin', ['budget_line_id' => $ligne->id])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/roles/auditeur/permissions', [
                'permission' => 'ordonnancement.signer',
                'accorder' => true,
                'motif' => 'Le seuil ne se contourne pas',
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/roles/auditeur/permissions', [
                'permission' => 'eb.creer',
                'accorder' => true,
                'motif' => 'Essai d’attribution contrôlée',
            ])
            ->assertOk();
        $this->assertTrue($auditeur->fresh()->porte('eb.creer'));
        $this->assertDatabaseHas('audit_events', ['action' => 'permission.accorder']);

        $this->actingAs($auditeur->fresh())
            ->postJson('/api/v1/expressions-besoin', ['budget_line_id' => $ligne->id])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/roles/auditeur/permissions', [
                'permission' => 'eb.creer',
                'accorder' => false,
                'motif' => 'Retrait de l’essai',
            ])
            ->assertOk();
        $this->assertFalse($auditeur->fresh()->porte('eb.creer'));
        $this->actingAs($auditeur->fresh())
            ->postJson('/api/v1/expressions-besoin', ['budget_line_id' => $ligne->id])
            ->assertForbidden();

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/roles/administrateur_habilitations', [
                'label' => 'Administrateur des habilitations',
                'active' => false,
                'motif' => 'Tentative de retrait du dernier rôle d’administration',
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->deleteJson('/api/v1/admin/roles/auditeur', ['motif' => 'Suppression refusée'])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/incompatibilites', [
                'role_a' => 'auditeur',
                'role_b' => 'expert_budget',
                'label' => 'Essai de séparation',
                'motif' => 'Règle de test',
            ])
            ->assertCreated();
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/utilisateurs/{$auditeur->id}/roles", ['role' => 'expert_budget'])
            ->assertStatus(422);

        $regle = SodRule::query()
            ->where('role_a', 'auditeur')
            ->where('role_b', 'expert_budget')
            ->firstOrFail();
        $this->actingAs($admin)
            ->postJson('/api/v1/admin/incompatibilites/'.$regle->id.'/desactiver', ['motif' => 'Fin de l’essai'])
            ->assertOk();
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/utilisateurs/{$auditeur->id}/roles", ['role' => 'expert_budget'])
            ->assertOk();

        $delegation = $this->actingAs($admin)
            ->postJson('/api/v1/admin/delegations', [
                'delegant_id' => $admin->id,
                'delegataire_id' => $initiateur->id,
                'fonction' => 'Visa budgétaire',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addMonth()->toDateString(),
                'motif' => 'Essai de révocation',
            ])
            ->assertCreated()
            ->json('id');
        $this->actingAs($admin)
            ->postJson('/api/v1/admin/delegations/'.$delegation.'/revoquer', ['motif' => 'Fin de l’essai'])
            ->assertOk()
            ->assertJsonPath('data.effective', false);
    }

    public function test_la_matrice_le_conflit_et_la_validation_d_une_habilitation(): void
    {
        $admin = User::query()->where('role', 'administrateur_habilitations')->firstOrFail();
        $initiateur = User::query()->where('role', 'initiateur')->firstOrFail();
        $this->assertTrue($initiateur->porte('eb.creer'));

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/roles/initiateur/matrice', [
                'cellules' => [['cle' => 'besoins.creer', 'niveau' => 'denied']],
            ])
            ->assertOk();
        $this->assertFalse($initiateur->fresh()->porte('eb.creer'));

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/roles/initiateur/matrice', [
                'cellules' => [['cle' => 'besoins.creer', 'niveau' => 'scoped']],
            ])
            ->assertOk();
        $frais = $initiateur->fresh();
        $this->assertTrue($frais->porte('eb.creer'));
        $autre = OrganizationUnit::query()->where('id', '!=', $frais->organization_unit_id)->value('id');
        if ($autre !== null) {
            $this->actingAs($admin)->putJson('/api/v1/admin/utilisateurs/'.$frais->id.'/perimetre', [
                'unites' => [(int) $frais->organization_unit_id],
            ])->assertOk();
            $borne = $frais->fresh();
            $this->assertFalse($borne->porte('eb.creer', (int) $autre));
            $this->assertTrue($borne->porte('eb.creer', (int) $borne->organization_unit_id));
            $this->actingAs($admin)->putJson('/api/v1/admin/roles/initiateur/matrice', [
                'cellules' => [['cle' => 'besoins.creer', 'niveau' => 'global']],
            ])->assertOk();
            $this->assertTrue($borne->fresh()->porte('eb.creer', (int) $autre));
        }

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/habilitations', [
                'user_id' => $initiateur->id,
                'role' => 'controleur_financier',
                'starts_on' => now()->toDateString(),
                'origine' => 'nomination',
            ])
            ->assertStatus(422);

        $habilitation = $this->actingAs($admin)
            ->postJson('/api/v1/admin/habilitations', [
                'user_id' => $initiateur->id,
                'role' => 'controleur_financier',
                'starts_on' => now()->toDateString(),
                'origine' => 'nomination',
                'derogation' => true,
                'motif' => 'Dérogation d’essai, périmètre distinct.',
            ])
            ->assertCreated()
            ->json('data.id');
        $this->assertFalse($initiateur->fresh()->holds('controleur_financier'));

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/habilitations/'.$habilitation.'/decision', ['decision' => 'approuver'])
            ->assertOk();
        $this->assertTrue($initiateur->fresh()->holds('controleur_financier'));

        $this->actingAs($admin)->getJson('/api/v1/admin/utilisateurs/'.$initiateur->id.'/droits')
            ->assertOk()
            ->assertJsonFragment(['permission' => 'eb.creer']);

        DB::table('user_roles')->where('id', $habilitation)->update([
            'status' => 'active',
            'ends_on' => now()->subDay()->toDateString(),
        ]);
        $this->assertSame(1, app(GestionHabilitations::class)->expirer());
        $this->assertSame('expiree', DB::table('user_roles')->where('id', $habilitation)->value('status'));
        $this->assertFalse($initiateur->fresh()->holds('controleur_financier'));
    }
}
