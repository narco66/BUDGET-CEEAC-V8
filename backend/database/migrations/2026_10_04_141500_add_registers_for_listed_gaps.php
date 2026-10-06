<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_periods', function (Blueprint $table) {
            $table->timestamp('consolidated_at')->nullable();
            $table->foreignId('consolidated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('indicators', function (Blueprint $table) {
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('tiers', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->constrained('tiers')->nullOnDelete();
        });

        Schema::table('annual_closes', function (Blueprint $table) {
            $table->string('archive_reference')->nullable();
            $table->timestamp('archived_at')->nullable();
        });

        Schema::create('indicator_collection_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitoring_period_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('horizon');
            $table->timestamps();
            $table->unique(['indicator_id', 'monitoring_period_id', 'horizon']);
        });

        Schema::create('tiers_compliance_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiers_id')->constrained('tiers')->cascadeOnDelete();
            $table->string('kind');
            $table->string('reference');
            $table->date('expires_on');
            $table->string('path')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tiers_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiers_id')->constrained('tiers')->cascadeOnDelete();
            $table->date('occurred_on');
            $table->string('nature');
            $table->text('suite');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('control_findings', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('module');
            $table->string('subject');
            $table->string('severity');
            $table->text('detail');
            $table->string('status')->default('ouvert');
            $table->timestamps();
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('filename');
            $table->string('status')->default('controle');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->json('payload');
            $table->string('verdict');
            $table->string('message');
            $table->timestamps();
        });

        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('path');
            $table->timestamp('generated_at');
            $table->timestamps();
        });

        Schema::create('access_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->date('starts_on');
            $table->string('status')->default('ouverte');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('access_review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('decision')->default('en_attente');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['access_review_id', 'user_id']);
        });

        Schema::create('ged_retentions', function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->date('retain_until');
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ged_retentions');
        Schema::dropIfExists('access_review_items');
        Schema::dropIfExists('access_reviews');
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('control_findings');
        Schema::dropIfExists('tiers_incidents');
        Schema::dropIfExists('tiers_compliance_documents');
        Schema::dropIfExists('indicator_collection_reminders');

        Schema::table('annual_closes', function (Blueprint $table) {
            $table->dropColumn(['archive_reference', 'archived_at']);
        });
        Schema::table('tiers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
        });
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_user_id');
        });
        Schema::table('monitoring_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consolidated_by');
            $table->dropColumn('consolidated_at');
        });
    }
};
