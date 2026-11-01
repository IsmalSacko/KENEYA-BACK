<?php

declare(strict_types=1);

namespace App\Sante\Apple;

use App\Models\Abonnement;
use App\Models\DemandeAbonnement;
use App\Models\Etablissement;
use App\Models\Formule;
use App\Sante\Abonnements\Abonnements;
use App\Sante\Alertes\AlertesPlateforme;
use App\Sante\Refus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Abonnements achetés dans l'application iPhone (achat intégré Apple,
 * StoreKit 2, auto-renouvelables), comme dans Ngoni Caisse : Apple encaisse
 * et renouvelle, le serveur suit.
 *
 * - L'abonnement est celui d'un établissement : l'application lie l'achat à
 *   son uuid (appAccountToken), seul celui-là en profite.
 * - L'application envoie l'achat qu'elle vient de faire (acheter) ; Apple
 *   envoie ensuite achats, renouvellements, fins et remboursements (notifier,
 *   App Store Server Notifications V2). Tout est un JWS vérifié (JwsApple).
 * - Chaque transaction Apple devient une fois, et une seule, une demande payée
 *   approuvée d'elle-même : reçu, recette dans la console. L'échéance est
 *   celle qu'Apple a vendue (expiresDate), pas une durée projetée.
 * - L'abonnement garde l'abonnement Apple qui le paie (originalTransactionId) :
 *   une fin chez Apple n'arrête que lui, jamais une période payée ensuite
 *   autrement (un autre paiement l'efface, voir Abonnements::approuver).
 */
final class AchatsApple
{
    /** Le moyen noté sur la demande. */
    public const MOYEN = 'apple_app_store';

    /** Achat (ou réabonnement) et renouvellement : la période payée est accordée. */
    private const ACCORDER = ['SUBSCRIBED', 'DID_RENEW'];

    /** Fin, paiement en échec après la grâce d'Apple, remboursement, partage familial retiré. */
    private const ARRETER = ['EXPIRED', 'GRACE_PERIOD_EXPIRED', 'REFUND', 'REVOKE'];

    public function __construct(
        private readonly JwsApple $jws,
        private readonly Abonnements $abonnements,
    ) {}

    /** L'identifiant App Store d'une formule et d'une durée (config apple.produits), s'il y en a un. */
    public static function produitPour(string $formule, int $mois): ?string
    {
        foreach ((array) config('apple.produits') as $produit => $offre) {
            if (is_array($offre) && ($offre[0] ?? null) === $formule && ($offre[1] ?? null) === $mois) {
                return (string) $produit;
            }
        }

        return null;
    }

    /**
     * Le dernier jour de l'abonnement : celui de la fin vendue par Apple (le
     * jour de fin compte en entier). Un achat de test (Sandbox : un mois dure
     * cinq minutes) court donc jusqu'au soir.
     */
    public static function finPour(Carbon $expire): Carbon
    {
        return $expire->copy()->startOfDay();
    }

    /**
     * L'achat que l'application vient de faire, pour l'établissement ouvert :
     * l'application l'a lié à son uuid (appAccountToken). Rejouer la même
     * transaction ne fait rien de plus.
     *
     * @throws Refus
     */
    public function acheter(Etablissement $etablissement, ?int $demandeurId, string $jws): void
    {
        try {
            $t = $this->jws->verifier($jws);
        } catch (JwsInvalide $e) {
            Log::warning('App Store : transaction refusée', ['erreur' => $e->getMessage(), 'etablissement' => $etablissement->uuid]);

            throw new Refus('Cette transaction de l’App Store est illisible ou n’est pas signée par Apple.');
        }

        $refus = $this->controler($t);
        if ($refus !== null) {
            throw new Refus($refus);
        }

        if (! is_string($t['appAccountToken'] ?? null) || strcasecmp($t['appAccountToken'], $etablissement->uuid) !== 0) {
            throw new Refus('Cet achat de l’App Store a été fait pour un autre établissement : il ne peut pas servir à celui-ci.');
        }

        $suite = $this->accorder($t, $etablissement, $demandeurId);
        Log::info('App Store : achat envoyé par l’application '.$suite, ['transaction' => $t['transactionId'], 'environnement' => $t['environment']]);
        $message = match ($suite) {
            'remboursée' => 'Cet achat a été remboursé par Apple : il n’ouvre plus d’abonnement.',
            'expirée' => 'Cet abonnement de l’App Store est déjà terminé : renouvelez-le depuis l’application.',
            'illimité' => 'L’abonnement de cet établissement est sans échéance : cet achat n’y change rien. Demandez son remboursement à Apple, ou écrivez au support.',
            default => null,
        };
        if ($message !== null) {
            throw new Refus($message);
        }
    }

    /**
     * Notification de l'App Store (V2). Une signature invalide lève JwsInvalide
     * (Apple réessaiera) ; tout le reste est accusé, même ignoré.
     *
     * @throws JwsInvalide
     */
    public function notifier(string $signedPayload): void
    {
        $notification = $this->jws->verifier($signedPayload);
        $type = self::texte($notification['notificationType'] ?? null);
        $data = is_array($notification['data'] ?? null) ? $notification['data'] : [];
        $journal = function (string $suite, array $t = []) use ($type, $notification): void {
            $sousType = self::texte($notification['subtype'] ?? null);
            Log::info('App Store : '.$type.($sousType !== '' ? '/'.$sousType : '').' '.$suite, array_filter([
                'transaction' => $t['transactionId'] ?? null,
                'originale' => $t['originalTransactionId'] ?? null,
                'environnement' => $t['environment'] ?? null,
            ]));
        };

        if (! in_array($type, [...self::ACCORDER, ...self::ARRETER], true)) {
            $journal('ignorée');

            return;
        }
        // Signée par Apple, mais pour une autre application (l'identifiant n'est donné qu'en production).
        if (($data['bundleId'] ?? null) !== config('apple.bundle_id')
            || (($data['environment'] ?? null) === 'Production' && self::entier($data['appAppleId'] ?? null) !== config()->integer('apple.app_apple_id'))) {
            $journal('ignorée : autre application');

            return;
        }
        if (! is_string($data['signedTransactionInfo'] ?? null)) {
            $journal('ignorée : sans transaction');

            return;
        }

        $t = $this->jws->verifier($data['signedTransactionInfo']);
        $refus = $this->controler($t);
        if ($refus !== null) {
            $journal('ignorée : '.$refus, $t);

            return;
        }

        if (in_array($type, self::ARRETER, true)) {
            $journal($this->arreter($t, $type), $t);

            return;
        }

        $etablissement = $this->etablissementDe($t);
        $journal($etablissement === null ? 'ignorée : établissement introuvable' : $this->accorder($t, $etablissement), $t);
    }

    /**
     * Ce qui interdit d'accorder cette transaction, ou null.
     *
     * @param  array<string, mixed>  $t
     */
    private function controler(array $t): ?string
    {
        $complete = filled($t['transactionId'] ?? null) && filled($t['originalTransactionId'] ?? null) && is_numeric($t['expiresDate'] ?? null);

        return match (true) {
            ($t['bundleId'] ?? null) !== config('apple.bundle_id') => 'Cet achat a été fait dans une autre application.',
            ! in_array($t['environment'] ?? null, (array) config('apple.environnements'), true) => 'Cet achat vient d’un environnement de l’App Store non accepté.',
            $this->offre($t) === null => 'Ce produit de l’App Store n’est pas une formule Ngoni Santé.',
            ! $complete => 'Cette transaction de l’App Store est incomplète.',
            default => null,
        };
    }

    /**
     * La formule et la durée (en mois) du produit acheté. Une formule retirée
     * du catalogue reste honorée : Apple continue de la renouveler.
     *
     * @param  array<string, mixed>  $t
     * @return array{0: Formule, 1: int}|null
     */
    private function offre(array $t): ?array
    {
        $produits = config('apple.produits');
        $offre = is_array($produits) ? ($produits[self::texte($t['productId'] ?? null)] ?? null) : null;
        $formule = is_array($offre) && is_string($offre[0] ?? null) ? Formule::where('code', $offre[0])->first() : null;
        $mois = is_array($offre) && is_int($offre[1] ?? null) ? $offre[1] : null;

        return $formule === null || $formule->code === Formule::ESSAI || $mois === null ? null : [$formule, $mois];
    }

    /**
     * L'établissement payé : celui que l'application a lié à l'achat, sinon
     * celui dont l'abonnement est déjà porté par cet abonnement Apple.
     *
     * @param  array<string, mixed>  $t
     */
    private function etablissementDe(array $t): ?Etablissement
    {
        $jeton = $t['appAccountToken'] ?? null;
        $etablissement = is_string($jeton) && Str::isUuid($jeton)
            ? Etablissement::whereIn('uuid', [strtolower($jeton), strtoupper($jeton)])->first()
            : null;

        return $etablissement
            ?? Abonnement::where('apple_transaction_originale', self::texte($t['originalTransactionId'] ?? null))->first()?->etablissement;
    }

    /**
     * La période payée chez Apple devient l'abonnement de l'établissement,
     * une seule fois par transaction.
     *
     * @param  array<string, mixed>  $t
     * @return string ce qu'il s'est passé : accordé, déjà traitée, remboursée, expirée, illimité
     */
    private function accorder(array $t, Etablissement $etablissement, ?int $demandeurId = null): string
    {
        [$formule, $mois] = $this->offre($t) ?? throw new Refus('Ce produit de l’App Store n’est pas une formule Ngoni Santé.');
        $transaction = self::texte($t['transactionId'] ?? null);
        $originale = self::texte($t['originalTransactionId'] ?? null);
        $expire = self::date($t['expiresDate']);
        $test = ($t['environment'] ?? null) === 'Sandbox';
        $demande = null;

        try {
            $suite = DB::transaction(function () use ($t, $etablissement, $demandeurId, $formule, $mois, $transaction, $originale, $expire, $test, &$demande): string {
                // L'achat envoyé par l'application et la notification d'Apple arrivent souvent ensemble : un à la fois par établissement.
                Etablissement::whereKey($etablissement->id)->lockForUpdate()->first();
                if (DemandeAbonnement::where('apple_transaction_id', $transaction)->exists()) {
                    return 'déjà traitée';
                }
                if (filled($t['revocationDate'] ?? null)) {
                    return 'remboursée';
                }
                // Une période déjà finie (achat ancien, message en retard) ne rouvre rien.
                if ($expire->isPast()) {
                    return 'expirée';
                }
                $courant = Abonnement::where('etablissement_id', $etablissement->id)->first();
                if ($courant !== null && $courant->fin === null) {
                    return 'illimité';
                }

                $fin = self::finPour($expire);
                // Une période déjà payée de la même formule n'est jamais raccourcie : un
                // renouvellement arrivé après le suivant, ou des mois payés autrement.
                if ($courant !== null && $courant->formule === $formule->code && $courant->enCours() && $courant->fin->gt($fin)) {
                    $fin = $courant->fin->copy();
                }

                // Le prix de la formule, comme toute demande : ce qu'Apple a
                // facturé (autre devise, sa commission retenue) est noté à côté.
                // Achat de test ou offert : rien d'encaissé, pas de recette.
                $tarif = $formule->tarifs()->where('mois', $mois)->first();
                $encaisse = ! $test && (! is_numeric($t['price'] ?? null) || (int) $t['price'] > 0);
                $demande = DemandeAbonnement::create([
                    'etablissement_id' => $etablissement->id,
                    'demandeur_id' => $demandeurId,
                    'formule' => $formule->code,
                    'mois' => $mois,
                    'montant' => $encaisse && $tarif !== null ? $tarif->montant : 0,
                    'devise' => $tarif !== null ? $tarif->devise : 'XOF',
                    'moyen' => self::MOYEN,
                    'reference' => $transaction,
                    'apple_transaction_id' => $transaction,
                    'statut' => 'en_attente',
                ]);
                $prix = self::prix($t);
                $note = ($test ? 'Achat de test (Sandbox) sur l’App Store' : 'Payé sur l’App Store'.($prix !== null ? " ({$prix})" : ''))
                    .", transaction {$transaction}";
                $this->abonnements->approuver($demande, null, note: $note, parNom: 'App Store', finApple: $fin, appleOriginale: $originale);

                return 'accordé';
            });
        } catch (UniqueConstraintViolationException) {
            // La même transaction enregistrée au même instant par l'autre voie.
            return 'déjà traitée';
        }

        // Pendant les essais (Sandbox), un renouvellement toutes les cinq minutes : seul l'achat est signalé.
        $renouvellement = ($t['transactionReason'] ?? null) === 'RENEWAL';
        if ($suite === 'accordé' && $demande !== null && ! ($test && $renouvellement)) {
            $demande->refresh();
            $prix = self::prix($t);
            app(AlertesPlateforme::class)->abonnementAppStore(
                $etablissement,
                $renouvellement ? 'Abonnement renouvelé sur l’App Store' : 'Abonnement payé sur l’App Store',
                $formule->nom.' · '.($mois === 12 ? '1 an' : "{$mois} mois")
                    .($prix !== null ? ' · '.$prix : '').($test ? ' · achat de test (Sandbox)' : '')
                    .' · jusqu’au '.$demande->periode_fin?->format('d/m/Y'),
            );
        }

        return $suite;
    }

    /**
     * Fin, remboursement ou retrait chez Apple : l'abonnement s'arrête (comme
     * une révocation), seulement s'il est encore porté par cet abonnement
     * Apple et qu'aucune période plus récente ne l'a prolongé.
     *
     * @param  array<string, mixed>  $t
     */
    private function arreter(array $t, string $type): string
    {
        $finTransaction = self::finPour(self::date($t['expiresDate']));
        $motif = match ($type) {
            'EXPIRED' => 'Abonnement App Store terminé (non renouvelé).',
            'GRACE_PERIOD_EXPIRED' => 'Abonnement App Store : paiement en échec, délai de grâce d’Apple écoulé.',
            'REFUND' => 'Abonnement App Store remboursé par Apple.',
            default => 'Abonnement App Store retiré par Apple (partage familial).',
        };

        $suite = 'sans effet';
        foreach (Abonnement::where('apple_transaction_originale', self::texte($t['originalTransactionId'] ?? null))->get() as $abonnement) {
            // Un établissement supprimé entre-temps : plus rien à arrêter.
            $etablissement = Etablissement::find($abonnement->etablissement_id);
            if (! $abonnement->enCours() || $abonnement->fin === null || $abonnement->fin->gt($finTransaction) || $etablissement === null) {
                continue;
            }
            $this->abonnements->revoquer($etablissement, $motif, null, 'App Store');
            $suite = 'arrêté';

            // Remboursé : la recette notée dans la console est à reprendre à la main.
            if (in_array($type, ['REFUND', 'REVOKE'], true)) {
                app(AlertesPlateforme::class)->abonnementAppStore($etablissement, 'Abonnement remboursé par Apple', $motif.' La recette est à reprendre.');
            }
        }

        return $suite;
    }

    /**
     * « 6,99 USD », « 4 000 XOF » : ce qu'Apple a facturé (prix en millièmes), s'il le dit.
     *
     * @param  array<string, mixed>  $t
     */
    private static function prix(array $t): ?string
    {
        if (! is_numeric($t['price'] ?? null) || ! is_string($t['currency'] ?? null)) {
            return null;
        }
        $montant = (int) $t['price'] / 1000;

        return number_format($montant, (int) $t['price'] % 1000 === 0 ? 0 : 2, ',', ' ').' '.$t['currency'];
    }

    /** Date Apple (millisecondes depuis 1970) à l'heure du serveur. */
    private static function date(mixed $millisecondes): Carbon
    {
        return Carbon::createFromTimestampMs(self::entier($millisecondes), config()->string('app.timezone'));
    }

    /** Un champ du JWS en texte (identifiants de transaction, types…), vide s'il manque. */
    private static function texte(mixed $valeur): string
    {
        return is_string($valeur) || is_int($valeur) ? (string) $valeur : '';
    }

    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }
}
