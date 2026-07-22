<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // paiements : référence polymorphe (consultation | vente_pharmacie) par uuid.
        Schema::table('paiements', function (Blueprint $t) {
            $t->uuid('source_uuid')->nullable()->after('source_id');
        });

        // journal_audits : cible polymorphe par uuid (tolérante, peut rester non résolue).
        // id_cible est déjà nullable — un audit dont la cible n'est pas (encore)
        // synchronisée ne bloque jamais la synchro.
        Schema::table('journal_audits', function (Blueprint $t) {
            $t->uuid('cible_uuid')->nullable()->after('id_cible');
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $t) {
            $t->dropColumn('source_uuid');
        });

        Schema::table('journal_audits', function (Blueprint $t) {
            $t->dropColumn('cible_uuid');
        });
    }
};
