<?php

declare(strict_types=1);

namespace App\Sante;

use App\Models\Etablissement;
use App\Models\Pays;
use App\Models\Personne;
use App\Models\User;

/**
 * Retrouve ou crée la personne derrière un numéro.
 */
final class Personnes
{
    /**
     * Personne d'un membre qu'on crée (inscription 3.x, ajout par un admin,
     * synchro hors ligne) : celle de son numéro, ou une nouvelle qui reprend
     * son nom et son PIN.
     */
    public static function pourMembre(User $membre): Personne
    {
        $etablissementId = $membre->getAttribute('etablissement_id');
        $paysCode = is_numeric($etablissementId)
            ? Etablissement::whereKey((int) $etablissementId)->value('pays_code')
            : null;
        $pays = Pays::find(is_string($paysCode) ? $paysCode : Pays::DEFAUT)
            ?? Pays::findOrFail(Pays::DEFAUT);

        $saisi = $membre->getAttribute('telephone');
        $telephone = Telephone::normaliser(is_string($saisi) ? $saisi : '', $pays);

        $existante = Personne::where('telephone', $telephone)->first();
        if ($existante !== null) {
            return $existante;
        }

        $nom = $membre->getAttribute('name');
        $personne = new Personne([
            'nom' => is_string($nom) ? $nom : '',
            'pays_code' => $pays->code,
            'telephone' => $telephone,
        ]);
        // Le PIN du membre est déjà haché : on le recopie tel quel.
        $personne->setRawAttributes(array_merge($personne->getAttributes(), [
            'pin' => $membre->getAttributes()['pin'] ?? '',
        ]));
        $personne->save();

        return $personne;
    }
}
