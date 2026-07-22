<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Séquence de révision GLOBALE et monotone (une seule ligne).
        // Incrémentée sous verrou (FOR UPDATE) à chaque écriture serveur d'un
        // modèle syncable. Le pull filtre par etablissement_id + sync_revision,
        // donc un compteur global suffit et évite toute résolution de scope.
        Schema::create('sync_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('current_rev')->default(0);
            $table->timestamps();
        });

        DB::table('sync_sequences')->insert([
            'id' => 1,
            'current_rev' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_sequences');
    }
};
