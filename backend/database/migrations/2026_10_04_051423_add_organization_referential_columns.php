<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_versions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('label');
            $table->string('document_reference')->nullable();
            $table->date('effective_on');
            $table->string('statut', 16)->default('brouillon');
            $table->text('comment')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('organization_positions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('rank')->default(100);
            $table->string('compatible_kind', 32)->nullable();
            $table->foreignId('parent_position_id')->nullable()->constrained('organization_positions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('organization_units', function (Blueprint $table) {
            $table->foreignId('version_id')->nullable()->constrained('organization_versions')->nullOnDelete();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('effective_on')->nullable();
            $table->unique('sigle');
            $table->index('kind');
            $table->index('is_active');
        });

        Schema::create('organization_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->constrained('organization_positions')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('statut', 16)->default('active');
            $table->string('motif')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->index(['organization_unit_id', 'statut']);
            $table->index(['user_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_assignments');
        Schema::table('organization_units', function (Blueprint $table) {
            $table->dropUnique(['sigle']);
            $table->dropIndex(['kind']);
            $table->dropIndex(['is_active']);
            $table->dropConstrainedForeignId('version_id');
            $table->dropColumn(['description', 'sort_order', 'is_active', 'effective_on']);
        });
        Schema::dropIfExists('organization_positions');
        Schema::dropIfExists('organization_versions');
    }
};
