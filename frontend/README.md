# Frontend BUDGET-CEEAC

React 19, Vite, Tailwind CSS 4, TypeScript. Aucune règle financière n’est décidée ici : les montants, le circuit et les droits viennent de `/api/v1`.

## Installation

```powershell
npm install
```

## Commandes

```powershell
npm run dev
npm run build
```

Le serveur de développement écoute le port 5173 et proxifie `/api` vers `http://127.0.0.1:8001`.

## Variables

`frontend/.env`

```text
VITE_API_URL=/api/v1
```

En production, `VITE_API_URL` pointe vers l’origine de l’API. Ne jamais y placer de secret serveur.

## Structure

```text
src/app/router          routes
src/api/httpClient.ts   client HTTP unique
src/components          layout et badges réutilisables
src/features/needs      module Expression de Besoin
src/styles              design system (couleurs EB)
src/utils               formatage d’affichage
```

L’en-tête `X-Actor-Id` est posé par le client à partir du sélecteur d’acteur. Ce n’est pas l’autorité de sécurité : Laravel revérifie chaque action.
