<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Socle GED distinct des actes officiels (generated_documents, append-only)
 * et du référentiel de pièces (document_types).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ged_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('ged_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 32)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('document_type_id')->nullable()->constrained('document_types')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('ged_categories')->nullOnDelete();
            $table->string('status', 32)->default('depose');
            $table->string('confidentiality', 32)->default('interne');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->unsignedSmallInteger('exercise_year')->nullable();
            $table->string('origin', 32)->default('user_upload');
            $table->foreignId('generated_document_id')->nullable()->unique()->constrained('generated_documents')->nullOnDelete();
            $table->string('source_table', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('scan_status', 32)->default('non_requis');
            $table->timestamps();

            $table->unique(['source_table', 'source_id']);
            $table->index(['status', 'exercise_year']);
            $table->index('confidentiality');
        });

        Schema::create('ged_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ged_document_id')->constrained('ged_documents')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime', 128);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_reason')->nullable();
            $table->boolean('is_current')->default(false);
            $table->boolean('is_signed')->default(false);
            $table->string('scan_status', 32)->default('non_requis');
            $table->timestamps();

            $table->unique(['ged_document_id', 'version_number']);
            $table->index(['sha256']);
        });

        Schema::create('ged_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ged_document_id')->constrained('ged_documents')->cascadeOnDelete();
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id');
            $table->string('relation', 32)->default('propre');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ged_document_id', 'entity_type', 'entity_id']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('ged_tags', function (Blueprint $table) {
            $table->id();
            $table->string('label', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ged_document_tag', function (Blueprint $table) {
            $table->foreignId('ged_document_id')->constrained('ged_documents')->cascadeOnDelete();
            $table->foreignId('ged_tag_id')->constrained('ged_tags')->cascadeOnDelete();
            $table->primary(['ged_document_id', 'ged_tag_id']);
        });

        Schema::create('ged_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ged_document_id')->constrained('ged_documents')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'ged_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ged_favorites');
        Schema::dropIfExists('ged_document_tag');
        Schema::dropIfExists('ged_tags');
        Schema::dropIfExists('ged_links');
        Schema::dropIfExists('ged_versions');
        Schema::dropIfExists('ged_documents');
        Schema::dropIfExists('ged_categories');
    }
};
