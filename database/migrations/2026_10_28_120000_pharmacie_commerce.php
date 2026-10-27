<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La pharmacie comme commerce : chaque produit dans un rayon (médicaments,
 * contraception, bébé…) avec son prix d'achat ; chaque ligne de facture
 * sait quel produit elle a vendu (ventes par rayon, plus vendus) ; la vente
 * au comptoir se distingue de la visite d'un patient ; et l'officine choisit
 * si le vendeur encaisse lui-même ou si le client passe à la caisse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicaments', function (Blueprint $table) {
            $table->string('rayon', 30)->default('medicaments');
            $table->decimal('prix_achat', 12, 2)->nullable();
        });
        Schema::table('visite_lignes', function (Blueprint $table) {
            $table->foreignId('medicament_id')->nullable()->constrained('medicaments')->nullOnDelete();
        });
        Schema::table('visites', function (Blueprint $table) {
            $table->string('nature', 10)->default('visite');
        });
        Schema::table('etablissements', function (Blueprint $table) {
            $table->boolean('caisse_separee')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('etablissements', fn (Blueprint $table) => $table->dropColumn('caisse_separee'));
        Schema::table('visites', fn (Blueprint $table) => $table->dropColumn('nature'));
        Schema::table('visite_lignes', fn (Blueprint $table) => $table->dropConstrainedForeignId('medicament_id'));
        Schema::table('medicaments', fn (Blueprint $table) => $table->dropColumn(['rayon', 'prix_achat']));
    }
};
