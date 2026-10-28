<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les catégories (rayons) de la pharmacie, propres à chaque établissement :
 * vide, ce sont celles de départ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->json('rayons')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('etablissements', fn (Blueprint $table) => $table->dropColumn('rayons'));
    }
};
