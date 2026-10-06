<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->string('bon_livraison')->nullable()->after('service_fait_reserves');
            $table->string('nature_prestation')->nullable()->after('bon_livraison');
            $table->string('lieu_reception')->nullable()->after('nature_prestation');
            $table->json('service_lignes')->nullable()->after('lieu_reception');
            $table->date('invoice_due')->nullable()->after('invoice_date');
        });
    }

    public function down(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropColumn([
                'bon_livraison',
                'nature_prestation',
                'lieu_reception',
                'service_lignes',
                'invoice_due',
            ]);
        });
    }
};
