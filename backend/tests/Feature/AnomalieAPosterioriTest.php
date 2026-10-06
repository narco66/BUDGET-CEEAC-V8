<?php

namespace Tests\Feature;

use App\Domains\Administration\Models\AuditEvent;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnomalieAPosterioriTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_anomalie_manuelle_se_cloture_sans_reescrire_la_ligne(): void
    {
        [$ligne, $directeur] = $this->piece();
        $vote = (int) $ligne->montant_vote;

        $this->postJson('/api/v1/controles/anomalies', [
            'reference' => 'EB-ANO-77',
            'gravite' => 'mineur',
            'constat' => 'Pièce de service fait à rapprocher.',
        ])->assertUnauthorized();

        $code = $this->actingAs($directeur)->postJson('/api/v1/controles/anomalies', [
            'reference' => 'eb-ano-77',
            'gravite' => 'mineur',
            'constat' => 'Pièce de service fait à rapprocher.',
        ])->assertCreated()->json('data.code');

        $this->assertSame('ANO-'.now()->year.'-000001', $code);
        $this->actingAs($directeur)->postJson('/api/v1/controles/anomalies/cloturer', ['code' => $code])->assertUnprocessable();
        $this->actingAs($directeur)->postJson('/api/v1/controles/anomalies/cloturer', [
            'code' => $code,
            'motif' => 'La pièce a été versée au dossier.',
        ])->assertOk()->assertJsonPath('data.statut', 'clos');

        $registre = $this->actingAs($directeur)->getJson('/api/v1/controles/anomalies')->assertOk()->json('data');
        $anomalie = collect($registre)->firstWhere('code', $code);
        $this->assertSame('clos', $anomalie['statut']);
        $this->assertSame('La pièce a été versée au dossier.', $anomalie['motif_cloture']);
        $this->assertSame($vote, (int) $ligne->fresh()->montant_vote);
        $this->assertNotNull(AuditEvent::query()->where('action', 'controle.anomalie_cloturee')->first());
    }

    public function test_une_constatation_automatique_cloturee_ne_se_rouvre_pas(): void
    {
        [$ligne, $directeur] = $this->piece();
        $ligne->forceFill(['officiel' => false])->save();

        $this->actingAs($directeur)->getJson('/api/v1/controles/anomalies')->assertOk()->assertJsonFragment(['code' => 'LIGNES-HORS-IMPORT']);
        $this->actingAs($directeur)->postJson('/api/v1/controles/anomalies/cloturer', [
            'code' => 'LIGNES-HORS-IMPORT',
            'motif' => 'Lignes conservées pour les journaux.',
        ])->assertOk();

        $registre = $this->actingAs($directeur)->getJson('/api/v1/controles/anomalies')->assertOk()->json('data');
        $this->assertSame('clos', collect($registre)->firstWhere('code', 'LIGNES-HORS-IMPORT')['statut']);
        $this->assertSame(10_000_000, (int) $ligne->fresh()->montant_vote);
    }

    public function test_une_reference_inconnue_ou_un_role_hors_controle_est_refuse(): void
    {
        $this->piece();
        $directeur = User::query()->where('role', 'directeur_budget')->firstOrFail();
        $initiateur = User::factory()->create(['role' => 'initiateur', 'account_status' => 'actif']);

        $this->actingAs($directeur)->postJson('/api/v1/controles/anomalies', [
            'reference' => 'EB-INCONNUE',
            'gravite' => 'majeur',
            'constat' => 'Sans pièce.',
        ])->assertUnprocessable();

        $this->actingAs($initiateur)->postJson('/api/v1/controles/anomalies', [
            'reference' => 'EB-ANO-77',
            'gravite' => 'mineur',
            'constat' => 'Hors rôle.',
        ])->assertUnprocessable();
        $this->assertSame(0, DB::table('control_findings')->where('origin', 'manuel')->count());
    }

    /**
     * @return array{0: BudgetLine, 1: User}
     */
    private function piece(): array
    {
        $exercice = Exercice::query()->create([
            'annee' => (int) now()->year,
            'statut' => 'executoire',
            'date_debut' => now()->startOfYear()->toDateString(),
            'date_fin' => now()->endOfYear()->toDateString(),
        ]);
        $unite = OrganizationUnit::query()->create([
            'sigle' => 'DB',
            'name' => 'Direction du Budget',
            'kind' => 'direction',
        ]);
        $directeur = User::factory()->create(['role' => 'directeur_budget', 'account_status' => 'actif']);
        $ligne = BudgetLine::query()->create([
            'exercice_id' => $exercice->id,
            'organization_unit_id' => $unite->id,
            'code' => 'LIGNE-ANO',
            'label' => 'Ligne de contrôle',
            'nature' => 'hors_pap',
            'montant_vote' => 10_000_000,
            'officiel' => true,
        ]);
        ExpressionBesoin::query()->create([
            'reference' => 'EB-ANO-77',
            'exercice_id' => $exercice->id,
            'organization_unit_id' => $unite->id,
            'initiator_id' => $directeur->id,
            'budget_line_id' => $ligne->id,
            'nature' => 'hors_pap',
            'objet' => 'Contrôle a posteriori',
            'status' => 'validee',
            'montant' => 1_000_000,
        ]);

        return [$ligne, $directeur];
    }
}
