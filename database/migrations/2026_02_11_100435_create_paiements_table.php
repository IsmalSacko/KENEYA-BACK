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
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('source_type'); // consultation | vente_pharmacie
            $table->unsignedBigInteger('source_id');
            $table->decimal('montant', 10, 2);
            $table->enum('mode_paiement', [
                'espece',
                'orange',
                'wave',
                'moov'
            ]);

            $table->uuid('reference_locale')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paiements');
    }
};
