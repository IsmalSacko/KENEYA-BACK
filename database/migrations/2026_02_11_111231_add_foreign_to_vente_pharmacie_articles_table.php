<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vente_pharmacie_articles', function (Blueprint $table) {
            $table->foreign('vente_pharmacie_id')->references('id')->on('vente_pharmacie_articles')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vente_pharmacie_articles', function (Blueprint $table) {
            $table->dropForeign(['vente_pharmacie_id']);
        });
    }
};
