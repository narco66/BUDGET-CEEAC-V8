# Matrice de migration

| Ancien chemin | Nouveau chemin | Type | Domaine | Statut | Test |
|---|---|---|---|---|---|
| `/` (racine Laravel) | `backend/` | déplacement | socle | fait | `php artisan test` |
| `resources/js` | `frontend/src` | déplacement + TypeScript | needs | fait | `tsc` et parcours navigateur |
| `resources/views/pdf/expression-besoin.blade.php` | `backend/resources/views/pdf/expression-besoin.blade.php` | conserver | needs | fait | PDF inchangé |
| `app/Models/ExpressionBesoin.php` et lignes, pièces, événements | `backend/app/Domains/Needs/Models` | déplacement | needs | fait | Feature EB |
| `app/Services/ExpressionBesoinWorkflow.php` | `backend/app/Domains/Needs/Services/ExpressionBesoinWorkflow.php` | déplacement | needs | fait | Feature EB |
| `app/Http/Controllers/Api/ExpressionBesoinController.php` | `backend/app/Domains/Needs/Http/Controllers` | déplacement | needs | fait | Feature EB |
| `app/Models/BudgetLine.php`, `Exercice.php` | `backend/app/Domains/Budget` | déplacement | budget | fait | Feature EB |
| `app/Models/PapEnrichment.php`, `PapTask.php` | `backend/app/Domains/PAP` | déplacement | pap | fait | Feature EB |
| `app/Models/OrganizationUnit.php` | `backend/app/Domains/Organization` | déplacement | organisation | fait | Feature EB |
| `app/Models/Engagement.php` | `backend/app/Domains/Commitments` | déplacement | commitments | fait | Feature EB |
| `app/Models/User.php` | `backend/app/Models/User.php` | conserver | auth | fait | Feature EB |
| `app/Http/Middleware/ResolveActor.php` | `backend/app/Shared/Auth/ResolveActor.php` | déplacement | auth | fait | en-tête X-Actor-Id |
| numérotation dans le workflow | `backend/app/Shared/Support/NumberingService.php` | extraction | shared | fait | création EB |
| contrôle de crédit | `backend/app/Shared/Support/BudgetAvailabilityService.php` | extraction | shared | fait | test crédit |
| journal `eb_events` | `backend/app/Shared/Audit/AuditLogger.php` | extraction | audit | fait | test retour |
| — | `backend/app/Domains/Needs/Policies/NeedPolicy.php` | création | needs | fait | 403 si acteur non habilité |
| `routes/api.php` `/api` | `backend/routes/api/v1` `/api/v1` | refactor | api | fait | tests mis à jour |
| `routes/web.php` SPA | JSON d’orientation | suppression UI | socle | fait | ExampleTest |
| `resources/js`, `package.json` racine, Blade SPA | supprimés | suppression | frontend | fait | une seule UI dans `frontend/` |
