<?php

declare(strict_types=1);

namespace App\Sante\Badges;

use App\Enums\Role;
use App\Models\Etablissement;
use App\Models\Hospitalisation;
use App\Models\Pointage;
use App\Models\User;
use App\Models\Visiteur;
use App\Sante\Qr;
use App\Sante\Rdv\Agendas;
use App\Sante\Refus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Qui est là : le personnel pointe avec son badge, les visiteurs entrent et
 * sortent avec le leur.
 */
final class Presence
{
    public const QR_BADGE = 'badge';

    public const QR_VISITEUR = 'visiteur';

    /** Au plus ce nombre de visiteurs auprès d'un même patient à la fois. */
    public const VISITEURS_MAX = 2;

    /** Un second scan dans la minute est le même pointage. */
    private const DOUBLON_SECONDES = 60;

    public function __construct(private readonly Agendas $agendas) {}

    public function badge(User $membre): string
    {
        if ($membre->badge_jeton === null) {
            $membre->update(['badge_jeton' => Str::random(24)]);
        }

        return Qr::creer(self::QR_BADGE, (string) $membre->badge_jeton);
    }

    /** Badge perdu : l'ancien QR ne marche plus. */
    public function renouveler(User $membre): string
    {
        $membre->update(['badge_jeton' => Str::random(24)]);

        return $this->badge($membre);
    }

    /**
     * Le badge scanné à l'entrée : arrivée, puis départ, puis arrivée…
     */
    public function pointer(Etablissement $etablissement, string $qr, User $par): Pointage
    {
        $lu = Qr::lire($qr);
        $membre = $lu !== null && $lu[0] === self::QR_BADGE
            ? User::where('badge_jeton', $lu[1])->where('etablissement_id', $etablissement->id)->first()
            : null;
        if ($membre === null) {
            throw new Refus('Badge inconnu ici.');
        }
        $dernier = Pointage::where('membre_id', $membre->id)->where('at', '>=', $this->debutJour($etablissement))->latest('at')->first();
        if ($dernier !== null && $dernier->at->diffInSeconds(now(), true) < self::DOUBLON_SECONDES) {
            return $dernier;
        }

        return Pointage::create([
            'etablissement_id' => $etablissement->id,
            'membre_id' => $membre->id,
            'type' => $dernier?->type === 'arrivee' ? 'depart' : 'arrivee',
            'at' => now(),
            'scanne_par' => $par->id,
        ]);
    }

    /**
     * Les membres présents en ce moment (dernier pointage du jour : arrivée).
     *
     * @return list<array{nom: string, role: string, depuis: string}>
     */
    public function presents(Etablissement $etablissement): array
    {
        $presents = [];
        $derniers = Pointage::with('membre')->where('etablissement_id', $etablissement->id)
            ->where('at', '>=', $this->debutJour($etablissement))->orderBy('at')->get()
            ->keyBy('membre_id');
        foreach ($derniers as $p) {
            if ($p->type === 'arrivee') {
                $presents[] = [
                    'nom' => $p->membre->name,
                    'role' => Role::tryFrom($p->membre->role)?->label() ?? '',
                    'depuis' => $p->at->toIso8601String(),
                ];
            }
        }

        return $presents;
    }

    /**
     * La présence du mois : jours de présence et heures, par membre.
     *
     * @return list<array{nom: string, role: string, jours: int, heures: float}>
     */
    public function rapport(Etablissement $etablissement, string $mois): array
    {
        $fuseau = $this->agendas->fuseau($etablissement);
        $debut = Carbon::parse("{$mois}-01", $fuseau)->startOfMonth();
        $pointages = Pointage::where('etablissement_id', $etablissement->id)
            ->whereBetween('at', [$debut->copy()->utc(), $debut->copy()->endOfMonth()->utc()])
            ->orderBy('at')->get()->groupBy('membre_id');

        $lignes = [];
        foreach (User::where('etablissement_id', $etablissement->id)->orderBy('name')->get() as $membre) {
            $jours = [];
            $minutes = 0;
            $arrivee = null;
            foreach ($pointages->get($membre->id) ?? [] as $p) {
                $jours[$p->at->copy()->setTimezone($fuseau)->toDateString()] = true;
                if ($p->type === 'arrivee') {
                    $arrivee = $p->at;
                } elseif ($arrivee !== null) {
                    $minutes += (int) $arrivee->diffInMinutes($p->at, true);
                    $arrivee = null;
                }
            }
            $lignes[] = [
                'nom' => $membre->name,
                'role' => Role::tryFrom($membre->role)?->label() ?? '',
                'jours' => count($jours),
                'heures' => round($minutes / 60, 1),
            ];
        }

        return $lignes;
    }

    /**
     * @param  array{nom: string, telephone?: string|null, piece?: string|null}  $donnees
     */
    public function entrer(Etablissement $etablissement, array $donnees, ?Hospitalisation $sejour, User $par): Visiteur
    {
        if ($sejour !== null) {
            if ($sejour->statut !== 'en_cours') {
                throw new Refus('Ce patient est sorti.');
            }
            $deja = Visiteur::where('hospitalisation_id', $sejour->id)->whereNull('sortie_at')->count();
            if ($deja >= self::VISITEURS_MAX) {
                throw new Refus('Déjà '.self::VISITEURS_MAX.' visiteurs auprès de ce patient : attendez qu’un sorte.');
            }
        }

        return Visiteur::create([
            ...$donnees,
            'etablissement_id' => $etablissement->id,
            'hospitalisation_id' => $sejour?->id,
            'entree_at' => now(),
            'enregistre_par' => $par->id,
        ]);
    }

    public function sortir(Visiteur $v): Visiteur
    {
        if ($v->sortie_at === null) {
            $v->update(['sortie_at' => now()]);
        }

        return $v;
    }

    /**
     * @return array<string, mixed>
     */
    public function visiteur(Visiteur $v): array
    {
        $v->loadMissing('hospitalisation.dossier', 'hospitalisation.lit.chambre');
        $h = $v->hospitalisation;

        return [
            'uuid' => $v->uuid,
            'nom' => $v->nom,
            'telephone' => $v->telephone,
            'patient' => $h?->dossier->nom,
            'lit' => $h?->lit?->libelle(),
            'entree_at' => $v->entree_at->toIso8601String(),
            'sortie_at' => $v->sortie_at?->toIso8601String(),
            'qr' => Qr::creer(self::QR_VISITEUR, $v->uuid),
        ];
    }

    private function debutJour(Etablissement $etablissement): Carbon
    {
        return now()->setTimezone($this->agendas->fuseau($etablissement))->startOfDay()->utc();
    }
}
