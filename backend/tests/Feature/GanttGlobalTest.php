<?php

namespace Tests\Feature;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Monitoring\Services\PortfolioGanttService;
use App\Domains\PAP\Models\PapEnrichment;
use App\Models\User;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Gantt global de l’exécution du PAP, par période.
 */
class GanttGlobalTest extends TestCase
{
    use RefreshDatabase;

    private User $transverse;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 10:00:00');
        $this->seed(ExpressionBesoinSeeder::class);
        $this->transverse = User::factory()->create(['role' => 'directeur_budget', 'password' => 'password']);
    }

    public function test_les_activites_se_placent_sur_la_periode_choisie(): void
    {
        $datee = $this->activite('Atelier régional T2', ['date_debut' => '2026-04-01', 'date_fin' => '2026-06-30']);
        $indicative = $this->activite('Mission du quatrième trimestre', ['periode' => 'T4 2026']);
        $sansPeriode = $this->activite('Activité sans période', ['periode' => null]);

        $t2 = $this->actingAs($this->transverse)->getJson('/api/v1/suivi/gantt/portefeuille?annee=2026&periode=T2')
            ->assertOk()
            ->assertJsonPath('data.periode.debut', '2026-04-01')
            ->assertJsonPath('data.periode.fin', '2026-06-30')
            ->assertJsonPath('data.periode.echelle', 'semaines')
            ->json('data');
        $ids = collect($t2['activites'])->pluck('id');
        $this->assertTrue($ids->contains($datee->id));
        $this->assertFalse($ids->contains($indicative->id));
        $ligne = collect($t2['activites'])->firstWhere('id', $datee->id);
        $this->assertSame('activite', $ligne['source']);
        $this->assertSame(['gauche' => 0, 'largeur' => 100, 'coupe_debut' => false, 'coupe_fin' => false], $ligne['barre']);
        $this->assertTrue(collect($t2['non_planifiees'])->pluck('id')->contains($sansPeriode->id));

        $t4 = $this->actingAs($this->transverse)->getJson('/api/v1/suivi/gantt/portefeuille?annee=2026&periode=T4')->json('data');
        $ligne = collect($t4['activites'])->firstWhere('id', $indicative->id);
        $this->assertSame('pap', $ligne['source']);
        $this->assertSame('indicative', $ligne['etat']);
        $this->assertSame(['2026-10-01', '2026-12-31'], [$ligne['debut'], $ligne['fin']]);
        $this->assertFalse(collect($t4['activites'])->pluck('id')->contains($datee->id));

        $annee = $this->actingAs($this->transverse)->getJson('/api/v1/suivi/gantt/portefeuille?annee=2026')->json('data');
        $this->assertCount(12, $annee['colonnes']);
        $this->assertCount(4, $annee['periodes']);
        $this->assertNotNull($annee['aujourdhui']);
    }

    public function test_le_retard_se_mesure_et_le_filtre_d_etat_garde_la_synthese(): void
    {
        $enRetard = $this->activite('Étude achevée trop tard', ['date_debut' => '2026-03-01', 'date_fin' => '2026-10-31']);

        $tout = $this->actingAs($this->transverse)->getJson('/api/v1/suivi/gantt/portefeuille?annee=2026')->json('data');
        $ligne = collect($tout['activites'])->firstWhere('id', $enRetard->id);
        $this->assertSame('en_retard', $ligne['etat']);
        $this->assertSame(16, $ligne['retard_jours']);

        $filtre = $this->actingAs($this->transverse)->getJson('/api/v1/suivi/gantt/portefeuille?annee=2026&etat=en_retard')->json('data');
        $this->assertSame(['en_retard'], collect($filtre['activites'])->pluck('etat')->unique()->values()->all());
        $this->assertSame($tout['synthese']['dans_la_periode'], $filtre['synthese']['dans_la_periode']);
        $this->assertSame($filtre['synthese']['en_retard'], $filtre['synthese']['affichees']);
    }

    public function test_seules_les_activites_visibles_figurent(): void
    {
        $ligne = BudgetLine::query()->where('code', '203232')->firstOrFail();
        $etranger = User::query()->where('email', 'dsi.initiateur@ceeac.int')->firstOrFail();
        $this->assertNotSame($ligne->organization_unit_id, $etranger->organization_unit_id);
        $activite = PapEnrichment::query()->where('budget_line_id', $ligne->id)->firstOrFail();

        $data = $this->actingAs($etranger)->getJson('/api/v1/suivi/gantt/portefeuille?annee=2026')->assertOk()->json('data');
        $ids = collect($data['activites'])->pluck('id')->merge(collect($data['non_planifiees'])->pluck('id'));
        $this->assertFalse($ids->contains($activite->id));
    }

    public function test_une_periode_invalide_est_refusee(): void
    {
        $this->actingAs($this->transverse)->getJson('/api/v1/suivi/gantt/portefeuille?periode=T5')->assertUnprocessable()->assertJsonValidationErrors('periode');
    }

    public function test_la_periode_saisie_dans_le_pap_est_interpretee(): void
    {
        $service = app(PortfolioGanttService::class);
        $lire = fn (?string $texte) => ($bornes = $service->parsePeriod($texte)) ? [$bornes[0]->toDateString(), $bornes[1]->toDateString()] : null;

        $this->assertSame(['2026-01-01', '2026-12-31'], $lire('2026'));
        $this->assertSame(['2026-01-01', '2026-12-31'], $lire('Janvier – décembre 2026'));
        $this->assertSame(['2026-03-01', '2026-06-30'], $lire('Mars à juin 2026'));
        $this->assertSame(['2026-04-01', '2026-06-30'], $lire('T2 2026'));
        $this->assertSame(['2026-07-01', '2026-12-31'], $lire('S2 2026'));
        $this->assertNull($lire('À définir'));
        $this->assertNull($lire(null));
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function activite(string $libelle, array $attributs): PapEnrichment
    {
        $ligne = BudgetLine::query()->whereDoesntHave('enrichment')->firstOrFail();

        return PapEnrichment::query()->create(['budget_line_id' => $ligne->id, 'activite' => $libelle] + $attributs);
    }
}
