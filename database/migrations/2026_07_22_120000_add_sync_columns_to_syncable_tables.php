<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables scopées directement par etablissement_id : index composite
     * (etablissement_id, sync_revision) pour un pull delta efficace.
     *
     * @var list<string>
     */
    private array $scopedTables = [
        'etablissements', // scopé par son propre id, pas de colonne etablissement_id
        'users',
        'patients',
        'medicaments',
        'consultations',
        'ventes_pharmacie',
        'mouvement_stocks',
        'paiements',
        'journal_audits',
    ];

    public function up(): void
    {
        foreach ($this->scopedTables as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->uuid('uuid')->nullable()->unique()->after('id');
                $t->unsignedBigInteger('sync_revision')->default(0)->after('uuid');
                $t->softDeletes();

                if ($table === 'etablissements') {
                    $t->index(['id', 'sync_revision']);
                } else {
                    $t->index(['etablissement_id', 'sync_revision']);
                }
            });
        }

        // vente_pharmacie_articles n'a pas de etablissement_id (scopé via son parent).
        Schema::table('vente_pharmacie_articles', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->unique()->after('id');
            $t->unsignedBigInteger('sync_revision')->default(0)->after('uuid');
            $t->softDeletes();
            $t->index('sync_revision');
        });
    }

    public function down(): void
    {
        foreach ([...$this->scopedTables, 'vente_pharmacie_articles'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique($table.'_uuid_unique');
                if ($table === 'etablissements') {
                    $t->dropIndex(['id', 'sync_revision']);
                } elseif ($table === 'vente_pharmacie_articles') {
                    $t->dropIndex(['sync_revision']);
                } else {
                    $t->dropIndex(['etablissement_id', 'sync_revision']);
                }
                $t->dropColumn(['uuid', 'sync_revision', 'deleted_at']);
            });
        }
    }
};
