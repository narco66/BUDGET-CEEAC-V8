# Audit et refonte de l’interface — BUDGET-CEEAC / GESBUDEP

Date : 03/10/2026 · Périmètre : `frontend/` (React 19, Vite 8, Tailwind 4, TypeScript 5.9)

La refonte porte sur l’interface et l’expérience utilisateur. Elle ne modifie ni le backend, ni les API, ni les migrations, ni les règles métier, ni les permissions. Tous les appels d’API, charges utiles et conditions d’affichage des actions (`actions.*`, `droits.*`) sont conservés à l’identique.

---

## Synthèse chiffrée

| Indicateur | Avant | Après |
|---|---:|---:|
| Styles inline (`style={{…}}`) | 1 112 | 610 |
| Couleurs hexadécimales codées en dur dans les composants | 602 | 67 |
| Tableaux cassés par la classe Tailwind `grid` | 16 | 0 |
| `window.prompt()` natifs | 7 appels (8 occurrences) | 0 |
| Symboles Unicode utilisés comme icônes (`← ● ○ ✓ ✔ ✖ ⚠ ↗`) | 20 | 0 |
| Usages d’icônes Font Awesome | 0 | 570 |
| Composants réutilisables du Design System | 1 (`StatusBadge`) | 23 |
| Bundle JavaScript initial | 684 kB (unique) | 533 kB + 1 chunk par écran (chargement différé) |

---

## 1. Pages auditées (36 routes)

| Domaine | Écrans |
|---|---|
| Accès | Connexion |
| Espace de travail | Mes tâches, fiche tâche |
| Chaîne de dépense | Tableau de chaîne · Expressions de besoin (liste, fiche, assistant de création et de modification) · Engagements (liste, fiche) · Liquidations (liste, fiche en 8 étapes) · Ordonnancements (liste, fiche, délégations et seuil) · Paiements (liste, fiche, lots) · Rapprochements |
| Budget et référentiels | Lignes budgétaires et mouvements · Tiers et comptes bancaires |
| Projets et performance | Planification GAR · Suivi-évaluation : tableau de bord, fiche activité 360°, Gantt, saisie et validation, saisie d’indicateur, écarts (liste et dossier), suivi des actions, rapports, synthèse exécutive, référentiels |
| Gouvernance | Vérification de document · Administration (accueil, utilisateurs, habilitations, paramétrage) |
| Système | Page introuvable |

## 2. Composants audités

- Layout : `AppShell` (sidebar, topbar), `AppRouter`.
- Composants partagés : `StatusBadge`, `ReturnModal`, bibliothèque S&E `monitoring/components/se.tsx` (icônes SVG maison, Pill, Card, StatTile, Pager, FilterSelect, Breadcrumb, BarRow, MiniBar, Donut, GapBox), `IndicatorEvolutionChart`.
- Composants dupliqués localement : `Row` (4 pages), `Info` (2), `Mini` (3), `Kpi` (2), `FilterCard` (2), `Modal` (2 + 4 modales ad hoc), `Field` (2), `Timeline`/`Historique` (3), `Chain` + frise EB→PAY recopiée dans 8 pages, `Meter`, `Bar`, `Stat`, `Completeness`.
- Feuille de style unique `eb.css` (114 lignes, sans jetons).

## 3. Problèmes détectés

**Design System**
- Aucun jeton : couleurs, espacements, rayons et tailles répétés en valeurs littérales (64 teintes distinctes).
- Tailwind n’était utilisé que pour sa remise à zéro (preflight) : titres `h2`, listes et contrôles natifs s’affichaient sans aucun style.
- La classe `btn-g` (bouton fantôme) était utilisée dans 6 écrans mais n’était définie nulle part.

**Bugs de rendu**
- 16 tableaux en `className="grid"` recevaient `display: grid` de Tailwind : en-têtes et cellules désalignés (Mes tâches, Paiements, Rapprochements, Lignes budgétaires, Utilisateurs, Habilitations, Paramétrage, Délégations, fiche Ordonnancement…).
- La modale de visa de liquidation n’avait pas de `z-index`.
- Fiche Ordonnancement, onglet « Bénéficiaire » : banque et compte affichés « Non renseignée » en dur, alors que l’onglet « Décision » affichait les vraies valeurs.

**Formulaires**
- Une vingtaine de formulaires en `<input>` / `<select>` bruts, sans libellé (placeholder seul) : création d’utilisateur, délégations, lots, mouvements budgétaires, filtres des tâches et des ordonnancements, référentiels, paramétrage, planification.
- Aucune indication des champs obligatoires, aucun message d’erreur rattaché à un champ.
- Motifs de rejet, de retour ou d’ajournement saisis via `window.prompt()` (7 appels), ou via un champ « Motif » partagé par plusieurs actions.

**Tableaux et listes**
- Pas d’état de chargement : une page de détail affichait simplement « Chargement… », et les listes paraissaient vides.
- États vides absents ou réduits à du texte brut ; pas d’état d’erreur.
- Cinq implémentations de pagination différentes.
- Accumulation de boutons colorés dans les lignes (utilisateurs, comptes bancaires, saisies S&E, rapports).

**Modales**
- 6 implémentations différentes, aucune fermeture par Échap, aucune gestion du focus.
- Fiche Paiement : panneau d’action « pseudo-modal » affiché en bas de page.

**Navigation**
- Sidebar sans icônes ni groupes repliables. Deux entrées non cliquables (« Dépenses », « Suivi-Évaluation ») ressemblaient à des liens.
- **Aucune navigation sous 1 100 px** : la sidebar était masquée sans alternative.
- Notifications réduites à un compteur textuel, alors que l’API de session renvoie déjà les 8 dernières notifications.
- Fil d’Ariane figé sur « Chaîne de dépense » quel que soit le module.

**Accessibilité**
- Pas de focus visible cohérent, onglets sans rôle `tablist`, statuts parfois portés par la seule couleur (pastilles `●` / `○`).

## 4. Composants créés ou refactorisés

Bibliothèque `frontend/src/components/ui/` (exportée par `index.ts`) :

| Composant | Rôle |
|---|---|
| `Button` | Variantes primaire, marque, secondaire, succès, danger, danger contour, avertissement, neutre, fantôme, lien ; tailles sm/md/lg ; icône seule (libellé accessible) ; état chargement ; rendu en `<button>`, `<Link>` ou `<a>`. |
| `PageHeader`, `BackLink` | En-tête de page : retour, sur-titre, titre, sous-titre, méta, chiffre clé, actions. |
| `SectionCard` | Carte de section : icône, titre, étiquette, actions, pied, tons. |
| `StatCard`, `StatStrip`, `StripCell` | Indicateurs : valeur, unité, aide, icône, ton, carte sombre, état actif, filtre cliquable. |
| `StatusBadge`, `Badge`, `NatureBadge` + `status.ts` | Statuts : couleur + libellé + icône (jamais la couleur seule). |
| `DataTable`, `OpenCell` | Tableau : colonnes typées, alignements, survol, ligne cliquable au clavier, squelette, état vide, état erreur. |
| `Pagination` | Pagination serveur numérotée avec résumé. |
| `FormField`, `SearchInput`, `InputGroup`, `AmountInput`, `FileDrop`, `CheckCard` | Formulaires : libellé, obligatoire, aide, erreur reliés au contrôle (`aria-describedby`, `aria-invalid`), unités, dépôt de fichier. |
| `FilterBar`, `FilterSelect` | Bandeau de recherche et de filtres, réinitialisation. |
| `Tabs`, `Segmented` | Onglets accessibles (flèches, Début, Fin), compteurs ; contrôle segmenté. |
| `Modal`, `Drawer` | Boîte de dialogue et panneau latéral : focus piégé, Échap, retour du focus, verrouillage du défilement. |
| `DialogProvider` / `useDialogs()` | `confirm()` et `prompt()` asynchrones, avec champs typés et obligatoires. Ils remplacent `window.prompt`. |
| `ToastProvider` / `useToast()` | Retours éphémères (succès, erreur, information). |
| `ActionMenu` | Menu « trois points » accessible (flèches, Échap, clic extérieur). |
| `Alert`, `ErrorMessage`, `EmptyState`, `Skeleton`, `TableSkeleton`, `PageSkeleton`, `PageError`, `Spinner` | États vides, de chargement et d’erreur. |
| `KeyValueList`, `InfoGrid` | Paires libellé / valeur, bandeau d’informations clés. |
| `WorkflowTimeline` | Chronologie de dossier (action, acteur, date, motif) avec icône déduite de l’action. |
| `Stepper` | Étapes d’assistant et circuits de validation. |
| `ChainTrail` | Frise EB → ENG → LIQ → ORD → PAY (navigation entre modules ou références du dossier). |
| `Checklist`, `ChecklistSummary` | Contrôles conformes / à revoir / non bloquants. |
| `ProgressBar`, `Meter`, `StackedBar` | Jauges et répartitions. |
| `DocumentList` | Pièces présentes ou manquantes. |
| `icons.ts` (`ICON`) | Référentiel sémantique des icônes. |

Utilitaires : `utils/useResource.ts` (données, chargement, erreur, relance, sans réponse obsolète) et `utils/useDebouncedValue.ts` (recherche différée de 250 à 300 ms).

Refactorisés : `AppShell`, `AppRouter` (chargement différé par écran, page introuvable), `ReturnModal`, `se.tsx` (même API publique, désormais adossé au Design System), `IndicatorEvolutionChart`, et les 36 écrans.

## 5. Icônes remplacées

- Font Awesome 7 (`@fortawesome/fontawesome-svg-core`, `free-solid-svg-icons`, `free-regular-svg-icons`, `react-fontawesome` 3.5), import nommé avec élagage à la compilation (≈ 42 icônes dans le chunk principal).
- Référentiel `ICON` : une fonction métier = une icône (création `faPlus`, modification `faPenToSquare`, consultation `faEye`, validation `faCircleCheck`, rejet `faCircleXmark`, retour `faRotateLeft`, soumission `faPaperPlane`, signature `faSignature`, visa `faStamp`, export `faFileExport`, PDF `faFilePdf`, pièce jointe `faPaperclip`, historique `faClockRotateLeft`, budget `faCoins`, paiement `faMoneyBillTransfer`, rapprochement `faScaleBalanced`, planification `faDiagramProject`, suivi-évaluation `faChartColumn`, Gantt `faBarsStaggered`, écarts `faTriangleExclamation`, sécurité `faShieldHalved`, rôles `faUserShield`, notifications `faBell`…).
- Les ~25 SVG maison de `se.tsx` sont remplacés par leurs équivalents Font Awesome, sans changer l’API `<Icon name="…">`.
- Remplacements Unicode : `←` → `faArrowLeft` (`BackLink`) ; `●` / `○` des contrôles → `Checklist` (cercle coché, cercle barré, triangle) ; `✓` de l’assistant de liquidation → `faCheck` ; `✔ ↗ ⚠ ✖` du graphique d’indicateur → `PerformancePill` ; flèches de saisie → `faArrowRight`.

## 6. Améliorations UI

- Identité institutionnelle : marine `#0B1C3E` (structure), vert `#1A6B3A` (action), or `#C9A227` (accent, usage parcimonieux). Titres en DM Serif Display, interface en Inter, montants et références en JetBrains Mono à chiffres tabulaires.
- Sidebar en dégradé marine, filet doré sur l’élément actif, compteur de tâches en pastille dorée.
- Cartes homogènes (rayon 12, ombre discrète), KPI hiérarchisés (libellé, valeur, unité, aide, icône d’état).
- Tableaux : en-têtes collants, montants alignés à droite, références cliquables, survol de ligne.
- Écran de connexion en deux panneaux (présentation institutionnelle + formulaire), avec affichage du mot de passe.
- Favicon et `theme-color`.
- Graphique d’évolution d’indicateur : palette validée par le contrôle dataviz (contraste ≥ 3:1, séparation daltonisme ΔE ≥ 21), cible distinguée par tirets.

## 7. Améliorations UX

- Navigation : groupes repliables mémorisés, sidebar compactable (icônes seules, préférence mémorisée), **tiroir mobile** sous 1 024 px, fil d’Ariane calculé depuis la configuration de navigation, palette **Ctrl + K** « Aller à un module » (recherche sans accents, clavier), titre d’onglet du navigateur par écran, lien d’évitement « Aller au contenu ».
- Panneau de notifications branché sur l’API de session (lues / non lues), menu utilisateur avec changement d’acteur de démonstration et déconnexion.
- Listes : onglets de statut avec compteurs, KPI cliquables comme filtres, recherche différée, réinitialisation des filtres, lignes cliquables (souris et clavier), pagination numérotée, états vides avec action (« Créer une expression de besoin »).
- Décisions formelles en boîtes de dialogue explicites : motif obligatoire (retour, rejet, suspension, rejet bancaire, réémission, levée de suspension, ajournement, rejet de planning), confirmation des décisions irréversibles (validation, approbation et génération d’engagement, publication de la chaîne GAR, annulation d’engagement, archivage de nœud).
- Retours systématiques : toast de succès ou d’erreur après chaque action, état de chargement sur le bouton déclencheur, erreurs d’API visibles même avec une modale ouverte.
- Fiches : frise de la chaîne avec liens vers les pièces liées, bandeau d’informations clés, onglets, chronologie, pièces attendues ou manquantes.
- Actions secondaires regroupées en menus « trois points » (PDF, copie modifiable, rectification, désactivation de compte, actions de rapport).
- Formulaires longs découpés en sections (création d’utilisateur, assistant EB), saisie de montants avec unité FCFA.
- Créations déplacées en modales ou panneaux latéraux : tiers, utilisateur, délégations, suppléances, mouvement budgétaire, fiche tiers et fiche utilisateur en panneau latéral.
- Lots de paiement : état d’exécution propre à chaque lot (référence, date et avis ne sont plus partagés entre les lots).
- Assistant EB : suppression d’une sous-ligne, contrôle visuel de l’égalité imputations / total, téléversement explicite (choix puis envoi).

## 8. Changements du Design System

Fichiers : `styles/tokens.css`, `styles/base.css`, `styles/components.css`, `styles/layout.css` (importés par `styles/app.css` ; `eb.css` supprimé).

- **Jetons** : rampes marine, vert, or et ardoise ; rôles sémantiques (fond, surface, bordure, texte fort, atténué, subtil) ; états succès, avertissement, danger, info, orange, violet, indigo, ciel (fond, texte, trait) ; échelle typographique de 10,5 à 30 px ; espacements base 4 ; rayons 4 à 16 ; ombres teintées ; hauteurs de contrôle 32 / 38 / 44 ; dimensions de layout ; z-index.
- **Base** : typographie, focus visible unifié, sélection, style par défaut sans spécificité des contrôles natifs, utilitaires conservés (`mono`, `lbl`, `muted`, `serif`, `sr-only`).
- **Compatibilité** : les anciennes classes restent des alias (`btn-p`, `btn-o`, `btn-n`, `btn-d`, `btn-w`, `btn-g`, `inp`, `area`, `tbl`, `pill`, `card`, `eb-content`, `se-main`, `se-grid-*`, `liq-split`, `liq-fields`, `kpis`, `grid-2`…).
- **Responsive** : points de rupture 1 440 / 1 280 / 1 100 / 1 024 / 760 px ; grilles qui s’empilent ; tableaux défilant dans leur carte ; modales en feuille basse sur mobile.
- **Mouvement réduit** respecté (`prefers-reduced-motion`).

## 9. Problèmes techniques corrigés

- Tableaux `className="grid"` (16) → `tbl` stylé.
- Classe `btn-g` manquante → définie (`btn-ghost`).
- Modale de visa de liquidation sans `z-index` → composant `Modal` (portail, couche dédiée).
- Fiche Ordonnancement, onglet Bénéficiaire : affichage des vraies données bancaires.
- Type de pièce par défaut de l’assistant EB : l’état est aligné sur la première valeur réellement proposée.
- Erreurs d’API non interceptées (référentiels S&E, chargements initiaux) → messages et relance.
- Chargement différé de chaque écran (`React.lazy`) : bundle initial réduit de 684 kB à 533 kB.
- Favicon absent (404 à chaque chargement) → ajouté.
- Contrôles : `tsc --noEmit` sans erreur, y compris avec `--noUnusedLocals` ; `npm run build` réussi.

**Tests effectués (navigateur réel, Chrome via Playwright, serveur Vite + API Laravel locales)**

- Parcours de toutes les routes et des fiches détaillées en 1 440 × 900, avec deux profils (initiateur et administrateur fonctionnel) : aucune erreur JavaScript, aucune erreur HTTP 5xx, aucun débordement horizontal. Les 403 de l’administration sous le profil initiateur sont attendus.
- Parcours mobile 390 × 844 : listes, tableau de bord S&E, ouverture du tiroir de navigation.
- 14 contrôles d’interaction, tous réussis : erreur de connexion, palette Ctrl + K et navigation, notifications, menu utilisateur, modale (ouverture, focus initial, fermeture par Échap), ligne cliquable vers la fiche, menu « trois points », onglets, sidebar compacte.
- Aucune action métier n’a été soumise pendant les tests (pas de modification des données).

## 10. Éléments restant à traiter

1. **Exercice courant** : le libellé « Exercice 2026 · Exécutoire » (sidebar), certains libellés de KPI (« exercice 2026 ») et la date d’effet par défaut de la planification restent codés en dur. L’API de session n’expose pas l’exercice courant ; il faudrait l’ajouter (évolution backend, hors périmètre).
2. **Notifications** : l’API ne propose pas d’action « marquer comme lu » ; le panneau est en lecture seule.
3. **Styles inline résiduels** (610) : surtout dans les écrans S&E calqués sur les maquettes (Gantt, fiche 360°, écarts, synthèse, saisie d’indicateur). Il s’agit pour l’essentiel de positionnements de mise en page ; ils pourraient être convertis progressivement en classes.
4. **67 couleurs littérales** : le graphique SVG (attributs de présentation), les tons de la carte de chaleur des risques et quelques fonds propres aux maquettes S&E.
5. **Chunk principal de 533 kB** : avertissement Vite au-delà de 500 kB. Un découpage des dépendances (React, routeur, Font Awesome) via `build.rolldownOptions.output` le supprimerait.
6. **Tri de colonnes** : non proposé, l’API ne l’expose que sur Mes tâches (paramètre `tri`, conservé dans les filtres).
7. **Mode sombre** : non demandé ; les jetons permettent de l’ajouter sans toucher aux composants.
8. **Tests automatisés** : le projet n’a pas de tests frontend. Les scripts de parcours utilisés pour cette intervention pourraient servir de base à une suite Playwright versionnée.
9. Les libellés de statut bruts renvoyés par certaines API (statuts des lots, des tiers, des workflows) sont affichés tels quels.
