# KENEYA — Backend API

API REST pour la gestion d'établissements de santé au Mali (cabinets, pharmacies) :
patients, consultations, médicaments & stock, ventes pharmacie, paiements et
journal d'audit. Multi-établissement : chaque utilisateur ne voit que les
données de son établissement.

## Stack

- **PHP 8.4** / **Laravel 12**
- **MySQL 8.4**, **Redis** (cache, queue, session)
- Auth **JWT** (`tymon/jwt-auth`)
- Doc API auto-générée (`dedoc/scramble`)
- Analyse statique **PHPStan niveau 9** (`larastan`)
- Environnement **Docker** (image PHP pré-construite, aucun build lourd)

## Démarrage (Docker)

```bash
docker compose up -d
docker compose exec keneya php artisan migrate:fresh --seed
```

- API : http://localhost:8000
- Doc API (Scramble) : http://localhost:8000/docs/api
- Mailpit (mails de test) : http://localhost:8025

Services : `keneya` (php-fpm), `nginx`, `mysql`, `redis`, `mailpit`.

## Authentification (JWT Bearer)

L'utilisateur se connecte avec **téléphone + PIN**. La réponse renvoie un
`access_token` (JWT) à placer dans l'en-tête `Authorization: Bearer <token>`
sur les routes protégées. Durée de vie : 24 h (`JWT_TTL=1440`).

```bash
# Connexion
curl -X POST http://localhost:8000/api/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"telephone":"76000001","pin":"1234"}'

# Appel protégé
curl http://localhost:8000/api/me \
  -H 'Accept: application/json' -H 'Authorization: Bearer <access_token>'
```

Endpoints auth : `POST /api/login`, `POST /api/register`, `GET /api/me`,
`POST /api/refresh`, `POST /api/deconnexion`.

Côté client mobile (Flutter) : stocker le token (`flutter_secure_storage`) et
l'ajouter à chaque requête via un intercepteur Dio.

## Ressources métier (protégées par `auth:api`)

`patients`, `medicaments`, `consultations`, `ventes-pharmacie`,
`vente-pharmacie-articles`, `paiements`, `mouvement-stocks`, `journal-audits`
(CRUD via `apiResource`), plus la gestion des établissements et des utilisateurs.

## Synchronisation offline

L'application cliente (Flutter) fonctionne 100 % hors-ligne sur SQLite local et
se synchronise avec ce backend central au retour du réseau. Endpoints (protégés
par `auth:api`) :

- `POST /api/sync/pull` — changements serveur depuis un curseur (`last_pulled_rev`).
- `POST /api/sync/push` — envoi idempotent des changements locaux (upserts +
  suppressions), scopé à l'établissement du JWT, résolution des clés étrangères
  par `uuid` parent, recalcul du stock depuis le ledger.

Chaque ligne synchronisable porte un `uuid` (identité globale), un
`sync_revision` (séquence globale monotone) et un `deleted_at` (tombstones).
Le contrat client détaillé est dans [docs/OFFLINE_SYNC.md](docs/OFFLINE_SYNC.md).

## Géolocalisation des établissements

Les établissements portent `latitude` / `longitude` et exposent une
`itineraire_url` (Google Maps) prête à ouvrir côté client (`url_launcher`), qui
utilise automatiquement la position de l'utilisateur comme point de départ.

## Données de test (seeders)

`php artisan migrate:fresh --seed` crée 10 lignes par table.
Tous les utilisateurs ont le **PIN `1234`** (ex. admin : téléphone `76000001`).

## Qualité

```bash
docker compose exec keneya vendor/bin/phpstan analyse   # niveau 9, 0 erreur
docker compose exec keneya php artisan test
```
