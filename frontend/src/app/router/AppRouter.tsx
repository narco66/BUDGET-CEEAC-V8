import { lazy, Suspense } from 'react';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import AppShell from '../../components/layout/AppShell';
import { Button, EmptyState, ICON } from '../../components/ui';

/* Chaque écran est chargé à la demande : le premier affichage reste léger. */
const AdminAccessPage = lazy(() => import('../../features/administration/pages/AdminAccessPage'));
const AdminHomePage = lazy(() => import('../../features/administration/pages/AdminHomePage'));
const AdminSettingsPage = lazy(() => import('../../features/administration/pages/AdminSettingsPage'));
const AdminUsersPage = lazy(() => import('../../features/administration/pages/AdminUsersPage'));
const RolesPermissionsPage = lazy(() => import('../../features/administration/pages/RolesPermissionsPage'));
const HabilitationsPage = lazy(() => import('../../features/administration/pages/HabilitationsPage'));
const AuditJournalPage = lazy(() => import('../../features/administration/pages/AuditJournalPage'));
const OrganisationPage = lazy(() => import('../../features/administration/pages/OrganisationPage'));
const BudgetLinesPage = lazy(() => import('../../features/budget/pages/BudgetLinesPage'));
const PreparationPage = lazy(() => import('../../features/budget/pages/PreparationPage'));
const CampagnesPage = lazy(() => import('../../features/budget/pages/CampagnesPage'));
const CampagneFormPage = lazy(() => import('../../features/budget/pages/CampagneFormPage'));
const CampagneFichePage = lazy(() => import('../../features/budget/pages/CampagneFichePage'));
const CadragePage = lazy(() => import('../../features/budget/pages/CadragePage'));
const ConsolidationPage = lazy(() => import('../../features/budget/pages/ConsolidationPage'));
const DossiersPage = lazy(() => import('../../features/budget/pages/DossiersPage'));
const DossierFormPage = lazy(() => import('../../features/budget/pages/DossierFormPage'));
const DossierFichePage = lazy(() => import('../../features/budget/pages/DossierFichePage'));
const CloturePage = lazy(() => import('../../features/budget/pages/CloturePage'));
const EtatsBasePage = lazy(() => import('../../features/budget/pages/EtatsBasePage'));
const MarchesPage = lazy(() => import('../../features/procurement/pages/MarchesPage'));
const MarcheFichePage = lazy(() => import('../../features/procurement/pages/MarcheFichePage'));
const RapprochementsPage = lazy(() => import('../../features/budget/pages/RapprochementsPage'));
const ChainePage = lazy(() => import('../../features/payments/pages/ChainePage'));
const DossierChainePage = lazy(() => import('../../features/payments/pages/DossierChainePage'));
const TresoreriePage = lazy(() => import('../../features/payments/pages/TresoreriePage'));
const ObligationsPage = lazy(() => import('../../features/payments/pages/ObligationsPage'));
const ExportComptablePage = lazy(() => import('../../features/payments/pages/ExportComptablePage'));
const MesTachesPage = lazy(() => import('../../features/tasks/pages/MesTachesPage'));
const NotificationsPage = lazy(() => import('../../features/notifications/pages/NotificationsPage'));
const NotificationDetailPage = lazy(() => import('../../features/notifications/pages/NotificationDetailPage'));
const TacheFichePage = lazy(() => import('../../features/tasks/pages/TacheFichePage'));
const PayFichePage = lazy(() => import('../../features/payments/pages/PayFichePage'));
const PayListPage = lazy(() => import('../../features/payments/pages/PayListPage'));
const PayLotsPage = lazy(() => import('../../features/payments/pages/PayLotsPage'));
const OrdDelegationsPage = lazy(() => import('../../features/payments/pages/OrdDelegationsPage'));
const OrdFichePage = lazy(() => import('../../features/payments/pages/OrdFichePage'));
const OrdListPage = lazy(() => import('../../features/payments/pages/OrdListPage'));
const LiqFichePage = lazy(() => import('../../features/settlements/pages/LiqFichePage'));
const LiqListPage = lazy(() => import('../../features/settlements/pages/LiqListPage'));
const EngFichePage = lazy(() => import('../../features/commitments/pages/EngFichePage'));
const EngListPage = lazy(() => import('../../features/commitments/pages/EngListPage'));
const EbFichePage = lazy(() => import('../../features/needs/pages/EbFichePage'));
const EbListPage = lazy(() => import('../../features/needs/pages/EbListPage'));
const EbWizardPage = lazy(() => import('../../features/needs/pages/EbWizardPage'));
const LoginPage = lazy(() => import('../../features/auth/pages/LoginPage'));
const AccueilPage = lazy(() => import('../../features/auth/pages/AccueilPage'));
const MotDePasseOubliePage = lazy(() => import('../../features/auth/pages/MotDePasseOubliePage'));
const MotDePassePage = lazy(() => import('../../features/auth/pages/MotDePassePage'));
const VerifyDocumentPage = lazy(() => import('../../features/documents/pages/VerifyDocumentPage'));
const DocumentSearchPage = lazy(() => import('../../features/documents/pages/DocumentSearchPage'));
const GedPage = lazy(() => import('../../features/ged/pages/GedPage'));
const GedFichePage = lazy(() => import('../../features/ged/pages/GedFichePage'));
const AnomaliesPage = lazy(() => import('../../features/administration/pages/AnomaliesPage'));
const ExecutionSnapshotsPage = lazy(() => import('../../features/administration/pages/ExecutionSnapshotsPage'));
const ImportStagingPage = lazy(() => import('../../features/administration/pages/ImportStagingPage'));
const PublicVerifyPage = lazy(() => import('../../features/documents/pages/PublicVerifyPage'));
const TiersPage = lazy(() => import('../../features/suppliers/pages/TiersPage'));
const Activite360Page = lazy(() => import('../../features/monitoring/pages/Activite360Page'));
const EcartPage = lazy(() => import('../../features/monitoring/pages/EcartPage'));
const GanttPage = lazy(() => import('../../features/monitoring/pages/GanttPage'));
const SaisiePage = lazy(() => import('../../features/monitoring/pages/SaisiePage'));
const SaisieIndicateurPage = lazy(() => import('../../features/monitoring/pages/SaisieIndicateurPage'));
const SuiviDashboardPage = lazy(() => import('../../features/monitoring/pages/SuiviDashboardPage'));
const SynthesePage = lazy(() => import('../../features/monitoring/pages/SynthesePage'));
const ReferentielsPage = lazy(() => import('../../features/monitoring/pages/ReferentielsPage'));
const SuiviActionsPage = lazy(() => import('../../features/monitoring/pages/SuiviActionsPage'));
const RapportsPage = lazy(() => import('../../features/monitoring/pages/RapportsPage'));
const PlanificationPage = lazy(() => import('../../features/planning/pages/PlanificationPage'));
const RecettesDashboardPage = lazy(() => import('../../features/revenues/pages/RecettesDashboardPage'));
const PrevisionsPage = lazy(() => import('../../features/revenues/pages/PrevisionsPage'));
const PrevisionFichePage = lazy(() => import('../../features/revenues/pages/PrevisionFichePage'));
const TitresPage = lazy(() => import('../../features/revenues/pages/TitresPage'));
const TitreFichePage = lazy(() => import('../../features/revenues/pages/TitreFichePage'));
const ContributionsPage = lazy(() => import('../../features/revenues/pages/ContributionsPage'));
const CreancesPage = lazy(() => import('../../features/revenues/pages/CreancesPage'));
const EncaissementsPage = lazy(() => import('../../features/revenues/pages/EncaissementsPage'));
const RapprochementsRecettesPage = lazy(() => import('../../features/revenues/pages/RapprochementsRecettesPage'));
const RelancesPage = lazy(() => import('../../features/revenues/pages/RelancesPage'));
const EtatsRecettesPage = lazy(() => import('../../features/revenues/pages/EtatsRecettesPage'));
const ParametrageRecettesPage = lazy(() => import('../../features/revenues/pages/ParametrageRecettesPage'));

function NotFoundPage() {
    return (
        <main className="app-content">
            <div className="card">
                <EmptyState icon={ICON.search} title="Page introuvable" action={<Button variant="primary" to="/taches" icon={ICON.tasks}>Retour à Mes tâches</Button>}>
                    L’adresse demandée ne correspond à aucun écran de BUDGET-CEEAC.
                </EmptyState>
            </div>
        </main>
    );
}

export default function AppRouter() {
    return (
        <BrowserRouter>
            <Routes>
                <Route path="/connexion" element={<Suspense fallback={null}><LoginPage /></Suspense>} />
                <Route path="/accueil" element={<Suspense fallback={null}><AccueilPage /></Suspense>} />
                <Route path="/mot-de-passe-oublie" element={<Suspense fallback={null}><MotDePasseOubliePage /></Suspense>} />
                <Route path="/mot-de-passe/:token" element={<Suspense fallback={null}><MotDePassePage /></Suspense>} />
                <Route path="/verifier/:code" element={<Suspense fallback={null}><PublicVerifyPage /></Suspense>} />
                <Route element={<AppShell />}>
                    <Route path="/" element={<Navigate to="/taches" replace />} />
                    <Route path="/taches" element={<MesTachesPage />} />
                    <Route path="/notifications" element={<NotificationsPage />} />
                    <Route path="/notifications/:id" element={<NotificationDetailPage />} />
                    <Route path="/taches/:id" element={<TacheFichePage />} />
                    <Route path="/chaine" element={<ChainePage />} />
                    <Route path="/chaine/tresorerie" element={<TresoreriePage />} />
                    <Route path="/chaine/obligations" element={<ObligationsPage />} />
                    <Route path="/chaine/comptabilite" element={<ExportComptablePage />} />
                    <Route path="/chaine/dossier" element={<DossierChainePage />} />
                    <Route path="/chaine/dossier/:id" element={<DossierChainePage />} />
                    <Route path="/lignes-budgetaires" element={<BudgetLinesPage />} />
                    <Route path="/preparation" element={<PreparationPage />} />
                    <Route path="/preparation/campagnes" element={<CampagnesPage />} />
                    <Route path="/preparation/campagnes/nouvelle" element={<CampagneFormPage />} />
                    <Route path="/preparation/campagnes/:id" element={<CampagneFichePage />} />
                    <Route path="/preparation/campagnes/:id/modifier" element={<CampagneFormPage />} />
                    <Route path="/preparation/campagnes/:id/cadrage" element={<CadragePage />} />
                    <Route path="/preparation/campagnes/:id/consolidation" element={<ConsolidationPage />} />
                    <Route path="/preparation/dossiers" element={<DossiersPage />} />
                    <Route path="/preparation/dossiers/nouvelle" element={<DossierFormPage />} />
                    <Route path="/preparation/dossiers/:id" element={<DossierFichePage />} />
                    <Route path="/preparation/dossiers/:id/modifier" element={<DossierFormPage />} />
                    <Route path="/cloture" element={<CloturePage />} />
                    <Route path="/etats" element={<EtatsBasePage />} />
                    <Route path="/marches" element={<MarchesPage />} />
                    <Route path="/marches/:id" element={<MarcheFichePage />} />
                    <Route path="/recettes" element={<RecettesDashboardPage />} />
                    <Route path="/recettes/previsions" element={<PrevisionsPage />} />
                    <Route path="/recettes/previsions/:id" element={<PrevisionFichePage />} />
                    <Route path="/recettes/titres" element={<TitresPage />} />
                    <Route path="/recettes/titres/:id" element={<TitreFichePage />} />
                    <Route path="/recettes/contributions" element={<ContributionsPage />} />
                    <Route path="/recettes/creances" element={<CreancesPage />} />
                    <Route path="/recettes/encaissements" element={<EncaissementsPage />} />
                    <Route path="/recettes/rapprochements" element={<RapprochementsRecettesPage />} />
                    <Route path="/recettes/relances" element={<RelancesPage />} />
                    <Route path="/recettes/etats" element={<EtatsRecettesPage />} />
                    <Route path="/recettes/parametrage" element={<ParametrageRecettesPage />} />
                    <Route path="/rapprochements" element={<RapprochementsPage />} />
                    <Route path="/expressions-besoin" element={<EbListPage />} />
                    <Route path="/expressions-besoin/nouvelle" element={<EbWizardPage />} />
                    <Route path="/expressions-besoin/:id" element={<EbFichePage />} />
                    <Route path="/expressions-besoin/:id/modifier" element={<EbWizardPage />} />
                    <Route path="/engagements" element={<EngListPage />} />
                    <Route path="/engagements/:id" element={<EngFichePage />} />
                    <Route path="/liquidations" element={<LiqListPage />} />
                    <Route path="/liquidations/:id" element={<LiqFichePage />} />
                    <Route path="/ordonnancements" element={<OrdListPage />} />
                    <Route path="/ordonnancements/delegations" element={<OrdDelegationsPage />} />
                    <Route path="/ordonnancements/:id" element={<OrdFichePage />} />
                    <Route path="/paiements" element={<PayListPage />} />
                    <Route path="/paiements/lots" element={<PayLotsPage />} />
                    <Route path="/paiements/:id" element={<PayFichePage />} />
                    <Route path="/documents/verifier" element={<VerifyDocumentPage />} />
                    <Route path="/documents/recherche" element={<DocumentSearchPage />} />
                    <Route path="/ged" element={<GedPage />} />
                    <Route path="/ged/:id" element={<GedFichePage />} />
                    <Route path="/controles/anomalies" element={<AnomaliesPage />} />
                    <Route path="/rapports/execution" element={<ExecutionSnapshotsPage />} />
                    <Route path="/imports/preparation" element={<ImportStagingPage />} />
                    <Route path="/tiers" element={<TiersPage />} />
                    <Route path="/planification" element={<PlanificationPage />} />
                    <Route path="/suivi" element={<SuiviDashboardPage />} />
                    <Route path="/suivi/gantt" element={<GanttPage />} />
                    <Route path="/suivi/saisie" element={<SaisiePage />} />
                    <Route path="/suivi/ecarts" element={<EcartPage />} />
                    <Route path="/suivi/ecarts/:id" element={<EcartPage />} />
                    <Route path="/suivi/activites/:id/gantt" element={<GanttPage />} />
                    <Route path="/suivi/indicateurs/:id/saisie" element={<SaisieIndicateurPage />} />
                    <Route path="/suivi/synthese" element={<SynthesePage />} />
                    <Route path="/suivi/referentiels" element={<ReferentielsPage />} />
                    <Route path="/suivi/actions" element={<SuiviActionsPage />} />
                    <Route path="/suivi/rapports" element={<RapportsPage />} />
                    <Route path="/suivi/activites/:id" element={<Activite360Page />} />
                    <Route path="/administration" element={<AdminHomePage />} />
                    <Route path="/administration/utilisateurs" element={<AdminUsersPage />} />
                    <Route path="/administration/utilisateurs/:id" element={<AdminUsersPage />} />
                    <Route path="/administration/organisation" element={<OrganisationPage />} />
                    <Route path="/administration/roles-permissions" element={<RolesPermissionsPage />} />
                    <Route path="/administration/habilitations" element={<HabilitationsPage />} />
                    <Route path="/administration/suivi-acces" element={<AdminAccessPage />} />
                    <Route path="/administration/audit" element={<AuditJournalPage />} />
                    <Route path="/administration/parametrage" element={<AdminSettingsPage />} />
                    <Route path="*" element={<NotFoundPage />} />
                </Route>
            </Routes>
        </BrowserRouter>
    );
}
