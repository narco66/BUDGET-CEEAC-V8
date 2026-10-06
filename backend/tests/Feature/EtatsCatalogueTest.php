<?php

namespace Tests\Feature;

use App\Domains\Administration\Services\SauvegardeVerifier;
use App\Domains\Budget\Models\Exercice;
use App\Domains\Ged\Models\GedDocument;
use App\Domains\Ged\Models\GedVersion;
use App\Domains\Ged\Services\GedService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EtatsCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_portrait_detaille_reste_a_zero_sans_regle_de_delai(): void
    {
        $exercice = Exercice::query()->create([
            'annee' => (int) now()->year,
            'statut' => 'executoire',
            'date_debut' => now()->startOfYear()->toDateString(),
            'date_fin' => now()->endOfYear()->toDateString(),
        ]);
        $auditeur = User::factory()->create(['role' => 'auditeur', 'account_status' => 'actif']);

        $this->actingAs($auditeur)
            ->getJson('/api/v1/etats/base?exercice_id='.$exercice->id)
            ->assertOk()
            ->assertJsonPath('data.credits.total.revise', 0)
            ->assertJsonPath('data.credits.total.disponible', 0)
            ->assertJsonPath('data.delais.regles', 0)
            ->assertJsonPath('data.delais.depassements', 0)
            ->assertJsonPath('data.delais.precision', 'Aucune règle de délai n’est enregistrée. Aucun dépassement n’est calculé.');

        $export = $this->actingAs($auditeur)->get('/api/v1/etats/base/export?exercice_id='.$exercice->id);
        $export->assertOk();
        $this->assertStringContainsString('section;reference;statut;montant;fuseau', $export->streamedContent());
    }

    public function test_l_indexation_retrouve_un_mot_dans_un_fichier_texte(): void
    {
        Storage::fake('local');
        $contenu = 'note xylophonebudget';
        Storage::disk('local')->put('ged/note-xylophone.txt', $contenu);
        $auditeur = User::factory()->create(['role' => 'auditeur', 'account_status' => 'actif']);
        $document = GedDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'reference' => 'DOC-TEST-XYLO',
            'title' => 'Note interne',
            'owner_user_id' => $auditeur->id,
        ]);
        GedVersion::query()->create([
            'ged_document_id' => $document->id,
            'version_number' => 1,
            'disk' => 'local',
            'path' => 'ged/note-xylophone.txt',
            'original_filename' => 'note.txt',
            'mime' => 'text/plain',
            'extension' => 'txt',
            'size' => strlen($contenu),
            'sha256' => hash('sha256', $contenu),
            'is_current' => true,
            'scan_status' => 'non_requis',
        ]);

        $this->assertSame(1, app(GedService::class)->indexerTextes());
        $this->assertSame($contenu, GedVersion::query()->firstOrFail()->extracted_text);
        $this->actingAs($auditeur)
            ->getJson('/api/v1/ged?q=xylophonebudget')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_l_inventaire_d_un_faux_dump_ne_supprime_pas_le_fichier(): void
    {
        $repertoire = storage_path('framework/testing-sauvegardes-inventaire');
        if (! is_dir($repertoire)) {
            mkdir($repertoire, 0777, true);
        }
        $chemin = $repertoire.DIRECTORY_SEPARATOR.'faux.dump';
        file_put_contents($chemin, 'PGDMP contenu illisible');

        $rapport = app(SauvegardeVerifier::class)->inventorier($repertoire);

        $this->assertFalse($rapport['inventorie']);
        $this->assertSame(0, $rapport['tables']);
        $this->assertFileExists($chemin);
    }
}
