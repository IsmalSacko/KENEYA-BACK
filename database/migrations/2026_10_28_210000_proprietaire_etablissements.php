<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La personne qui a créé l'établissement : elle seule peut le supprimer, et
 * il compte dans sa limite d'établissements. Pour ceux d'avant : le premier
 * administrateur de l'établissement relié à une personne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->foreignId('proprietaire_id')->nullable()->constrained('personnes')->nullOnDelete();
        });

        $premiers = DB::table('users')
            ->where('role', 'admin_etablissement')
            ->whereNotNull('personne_id')
            ->whereNotNull('etablissement_id')
            ->orderBy('id')
            ->get(['etablissement_id', 'personne_id'])
            ->unique('etablissement_id');
        foreach ($premiers as $membre) {
            DB::table('etablissements')->where('id', $membre->etablissement_id)->update(['proprietaire_id' => $membre->personne_id]);
        }
    }

    public function down(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proprietaire_id');
        });
    }
};
