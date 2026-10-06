# Infrastructure locale

Le développement courant est Windows + Laragon :

- PHP 8.3 livré par Laragon
- PostgreSQL 18 sur le port 5433
- `php artisan serve` pour l’API (port 8001)
- `npm run dev` pour React (port 5173)

Docker, Nginx et Redis ne sont pas installés et ne sont pas nécessaires pour ce lot. Les introduire seulement au moment du déploiement, sans changer le contrat `/api/v1`.

Si un vhost Laragon pointe encore vers l’ancien dossier `public/` à la racine du dépôt, le déplacer vers `backend/public`. L’interface ne passe plus par ce dossier : elle est servie par Vite.
