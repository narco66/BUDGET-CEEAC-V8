<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $fiche['reference'] }}</title>
    <style>
        @page { margin: 34mm 12mm 16mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #16324F; font-size: 9.5pt; }
        .entete { position: fixed; top: -28mm; left: 0; right: 0; }
        .pied { position: fixed; bottom: -10mm; left: 0; right: 0; color: #5C6B8C; font-size: 8pt; border-top: 1px solid #D5DDE8; padding-top: 3px; }
        .logo { width: 16mm; height: 16mm; }
        h1 { font-size: 15pt; color: #0E3A5D; margin: 0 0 1px; letter-spacing: 0.2px; }
        .soustitre { color: #5C6B8C; font-size: 9pt; margin: 0 0 8px; }
        .filet { border-top: 2.5px solid #1B7A4A; margin: 0 0 8px; }
        .meta { background: #E7EEF5; padding: 7px 9px; margin-bottom: 8px; }
        .meta strong { color: #0E3A5D; }
        .section { background: #0E3A5D; color: #fff; font-weight: bold; font-size: 9pt; padding: 5px 8px; margin: 11px 0 0; page-break-after: avoid; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        table.kv td { padding: 4px 6px; border-bottom: 1px solid #E1E7EF; }
        table.kv td.lib { width: 34%; color: #5C6B8C; }
        table.grid { margin-top: 0; }
        table.grid th { background: #F4F7FB; color: #5C6B8C; font-size: 8pt; font-weight: bold; text-align: left; padding: 4px; border: 1px solid #D5DDE8; }
        table.grid td { padding: 4px; border: 1px solid #D5DDE8; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .num { text-align: right; white-space: nowrap; }
        .total { background: #1B7A4A; color: #fff; margin-top: 0; page-break-inside: avoid; }
        .total td { padding: 8px 10px; font-weight: bold; }
        .note { color: #5C6B8C; font-size: 8pt; margin: 4px 0 0; }
        .bandeau { background: #FDECEC; color: #8C1D1D; font-weight: bold; padding: 5px 8px; margin-bottom: 8px; }
        .qr { width: 28mm; height: auto; }
    </style>
</head>
<body>
    <div class="entete">
        <table>
            <tr>
                <td style="width: 18mm;">@if ($fiche['logo'] !== '')<img class="logo" src="{{ $fiche['logo'] }}" alt="CEEAC">@endif</td>
                <td>
                    <div style="font-weight: bold; color: #0E3A5D;">COMMISSION DE LA CEEAC</div>
                    <div style="font-size: 8pt; color: #5C6B8C;">Communauté Économique des États de l’Afrique Centrale</div>
                    <div style="font-size: 8pt; color: #0E3A5D;">BUDGET-CEEAC / GESBUDEP</div>
                </td>
            </tr>
        </table>
        <div class="filet"></div>
    </div>
    <div class="pied">CEEAC · GESBUDEP · {{ $fiche['reference'] }}</div>

    @if ($fiche['brouillon'])
        <div class="bandeau">BROUILLON — document non archivé. Cette édition ne constitue pas l’acte officiel.</div>
    @endif

    <h1>FICHE D’EXPRESSION DE BESOIN</h1>
    <p class="soustitre">Identification, justification, rattachement programmatique et circuit de validation</p>
    <div class="meta">
        <strong>{{ $fiche['reference'] }}</strong> | Exercice {{ $fiche['exercice'] }} | Révision {{ $verification['version'] ?? 'non archivée' }}<br>
        Statut : {{ $fiche['statut'] }}
    </div>

    <div class="section">01 &nbsp; IDENTIFICATION DE LA DEMANDE</div>
    <table class="kv">
        <tr><td class="lib">Structure demandeuse</td><td>{{ $fiche['structure'] }}</td></tr>
        <tr><td class="lib">Initiateur / rattachement</td><td>{{ $fiche['fonction'] }} — {{ $fiche['initiateur'] }}<br>{{ $fiche['rattachement'] }}</td></tr>
        <tr><td class="lib">Date / priorité</td><td>{{ $fiche['date'] }} · {{ $fiche['priorite'] }}</td></tr>
        <tr><td class="lib">Classification / imputation</td><td>{{ $fiche['classification'] }} · {{ $fiche['imputation'] }}</td></tr>
    </table>

    <div class="section">02 &nbsp; OBJET ET JUSTIFICATION</div>
    <p>{{ $fiche['objet'] }}</p>
    <p>Justification : {{ $fiche['justification'] }}</p>
    @if ($fiche['contexte'])
        <p class="note">Contexte : {{ $fiche['contexte'] }}</p>
    @endif

    <div class="section">03 &nbsp; DÉTAIL ESTIMATIF DU BESOIN</div>
    <table class="grid">
        <thead>
            <tr>
                <th>Désignation</th>
                <th class="num">Qté</th>
                <th>Unité</th>
                <th class="num">P. unitaire (XAF)</th>
                <th class="num">Montant (XAF)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($fiche['lignes'] as $ligne)
                <tr>
                    <td>{{ $ligne['designation'] }}@if ($ligne['tache'])<br><span class="note">Tâche : {{ $ligne['tache'] }}</span>@endif</td>
                    <td class="num">{{ $ligne['quantite'] }}</td>
                    <td>{{ $ligne['unite'] }}</td>
                    <td class="num">{{ $ligne['prix'] }}</td>
                    <td class="num">{{ $ligne['montant'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Aucune sous-ligne enregistrée.</td></tr>
            @endforelse
        </tbody>
    </table>
    <table class="total">
        <tr>
            <td>MONTANT TOTAL DEMANDÉ</td>
            <td class="num">{{ $fiche['total'] }} XAF</td>
        </tr>
    </table>
    <p class="note">Crédit disponible indiqué : {{ $fiche['disponible'] }} · Solde théorique après demande : {{ $fiche['solde'] }}</p>
    <p class="note">Solde indicatif au {{ $fiche['controle_le'] }}, à confirmer par le contrôle budgétaire. Cette fiche ne vaut pas engagement. Le disponible indiqué est le crédit avant cette demande.</p>
    @if (count($fiche['imputations']) > 1)
        <p class="note">Imputations</p>
        <table class="grid">
            <thead><tr><th>Code</th><th>Libellé</th><th class="num">Montant (XAF)</th></tr></thead>
            <tbody>
                @foreach ($fiche['imputations'] as $imputation)
                    <tr><td>{{ $imputation['code'] }}</td><td>{{ $imputation['libelle'] }}</td><td class="num">{{ $imputation['montant'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="section">04 &nbsp; CONTEXTE PROGRAMMATIQUE ET EXÉCUTION</div>
    <table class="kv">
        <tr><td class="lib">Source</td><td>{{ $fiche['pap'] ? 'Budget officiel / Référentiel PAP' : 'Budget officiel / Hors PAP' }}</td></tr>
        <tr><td class="lib">Activité / code</td><td>{{ $fiche['imputation'] }}</td></tr>
        <tr><td class="lib">Chaîne GAR / RBM</td><td>{{ $fiche['chaine'] }}</td></tr>
        <tr><td class="lib">Résultat attendu</td><td>{{ $fiche['resultat'] }}</td></tr>
        <tr><td class="lib">Indicateur</td><td>{{ $fiche['indicateur'] }}</td></tr>
        <tr><td class="lib">Unité responsable</td><td>{{ $fiche['unite'] }}</td></tr>
        <tr><td class="lib">Période / lieu</td><td>{{ $fiche['periode_lieu'] }}</td></tr>
    </table>

    <div class="section">05 &nbsp; PIÈCES JUSTIFICATIVES</div>
    @if ($fiche['pieces'] === [])
        <p>Aucune pièce jointe enregistrée.</p>
    @else
        <table class="grid">
            <thead><tr><th>Intitulé</th><th>Référence</th><th>Date</th><th>Identifiant</th></tr></thead>
            <tbody>
                @foreach ($fiche['pieces'] as $piece)
                    <tr>
                        <td>{{ $piece['intitule'] }}</td>
                        <td>{{ $piece['reference'] }}</td>
                        <td>{{ $piece['date'] }}</td>
                        <td>{{ $piece['identifiant'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="section">06 &nbsp; VALIDATIONS ET DÉCISIONS</div>
    @if ($fiche['decisions'] === [])
        <p>Aucune décision enregistrée.</p>
    @else
        <table class="grid">
            <thead><tr><th>Étape</th><th>Acteur</th><th>Date</th><th>Observation</th></tr></thead>
            <tbody>
                @foreach ($fiche['decisions'] as $decision)
                    <tr>
                        <td>{{ $decision['decision'] }}<br><span class="note">{{ $decision['visa'] }}</span></td>
                        <td>{{ $decision['acteur'] }}<br><span class="note">{{ $decision['fonction'] }} · {{ $decision['structure'] }}</span></td>
                        <td>{{ $decision['date'] }}</td>
                        <td>{{ $decision['observation'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    @foreach ($fiche['attente'] as $etape)
        <p class="note">{{ $etape['libelle'] }}</p>
    @endforeach

    <div class="section">07 &nbsp; TRAÇABILITÉ ET AUTHENTIFICATION</div>
    @if (! empty($verification['qr']))
        <table>
            <tr>
                <td style="width: 32mm;"><img class="qr" src="{{ $verification['qr'] }}" alt="QR de vérification"></td>
                <td>
                    <strong>VÉRIFICATION DU DOCUMENT</strong><br>
                    Référence : {{ $fiche['reference'] }} · Exercice : {{ $fiche['exercice'] }} · Révision : {{ $verification['version'] }}<br>
                    <span class="note">Émis le {{ $verification['genere_le'] }}. Le QR ouvre la page de vérification de cette révision. L’empreinte du fichier est conservée dans l’archive et n’est pas imprimée dans le document.</span>
                </td>
            </tr>
        </table>
    @else
        <p>Ce brouillon n’a pas de code de vérification. Le QR est émis uniquement avec la révision archivée.</p>
    @endif
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
            $pdf->page_text(500, 800, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.36, 0.42, 0.55]);
        }
    </script>
</body>
</html>
