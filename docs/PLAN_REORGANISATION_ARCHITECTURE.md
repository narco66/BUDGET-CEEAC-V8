# Plan de réorganisation — BUDGET-CEEAC / GESBUDEP

## Architecture actuelle

Monolithe Laravel à la racine. React est une dépendance Vite de Laravel. L’API n’est pas versionnée. Le métier EB est correct et couvert par des tests Feature.

## Architecture cible

```text
BUDGET-CEEAC-V8/
├── frontend/          React 19 + Vite + Tailwind 4 + TypeScript
├── backend/           Laravel 13, API /api/v1
├── docs/              audit, plan, matrice, OpenAPI, maquettes
├── infrastructure/    note Laragon (pas de Docker imposé)
├── scripts/           dev.ps1, test.ps1
├── README.md
└── .gitignore
```

Chaîne d’exécution :

```text
React (frontend :5173)
  → /api/v1
  → Laravel (backend :8001)
  → Policies + ExpressionBesoinWorkflow + services partagés
  → PostgreSQL 18 (budget_ceeac_v8)
```

## Écarts

| Sujet | Actuel | Cible de ce lot |
|---|---|---|
| Racine | Laravel + React | Deux applications |
| UI | JSX dans `resources/js` | `frontend/src/features/needs` |
| API | `/api` | `/api/v1` |
| Domaines | `app/Models` plat | `app/Domains/{Needs,Budget,PAP,Organization,Commitments}` |
| Sécurité | Contrôles dans le service | Policy + contrôles du service conservés |
| Auth | `X-Actor-Id` | Conservé et documenté jusqu’au module Administration. `GET /api/v1/auth/me` ajouté |
| Workflow transverse | Méthodes privées du service EB | `NumberingService`, `BudgetAvailabilityService`, `AuditLogger` appelés par EB |
| GED | Upload dans le contrôleur | `AttachmentService` |
| PDF | DomPDF dans le contrôleur | `OfficialPdfService` |
| Docker / Redis | Absents | Non introduits |

Les domaines sans code (liquidations, paiements, marchés, etc.) ne reçoivent pas de dossiers vides.

## Risques

- Déplacer `vendor/`, `.env` et `storage/` casse le serveur lancé depuis l’ancienne racine.
- Un mauvais namespace casse l’autoload et les tests.
- Changer les montants entiers vers `NUMERIC` changerait les calculs déjà testés : hors de ce lot.
- Le vhost Laragon qui pointerait vers l’ancien `public/` ne verrait plus Laravel.

## Stratégie de migration

1. Figer le comportement avec les tests Feature existants.
2. Déplacer l’arbre Laravel vers `backend/` (même volume, donc renommage).
3. Reclasser les classes métier sans réécrire les règles.
4. Publier `/api/v1` et retirer le catch-all React.
5. Recréer le frontend TypeScript qui consomme uniquement `/api/v1`.
6. Relancer les tests, Pint, le backend et le frontend.
7. Supprimer l’UI React de Laravel pour qu’une seule implémentation reste active.

## Ordre des travaux

Audit → plan → déplacement racine → domaines et services partagés → API v1 → Policy → frontend → tests → nettoyage → rapport.

## Impacts

- URL de l’interface : `http://127.0.0.1:5173` (plus la SPA Laravel sur le port 8001).
- URL API : `http://127.0.0.1:8001/api/v1`.
- `GET /` sur le backend répond un JSON d’orientation, plus le HTML React.
- Commandes : `backend/` pour Composer et Artisan, `frontend/` pour npm.
- Les données PostgreSQL et les fichiers déjà stockés suivent `backend/storage` et `backend/.env`.

## Tests nécessaires

- `php artisan test` dans `backend/` (liste, soumission, retour, crédit, accueil).
- Démarrage `php artisan serve` et `npm run dev`.
- Parcours navigateur : liste, fiche, assistant.

## Retour arrière

Le déplacement est un renommage de dossiers sur le même disque. Pour revenir en arrière : redescendre le contenu de `backend/` à la racine, restaurer `routes/web.php` (catch-all SPA) et `package.json` racine, puis `npm run build`. Ne pas relancer de migration destructrice : le schéma PostgreSQL n’est pas modifié par ce lot.
