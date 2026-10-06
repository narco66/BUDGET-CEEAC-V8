<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidation_rectifications', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('liquidation_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->unsignedBigInteger('amount');
            $table->string('motif');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('credit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counterpart_line_id')->nullable()->constrained('budget_lines')->nullOnDelete();
            $table->string('kind');
            $table->unsignedBigInteger('amount');
            $table->string('motif');
            $table->string('acte');
            $table->string('group_key')->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('integration_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('idempotence_key')->unique();
            $table->string('event');
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->json('payload');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox');
        Schema::dropIfExists('credit_movements');
        Schema::dropIfExists('liquidation_rectifications');
    }
};
