<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $acte['reference'] }}</title>
    <style>
        @page { margin: 34mm 12mm 16mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #16324F; font-size: 9.5pt; }
        .entete { position: fixed; top: -28mm; left: 0; right: 0; }
        .pied { position: fixed; bottom: -10mm; left: 0; right: 0; color: #5C6B8C; font-size: 8pt; border-top: 1px solid #D5DDE8; padding-top: 3px; }
        .logo { width: 16mm; height: 16mm; }
        h1 { font-size: 15pt; color: #0E3A5D; margin: 0 0 1px; }
        .soustitre { color: #5C6B8C; font-size: 9pt; margin: 0 0 8px; }
        .filet { border-top: 2.5px solid #1B7A4A; margin: 0 0 8px; }
        .meta { background: #E7EEF5; padding: 7px 9px; margin-bottom: 8px; }
        .section { background: #0E3A5D; color: #fff; font-weight: bold; font-size: 9pt; padding: 5px 8px; margin: 11px 0 0; page-break-after: avoid; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        table.kv td { padding: 4px 6px; border-bottom: 1px solid #E1E7EF; }
        table.kv td.lib { width: 38%; color: #5C6B8C; }
        table.grid th { background: #F4F7FB; color: #5C6B8C; font-size: 8pt; font-weight: bold; text-align: left; padding: 4px; border: 1px solid #D5DDE8; }
        table.grid td { padding: 4px; border: 1px solid #D5DDE8; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .num { text-align: right; white-space: nowrap; }
        .total { background: #1B7A4A; color: #fff; page-break-inside: avoid; }
        .total td { padding: 8px 10px; font-weight: bold; }
        .note { color: #5C6B8C; font-size: 8pt; margin: 4px 0 0; }
        .qr { width: 28mm; height: auto; }
    </style>
</head>
<body>
    <div class="entete">
        <table>
            <tr>
                <td style="width: 18mm;">@if (($acte['logo'] ?? '') !== '')<img class="logo" src="{{ $acte['logo'] }}" alt="CEEAC">@endif</td>
                <td>
                    <div style="font-weight: bold; color: #0E3A5D;">COMMISSION DE LA CEEAC</div>
                    <div style="font-size: 8pt; color: #5C6B8C;">Communauté Économique des États de l’Afrique Centrale</div>
                    <div style="font-size: 8pt; color: #0E3A5D;">BUDGET-CEEAC / GESBUDEP</div>
                </td>
            </tr>
        </table>
        <div class="filet"></div>
    </div>
    <div class="pied">CEEAC · GESBUDEP · {{ $acte['reference'] }} · {{ $acte['titre'] }}</div>

    <h1>{{ $acte['titre'] }}</h1>
    <p class="soustitre">{{ $acte['soustitre'] }}</p>
    <div class="meta">
        <strong>{{ $acte['reference'] }}</strong> | Exercice {{ $acte['exercice'] }} | Révision {{ $verification['version'] ?? 'non archivée' }}<br>
        Statut : {{ $acte['statut'] }}
    </div>

    @foreach ($acte['sections'] as $section)
        <div class="section">{{ $section['titre'] }}</div>
        @if (! empty($section['texte']))
            <p>{{ $section['texte'] }}</p>
        @endif
        @if (! empty($section['lignes']))
            <table class="kv">
                @foreach ($section['lignes'] as $ligne)
                    <tr><td class="lib">{{ $ligne['libelle'] }}</td><td>{{ $ligne['valeur'] }}</td></tr>
                @endforeach
            </table>
        @endif
        @if (! empty($section['tableau']))
            <table class="grid">
                <thead>
                    <tr>
                        @foreach ($section['tableau']['colonnes'] as $colonne)
                            <th>{{ $colonne }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($section['tableau']['lignes'] as $rangee)
                        <tr>
                            @foreach ($rangee as $cellule)
                                <td>{{ $cellule }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($section['tableau']['colonnes']) }}">Aucune ligne enregistrée.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if (! empty($section['tableau']['total']))
                <table class="total">
                    <tr>
                        <td>{{ $section['tableau']['total']['libelle'] }}</td>
                        <td class="num">{{ $section['tableau']['total']['valeur'] }}</td>
                    </tr>
                </table>
                @if (! empty($section['tableau']['lettres']))
                    <p class="note">Montant en lettres : {{ $section['tableau']['lettres'] }}</p>
                @endif
            @endif
        @endif
        @if (! empty($section['signataires']))
            <table class="grid">
                <thead><tr><th>Étape</th><th>Acteur enregistré</th><th>Date</th><th>Décision</th></tr></thead>
                <tbody>
                    @foreach ($section['signataires'] as $signataire)
                        <tr>
                            <td>{{ $signataire['role'] }}</td>
                            <td>{{ $signataire['nom'] }}</td>
                            <td>{{ $signataire['date'] }}</td>
                            <td>{{ $signataire['decision'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    <div class="section">TRAÇABILITÉ</div>
    @if (! empty($verification['qr']))
        <table>
            <tr>
                <td style="width: 32mm;"><img class="qr" src="{{ $verification['qr'] }}" alt="QR de vérification"></td>
                <td>
                    <strong>VÉRIFICATION DU DOCUMENT</strong><br>
                    Référence : {{ $acte['reference'] }} · Exercice : {{ $acte['exercice'] }} · Révision : {{ $verification['version'] }}<br>
                    <span class="note">Émis le {{ $verification['genere_le'] }} à l’événement « {{ $verification['evenement'] }} ». Le QR ouvre la vérification de cette révision. L’empreinte du fichier est conservée dans l’archive et n’est pas imprimée.</span>
                </td>
            </tr>
        </table>
    @endif
    <p class="note">{{ $acte['mention'] }}</p>
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
            $pdf->page_text(500, 800, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.36, 0.42, 0.55]);
        }
    </script>
</body>
</html>
