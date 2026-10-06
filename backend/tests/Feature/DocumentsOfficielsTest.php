<?php

namespace Tests\Feature;

use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Services\ChainActePresenter;
use App\Models\User;
use App\Shared\Documents\GeneratedDocument;
use Database\Seeders\EngagementSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class DocumentsOfficielsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ExpressionBesoinSeeder::class);
        $this->seed(EngagementSeeder::class);
    }

    public function test_le_visa_archive_l_acte_une_fois_et_le_sert_a_l_identique(): void
    {
        $engagement = $this->engagementVise();
        $document = GeneratedDocument::query()->where('documentable_id', $engagement->id)->where('kind', 'engagement')->sole();

        $this->assertSame(1, $document->version);
        $this->assertSame('visa', $document->event);
        $this->assertSame($document->sha256, hash('sha256', Storage::disk('local')->get($document->path)));
        $this->assertSame($engagement->reference, $document->snapshot['reference']);
        $controle = GeneratedDocument::query()->where('documentable_id', $engagement->id)->where('kind', 'controle_budgetaire')->first();
        $this->assertNotNull($controle);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($document->path));
        $acte = app(ChainActePresenter::class)->engagement($engagement->fresh());
        $this->assertSame($engagement->reference, $acte['reference']);
        $this->assertStringNotContainsString('à renseigner', (string) json_encode($acte));

        $controleur = User::query()->where('email', 'controleur.financier@ceeac.int')->firstOrFail();
        foreach ([1, 2] as $consultation) {
            $response = $this->actingAs($controleur)->get("/api/v1/engagements/{$engagement->id}/pdf")->assertOk();
            $this->assertSame($document->sha256, $response->headers->get('X-Document-Sha256'));
            $this->assertSame($document->sha256, hash('sha256', $response->streamedContent()));
        }
        $this->assertSame(1, GeneratedDocument::query()->where('documentable_id', $engagement->id)->where('kind', 'engagement')->count());
    }

    public function test_un_degagement_produit_une_nouvelle_version_sans_toucher_la_precedente(): void
    {
        $engagement = $this->engagementVise();
        $v1 = GeneratedDocument::query()->where('documentable_id', $engagement->id)->where('kind', 'engagement')->sole();
        $directeur = User::query()->where('email', 'directeur.budget@ceeac.int')->firstOrFail();

        $this->actingAs($directeur)
            ->postJson("/api/v1/engagements/{$engagement->id}/degager", ['montant' => 1000, 'motif' => 'Ajustement'])
            ->assertOk();

        $versions = $this->actingAs($directeur)
            ->getJson("/api/v1/documents?type=engagement&id={$engagement->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version', 2)
            ->assertJsonPath('data.0.evenement', 'degagement');
        $this->assertNotSame($v1->sha256, $versions->json('data.0.sha256'));

        $ancienne = $this->actingAs($directeur)->get("/api/v1/engagements/{$engagement->id}/pdf?version=1")->assertOk();
        $this->assertSame($v1->sha256, hash('sha256', $ancienne->streamedContent()));
    }

    public function test_le_code_de_verification_detecte_une_alteration(): void
    {
        $engagement = $this->engagementVise();
        $document = GeneratedDocument::query()->where('documentable_id', $engagement->id)->where('kind', 'engagement')->sole();
        $controleur = User::query()->where('email', 'controleur.financier@ceeac.int')->firstOrFail();

        $this->actingAs($controleur)
            ->getJson("/api/v1/documents/verifier/{$document->verification_code}")
            ->assertOk()
            ->assertJsonPath('authentique', true)
            ->assertJsonPath('integre', true)
            ->assertJsonPath('document.reference', $engagement->reference);

        Storage::disk('local')->put($document->path, 'contenu falsifié');

        $this->actingAs($controleur)
            ->getJson("/api/v1/documents/verifier/{$document->verification_code}")
            ->assertOk()
            ->assertJsonPath('integre', false);
        $this->actingAs($controleur)->get("/api/v1/engagements/{$engagement->id}/pdf")->assertStatus(409);
        $this->actingAs($controleur)->getJson('/api/v1/documents/verifier/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }

    public function test_une_version_archivee_n_est_pas_modifiable(): void
    {
        $engagement = $this->engagementVise();
        $document = GeneratedDocument::query()->where('documentable_id', $engagement->id)->firstOrFail();

        $this->expectException(LogicException::class);
        $document->update(['sha256' => str_repeat('0', 64)]);
    }

    public function test_la_verification_publique_ne_revele_pas_lempreinte(): void
    {
        $engagement = $this->engagementVise();
        $document = GeneratedDocument::query()->where('documentable_id', $engagement->id)->where('kind', 'engagement')->sole();

        $this->getJson('/api/v1/public/documents/'.$document->verification_code)
            ->assertOk()
            ->assertJsonPath('authentique', true)
            ->assertJsonPath('reference', $engagement->reference)
            ->assertJsonPath('archivage', 'courant')
            ->assertJsonMissingPath('sha256')
            ->assertJsonMissingPath('document');

        $this->getJson('/api/v1/public/documents/00000000-0000-0000-0000-000000000000')->assertNotFound();
        $this->getJson('/api/v1/public/documents/inconnu')->assertNotFound();
    }

    public function test_un_cheque_ne_produit_pas_un_ordre_de_virement(): void
    {
        $paiement = new Paiement(['mode' => 'cheque', 'reference' => 'PAY-TEST', 'montant' => 1000, 'montant_paye' => 1000]);

        $this->expectException(ValidationException::class);
        app(ChainActePresenter::class)->present('ordre_virement', $paiement);
    }

    private function engagementVise(): Engagement
    {
        $engagement = Engagement::query()->where('reference', 'ENG-2026-000457')->firstOrFail();
        $this->actingAs(User::query()->where('email', 'controleur.financier@ceeac.int')->firstOrFail())
            ->postJson("/api/v1/engagements/{$engagement->id}/viser")
            ->assertOk();

        return $engagement->fresh();
    }
}
