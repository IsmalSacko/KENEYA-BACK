<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rendez_vous', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->unsignedBigInteger('sync_revision')->default(0);

            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            // Médecin optionnel : le patient peut ne pas en choisir un.
            $table->foreignId('medecin_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('patient_nom');
            $table->string('patient_telephone');
            $table->dateTime('date_souhaitee');
            $table->string('motif')->nullable();

            $table->enum('statut', [
                'demande',
                'confirme',
                'refuse',
                'annule',
                'honore',
            ])->default('demande');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['etablissement_id', 'sync_revision']);
            $table->index(['etablissement_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rendez_vous');
    }
};
