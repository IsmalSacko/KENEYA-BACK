<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ngoni Santé, lot 1 : une personne = un numéro de téléphone.
 *
 * - `personnes` : l'identité (patient et/ou professionnel), avec son PIN.
 * - `users` devient le « membre » : une personne dans un établissement, avec
 *   son rôle. Chaque membre est rattaché à sa personne (`personne_id`).
 * - `etablissements` reçoit pays, devise, profil et modules.
 *
 * Reprise : les membres existants qui partagent un numéro sont rattachés à la
 * même personne ; elle prend le nom et le PIN du membre modifié en dernier.
 * Cette migration est autonome (pas de modèles) : elle doit rester rejouable
 * telle quelle quand les modèles évolueront.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('nom');
            $table->string('pays_code', 2);
            $table->string('telephone', 20)->unique(); // international : +22376123456
            $table->string('pin');
            $table->timestamp('telephone_verifie_at')->nullable();
            $table->date('date_naissance')->nullable();
            $table->string('sexe', 1)->nullable(); // F, M
            $table->string('langue', 5)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('pays_code')->references('code')->on('pays');
        });

        Schema::create('codes_verification', function (Blueprint $table) {
            $table->id();
            $table->string('telephone', 20)->index();
            $table->string('objet', 20); // inscription, pin
            $table->string('code');
            $table->timestamp('expire_at');
            $table->unsignedTinyInteger('tentatives')->default(0);
            $table->timestamp('utilise_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('personne_id')->nullable()->after('id')->constrained('personnes')->nullOnDelete();
        });

        Schema::table('etablissements', function (Blueprint $table) {
            $table->string('pays_code', 2)->default('ML')->after('type');
            $table->string('devise', 3)->default('XOF')->after('pays_code');
            $table->string('profil', 30)->nullable()->after('devise');
            $table->json('modules')->nullable()->after('profil');
        });

        $this->reprendreEtablissements();
        $this->reprendrePersonnes();
    }

    public function down(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->dropColumn(['pays_code', 'devise', 'profil', 'modules']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('personne_id');
        });
        Schema::dropIfExists('codes_verification');
        Schema::dropIfExists('personnes');
    }

    private function reprendreEtablissements(): void
    {
        $profils = [
            'cabinet' => ['cabinet_medical', ['file', 'rdv', 'consultation', 'caisse']],
            'pharmacie' => ['pharmacie', ['file', 'caisse', 'pharmacie']],
            'cabinet_pharmacie' => ['centre_sante', ['file', 'rdv', 'consultation', 'caisse', 'pharmacie', 'labo', 'maternite']],
        ];

        foreach ($profils as $type => [$profil, $modules]) {
            DB::table('etablissements')
                ->where('type', $type)
                ->whereNull('profil')
                ->update(['profil' => $profil, 'modules' => json_encode($modules)]);
        }
    }

    private function reprendrePersonnes(): void
    {
        $indicatifs = DB::table('pays')->pluck('indicatif', 'code');

        $membres = DB::table('users')
            ->leftJoin('etablissements', 'etablissements.id', '=', 'users.etablissement_id')
            ->whereNull('users.deleted_at')
            ->orderByDesc('users.updated_at')
            ->orderByDesc('users.id')
            ->get(['users.id', 'users.name', 'users.telephone', 'users.pin', 'users.actif', 'etablissements.pays_code']);

        /** @var array<string, int> $parNumero */
        $parNumero = [];
        foreach ($membres as $membre) {
            $paysCode = is_string($membre->pays_code) ? $membre->pays_code : 'ML';
            $indicatif = (string) ($indicatifs[$paysCode] ?? '223');
            $telephone = $this->international((string) $membre->telephone, $indicatif);

            if (! isset($parNumero[$telephone])) {
                $parNumero[$telephone] = (int) DB::table('personnes')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'nom' => $membre->name,
                    'pays_code' => $paysCode,
                    'telephone' => $telephone,
                    'pin' => $membre->pin, // déjà haché
                    'actif' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('users')->where('id', $membre->id)->update(['personne_id' => $parNumero[$telephone]]);
        }
    }

    /** Même règle que App\Sante\Telephone, figée ici (pays sans 0 initial). */
    private function international(string $saisi, string $indicatif): string
    {
        $saisi = trim($saisi);
        $chiffres = (string) preg_replace('/\D+/', '', $saisi);

        if (str_starts_with($saisi, '+')) {
            return '+'.$chiffres;
        }
        if (str_starts_with($saisi, '00')) {
            return '+'.substr($chiffres, 2);
        }
        // « 22376123456 » : déjà international, seul le + manque.
        if (str_starts_with($chiffres, $indicatif) && strlen($chiffres) >= strlen($indicatif) + 8) {
            return '+'.$chiffres;
        }

        return '+'.$indicatif.$chiffres;
    }
};
