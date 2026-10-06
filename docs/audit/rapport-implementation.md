# Rapport — journalisation, traçabilité et horodatage

Date : 5 octobre 2026. Base `budget_ceeac_v8`. Aucune réinitialisation. Les événements déjà présents n’ont pas été modifiés.

## Opérationnel

- `AuditService` enregistre l’acteur, son nom et son rôle au moment de l’action, l’instant serveur en UTC, le résultat, la corrélation de requête, et les différences avant/après sans mot de passe ni jeton.
- Les écritures existantes de l’administration, de la chaîne financière et de l’authentification passent par ce service.
- Une réussite annulée par la transaction disparaît avec elle. Un refus écrit ensuite reste.
- Le journal refuse la modification applicative et la suppression PostgreSQL. `audit:purger` ne supprime rien.
- Un gel se pose par l’administrateur des habilitations et s’ajoute au journal.
- Reprise des historiques de chaîne : **186** événements, rôle non inventé. Seconde exécution : **0**.
- Écran du journal : filtres serveur, fiche, export CSV réservé à l’auditeur et à l’administrateur des habilitations.
- Chronologie sur les fiches EB, ENG, LIQ, ORD et PAY, limitée aux dossiers que l’utilisateur peut voir.

## Tests

`php artisan test --compact tests/Feature/AuditTraceTest.php` : 2 tests, 19 assertions, réussis.

La suite complète n’a pas été relancée.

## Arbitrages

- Pas de nouvelles permissions `audit.*` dans le catalogue déjà figé.
- Pas de durée de conservation choisie dans le code.
- Pas de chaînage cryptographique : il ne tiendrait pas face à un administrateur de base.
- Les modules qui n’écrivaient pas dans `audit_events` (GAR, recettes, S&E, notifications) ne reçoivent pas un second historique inventé.

## Limites

L’écran ne filtre pas encore par période ni par unité. L’export est CSV, pas Excel ni PDF. Le périmètre organisationnel ne restreint pas le journal global. Les actes PDF déjà émis avant la GED ne sont pas reliés rétroactivement à ces événements.
