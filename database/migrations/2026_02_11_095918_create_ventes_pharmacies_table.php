<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ventes_pharmacie', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('montant_total', 10, 2);
            $table->enum('mode_paiement', [
                'espece',
                'orange',
                'wave',
                'moov'
            ]);

            $table->enum('statut_sync', [
                'en_attente',
                'synchronise'
            ])->default('synchronise');

            $table->uuid('reference_locale')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ventes_pharmacies');
    }
};
