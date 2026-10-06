<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport de suivi-évaluation</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0B1C3E; font-size: 11px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #5C6B8C; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #E2E8F0; text-align: left; }
        th { background: #F8FAFC; font-size: 10px; text-transform: uppercase; }
        .r { text-align: right; }
        .alerte { color: #B91C1C; font-weight: bold; }
    </style>
</head>
<body>
    <p class="muted">COMMISSION DE LA CEEAC · SUIVI-ÉVALUATION</p>
    @isset($rapport)
        <h1>{{ $rapport->title }}</h1>
        <p class="muted">
            {{ $rapport->reference }} · version {{ $rapport->version }} · situation au {{ $rapport->situation_au?->format('d/m/Y') }}
            · périmètre {{ $rapport->snapshot['perimetre'] ?? '—' }}<br>
            Établi par {{ $rapport->generatedBy?->name ?? '—' }} · validé par {{ $rapport->validatedBy?->name ?? '—' }}
            le {{ $rapport->validated_at?->format('d/m/Y H:i') ?? '—' }}
        </p>
    @else
        <h1>Rapport de performance</h1>
        <p class="alerte">Document de travail non officiel : seuls les rapports publiés font foi.</p>
    @endisset

    <table>
        <tr><td>Avancement physique</td><td class="r">{{ $board['physique'] }} %</td></tr>
        <tr><td>Exécution financière (payé / révisé)</td><td class="r">{{ $board['financier'] }} %</td></tr>
        <tr><td>Écart physique / financier</td><td class="r">{{ $board['ecart'] }} points</td></tr>
        <tr><td>Activités suivies · en retard · critiques</td><td class="r">{{ $board['activites'] }} · {{ $board['en_retard'] }} · {{ $board['critiques'] }}</td></tr>
        <tr><td>Montant payé</td><td class="r">{{ number_format((int) $board['montant_paye'], 0, ',', ' ') }} FCFA</td></tr>
        <tr><td>Risques critiques · recommandations échues · mesures en retard</td><td class="r">{{ $board['risques_critiques'] }} · {{ $board['recommandations_echues'] }} · {{ $board['mesures_en_retard'] }}</td></tr>
    </table>

    <table>
        <thead>
            <tr><th>Activité</th><th>Structure</th><th class="r">Physique</th><th class="r">Financier</th><th class="r">Écart</th><th class="r">Payé</th></tr>
        </thead>
        <tbody>
            @foreach ($board['activites_detail'] as $row)
                <tr>
                    <td>{{ $row['activite'] }}</td>
                    <td>{{ $row['structure'] ?? '—' }}</td>
                    <td class="r">{{ $row['physique'] }} %</td>
                    <td class="r">{{ $row['financier'] }} %</td>
                    <td class="r {{ ($row['alerte'] ?? false) ? 'alerte' : '' }}">{{ $row['ecart'] }}{{ ($row['alerte'] ?? false) ? ' · alerte' : '' }}</td>
                    <td class="r">{{ number_format((int) ($row['finances']['paye'] ?? 0), 0, ',', ' ') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @isset($rapport)
        @if (! empty($rapport->snapshot['risques_critiques']))
            <h3>Risques critiques</h3>
            <ul>
                @foreach ($rapport->snapshot['risques_critiques'] as $risk)
                    <li>{{ $risk['reference'] }} · {{ $risk['description'] }} ({{ $risk['statut'] }})</li>
                @endforeach
            </ul>
        @endif
        @if (! empty($rapport->snapshot['recommandations_ouvertes']))
            <h3>Recommandations ouvertes</h3>
            <ul>
                @foreach ($rapport->snapshot['recommandations_ouvertes'] as $row)
                    <li>{{ $row['reference'] }} · {{ $row['description'] }} · échéance {{ $row['echeance'] ?? '—' }}{{ $row['en_retard'] ? ' · en retard' : '' }}</li>
                @endforeach
            </ul>
        @endif
        @if ($rapport->commentaire)
            <h3>Commentaire</h3>
            <p>{{ $rapport->commentaire }}</p>
        @endif
    @endisset

    @include('pdf._verification')
</body>
</html>
