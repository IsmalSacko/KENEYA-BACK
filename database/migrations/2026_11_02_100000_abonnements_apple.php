<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achat intégré Apple. L'abonnement de l'établissement garde l'abonnement
 * Apple qui le paie (originalTransactionId, le même à chaque renouvellement) :
 * seule sa fin chez Apple peut l'arrêter. Chaque transaction Apple (achat,
 * renouvellement) devient une demande payée, une seule fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abonnements', function (Blueprint $table) {
            $table->string('apple_transaction_originale', 64)->nullable()->index();
        });
        Schema::table('demandes_abonnement', function (Blueprint $table) {
            $table->string('apple_transaction_id', 64)->nullable()->unique()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $table) {
            $table->dropUnique(['apple_transaction_id']);
            $table->dropColumn('apple_transaction_id');
        });
        Schema::table('abonnements', function (Blueprint $table) {
            $table->dropIndex(['apple_transaction_originale']);
            $table->dropColumn('apple_transaction_originale');
        });
    }
};
