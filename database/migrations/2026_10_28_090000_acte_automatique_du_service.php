<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'acte facturé à chaque ticket d'un service (« Consultation générale »
 * pour la Consultation) : le patient arrive à la caisse avec son montant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('tarif_id')->nullable()->constrained('tarifs')->nullOnDelete();
        });

        // Le service Consultation reprend le premier tarif de consultation existant.
        foreach (DB::table('services')->whereNull('deleted_at')->where('nom', 'like', 'Consultation%')->get() as $service) {
            $tarif = DB::table('tarifs')->where('etablissement_id', $service->etablissement_id)
                ->where('categorie', 'consultation')->where('actif', true)->whereNull('deleted_at')
                ->orderBy('id')->value('id');
            if ($tarif !== null) {
                DB::table('services')->where('id', $service->id)->update(['tarif_id' => $tarif]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tarif_id');
        });
    }
};
