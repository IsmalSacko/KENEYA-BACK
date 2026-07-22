# KENEYA — Synchronisation offline (contrat client Flutter)

Ce document décrit **comment le client Flutter** (mobile / desktop / tablette)
doit fonctionner hors-ligne et se synchroniser avec le backend central. Le
backend n'exécute jamais de logique offline : il expose des endpoints de
synchronisation que le client appelle **quand le réseau est disponible**.

> Contexte : au Mali, une clinique peut rester 2–3 jours sans électricité donc
> sans internet. L'application doit rester 100 % fonctionnelle (inscription,
> auth, consultation, ventes, stock…) puis rattraper la synchro au retour du
> réseau, automatiquement **et** manuellement.

---

## 1. Principe général

```
┌──────────────────────────┐         ┌────────────────────────────┐
│  Client Flutter          │         │  Backend central (ce repo) │
│  (téléphone/desktop/tab.) │         │  Laravel + MySQL           │
│                          │         │                            │
│  Lecture/écriture  ───►  SQLite    │   /api/sync/pull           │
│  TOUJOURS en local       │  local  │   /api/sync/push           │
│                          │         │                            │
│  Moniteur réseau ────────┼──sync──►│   (JWT Bearer requis)      │
└──────────────────────────┘         └────────────────────────────┘
```

- **Toutes les opérations UI lisent et écrivent dans SQLite local**, en ligne
  comme hors-ligne. L'app ne dépend jamais du réseau pour fonctionner.
- Un **moniteur de connectivité** déclenche la synchronisation dès que le réseau
  revient (bascule automatique), et un bouton « Synchroniser » permet la bascule
  manuelle.
- L'identité de chaque enregistrement est un **uuid généré par le client** (v4).
  Le serveur conserve son `id` interne mais l'expose toujours via `uuid`.

---

## 2. Schéma SQLite local conseillé

Reproduire les tables métier avec, sur **chaque** table, ces colonnes de synchro :

| Colonne         | Rôle                                                              |
|-----------------|-------------------------------------------------------------------|
| `uuid`          | identité globale (clé primaire logique côté client), UUID v4      |
| `sync_revision` | dernière révision serveur connue pour cette ligne (0 si locale)   |
| `_status`       | `created` \| `updated` \| `deleted` \| `synced`                   |
| `updated_at`    | horodatage local (indicatif)                                      |

Les **clés étrangères sont stockées en `*_uuid`** (jamais l'`id` serveur) :
`patient_uuid`, `medecin_uuid`, `medicament_uuid`, `vente_pharmacie_uuid`, etc.
Références polymorphes : `paiements(source_type, source_uuid)`,
`journal_audits(type_cible, cible_uuid)`.

Stocker aussi un curseur global : `last_pulled_rev` (entier, 0 au départ).

Tables synchronisées : `etablissements`, `users`, `patients`, `medicaments`,
`consultations`, `ventes_pharmacie`, `vente_pharmacie_articles`,
`mouvement_stocks`, `paiements`, `journal_audits`.

---

## 3. Moniteur réseau & bascule

- Utiliser `connectivity_plus` pour détecter les changements d'interface.
- Ne pas se fier au seul « wifi connecté » : confirmer un accès réel par un ping
  léger authentifié (`GET /api/me`, court timeout). Vrai accès → déclencher la
  synchro.
- **Auto** : à chaque transition hors-ligne → en-ligne, lancer `SyncService.run()`.
- **Manuel** : bouton « Synchroniser » qui appelle le même `SyncService.run()`.
- Sérialiser les synchros (un seul run à la fois) et réessayer avec back-off en
  cas d'échec réseau.

---

## 4. Boucle de synchronisation

Ordre recommandé : **push puis pull**, le curseur n'avançant qu'après le pull.

```
SyncService.run():
  1. PUSH : envoyer tous les enregistrements locaux dont _status != 'synced'
  2. Traiter la réponse (applied / rejected) — voir §6
  3. PULL : récupérer les changements serveur depuis last_pulled_rev
  4. Appliquer les upserts/suppressions en local (upsert par uuid)
  5. Enregistrer server_rev -> last_pulled_rev
  6. Marquer 'synced' les lignes acceptées
```

> Important : **ne pas** avancer `last_pulled_rev` avec le `server_rev` de la
> réponse du *push*. Le pull qui suit (depuis l'ancien curseur) renverra vos
> propres lignes (upsert local idempotent) **et** les changements dérivés côté
> serveur (ex. `medicaments.stock` recalculé). C'est voulu.

---

## 5. Endpoints

Toutes les routes sont sous `Authorization: Bearer <JWT>` (sauf login/register).
Les FK sont **toujours** en `*_uuid`. L'`etablissement_id` envoyé par le client
est ignoré : le serveur force l'établissement du JWT.

### `POST /api/sync/pull`

Requête :
```json
{ "last_pulled_rev": 0, "tables": ["patients", "medicaments"] }
```
- `last_pulled_rev` : 0 pour la première synchro (renvoie tout le vivant).
- `tables` : optionnel (pull partiel/reprise) ; absent = toutes les tables.

Réponse :
```json
{
  "server_rev": 42,
  "changes": {
    "patients": {
      "upserted": [
        { "uuid": "…", "nom": "Awa", "telephone": "70…", "adresse": "Bamako",
          "sync_revision": 41, "created_at": "…", "updated_at": "…" }
      ],
      "deleted": ["uuid-supprimé"]
    },
    "consultations": {
      "upserted": [
        { "uuid": "…", "patient_uuid": "…", "medecin_uuid": "…",
          "motif": "Fièvre", "montant": "5000.00", "statut": "en_attente", … }
      ],
      "deleted": []
    }
  }
}
```

### `POST /api/sync/push`

Requête :
```json
{
  "last_pulled_rev": 42,
  "changes": {
    "patients": {
      "upserted": [ { "uuid": "…", "nom": "Awa" } ],
      "deleted": ["uuid-x"]
    },
    "mouvement_stocks": {
      "upserted": [
        { "uuid": "…", "medicament_uuid": "…", "type": "sortie", "quantite": 3 }
      ]
    }
  }
}
```

Réponse :
```json
{
  "accepted": true,
  "server_rev": 60,
  "applied": { "patients": 1, "mouvement_stocks": 1 },
  "rejected": []
}
```

---

## 6. Gestion des rejets (`rejected`)

Le push est **idempotent** et applique tout ce qui est valide ; les
enregistrements invalides sont rejetés **sans** faire échouer le batch. Chaque
rejet : `{ table, uuid, reason, detail }`.

| `reason`           | Signification                                        | Action client                              |
|--------------------|------------------------------------------------------|--------------------------------------------|
| `missing_parent`   | uuid parent inconnu (pas encore synchronisé)         | garder `_status`, renvoyer au prochain run |
| `scope_violation`  | uuid appartenant à un autre établissement            | ne jamais renvoyer, journaliser            |
| `duplicate_unique` | contrainte d'unicité (ex. téléphone user)            | résolution manuelle (renommer/fusionner)   |
| `validation_failed`| structure invalide (uuid manquant…)                  | corriger localement                        |

Comme tout est idempotent (clé `uuid`), **rejouer** un enregistrement est sûr.

---

## 7. Règles métier importantes

- **Stock = ledger.** Ne jamais pousser `medicaments.stock` comme valeur absolue
  (champ en lecture seule côté sync). Pour toute variation de stock, pousser un
  `mouvement_stocks` (`entree` / `sortie` / `ajustement` avec quantité signée).
  Une vente pharmacie doit générer un `mouvement_stocks` de type `sortie`. Le
  serveur recalcule `stock` et le renvoie au pull. Deux ventes hors-ligne
  concurrentes s'additionnent (aucune perte).
- **Ordre implicite.** Le serveur applique les tables dans l'ordre
  parents → enfants. Toujours inclure le parent dans le même batch que l'enfant
  (ou l'avoir déjà synchronisé), sinon `missing_parent`.
- **users en priorité.** Un `medecin_uuid` / `user_uuid` référencé doit exister ;
  synchroniser les utilisateurs avant les consultations/audits.

---

## 8. Authentification hors-ligne

- **Login hors-ligne** : au premier login *en ligne* réussi, mettre en cache
  localement un hash du PIN (ex. via `flutter_secure_storage`). Hors-ligne, on
  valide le PIN saisi contre ce hash. Le backend n'expose jamais le hash du PIN.
- **Multi-établissement** : `POST /api/login` accepte un `etablissement_uuid`
  optionnel. Si le même téléphone existe dans plusieurs établissements, le
  backend répond **409** `{ code: "etablissement_requis", etablissements: [...] }` ;
  l'app renvoie alors le login avec l'`etablissement_uuid` choisi (elle connaît
  le sien après le premier login/inscription).
- **Inscription hors-ligne** : créer localement l'établissement + l'admin avec
  leurs `uuid`. À la reconnexion, appeler `POST /api/register` en fournissant
  `uuid` (établissement) et `user_uuid`. L'appel est **idempotent** : rejoué, il
  renvoie le compte existant + un token, sans doublon. Les utilisateurs créés
  hors-ligne dans un établissement **déjà existant** passent, eux, par le push
  normal de la table `users`.

---

## 9. Géolocalisation & itinéraire

- Saisie de la position d'un établissement : GPS de l'appareil (`geolocator`),
  puis `PATCH /api/etablissements/{id}` avec `latitude` / `longitude` (ou via le
  push de la table `etablissements`).
- Bouton **« Itinéraire »** : le backend renvoie `itineraire_url` sur
  l'établissement (`GET /api/etablissements/{id}/show`), de la forme
  `https://www.google.com/maps/dir/?api=1&destination=<lat>,<lng>`. L'ouvrir avec
  `url_launcher` : Google Maps utilise **automatiquement la position actuelle**
  de l'utilisateur comme point de départ.
