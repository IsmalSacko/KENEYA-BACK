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
        Schema::create('journal_audits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('etablissement_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('action');
            // ex: "creation_vente", "suppression_patient"

            $table->string('type_cible');
            // ex: "Patient", "VentePharmacie"

            $table->unsignedBigInteger('id_cible')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_audits');
    }
};
