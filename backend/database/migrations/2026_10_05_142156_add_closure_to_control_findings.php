<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_findings', function (Blueprint $table) {
            $table->string('origin', 32)->default('automatique');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motif_cloture')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('control_findings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opened_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['origin', 'closed_at', 'motif_cloture']);
        });
    }
};
