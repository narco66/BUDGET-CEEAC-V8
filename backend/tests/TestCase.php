<?php

namespace Tests;

use App\Domains\Commitments\Models\Paiement;
use App\Domains\Suppliers\Services\TiersService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Les actes officiels et les pièces sont écrits sur un disque factice :
     * aucun test ne dépose de fichier dans storage/.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Compte bancaire validé du tiers attendu par ce paiement.
     */
    protected function avisBancaire(): UploadedFile
    {
        return UploadedFile::fake()->create('avis-bancaire.pdf', 16, 'application/pdf');
    }

    protected function compteValide(Paiement $paiement): int
    {
        $account = app(TiersService::class)->eligibleAccounts($paiement->fresh())->first();
        $this->assertNotNull($account, 'Aucun compte validé pour '.$paiement->reference);

        return $account->id;
    }

    /**
     * Réinitialise les gardes avant de changer d’acteur : le garde Sanctum
     * mémorise l’utilisateur résolu lors de la requête précédente.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::actingAs($user, $guard);
    }
}
