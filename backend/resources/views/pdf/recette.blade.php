<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $order->reference }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0B1C3E; font-size: 12px; }
        h1 { font-size: 18px; margin-bottom: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #CBD5E1; padding: 6px; text-align: left; }
        th { background: #F0F4FA; width: 34%; }
        .muted { color: #5C6B8C; }
    </style>
</head>
<body>
    <p class="muted">Commission de la CEEAC — BUDGET-CEEAC</p>
    <h1>{{ $titre }}</h1>
    <p>{{ $order->reference }} · exercice {{ $order->exercice->annee }}</p>
    <table>
        <tr><th>Débiteur</th><td>{{ $order->debtor_label }}</td></tr>
        <tr><th>Nature</th><td>{{ $order->category->label }}</td></tr>
        <tr><th>Motif</th><td>{{ $order->motif }}</td></tr>
        <tr><th>Montant constaté</th><td>{{ number_format($order->montant, 0, ',', ' ') }} FCFA</td></tr>
        <tr><th>Montant encaissé</th><td>{{ number_format($order->montant_encaisse, 0, ',', ' ') }} FCFA</td></tr>
        <tr><th>Solde</th><td>{{ number_format($order->solde(), 0, ',', ' ') }} FCFA</td></tr>
        <tr><th>Échéance</th><td>{{ $order->echeance?->format('d/m/Y') }}</td></tr>
        <tr><th>Statut</th><td>{{ $order->statut }}</td></tr>
    </table>
    @include('pdf._verification')
</body>
</html>
