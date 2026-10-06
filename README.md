# BUDGET-CEEAC / GESBUDEP

Application budgétaire de la CEEAC. Le frontend et le backend sont deux applications distinctes.

```text
Utilisateur → React (frontend, port 5173)
           → API REST /api/v1 (backend Laravel, port 8001)
           → PostgreSQL 18 (budget_ceeac_v8)
```

La documentation fonctionnelle est dans `docs/`.

## Installation

Prérequis : PHP 8.3, Composer, Node.js 22, PostgreSQL 18.

```powershell
cd backend
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate

cd ..\frontend
npm install
```

Renseigner `backend/.env` (`DB_*`, ne pas committer le mot de passe). Le frontend n’a aucun secret de base.

## Lancement

Deux terminaux, ou `scripts/dev.ps1`.

```powershell
cd backend
php artisan serve --host=127.0.0.1 --port=8001

cd frontend
npm run dev
```

- Interface : http://127.0.0.1:5173/connexion (comptes de démonstration : mot de passe `password`)
- API : http://127.0.0.1:8001/api/v1
- Santé backend : http://127.0.0.1:8001/up

Le port 8000 peut être occupé par une autre copie du projet. Ne pas l’arrêter.

## Tests

```powershell
cd backend
php artisan test --compact
```

## Build

```powershell
cd frontend
npm run build
```

## Environnements

`APP_ENV` côté Laravel : `local`, `testing` (PHPUnit force SQLite mémoire), puis `staging` et `production` au déploiement. Les origines CORS se règlent avec `CORS_ALLOWED_ORIGINS`. Ne pas utiliser `*` avec les cookies.

Laragon sert aujourd’hui le développement local. Docker n’est pas requis : voir `infrastructure/README.md`.
