# Backend BUDGET-CEEAC

Laravel 13, PHP 8.3, PostgreSQL 18, Sanctum. API versionnée `/api/v1`.

## Installation

```powershell
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
```

La base de développement est `budget_ceeac_v8` sur le port 5433. Ne pas modifier la base historique `budget_ceeac`.

## Lancement

```powershell
php artisan serve --host=127.0.0.1 --port=8001
```

Les files et le planificateur ne sont pas requis pour l’Expression de Besoin : les notifications sont enregistrées en base dans la même transaction.

## Tests

```powershell
php artisan test --compact
vendor\bin\pint --format agent app bootstrap routes tests
```

PHPUnit utilise SQLite en mémoire. Il ne touche pas PostgreSQL.

## Structure

```text
app/Domains/Needs            expression de besoin
app/Domains/Budget           exercice et lignes
app/Domains/PAP              référentiel programmatique
app/Domains/Organization     structures
app/Domains/Commitments      engagement issu d’une EB
app/Shared/Auth              résolution d’acteur de développement
app/Shared/Support           numérotation et crédit
app/Shared/Audit             journal des dossiers
routes/api/v1                contrat HTTP
```

Les pièces sont stockées sur le disque local privé (`storage/app/private`), jamais dans `public/`.
