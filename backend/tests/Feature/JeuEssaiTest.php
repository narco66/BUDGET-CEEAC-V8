<?php

namespace Tests\Feature;

use App\Domains\Administration\Models\BusinessRule;
use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Monitoring\Models\Indicator;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\Revenues\Models\RevenueOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JeuEssaiTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_jeu_essai_parcours_les_circuits_sans_les_doubler(): void
    {
        $this->acteurs();

        $this->assertSame(0, Artisan::call('demo:jeu-essai'));
        $dossiers = ExpressionBesoin::query()->where('objet', 'like', 'Jeu d\'essai%')->count();
        $this->assertGreaterThanOrEqual(5, $dossiers);
        $eb = ExpressionBesoin::query()->where('objet', 'like', 'Jeu d\'essai%')->firstOrFail();
        $pieces = DB::table('document_types')->where('operation', 'engagement')->where('required', true)->where('active', true)->pluck('label');
        $this->assertNotEmpty($pieces);
        foreach ($pieces as $piece) {
            $this->assertDatabaseHas('eb_documents', ['expression_besoin_id' => $eb->id, 'type' => $piece]);
        }
        $this->assertTrue(Paiement::query()->where('status', 'cloture')->exists());
        $this->assertTrue(Ordonnancement::query()->whereNull('signed_at')->exists());
        $this->assertSame(8_000_000, RevenueOrder::query()->where('motif', 'Jeu d\'essai — vente de publications')->value('montant'));
        $this->assertNotNull(Indicator::query()->where('code', 'IND-JEU-203232')->first());
        $this->assertSame(40309295803, (int) BudgetLine::query()->officielle()->sum('montant_vote'));

        $this->assertSame(0, Artisan::call('demo:jeu-essai'));
        $this->assertSame($dossiers, ExpressionBesoin::query()->where('objet', 'like', 'Jeu d\'essai%')->count());
    }

    private function acteurs(): void
    {
        User::factory()->create([
            'name' => 'Directeur du Budget',
            'email' => 'directeur.budget@ceeac.int',
            'role' => 'directeur_budget',
            'function_title' => 'Directeur du Budget',
            'initials' => 'DB',
        ]);
        $this->assertSame(0, Artisan::call('ceeac:importer-2026'));
        BusinessRule::query()->firstOrCreate(
            ['code' => 'plafond_caisse'],
            ['label' => 'Plafond de règlement en caisse', 'value' => '500000', 'unit' => 'XAF', 'active' => true],
        );

        $unites = OrganizationUnit::query()->pluck('id', 'sigle');
        $fiches = [
            ['Blaise ESSONO', 'blaise.essono@ceeac.int', 'expert_budget', 'DSG-DPPB-SB'],
            ['Chef Budget', 'chef.budget@ceeac.int', 'chef_budget', 'DSG-DPPB-SB'],
            ['Contrôleur', 'controleur.financier@ceeac.int', 'controleur_financier', 'DPRES-CFC'],
            ['Aline MOUSSAVOU', 'aline.moussavou@ceeac.int', 'secretaire_general', 'DSG'],
            ['Ordonnateur', 'ordonnateur@ceeac.int', 'ordonnateur', 'DPRES'],
            ['Rita OBAME', 'rita.obame@ceeac.int', 'comptable', 'DPRES-ACC'],
            ['Marc NDZIE', 'marc.ndzie@ceeac.int', 'chef_comptable', 'DPRES-ACC'],
            ['Paul NGUEMA', 'paul.nguema@ceeac.int', 'agent_comptable', 'DPRES-ACC'],
            ['Clarisse NDONG', 'clarisse.ndong@ceeac.int', 'initiateur', 'DATI-DENER'],
            ['Jean-Pierre OKOMBI', 'jp.okombi@ceeac.int', 'directeur', 'DATI-DENER'],
            ['Initiateur DSI', 'dsi.initiateur@ceeac.int', 'initiateur', 'DSG-DSI'],
            ['Directeur DSI', 'dsi.directeur@ceeac.int', 'directeur', 'DSG-DSI'],
            ['Initiateur DRH', 'drh.initiateur@ceeac.int', 'initiateur', 'DSG-DRHMG'],
            ['Directeur DRH', 'drh.directeur@ceeac.int', 'directeur', 'DSG-DRHMG'],
        ];
        foreach ($fiches as [$nom, $email, $role, $sigle]) {
            User::factory()->create([
                'name' => $nom,
                'email' => $email,
                'role' => $role,
                'function_title' => $nom,
                'initials' => 'JE',
                'organization_unit_id' => $unites[$sigle],
            ]);
        }
    }
}
