<?php

return [
    'analyse_obligatoire' => (bool) env('GED_ANALYSE_OBLIGATOIRE', false),
    'extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'csv', 'docx', 'xlsx', 'zip'],
    'mimes' => [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ],
    'taille_max_ko' => 10240,
    'confidentialites' => ['public', 'interne', 'restreint', 'confidentiel', 'tres_confidentiel'],
];
