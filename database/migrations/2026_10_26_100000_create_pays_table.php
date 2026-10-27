<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pays', function (Blueprint $table) {
            $table->string('code', 2)->primary();
            $table->string('nom');
            $table->string('drapeau', 16);
            $table->string('indicatif', 4);
            $table->string('devise', 3);
            $table->unsignedTinyInteger('decimales')->default(0);
            $table->json('langues');
            $table->string('fuseau');
            $table->json('moyens_paiement');
            $table->boolean('supprimer_zero_initial')->default(false);
            $table->boolean('actif')->default(true);
            $table->unsignedSmallInteger('ordre')->default(100);
            $table->timestamps();
        });

        // Données de départ : le Mali d'abord (pays par défaut), puis la
        // sous-région. Moyens de paiement et règle du 0 initial à vérifier
        // pays par pays avant l'ouverture ; ce sont des données modifiables.
        $pays = [
            ['ML', 'Mali', '🇲🇱', '223', 'XOF', 0, ['fr', 'bm'], 'Africa/Bamako', ['especes', 'orange_money', 'moov_money', 'wave'], false],
            ['SN', 'Sénégal', '🇸🇳', '221', 'XOF', 0, ['fr', 'wo'], 'Africa/Dakar', ['especes', 'orange_money', 'wave', 'free_money'], false],
            ['CI', 'Côte d’Ivoire', '🇨🇮', '225', 'XOF', 0, ['fr'], 'Africa/Abidjan', ['especes', 'orange_money', 'mtn_momo', 'moov_money', 'wave'], false],
            ['BF', 'Burkina Faso', '🇧🇫', '226', 'XOF', 0, ['fr'], 'Africa/Ouagadougou', ['especes', 'orange_money', 'moov_money'], false],
            ['NE', 'Niger', '🇳🇪', '227', 'XOF', 0, ['fr'], 'Africa/Niamey', ['especes'], false],
            ['GN', 'Guinée', '🇬🇳', '224', 'GNF', 0, ['fr'], 'Africa/Conakry', ['especes', 'orange_money', 'mtn_momo'], false],
            ['TG', 'Togo', '🇹🇬', '228', 'XOF', 0, ['fr'], 'Africa/Lome', ['especes'], false],
            ['BJ', 'Bénin', '🇧🇯', '229', 'XOF', 0, ['fr'], 'Africa/Porto-Novo', ['especes', 'mtn_momo', 'moov_money'], false],
            ['MR', 'Mauritanie', '🇲🇷', '222', 'MRU', 2, ['fr', 'ar'], 'Africa/Nouakchott', ['especes'], false],
            ['CM', 'Cameroun', '🇨🇲', '237', 'XAF', 0, ['fr', 'en'], 'Africa/Douala', ['especes', 'orange_money', 'mtn_momo'], false],
            ['GA', 'Gabon', '🇬🇦', '241', 'XAF', 0, ['fr'], 'Africa/Libreville', ['especes'], false],
            ['CG', 'Congo', '🇨🇬', '242', 'XAF', 0, ['fr'], 'Africa/Brazzaville', ['especes', 'mtn_momo'], false],
            ['TD', 'Tchad', '🇹🇩', '235', 'XAF', 0, ['fr', 'ar'], 'Africa/Ndjamena', ['especes'], false],
            ['CD', 'RD Congo', '🇨🇩', '243', 'CDF', 2, ['fr'], 'Africa/Kinshasa', ['especes', 'orange_money', 'mpesa'], true],
            ['GH', 'Ghana', '🇬🇭', '233', 'GHS', 2, ['en'], 'Africa/Accra', ['especes', 'mtn_momo'], true],
            ['NG', 'Nigeria', '🇳🇬', '234', 'NGN', 2, ['en'], 'Africa/Lagos', ['especes'], true],
            ['KE', 'Kenya', '🇰🇪', '254', 'KES', 2, ['en'], 'Africa/Nairobi', ['especes', 'mpesa'], true],
            ['MA', 'Maroc', '🇲🇦', '212', 'MAD', 2, ['fr', 'ar'], 'Africa/Casablanca', ['especes'], true],
            ['FR', 'France', '🇫🇷', '33', 'EUR', 2, ['fr'], 'Europe/Paris', ['especes'], true],
        ];

        $maintenant = now();
        foreach ($pays as $ordre => [$code, $nom, $drapeau, $indicatif, $devise, $decimales, $langues, $fuseau, $moyens, $zero]) {
            DB::table('pays')->insert([
                'code' => $code,
                'nom' => $nom,
                'drapeau' => $drapeau,
                'indicatif' => $indicatif,
                'devise' => $devise,
                'decimales' => $decimales,
                'langues' => json_encode($langues),
                'fuseau' => $fuseau,
                'moyens_paiement' => json_encode($moyens),
                'supprimer_zero_initial' => $zero,
                'actif' => true,
                'ordre' => $ordre,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pays');
    }
};
