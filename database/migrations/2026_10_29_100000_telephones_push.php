<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Les téléphones d'une personne qui reçoivent ses notifications (Firebase). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telephones_push', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personne_id')->constrained('personnes')->cascadeOnDelete();
            $table->string('jeton', 512)->unique();
            $table->string('plateforme', 20)->default('android');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telephones_push');
    }
};
