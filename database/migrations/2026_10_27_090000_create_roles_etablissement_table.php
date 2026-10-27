<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Droits d'un rôle dans un établissement, quand l'admin les a ajustés.
 * Sans ligne ici, le rôle garde ses droits par défaut (App\Sante\Permission).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles_etablissement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('role', 50);
            $table->json('permissions');
            $table->timestamps();

            $table->unique(['etablissement_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles_etablissement');
    }
};
