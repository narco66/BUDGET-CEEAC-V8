<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Revenues\Models\RevenueContribution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CeeacReferentiel2026Test extends TestCase
{
    use RefreshDatabase;

    public function test_l_import_installe_l_organigramme_et_le_budget_vote(): void
    {
        User::factory()->create([
            'name' => 'Directeur du Budget',
            'email' => 'directeur.budget@ceeac.int',
            'role' => 'directeur_budget',
            'function_title' => 'Directeur du Budget',
            'initials' => 'DB',
        ]);

        $this->assertSame(0, Artisan::call('ceeac:importer-2026'));
        $this->assertSame(0, Artisan::call('ceeac:importer-2026'));

        $dati = OrganizationUnit::query()->where('sigle', 'DATI')->firstOrFail();
        $energie = OrganizationUnit::query()->where('sigle', 'DATI-DENER')->firstOrFail();
        $this->assertSame($dati->id, $energie->parent_id);
        $this->assertNotNull(OrganizationUnit::query()->where('sigle', 'DPRES-CAB-BCJ')->first());
        $this->assertSame(1, OrganizationUnit::query()->where('sigle', 'DSG')->count());

        $salaire = BudgetLine::query()->where('code', '66101')->firstOrFail();
        $this->assertSame(5377302285, $salaire->montant_vote);
        $this->assertTrue($salaire->officiel);
        $this->assertSame(245000000, BudgetLine::query()->where('code', '203232')->value('montant_vote'));
        $this->assertSame(40309295803, (int) BudgetLine::query()->officielle()->sum('montant_vote'));
        $this->assertSame(
            BudgetLine::query()->officielle()->count(),
            BudgetLine::query()->count(),
        );

        $angola = RevenueContribution::query()->whereHas('memberState', fn ($query) => $query->where('code', 'AO'))->firstOrFail();
        $this->assertSame(2890306628, $angola->montant_attendu);
        $this->assertSame(10000, (int) RevenueContribution::query()->sum('quote_part'));
    }
}
