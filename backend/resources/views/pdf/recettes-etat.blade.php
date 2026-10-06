<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titre }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0B1C3E; font-size: 11px; }
        h1 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #CBD5E1; padding: 4px; text-align: left; }
        th { background: #F0F4FA; }
        .muted { color: #5C6B8C; }
    </style>
</head>
<body>
    <p class="muted">Commission de la CEEAC — BUDGET-CEEAC</p>
    <h1>{{ $titre }}</h1>
    <table>
        <thead>
            <tr>
                @foreach(array_keys($lignes[0] ?? []) as $head)
                    <th>{{ $head }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($lignes as $ligne)
                <tr>
                    @foreach($ligne as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td>Aucune recette pour les filtres demandés.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
