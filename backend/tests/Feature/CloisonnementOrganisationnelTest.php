<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloisonnementOrganisationnelTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_agent_ne_voit_pas_le_dossier_d_un_service_frere(): void
    {
        $org = $this->organigramme();
        $besoinA11 = $this->besoin($org['serviceA11'], $org['exercice'], 'A11');
        $agentA12 = $this->utilisateur($org['serviceA12'], 'agent');

        $this->actingAs($agentA12)
            ->getJson('/api/v1/expressions-besoin/'.$besoinA11->id)
            ->assertForbidden();

        $references = collect($this->actingAs($agentA12)
            ->getJson('/api/v1/expressions-besoin?per_page=100')
            ->assertOk()
            ->json('data'))
            ->pluck('reference');
        $this->assertNotContains($besoinA11->reference, $references);
    }

    public function test_un_chef_de_service_voit_son_service_pas_le_service_frere(): void
    {
        $org = $this->organigramme();
        $besoinA11 = $this->besoin($org['serviceA11'], $org['exercice'], 'A11');
        $chefA11 = $this->utilisateur($org['serviceA11'], 'chef_service');

        $this->actingAs($chefA11)->getJson('/api/v1/expressions-besoin/'.$besoinA11->id)->assertOk();
    }

    public function test_un_directeur_voit_sa_direction_pas_la_direction_parallele(): void
    {
        $org = $this->organigramme();
        $besoinA11 = $this->besoin($org['serviceA11'], $org['exercice'], 'A11');
        $besoinA21 = $this->besoin($org['serviceA21'], $org['exercice'], 'A21');
        $directeurA1 = $this->utilisateur($org['directionA1'], 'directeur');

        $this->actingAs($directeurA1)->getJson('/api/v1/expressions-besoin/'.$besoinA11->id)->assertOk();
        $this->actingAs($directeurA1)->getJson('/api/v1/expressions-besoin/'.$besoinA21->id)->assertForbidden();
    }

    public function test_un_commissaire_voit_son_departement_pas_l_autre_departement(): void
    {
        $org = $this->organigramme();
        $besoinA11 = $this->besoin($org['serviceA11'], $org['exercice'], 'A11');
        $besoinB11 = $this->besoin($org['serviceB11'], $org['exercice'], 'B11');
        $commissaireA = $this->utilisateur($org['departementA'], 'commissaire');

        $this->actingAs($commissaireA)->getJson('/api/v1/expressions-besoin/'.$besoinA11->id)->assertOk();
        $this->actingAs($commissaireA)->getJson('/api/v1/expressions-besoin/'.$besoinB11->id)->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function organigramme(): array
    {
        $departementA = $this->unite('DEPA', 'Département A', 'departement', null);
        $directionA1 = $this->unite('DIRA1', 'Direction A1', 'direction', $departementA->id);
        $directionA2 = $this->unite('DIRA2', 'Direction A2', 'direction', $departementA->id);
        $serviceA11 = $this->unite('SERA11', 'Service A1.1', 'service', $directionA1->id);
        $serviceA12 = $this->unite('SERA12', 'Service A1.2', 'service', $directionA1->id);
        $serviceA21 = $this->unite('SERA21', 'Service A2.1', 'service', $directionA2->id);

        $departementB = $this->unite('DEPB', 'Département B', 'departement', null);
        $directionB1 = $this->unite('DIRB1', 'Direction B1', 'direction', $departementB->id);
        $serviceB11 = $this->unite('SERB11', 'Service B1.1', 'service', $directionB1->id);

        $exercice = Exercice::query()->create([
            'annee' => 2026,
            'statut' => 'executoire',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
        ]);

        return compact(
            'departementA',
            'directionA1',
            'directionA2',
            'serviceA11',
            'serviceA12',
            'serviceA21',
            'departementB',
            'directionB1',
            'serviceB11',
            'exercice',
        );
    }

    private function unite(string $sigle, string $name, string $kind, ?int $parentId): OrganizationUnit
    {
        return OrganizationUnit::query()->create([
            'parent_id' => $parentId,
            'sigle' => $sigle,
            'name' => $name,
            'kind' => $kind,
            'is_technical' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function utilisateur(OrganizationUnit $unit, string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'organization_unit_id' => $unit->id,
            'account_status' => 'actif',
        ]);
    }

    private function besoin(OrganizationUnit $unit, Exercice $exercice, string $suffix): ExpressionBesoin
    {
        $ligne = BudgetLine::query()->create([
            'exercice_id' => $exercice->id,
            'organization_unit_id' => $unit->id,
            'code' => 'LIG-'.$suffix,
            'label' => 'Ligne '.$suffix,
            'nature' => 'hors_pap',
            'montant_vote' => 10_000_000,
        ]);

        return ExpressionBesoin::query()->create([
            'reference' => 'EB-'.$suffix,
            'exercice_id' => $exercice->id,
            'organization_unit_id' => $unit->id,
            'initiator_id' => $this->utilisateur($unit, 'agent')->id,
            'budget_line_id' => $ligne->id,
            'nature' => 'hors_pap',
            'objet' => 'Besoin '.$suffix,
            'status' => 'brouillon',
            'workflow_step' => 'initiateur',
            'montant' => 100_000,
        ]);
    }
}
