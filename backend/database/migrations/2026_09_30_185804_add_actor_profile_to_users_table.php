<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_unit_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('function_title')->nullable()->after('name');
            $table->string('role', 32)->nullable()->after('function_title');
            $table->string('initials', 8)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_unit_id');
            $table->dropColumn(['function_title', 'role', 'initials']);
        });
    }
};
