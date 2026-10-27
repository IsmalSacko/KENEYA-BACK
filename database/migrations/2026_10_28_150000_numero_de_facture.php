<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le numéro de facture (« F26-00001 ») : donné au premier encaissement, à la
 * suite dans l'établissement et l'année, jamais deux fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visites', function (Blueprint $table) {
            $table->string('numero_facture', 20)->nullable();
            $table->unique(['etablissement_id', 'numero_facture']);
        });
    }

    public function down(): void
    {
        Schema::table('visites', function (Blueprint $table) {
            $table->dropUnique(['etablissement_id', 'numero_facture']);
            $table->dropColumn('numero_facture');
        });
    }
};
