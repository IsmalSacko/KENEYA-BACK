<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valider à un autre prix que celui demandé (une remise négociée) : le
 * montant demandé est gardé, le montant validé le remplace, avec un motif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $table) {
            $table->unsignedInteger('montant_demande')->nullable()->after('montant');
            $table->string('note_validation', 255)->nullable()->after('motif_refus');
        });
    }

    public function down(): void
    {
        Schema::table('demandes_abonnement', fn (Blueprint $table) => $table->dropColumn(['montant_demande', 'note_validation']));
    }
};
