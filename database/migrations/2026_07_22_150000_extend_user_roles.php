<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.role` passe d'un ENUM figé à une chaîne (VARCHAR).
 *
 * Les rôles valides sont désormais contrôlés côté application (App\Enums\Role
 * + Rule::in), ce qui permet d'ajouter un rôle sans migration et fonctionne
 * de la même façon sur MySQL (prod) et SQLite (tests).
 * Défaut = 'medecin' (moindre privilège : jamais admin par défaut).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 50)->default('medecin')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', ['admin', 'medecin', 'pharmacien', 'caissier'])
                ->default('admin')->change();
        });
    }
};
