# Rapport de réorganisation — 30 septembre 2026

## 1. Architecture initiale

Monolithe Laravel à la racine. React était compilé par Vite à l’intérieur de Laravel et servi par une route web fourre-tout. L’API n’était pas versionnée. Un seul module métier existait : l’Expression de Besoin.

## 2. Problèmes trouvés

React et Laravel partageaient le même `package.json`. Les règles de circuit étaient dans un service, sans Policy. L’acteur de démonstration n’était pas vu par le Gate Laravel. Les classes métier étaient toutes dans `app/Models`. Le détail est dans `docs/AUDIT_ARCHITECTURE_EXISTANTE.md`.

## 3. Architecture cible mise en place

```text
frontend/     React + Vite + Tailwind + TypeScript, port 5173
backend/      Laravel 13, API /api/v1, port 8001
docs/         audit, plan, matrice, OpenAPI, maquettes
infrastructure/  Laragon, sans Docker
scripts/      dev.ps1, test.ps1
```

## 4. Fichiers déplacés

L’arbre Laravel (`app`, `bootstrap`, `config`, `database`, `public`, `routes`, `storage`, `tests`, `vendor`, `.env`, Composer) est dans `backend/`. Les classes EB, budget, PAP, organisation et engagement sont rangées dans `app/Domains`. L’interface est dans `frontend/src/features/needs`. La matrice complète est `docs/MATRICE_MIGRATION_ARCHITECTURE.md`.

## 5. Fichiers supprimés

`resources/js`, les feuilles CSS de l’UI Laravel, les vues Blade de la SPA, le `package.json` et le `vite.config.js` de la racine. Une seule interface reste active. Le PDF officiel Blade est conservé.

## 6. Services créés

`NumberingService`, `BudgetAvailabilityService`, `AuditLogger`, `NeedPolicy`. Le workflow EB les appelle. Les règles de soumission, de retour et de transformation n’ont pas été réécrites.

## 7. API

Les routes sont sous `/api/v1` (`routes/api/v1/auth.php`, `budget.php`, `needs.php`). Les erreurs de validation portent `code: VALIDATION_ERROR`. Le contrat est esquissé dans `docs/openapi/v1-expressions-besoin.yaml`.

## 8. Tests

`php artisan test` : 5 tests, 5 réussites (liste, soumission, retour, crédit, accueil). `tsc --noEmit` du frontend : succès. Navigateur : liste (9 dossiers) et fiche `EB/2026/DATI/000127` servies par Vite et proxifiées vers l’API.

## 9. Régressions corrigées

Les Policies répondaient 403 parce que le Gate ne voyait pas l’utilisateur posé seulement sur la requête. `ResolveActor` enregistre maintenant l’acteur sur le guard. Les tests repassent.

## 10. Dettes restantes

- L’authentification réelle (login Sanctum, délégation, intérim) n’existe pas. `X-Actor-Id` reste une béquille de développement, contrôlée ensuite par la Policy.
- Les écrans React typent encore largement les réponses avec `any`.
- Le tableau de bord charge tous les dossiers en mémoire.
- Les montants restent des entiers FCFA. Ce n’est pas un float ; un passage à `NUMERIC` serait une migration de schéma à part.
- Pas de moteur de workflow générique pour les modules encore absents (liquidation, ordonnancement, paiement). Le circuit EB est centralisé dans son service et ses services partagés.
- Pas de suite e2e automatisée au-delà des tests Feature et du contrôle navigateur.
- `node_modules` de l’ancienne racine peut être supprimé : les dépendances actives sont dans `frontend/node_modules`.
- Les domaines sans code n’ont pas reçu de dossiers vides.
