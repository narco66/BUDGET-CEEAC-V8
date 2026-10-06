<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        p { margin: 0 0 12px; color: #475569; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e2e8f0; padding: 4px 6px; text-align: left; }
        th { background: #0e3a5d; color: #fff; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $titre }}</h1>
    <p>{{ $campagne }} · dépenses {{ number_format($depenses, 0, ',', ' ') }} FCFA · recettes {{ number_format($recettes, 0, ',', ' ') }} FCFA · équilibre {{ number_format($equilibre, 0, ',', ' ') }} FCFA</p>
    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Libellé</th>
                <th>Structure</th>
                <th>Classe</th>
                <th class="right">Retenu</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lignes as $ligne)
                <tr>
                    <td>{{ $ligne['code'] }}</td>
                    <td>{{ $ligne['label'] }}</td>
                    <td>{{ $ligne['structure'] ?? '' }}</td>
                    <td>{{ $ligne['classification'] }}</td>
                    <td class="right">{{ number_format($ligne['montant_retenu'], 0, ',', ' ') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
