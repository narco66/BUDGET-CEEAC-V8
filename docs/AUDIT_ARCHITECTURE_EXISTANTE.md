# Audit de l’architecture existante — BUDGET-CEEAC / GESBUDEP

Date : 30 septembre 2026. Périmètre : dépôt `BUDGET-CEEAC-V8` avant séparation frontend / backend. Aucun fichier métier n’a été déplacé pour produire cet audit.

## 4.1 Architecture actuelle

### Arborescence

Monolithe Laravel 13 à la racine du dépôt. React 19 est compilé par Vite **dans** Laravel (`resources/js`, `vite.config.js`, plugin `laravel-vite-plugin`) et servi par une route web fourre-tout.

```text
BUDGET-CEEAC-V8/
├── app/                  PHP métier (28 fichiers applicatifs)
├── bootstrap/
├── config/
├── database/migrations, seeders
├── docs/maquette-EB/     maquettes HTML du module EB
├── public/               build Vite + index.php Laravel
├── resources/js, css, views
├── routes/web.php, api.php
├── storage/              pièces EB hors du web public
├── tests/Feature
├── vendor/, node_modules/
├── composer.json, package.json, vite.config.js, .env
```

Il n’existe pas de dossiers `frontend/`, `backend/`, `infrastructure/` ni `scripts/`.

### Frontend

- Point d’entrée : `resources/js/app.jsx` monté par `resources/views/app.blade.php` via `@vite`.
- Écrans : `Shell`, `EbList`, `EbWizard`, `EbFiche`, `ReturnModal`, `StatusPill`.
- Client HTTP : `resources/js/api.js` (Axios, en-tête `X-Actor-Id`).
- Styles : Tailwind 4 (`resources/css/app.css`) + feuille métier `resources/css/eb.css`.
- Pas de TypeScript, pas de feature folders, pas de routeur lazy, pas de pages 403/404 dédiées.
- Doublon Windows : `resources/js/App.jsx` et `resources/js/app.jsx` désignent le même fichier (système de fichiers insensible à la casse). Le composant réel est `resources/js/components/App.jsx`.

### Backend

- Laravel 13.17, PHP 8.3, Sanctum 4, DomPDF, Maatwebsite Excel.
- Un seul module métier livré : Expression de Besoin.
- Contrôleurs : `ExpressionBesoinController` (278 lignes), `BudgetLineController`, `ActorController`.
- Service : `ExpressionBesoinWorkflow` (506 lignes) — numérotation, crédit, circuit, retour, rejet, approbation, engagement.
- Form Requests présentes pour création, mise à jour et retour.
- Pas de Policy, pas d’Action, pas de DTO, pas d’événements métier.

### Routes et API

Préfixe Laravel `/api`, **sans version**.

| Méthode | Chemin |
|---|---|
| GET/POST | `/api/acteurs`, `/api/acteurs/courant` |
| GET/POST | `/api/lignes-budgetaires`, `.../taches` |
| CRUD + transitions | `/api/expressions-besoin` (soumettre, valider, retourner, rejeter, approuver, transformer, annuler, dupliquer, documents, pdf, export) |

`routes/web.php` sert l’interface React pour toute URL qui ne commence pas par `api`.

### Modèles

`User`, `Exercice`, `OrganizationUnit`, `BudgetLine`, `PapEnrichment`, `PapTask`, `ExpressionBesoin`, `EbLine`, `EbImputation`, `EbDocument`, `EbEvent`, `Engagement`.

Relations Eloquent classiques. Le calcul de disponible (`BudgetLine::disponible`) et la complétude PAP (`PapEnrichment::completeness`) sont sur les modèles.

### Accès base de données

- PostgreSQL 18, port 5433, base `budget_ceeac_v8` (la base historique `budget_ceeac` n’est pas utilisée).
- Eloquent uniquement. Aucun accès PostgreSQL depuis React.
- Montants en entiers FCFA (`unsignedBigInteger`), pas en float.
- Tests PHPUnit sur SQLite mémoire (`phpunit.xml`).
- Migrations : users, cache, jobs, notifications, personal_access_tokens, module EB, profil acteur. Pas de migration contradictoire.

### Stockage documentaire

Upload dans le disque local privé `storage/app/private/eb-documents/{id}` avec empreinte SHA-256. Les pièces ne sont pas dans `public/`. Pas de service GED partagé : l’upload est codé dans le contrôleur EB.

### Configuration

`.env` à la racine (secret base uniquement là). `APP_URL=http://127.0.0.1:8001` parce que le port 8000 est occupé par une autre version. Sanctum stateful inclut `localhost:5173`. Pas de `config/cors.php` publié (défaut framework). Pas de Redis imposé. Pas de Docker.

### Tests

- `tests/Feature/ExpressionBesoinTest.php` : liste, soumission incomplète, retour avec motif, blocage crédit.
- `tests/Feature/ExampleTest.php` : `GET /` répond 200 (la coquille SPA).
- Pas de tests frontend, pas de tests de sécurité dédiés, pas d’OpenAPI.

### Fonctionnalités recensées

Expression de Besoin uniquement : liste et indicateurs, assistant 9 étapes, fiche, circuit PAP / Hors PAP, pièces, PDF après approbation, export Excel, notifications base de données, changement d’acteur de démonstration. Les autres modules de la chaîne (engagement réel au-delà de la transformation, liquidation, ordonnancement, paiement, marchés, GED transverse) ne sont pas implémentés.

## 4.2 Anomalies détectées

| Anomalie | Constat | Décision |
|---|---|---|
| React mélangé à Laravel | Vite, `package.json` et Blade servent l’UI | Séparer `frontend/` et `backend/` |
| Routes non versionnées | `/api/...` | Passer à `/api/v1` |
| Contrôleur chargé | Liste, tableau de bord, upload, PDF et export dans `ExpressionBesoinController` | Extraire tableau de bord et pièces |
| Logique métier dans le contrôleur | Filtrage, crédit indirect, génération PDF | Déléguer au service déjà existant et à des actions ciblées |
| Logique métier dans React | Affichage et formatage seulement. Le seuil de crédit et le circuit sont côté Laravel | Conserver. Ne pas y ajouter de règle financière |
| Autorisation uniquement partielle | `X-Actor-Id` choisit l’utilisateur sans authentification. Les contrôles de circuit sont dans le service, pas dans une Policy | Policy EB + conserver le résolveur d’acteur comme béquille de développement, documentée |
| Pas de moteur transverse | Workflow, numérotation, audit et notification sont internes à EB | Extraire des services partagés appelés par EB, sans dupliquer le circuit |
| Modèles au même niveau | Budget, PAP, organisation et EB dans `app/Models` | Ranger par domaine, garder `User` sur `App\Models\User` |
| Code mort / doublon | Collision `App.jsx` / `app.jsx` | Une seule entrée frontend |
| Constantes d’interface | Motifs de retour et couleurs de statut dans React | Acceptable pour l’affichage ; les statuts opposables restent les enums PHP |
| API hétérogène | Réponses Resource Laravel (`data`, `meta`) plus `tableau_de_bord` | Conserver ce contrat et ajouter `code` sur les erreurs de validation |
| Requêtes | Le tableau de bord charge tous les dossiers en mémoire | Acceptable sur le jeu de démonstration ; dette notée |
| Dépendance circulaire | Aucune détectée | — |
| Accès SQL hors backend | Aucun | — |
| Montants en float | Aucun. Entiers FCFA | Conserver (plus sûr qu’un float). `NUMERIC` reste une évolution de schéma, pas un correctif urgent |
| Deux implémentations | Une seule chaîne EB | Ne pas en créer une seconde |

## Couplages à rompre

1. `@vite` dans Blade et le catch-all web.
2. `laravel-vite-plugin` et le `package.json` racine.
3. Le frontend qui appelle `/api` non versionné.
4. Les namespaces plats `App\Models` / `App\Http\Controllers\Api` pour le métier EB.

## Ce qui est déjà correct et doit être conservé

- Règles de soumission (objet, justification, sous-lignes, pièces, égalité des imputations, crédit, activité PAP, exercice ouvert).
- Numérotation `EB/{année}/{sigle}/{séquence}`.
- Circuit PAP technique versus support / Hors PAP.
- Instantané de version lors d’un retour.
- Transformation en engagement à l’approbation.
- Pièces hors de `public/`.
- Base `budget_ceeac_v8` et l’historique des migrations.
- Jeu de tests Feature existant.
