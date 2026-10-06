<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('verification_code')->unique();
            $table->string('documentable_type');
            $table->unsignedBigInteger('documentable_id');
            $table->string('kind', 32);
            $table->unsignedInteger('version');
            $table->string('business_reference', 64);
            $table->string('event', 64);
            $table->string('path');
            $table->string('filename');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->json('snapshot');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('generated_documents')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['documentable_type', 'documentable_id', 'kind', 'version'], 'generated_documents_version_unique');
            $table->index(['documentable_type', 'documentable_id', 'kind']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER generated_documents_append_only BEFORE UPDATE OR DELETE ON generated_documents FOR EACH ROW EXECUTE FUNCTION gesbudep_forbid_journal_change();');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS generated_documents_append_only ON generated_documents;');
        }
        Schema::dropIfExists('generated_documents');
    }
};
