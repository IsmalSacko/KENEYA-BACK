<?php

declare(strict_types=1);

namespace App\Sante\Pharmacie;

use App\Models\Etablissement;
use App\Models\Medicament;
use App\Sante\Refus;
use Illuminate\Support\Str;

/**
 * Les catégories (rayons) d'un établissement : celles de départ, renommées,
 * complétées ou retirées par lui. « Médicaments » reste toujours : c'est le
 * rayon où l'ordonnance et la molécule ont un sens.
 */
final class Rayons
{
    /**
     * @return list<array{code: string, nom: string}>
     */
    public function de(Etablissement $etablissement): array
    {
        $propres = $etablissement->getAttribute('rayons');
        if (is_array($propres) && $propres !== []) {
            $liste = [];
            foreach ($propres as $r) {
                if (is_array($r) && is_string($r['code'] ?? null) && is_string($r['nom'] ?? null)) {
                    $liste[] = ['code' => $r['code'], 'nom' => $r['nom']];
                }
            }

            return $liste;
        }

        return array_map(static fn (Rayon $r): array => ['code' => $r->value, 'nom' => $r->label()], Rayon::cases());
    }

    /** @return list<string> */
    public function codes(Etablissement $etablissement): array
    {
        return array_column($this->de($etablissement), 'code');
    }

    public function nom(Etablissement $etablissement, string $code): string
    {
        foreach ($this->de($etablissement) as $r) {
            if ($r['code'] === $code) {
                return $r['nom'];
            }
        }

        return Rayon::tryFrom($code)?->label() ?? $code;
    }

    /**
     * La nouvelle liste, dans l'ordre voulu. Une catégorie sans code est
     * nouvelle ; une catégorie absente est retirée, si aucun produit n'y est.
     *
     * @param  list<array{code?: string|null, nom: string}>  $saisies
     * @return list<array{code: string, nom: string}>
     */
    public function enregistrer(Etablissement $etablissement, array $saisies): array
    {
        $anciens = $this->codes($etablissement);
        $liste = [];
        $noms = [];
        foreach ($saisies as $s) {
            $nom = trim($s['nom']);
            $cle = mb_strtolower($nom);
            if ($nom === '' || isset($noms[$cle])) {
                throw new Refus($nom === '' ? 'Une catégorie n’a pas de nom.' : "« {$nom} » est en double.");
            }
            $noms[$cle] = true;
            $code = isset($s['code']) && in_array($s['code'], $anciens, true) ? $s['code'] : $this->nouveauCode($nom, [...$anciens, ...array_column($liste, 'code')]);
            $liste[] = ['code' => $code, 'nom' => $nom];
        }
        $codes = array_column($liste, 'code');
        if (! in_array(Rayon::Medicaments->value, $codes, true)) {
            throw new Refus('« '.$this->nom($etablissement, Rayon::Medicaments->value).' » ne se retire pas : renommez-la si besoin.');
        }
        foreach (array_diff($anciens, $codes) as $retire) {
            $n = Medicament::where('etablissement_id', $etablissement->id)->where('rayon', $retire)->count();
            if ($n > 0) {
                throw new Refus('« '.$this->nom($etablissement, $retire)." » contient {$n} produit(s) : changez-les de catégorie d’abord.");
            }
        }
        $etablissement->forceFill(['rayons' => $liste])->save();

        return $liste;
    }

    /** @param list<string> $pris */
    private function nouveauCode(string $nom, array $pris): string
    {
        $base = Str::limit(Str::slug($nom, '_'), 24, '') ?: 'rayon';
        $code = $base;
        for ($i = 2; in_array($code, $pris, true); $i++) {
            $code = $base.'_'.$i;
        }

        return $code;
    }
}
