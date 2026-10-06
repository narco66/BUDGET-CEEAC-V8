@isset($verification)
    <p style="margin-top: 24px; padding-top: 8px; border-top: 1px solid #CBD5E1; color: #5C6B8C; font-size: 9px;">
        Document officiel · version {{ $verification['version'] }} · {{ $verification['evenement'] }} · généré le {{ $verification['genere_le'] }}<br>
        Code de vérification : {{ $verification['code'] }} — vérifiable dans BUDGET-CEEAC (Documents › Vérifier).
    </p>
@endisset
