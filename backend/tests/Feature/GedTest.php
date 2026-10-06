<?php

namespace Tests\Feature;

use App\Domains\Administration\Models\SystemSetting;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Ged\Models\GedDocument;
use App\Domains\Ged\Services\GedService;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Models\User;
use App\Shared\Documents\GeneratedDocument;
use Database\Seeders\AdministrationSeeder;
use Database\Seeders\ExpressionBesoinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpressionBesoinSeeder::class);
        $this->seed(AdministrationSeeder::class);
    }

    public function test_le_depot_le_refus_la_quarantaine_et_l_acces(): void
    {
        $initiateur = User::query()->where('email', 'daj.initiateur@ceeac.int')->firstOrFail();
        $autre = User::query()->where('email', 'dps.initiateur@ceeac.int')->firstOrFail();
        $eb = ExpressionBesoin::query()->where('reference', 'EB/2026/DAJ/000118')->firstOrFail();

        $this->actingAs($initiateur)->post('/api/v1/ged', [
            'fichier' => UploadedFile::fake()->create('script.php', 1, 'application/x-httpd-php'),
            'title' => 'Script',
            'entity_type' => 'expression_besoin',
            'entity_id' => $eb->id,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $cree = $this->actingAs($initiateur)->post('/api/v1/ged', [
            'fichier' => UploadedFile::fake()->createWithContent('note.txt', "justificatif d'essai\n"),
            'title' => 'Note confidentielle',
            'entity_type' => 'expression_besoin',
            'entity_id' => $eb->id,
            'confidentiality' => 'confidentiel',
        ], ['Accept' => 'application/json'])->assertCreated();

        $id = $cree->json('data.id');
        $this->assertNotNull($id);
        $this->assertDatabaseHas('audit_events', ['action' => 'ged.deposer', 'object_id' => (string) $id]);
        $this->assertDatabaseHas('workflow_tasks', ['fingerprint' => 'ged:'.$id, 'status' => 'a_traiter']);

        $this->actingAs($autre)->getJson('/api/v1/ged/'.$id)->assertForbidden();
        $this->actingAs($autre)->get('/api/v1/ged/'.$id.'/fichier')->assertForbidden();
        $export = $this->actingAs($autre)->postJson('/api/v1/ged/export', ['ids' => [$id]])->assertOk();
        $archive = new \ZipArchive;
        $archive->open($export->baseResponse->getFile()->getPathname());
        $this->assertSame(1, $archive->numFiles);
        $this->assertSame('bordereau.csv', $archive->getNameIndex(0));
        $archive->close();

        $quarantaine = $this->actingAs($initiateur)->post('/api/v1/ged', [
            'fichier' => UploadedFile::fake()->createWithContent('piece.txt', "%PDF-1.4\n"),
            'title' => 'Piece suspecte',
            'entity_type' => 'expression_besoin',
            'entity_id' => $eb->id,
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('quarantaine', $quarantaine->json('data.scan'));
        $this->actingAs($initiateur)->get('/api/v1/ged/'.$quarantaine->json('data.id').'/fichier')->assertStatus(423);

        $this->actingAs($initiateur)->post('/api/v1/ged/'.$id.'/versions', [
            'fichier' => UploadedFile::fake()->createWithContent('note-v2.txt', "version suivante\n"),
            'motif' => 'Correction de la note',
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, GedDocument::query()->find($id)->versions()->where('is_current', true)->count());
        $this->assertSame(2, GedDocument::query()->find($id)->versions()->count());

        $admin = User::query()->where('email', 'amina.oko@ceeac.int')->firstOrFail();
        $this->actingAs($admin)->postJson('/api/v1/ged/'.$id.'/decision', ['decision' => 'geler', 'motif' => 'Contrôle'])->assertOk();
        $this->actingAs($initiateur)->post('/api/v1/ged/'.$id.'/versions', [
            'fichier' => UploadedFile::fake()->createWithContent('note-v3.txt', "apres gel\n"),
            'motif' => 'Tentative',
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_l_heritage_le_versement_et_la_reprise(): void
    {
        $initiateur = User::query()->where('email', 'daj.initiateur@ceeac.int')->firstOrFail();
        $eb = ExpressionBesoin::query()->where('reference', 'EB/2026/DAJ/000118')->firstOrFail();
        $depot = $this->actingAs($initiateur)->post('/api/v1/ged', [
            'fichier' => UploadedFile::fake()->createWithContent('devis.txt', "devis d'essai\n"),
            'title' => 'Devis',
            'entity_type' => 'expression_besoin',
            'entity_id' => $eb->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $engagement = Engagement::query()->create([
            'reference' => 'ENG-TEST-GED',
            'expression_besoin_id' => $eb->id,
            'budget_line_id' => $eb->budget_line_id,
            'montant' => $eb->montant,
        ]);
        $dossier = $this->actingAs($initiateur)->getJson('/api/v1/ged/dossier?type=engagement&id='.$engagement->id)->assertOk()->json('data');
        $this->assertSame('herite', $dossier['documents'][0]['heritage']);
        $this->assertSame(1, GedDocument::query()->count());

        Storage::disk('local')->put('actes/essai.pdf', 'pdf');
        $acte = GeneratedDocument::query()->create([
            'verification_code' => (string) \Illuminate\Support\Str::uuid(),
            'documentable_type' => $engagement->getMorphClass(),
            'documentable_id' => $engagement->id,
            'kind' => 'fiche',
            'version' => 1,
            'business_reference' => $engagement->reference,
            'event' => 'generation',
            'path' => 'actes/essai.pdf',
            'filename' => 'essai.pdf',
            'sha256' => hash('sha256', 'pdf'),
            'size' => 3,
            'snapshot' => ['reference' => $engagement->reference],
            'generated_by' => $initiateur->id,
        ]);
        $service = app(GedService::class);
        $service->verserActe($acte);
        $service->verserActe($acte);
        $this->assertSame(1, GedDocument::query()->where('generated_document_id', $acte->id)->count());

        Storage::disk('local')->put('pieces/reprise.txt', 'contenu repris');
        DB::table('eb_documents')->insert([
            'expression_besoin_id' => $eb->id,
            'uploaded_by' => $initiateur->id,
            'type' => 'note',
            'original_name' => 'reprise.txt',
            'path' => 'pieces/reprise.txt',
            'mime' => 'text/plain',
            'size' => 14,
            'sha256' => hash('sha256', 'contenu repris'),
            'confidentialite' => 'interne',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('eb_documents')->insert([
            'expression_besoin_id' => $eb->id,
            'uploaded_by' => $initiateur->id,
            'type' => 'note',
            'original_name' => 'absente.txt',
            'path' => null,
            'mime' => 'text/plain',
            'size' => 0,
            'confidentialite' => 'interne',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rapport = $service->reprendrePieces();
        $this->assertSame(1, $rapport['repris']);
        $this->assertTrue(collect($rapport['anomalies'])->contains(fn (string $anomalie) => str_contains($anomalie, 'sans fichier')));
        $this->assertSame(0, $service->reprendrePieces()['repris']);
        Storage::disk('local')->assertExists('pieces/reprise.txt');

        $manques = $this->actingAs($initiateur)->getJson('/api/v1/ged/dossier?type=engagement&id='.$engagement->id)->json('data.completude.manques');
        $this->assertNotEmpty($manques);
        $this->assertSame(0, $service->integrite());

        SystemSetting::query()->create(['key' => 'ged.bloquer_pieces', 'value' => '1', 'critical' => false]);
        try {
            $service->assertSiBloquant('engagement', $engagement->id);
            $this->fail('La pièce obligatoire manquante aurait dû bloquer la transition.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pieces', $exception->errors());
        }
    }
}
