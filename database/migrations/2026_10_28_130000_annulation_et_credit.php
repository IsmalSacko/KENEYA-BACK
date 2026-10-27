<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annuler une vente du jour (les produits rentrent en stock, l'argent est
 * rendu) et vendre à crédit (le client paie plus tard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visite_lignes', function (Blueprint $table) {
            // Unités sorties du stock : ce qu'on y remet si la vente est annulée.
            $table->unsignedInteger('unites')->nullable();
        });
        Schema::table('encaissements', function (Blueprint $table) {
            $table->timestamp('rembourse_at')->nullable();
            $table->foreignId('rembourse_par')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('visites', function (Blueprint $table) {
            $table->boolean('credit')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('visites', fn (Blueprint $table) => $table->dropColumn('credit'));
        Schema::table('encaissements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rembourse_par');
            $table->dropColumn('rembourse_at');
        });
        Schema::table('visite_lignes', fn (Blueprint $table) => $table->dropColumn('unites'));
    }
};
