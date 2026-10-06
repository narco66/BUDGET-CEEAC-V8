<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\CreditMovement;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use App\Shared\Integration\IntegrationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExportComptableTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_messages_sont_rejetes_sans_ecriture(): void
    {
        [$exercice, $auditeur, $paiement] = $this->chaine();
        $autre = Exercice::query()->create([
            'annee' => 2025,
            'statut' => 'clos',
            'date_debut' => '2025-01-01',
            'date_fin' => '2025-12-31',
        ]);
        $ligne = BudgetLine::query()->create([
            'exercice_id' => $autre->id,
            'organization_unit_id' => OrganizationUnit::query()->value('id'),
            'code' => 'LIGNE-2025',
            'label' => 'Ligne hors exercice',
            'nature' => 'hors_pap',
            'montant_vote' => 1000,
        ]);
        $mouvement = CreditMovement::query()->create([
            'budget_line_id' => $ligne->id,
            'kind' => 'ouverture',
            'amount' => 1000,
            'motif' => 'Hors exercice',
            'acte' => 'ACTE-2025',
            'group_key' => 'GRP-2025',
        ]);
        IntegrationMessage::record('CREDIT-HORS', 'credit.mouvement', 'credit_movement', (string) $mouvement->id, [
            'ligne' => 'LIGNE-2025',
            'montant' => 1000,
        ]);

        $this->getJson('/api/v1/chaine/comptabilite')->assertUnauthorized();

        $this->actingAs($auditeur)
            ->getJson('/api/v1/chaine/comptabilite?exercice_id='.$exercice->id)
            ->assertOk()
            ->assertJsonPath('data.schema_enregistre', false)
            ->assertJsonPath('data.ecritures', [])
            ->assertJsonPath('data.debit', 0)
            ->assertJsonPath('data.credit', 0)
            ->assertJsonPath('data.equilibre', true)
            ->assertJsonPath('data.nombre_rejets', 2)
            ->assertJsonPath('data.par_evenement.0.evenement', 'engagement.vise')
            ->assertJsonPath('data.par_evenement.0.montant', 3_200_000)
            ->assertJsonPath('data.par_evenement.1.evenement', 'paiement.execute')
            ->assertJsonPath('data.par_evenement.1.montant', 1_000_000)
            ->assertJsonPath('data.rejets.0.reference', 'PAY-COMPTA-77')
            ->assertJsonPath('data.rejets.0.montant', 1_000_000)
            ->assertJsonPath('data.rejets.0.cible', 'paiement')
            ->assertJsonPath('data.rejets.0.cible_id', $paiement->id)
            ->assertJsonPath('data.rejets.0.motif', 'Aucun schéma d’écritures n’est enregistré. Aucun compte n’est affecté.');

        $this->assertDatabaseHas('integration_outbox', [
            'idempotence_key' => 'PAY-COMPTA-77',
            'published_at' => null,
        ]);

        $export = $this->actingAs($auditeur)
            ->get('/api/v1/chaine/comptabilite/export?exercice_id='.$exercice->id);
        $export->assertOk();
        $contenu = $export->streamedContent();
        $this->assertStringContainsString('section;evenement;reference;montant;motif', $contenu);
        $this->assertStringContainsString('PAY-COMPTA-77', $contenu);
        $this->assertStringNotContainsString('LIGNE-2025', $contenu);
        $this->assertStringNotContainsString('debit', strtolower($contenu));
        $this->assertDatabaseHas('audit_events', ['action' => 'comptabilite.export']);
    }

    public function test_un_perimetre_organisationnel_masque_les_messages(): void
    {
        [$exercice] = $this->chaine();
        $autre = OrganizationUnit::query()->create([
            'sigle' => 'AUTRE',
            'name' => 'Autre service',
            'kind' => 'service',
        ]);
        $borne = User::factory()->create(['role' => 'initiateur', 'account_status' => 'actif']);
        DB::table('access_scopes')->insert([
            'user_id' => $borne->id,
            'scope_type' => 'organization_unit',
            'scope_value' => (string) $autre->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($borne)
            ->getJson('/api/v1/chaine/comptabilite?exercice_id='.$exercice->id)
            ->assertOk()
            ->assertJsonPath('data.nombre_rejets', 0)
            ->assertJsonPath('data.ecritures', [])
            ->assertJsonPath('data.debit', 0);
    }

    /**
     * @return array{0: Exercice, 1: User, 2: Paiement}
     */
    private function chaine(): array
    {
        $exercice = Exercice::query()->create([
            'annee' => 2026,
            'statut' => 'executoire',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
        ]);
        $unite = OrganizationUnit::query()->create([
            'sigle' => 'DB',
            'name' => 'Direction du Budget',
            'kind' => 'direction',
        ]);
        $auditeur = User::factory()->create(['role' => 'auditeur', 'account_status' => 'actif']);
        $ligne = BudgetLine::query()->create([
            'exercice_id' => $exercice->id,
            'organization_unit_id' => $unite->id,
            'code' => 'LIGNE-COMPTA',
            'label' => 'Ligne comptable',
            'nature' => 'hors_pap',
            'montant_vote' => 10_000_000,
        ]);
        $besoin = ExpressionBesoin::query()->create([
            'reference' => 'EB-COMPTA-77',
            'exercice_id' => $exercice->id,
            'organization_unit_id' => $unite->id,
            'initiator_id' => $auditeur->id,
            'budget_line_id' => $ligne->id,
            'nature' => 'hors_pap',
            'objet' => 'Export comptable',
            'status' => 'transformee_engagement',
            'montant' => 3_200_000,
        ]);
        $engagement = Engagement::query()->create([
            'reference' => 'ENG-COMPTA-77',
            'expression_besoin_id' => $besoin->id,
            'budget_line_id' => $ligne->id,
            'montant' => 3_200_000,
            'status' => 'transforme_liquidation',
        ]);
        IntegrationMessage::record('ENG-COMPTA-77', 'engagement.vise', 'engagement', (string) $engagement->id, [
            'visa' => 'VISA-77',
        ]);
        $liquidation = Liquidation::query()->create([
            'reference' => 'LIQ-COMPTA-77',
            'engagement_id' => $engagement->id,
            'montant' => 3_200_000,
            'status' => 'transformee_ordonnancement',
        ]);
        $ordre = Ordonnancement::query()->create([
            'reference' => 'ORD-COMPTA-77',
            'liquidation_id' => $liquidation->id,
            'montant' => 3_200_000,
            'status' => 'transforme_paiement',
        ]);
        $paiement = Paiement::query()->create([
            'reference' => 'PAY-COMPTA-77',
            'ordonnancement_id' => $ordre->id,
            'montant' => 3_200_000,
            'montant_paye' => 1_000_000,
            'status' => 'paye_partiel',
        ]);
        IntegrationMessage::record('PAY-COMPTA-77', 'paiement.execute', 'paiement', (string) $paiement->id, [
            'reference' => 'REG-77',
            'montant' => 1_000_000,
        ]);

        return [$exercice, $auditeur, $paiement];
    }
}
