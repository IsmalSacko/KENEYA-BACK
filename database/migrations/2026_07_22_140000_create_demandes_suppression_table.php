<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Demandes de suppression de compte / de données (RGPD, exigence Play Store).
        Schema::create('demandes_suppression', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->nullable();
            $table->string('telephone');
            // pro = compte personnel de clinique ; patient = données de rendez-vous.
            $table->enum('type_compte', ['pro', 'patient']);
            $table->text('motif')->nullable();
            $table->enum('statut', ['en_attente', 'traitee', 'rejetee'])->default('en_attente');
            $table->timestamp('traitee_le')->nullable();
            $table->timestamps();

            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_suppression');
    }
};
