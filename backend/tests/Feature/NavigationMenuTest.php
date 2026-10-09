<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdministrationSeeder;
use Database\Seeders\ReferentielOrganisationRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NavigationMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AdministrationSeeder::class);
        $this->seed(ReferentielOrganisationRolesSeeder::class);
    }

    public function test_un_acteur_authentifie_recoit_ses_modules_sans_l_administration(): void
    {
        $initiateur = User::factory()->create([
            'role' => 'initiateur',
            'account_status' => 'actif',
        ]);

        $reponse = $this->actingAs($initiateur)->getJson('/api/v1/navigation')->assertOk();
        $cles = $this->cles($reponse);

        $this->assertContains('taches', $cles);
        $this->assertContains('besoins', $cles);
        $this->assertNotContains('parametrage', $cles);
        $this->assertNotContains('administration', $cles);
    }

    public function test_les_entrees_administratives_suivent_les_roles(): void
    {
        $fonctionnel = User::factory()->create([
            'role' => 'administrateur_fonctionnel',
            'account_status' => 'actif',
        ]);
        $ordonnateur = User::factory()->create([
            'role' => 'ordonnateur',
            'account_status' => 'actif',
        ]);

        $clesFonctionnel = $this->cles($this->actingAs($fonctionnel)->getJson('/api/v1/navigation')->assertOk());
        $clesOrdonnateur = $this->cles($this->actingAs($ordonnateur)->getJson('/api/v1/navigation')->assertOk());

        $this->assertContains('administration', $clesFonctionnel);
        $this->assertContains('parametrage', $clesFonctionnel);
        $this->assertNotContains('habilitations', $clesFonctionnel);
        $this->assertContains('habilitations', $clesOrdonnateur);
        $this->assertNotContains('parametrage', $clesOrdonnateur);
    }

    public function test_une_requete_anonyme_est_refusee(): void
    {
        $this->getJson('/api/v1/navigation')->assertUnauthorized();
    }

    public function test_les_profils_du_referentiel_organisationnel_activent_leur_navigation(): void
    {
        $president = User::factory()->create(['role' => 'president', 'account_status' => 'actif']);
        $commissaire = User::factory()->create(['role' => 'commissaire', 'account_status' => 'actif']);
        $chefService = User::factory()->create(['role' => 'chef_service', 'account_status' => 'actif']);

        $clesPresident = $this->cles($this->actingAs($president)->getJson('/api/v1/navigation')->assertOk());
        $clesCommissaire = $this->cles($this->actingAs($commissaire)->getJson('/api/v1/navigation')->assertOk());
        $clesChefService = $this->cles($this->actingAs($chefService)->getJson('/api/v1/navigation')->assertOk());

        $this->assertContains('chaine', $clesPresident);
        $this->assertNotContains('administration', $clesPresident);
        $this->assertContains('planification', $clesCommissaire);
        $this->assertContains('besoins', $clesCommissaire);
        $this->assertContains('besoins', $clesChefService);
        $this->assertNotContains('preparation', $clesChefService);
    }

    public function test_les_fonctions_officielles_activent_leurs_roles_metier(): void
    {
        $president = User::factory()->create(['role' => 'president', 'account_status' => 'actif']);
        $agentComptableCentral = User::factory()->create(['role' => 'agent_comptable_central', 'account_status' => 'actif']);
        $auditeurInterne = User::factory()->create(['role' => 'auditeur_interne', 'account_status' => 'actif']);
        $controleurCentral = User::factory()->create(['role' => 'controleur_financier_central', 'account_status' => 'actif']);

        $this->assertTrue($president->holds('ordonnateur'));
        $this->assertTrue($agentComptableCentral->holds('agent_comptable'));
        $this->assertTrue($auditeurInterne->holds('auditeur'));
        $this->assertTrue($controleurCentral->holds('controleur_financier'));
    }

    /**
     * @param  TestResponse  $reponse
     * @return list<string>
     */
    private function cles($reponse): array
    {
        return collect($reponse->json('groups'))
            ->flatMap(fn (array $groupe): array => collect($groupe['items'])->pluck('key')->all())
            ->values()
            ->all();
    }
}
