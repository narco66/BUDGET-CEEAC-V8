<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Services\BudgetBalanceService;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Needs\Services\ExpressionBesoinWorkflow;
use App\Domains\Suppliers\Models\Tiers;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scénario réel du cahier d’audit, entièrement par l’API et avec les acteurs
 * attendus : EB PAP → ENG → LIQ → ORD (seuil) → PAI en trois tranches, puis
 * vérification des soldes, tâches, notifications, actes, GED et journal.
 */
class ScenarioBoutEnBoutTest extends TestCase
{
    use RefreshDatabase;

    private const MONTANT = 4_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_la_chaine_complete_de_l_eb_au_paiement_partiel(): void
    {
        $initiateur = User::query()->where('email', 'clarisse.ndong@ceeac.int')->firstOrFail();
        $ligne = BudgetLine::query()->where('code', '203232')->firstOrFail();
        $avant = app(BudgetBalanceService::class)->forLine($ligne);

        // 1. Expression de besoin PAP : création, détail, pièces, soumission.
        $ebId = $this->actingAs($initiateur)->postJson('/api/v1/expressions-besoin', ['budget_line_id' => $ligne->id])->assertCreated()->json('data.id');
        $this->actingAs($initiateur)->patchJson("/api/v1/expressions-besoin/{$ebId}", [
            'objet' => 'Scénario d’audit — atelier régional',
            'justification' => 'Scénario de bout en bout du cahier d’audit.',
            'lignes' => [['designation' => 'Atelier', 'quantite' => 1, 'unite' => 'forfait', 'prix_unitaire' => self::MONTANT]],
        ])->assertOk();
        $types = DB::table('document_types')->where('operation', 'engagement')->where('required', true)->where('active', true)->pluck('label');
        foreach ($types->isEmpty() ? collect(['Devis']) : $types as $type) {
            $this->actingAs($initiateur)->post("/api/v1/expressions-besoin/{$ebId}/documents", [
                'type' => $type,
                'fichier' => UploadedFile::fake()->create('piece.pdf', 20, 'application/pdf'),
            ], ['Accept' => 'application/json'])->assertOk();
        }
        $this->actingAs($initiateur)->postJson("/api/v1/expressions-besoin/{$ebId}/soumettre")->assertOk();

        // 2. Circuit de validation : chaque étape par l’acteur que le moteur désigne.
        $etapes = [];
        for ($garde = 0; $garde < 6; $garde++) {
            $eb = ExpressionBesoin::query()->findOrFail($ebId);
            if ($eb->status->value !== 'soumise' && ! in_array($eb->workflow_step, ['directeur', 'commissaire', 'secretaire_general', 'ordonnateur'], true)) {
                break;
            }
            $acteur = $this->acteurAttendu($eb);
            $etapes[] = $eb->workflow_step;
            $action = $eb->workflow_step === 'ordonnateur' ? 'approuver' : 'valider';
            $this->actingAs($acteur)->postJson("/api/v1/expressions-besoin/{$ebId}/{$action}", ['observations' => 'Visa du scénario.'])->assertOk();
        }
        $this->assertSame(['directeur', 'commissaire', 'ordonnateur'], $etapes, 'Circuit PAP d’une structure technique');
        $eb = ExpressionBesoin::query()->findOrFail($ebId);
        if ($eb->status->value === 'approuvee') {
            $this->actingAs(User::query()->where('role', 'expert_budget')->firstOrFail())->postJson("/api/v1/expressions-besoin/{$ebId}/transformer")->assertSuccessful();
        }
        $engagement = Engagement::query()->where('expression_besoin_id', $ebId)->firstOrFail();
        $this->assertSame(self::MONTANT, (int) $engagement->montant);

        // 3. Engagement : instruction Budget, contrôle, validation, visa du contrôleur financier.
        $expert = User::query()->where('role', 'expert_budget')->firstOrFail();
        $tiers = Tiers::query()->where('status', 'actif')->whereHas('bankAccounts', fn ($query) => $query->where('status', 'valide'))->firstOrFail();
        $this->actingAs($expert)->patchJson("/api/v1/engagements/{$engagement->id}", ['tiers_id' => $tiers->id])->assertOk();
        foreach (['expert_budget', 'chef_budget', 'directeur_budget'] as $role) {
            $this->assertSame($role, $engagement->fresh()->workflow_step);
            $this->actingAs(User::query()->where('role', $role)->firstOrFail())
                ->postJson("/api/v1/engagements/{$engagement->id}/transmettre", ['observations' => 'Transmis.'])->assertOk();
        }
        $controleur = User::query()->where('role', 'controleur_financier')->firstOrFail();
        $this->actingAs($controleur)->postJson("/api/v1/engagements/{$engagement->id}/viser", ['observations' => 'Visa.'])->assertOk();
        $this->assertContains($this->actingAs($controleur)->postJson("/api/v1/engagements/{$engagement->id}/viser")->status(), [403, 409, 422], 'Double visa refusé');

        // 4. Liquidation : service fait par l’initiateur, facture, visa du contrôleur financier.
        $liquidation = Liquidation::query()->where('engagement_id', $engagement->id)->firstOrFail();
        $this->actingAs($initiateur)->postJson("/api/v1/liquidations/{$liquidation->id}/certifier", [
            'montant_accepte' => self::MONTANT, 'date_service' => '2026-09-20', 'bon_livraison' => 'BL-AUDIT', 'nature_prestation' => 'Prestation',
        ])->assertOk();
        $this->actingAs($initiateur)->postJson("/api/v1/liquidations/{$liquidation->id}/facture", [
            'numero' => 'FAC-AUDIT-1', 'date' => '2026-09-22', 'montant_ht' => self::MONTANT, 'taxes' => 0,
        ])->assertOk();
        // Une facture supérieure reste plafonnée au service fait, lui-même borné par l’engagement.
        $this->actingAs($initiateur)->postJson("/api/v1/liquidations/{$liquidation->id}/facture", [
            'numero' => 'FAC-AUDIT-1', 'date' => '2026-09-22', 'montant_ht' => self::MONTANT * 2, 'taxes' => 0,
        ])->assertOk();
        $this->assertSame(self::MONTANT, (int) $liquidation->fresh()->montant_brut);
        $this->actingAs($initiateur)->postJson("/api/v1/liquidations/{$liquidation->id}/certifier", [
            'montant_accepte' => self::MONTANT + 1,
        ])->assertStatus(422);
        $this->actingAs($initiateur)->postJson("/api/v1/liquidations/{$liquidation->id}/soumettre")->assertOk();
        $this->actingAs($controleur)->postJson("/api/v1/liquidations/{$liquidation->id}/viser", ['observations' => 'Service fait conforme.'])->assertOk();

        // 5. Ordonnancement : seuil appliqué par le serveur (≤ 5 000 000 → Secrétaire général).
        $ordre = $liquidation->fresh('ordonnancement')->ordonnancement;
        $this->assertNotNull($ordre);
        $this->assertSame('secretaire_general', $ordre->ordonnateur_role);
        $this->actingAs(User::query()->where('role', 'ordonnateur')->firstOrFail())
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])->tap(fn ($r) => $this->assertRefus($r->status(), 'Président sous le seuil'));
        $this->actingAs(User::query()->where('role', 'secretaire_general')->firstOrFail())
            ->postJson("/api/v1/ordonnancements/{$ordre->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])->assertOk();

        // 6. Paiement : comptable, chef comptable, agent comptable ; trois tranches ; rapprochement.
        $paiement = Paiement::query()->where('ordonnancement_id', $ordre->id)->firstOrFail();
        $comptable = User::query()->where('role', 'comptable')->firstOrFail();
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/prendre-en-charge")->assertOk();
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/preparer", [
            'mode' => 'virement',
            'compte_bancaire_id' => $tiers->bankAccounts()->where('status', 'valide')->value('id'),
            'compte_ceeac' => 'CEEAC-01',
        ])->assertOk();
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/soumettre")->assertOk();
        $this->actingAs(User::query()->where('role', 'chef_comptable')->firstOrFail())->postJson("/api/v1/paiements/{$paiement->id}/valider")->assertOk();
        $this->assertRefus($this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])->status(), 'Comptable sans droit de signer');
        $this->actingAs(User::query()->where('role', 'agent_comptable')->firstOrFail())
            ->postJson("/api/v1/paiements/{$paiement->id}/signer", ['confirmation' => true, 'mot_de_passe' => 'password'])->assertOk();

        foreach ([1_200_000, 800_000, 2_000_000] as $rang => $montant) {
            $this->actingAs($comptable)->post("/api/v1/paiements/{$paiement->id}/executer", [
                'montant' => $montant,
                'reference' => 'VIR-AUDIT-'.($rang + 1),
                'date_valeur' => now()->toDateString(),
                'preuve' => UploadedFile::fake()->create('avis.pdf', 12, 'application/pdf'),
            ], ['Accept' => 'application/json'])->assertOk();
        }
        $paiement->refresh();
        $this->assertSame(self::MONTANT, (int) $paiement->montant_paye);
        $this->assertSame(0, $paiement->reste());
        $this->actingAs($comptable)->postJson("/api/v1/paiements/{$paiement->id}/rapprocher", ['reference' => 'REL-AUDIT'])->assertOk()->assertJsonPath('data.statut', 'cloture');

        // 7. Soldes : la ligne reflète exactement le dossier (source unique).
        $apres = app(BudgetBalanceService::class)->forLine($ligne->fresh());
        foreach (['engage', 'liquide', 'ordonnance', 'paye'] as $agregat) {
            $this->assertSame($avant[$agregat] + self::MONTANT, $apres[$agregat], 'Solde '.$agregat);
        }
        $this->assertSame($avant['disponible'] - self::MONTANT, $apres['disponible']);

        // 8. Tâches et notifications : les tâches du dossier sont closes, les acteurs ont été notifiés.
        $this->assertSame(0, DB::table('workflow_tasks')->where('dossier_reference', $eb->reference)->where('status', '!=', 'terminee')->count());
        $this->assertGreaterThan(0, DB::table('notifications')->where('notifiable_id', $initiateur->id)->count());

        // 9. Actes officiels archivés et versés à la GED.
        $actes = DB::table('generated_documents')->pluck('kind');
        foreach (['engagement', 'liquidation', 'ordonnancement', 'paiement'] as $kind) {
            $this->assertContains($kind, $actes->all(), 'Acte '.$kind);
        }
        $this->assertGreaterThanOrEqual($actes->count(), DB::table('ged_documents')->count(), 'Chaque acte est versé à la GED');

        // 10. Journal central d’audit : chaque maillon y est tracé avec son acteur.
        $journal = DB::table('audit_events')->whereNotNull('actor_id')->pluck('action');
        foreach (['expression_besoin.soumission', 'expression_besoin.validation', 'expression_besoin.approbation', 'engagement.visa', 'liquidation.visa', 'paiement.execution'] as $action) {
            $this->assertContains($action, $journal->all(), 'Journal '.$action);
        }
        $this->assertTrue($journal->contains(fn ($valeur) => str_starts_with((string) $valeur, 'ordonnancement.')), 'Journal ordonnancement');
    }

    private function assertRefus(int $status, string $cas): void
    {
        $this->assertContains($status, [403, 422], 'Refus attendu : '.$cas);
    }

    private function acteurAttendu(ExpressionBesoin $eb): User
    {
        $workflow = app(ExpressionBesoinWorkflow::class);

        return User::query()->where('role', $eb->workflow_step)->get()->first(fn (User $user) => $workflow->allows($user, $eb))
            ?? $this->fail('Aucun acteur pour l’étape '.$eb->workflow_step);
    }
}
