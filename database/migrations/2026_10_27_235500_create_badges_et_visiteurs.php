<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ngoni Santé, lot 8 : badges du personnel (pointage) et visiteurs.
 *
 * - `users.badge_jeton` : le QR du badge, renouvelable s'il est perdu.
 * - `pointages` : arrivées et départs, scannés à l'entrée.
 * - `visiteurs` : qui rend visite à quel patient hospitalisé, entrée et
 *   sortie (badge visiteur avec QR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('badge_jeton', 40)->nullable()->unique();
        });

        Schema::create('pointages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membre_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 8); // arrivee, depart
            $table->timestamp('at');
            $table->foreignId('scanne_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['etablissement_id', 'at']);
        });

        Schema::create('visiteurs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hospitalisation_id')->nullable()->constrained('hospitalisations')->nullOnDelete();
            $table->string('nom');
            $table->string('telephone', 20)->nullable();
            $table->string('piece', 40)->nullable();
            $table->timestamp('entree_at');
            $table->timestamp('sortie_at')->nullable();
            $table->foreignId('enregistre_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['etablissement_id', 'sortie_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visiteurs');
        Schema::dropIfExists('pointages');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['badge_jeton']);
            $table->dropColumn('badge_jeton');
        });
    }
};
