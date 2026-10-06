<?php

$root = dirname(__DIR__).DIRECTORY_SEPARATOR.'backend';

$moves = [
    ['app/Enums/EbStatus.php', 'app/Domains/Needs/Enums/EbStatus.php', 'App\\Domains\\Needs\\Enums'],
    ['app/Enums/BudgetNature.php', 'app/Domains/Budget/Enums/BudgetNature.php', 'App\\Domains\\Budget\\Enums'],
    ['app/Models/ExpressionBesoin.php', 'app/Domains/Needs/Models/ExpressionBesoin.php', 'App\\Domains\\Needs\\Models'],
    ['app/Models/EbLine.php', 'app/Domains/Needs/Models/EbLine.php', 'App\\Domains\\Needs\\Models'],
    ['app/Models/EbImputation.php', 'app/Domains/Needs/Models/EbImputation.php', 'App\\Domains\\Needs\\Models'],
    ['app/Models/EbDocument.php', 'app/Domains/Needs/Models/EbDocument.php', 'App\\Domains\\Needs\\Models'],
    ['app/Models/EbEvent.php', 'app/Domains/Needs/Models/EbEvent.php', 'App\\Domains\\Needs\\Models'],
    ['app/Models/Engagement.php', 'app/Domains/Commitments/Models/Engagement.php', 'App\\Domains\\Commitments\\Models'],
    ['app/Models/BudgetLine.php', 'app/Domains/Budget/Models/BudgetLine.php', 'App\\Domains\\Budget\\Models'],
    ['app/Models/Exercice.php', 'app/Domains/Budget/Models/Exercice.php', 'App\\Domains\\Budget\\Models'],
    ['app/Models/PapEnrichment.php', 'app/Domains/PAP/Models/PapEnrichment.php', 'App\\Domains\\PAP\\Models'],
    ['app/Models/PapTask.php', 'app/Domains/PAP/Models/PapTask.php', 'App\\Domains\\PAP\\Models'],
    ['app/Models/OrganizationUnit.php', 'app/Domains/Organization/Models/OrganizationUnit.php', 'App\\Domains\\Organization\\Models'],
    ['app/Services/ExpressionBesoinWorkflow.php', 'app/Domains/Needs/Services/ExpressionBesoinWorkflow.php', 'App\\Domains\\Needs\\Services'],
    ['app/Http/Controllers/Api/ExpressionBesoinController.php', 'app/Domains/Needs/Http/Controllers/ExpressionBesoinController.php', 'App\\Domains\\Needs\\Http\\Controllers'],
    ['app/Http/Controllers/Api/BudgetLineController.php', 'app/Domains/Budget/Http/Controllers/BudgetLineController.php', 'App\\Domains\\Budget\\Http\\Controllers'],
    ['app/Http/Controllers/Api/ActorController.php', 'app/Shared/Auth/Http/ActorController.php', 'App\\Shared\\Auth\\Http'],
    ['app/Http/Middleware/ResolveActor.php', 'app/Shared/Auth/ResolveActor.php', 'App\\Shared\\Auth'],
    ['app/Http/Requests/StoreExpressionBesoinRequest.php', 'app/Domains/Needs/Http/Requests/StoreExpressionBesoinRequest.php', 'App\\Domains\\Needs\\Http\\Requests'],
    ['app/Http/Requests/UpdateExpressionBesoinRequest.php', 'app/Domains/Needs/Http/Requests/UpdateExpressionBesoinRequest.php', 'App\\Domains\\Needs\\Http\\Requests'],
    ['app/Http/Requests/ReturnExpressionBesoinRequest.php', 'app/Domains/Needs/Http/Requests/ReturnExpressionBesoinRequest.php', 'App\\Domains\\Needs\\Http\\Requests'],
    ['app/Http/Resources/ExpressionBesoinResource.php', 'app/Domains/Needs/Http/Resources/ExpressionBesoinResource.php', 'App\\Domains\\Needs\\Http\\Resources'],
    ['app/Http/Resources/EbLineResource.php', 'app/Domains/Needs/Http/Resources/EbLineResource.php', 'App\\Domains\\Needs\\Http\\Resources'],
    ['app/Exports/ExpressionsBesoinExport.php', 'app/Domains/Needs/Exports/ExpressionsBesoinExport.php', 'App\\Domains\\Needs\\Exports'],
    ['app/Notifications/EbWorkflowNotification.php', 'app/Domains/Needs/Notifications/EbWorkflowNotification.php', 'App\\Domains\\Needs\\Notifications'],
];

foreach ($moves as [$from, $to, $namespace]) {
    $src = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $from);
    $dst = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $to);
    if (! is_file($src)) {
        fwrite(STDERR, "Manquant: {$src}\n");
        exit(1);
    }
    if (! is_dir(dirname($dst))) {
        mkdir(dirname($dst), 0777, true);
    }
    $code = file_get_contents($src);
    $code = preg_replace('/namespace [^;]+;/', 'namespace '.$namespace.';', $code, 1);
    file_put_contents($dst, $code);
    unlink($src);
    echo "OK {$to}\n";
}

$replacements = [
    'App\\Http\\Controllers\\Api\\ExpressionBesoinController' => 'App\\Domains\\Needs\\Http\\Controllers\\ExpressionBesoinController',
    'App\\Http\\Controllers\\Api\\BudgetLineController' => 'App\\Domains\\Budget\\Http\\Controllers\\BudgetLineController',
    'App\\Http\\Controllers\\Api\\ActorController' => 'App\\Shared\\Auth\\Http\\ActorController',
    'App\\Http\\Middleware\\ResolveActor' => 'App\\Shared\\Auth\\ResolveActor',
    'App\\Http\\Requests\\ReturnExpressionBesoinRequest' => 'App\\Domains\\Needs\\Http\\Requests\\ReturnExpressionBesoinRequest',
    'App\\Http\\Requests\\UpdateExpressionBesoinRequest' => 'App\\Domains\\Needs\\Http\\Requests\\UpdateExpressionBesoinRequest',
    'App\\Http\\Requests\\StoreExpressionBesoinRequest' => 'App\\Domains\\Needs\\Http\\Requests\\StoreExpressionBesoinRequest',
    'App\\Http\\Resources\\ExpressionBesoinResource' => 'App\\Domains\\Needs\\Http\\Resources\\ExpressionBesoinResource',
    'App\\Http\\Resources\\EbLineResource' => 'App\\Domains\\Needs\\Http\\Resources\\EbLineResource',
    'App\\Notifications\\EbWorkflowNotification' => 'App\\Domains\\Needs\\Notifications\\EbWorkflowNotification',
    'App\\Exports\\ExpressionsBesoinExport' => 'App\\Domains\\Needs\\Exports\\ExpressionsBesoinExport',
    'App\\Services\\ExpressionBesoinWorkflow' => 'App\\Domains\\Needs\\Services\\ExpressionBesoinWorkflow',
    'App\\Models\\ExpressionBesoin' => 'App\\Domains\\Needs\\Models\\ExpressionBesoin',
    'App\\Models\\EbImputation' => 'App\\Domains\\Needs\\Models\\EbImputation',
    'App\\Models\\EbDocument' => 'App\\Domains\\Needs\\Models\\EbDocument',
    'App\\Models\\EbEvent' => 'App\\Domains\\Needs\\Models\\EbEvent',
    'App\\Models\\EbLine' => 'App\\Domains\\Needs\\Models\\EbLine',
    'App\\Models\\OrganizationUnit' => 'App\\Domains\\Organization\\Models\\OrganizationUnit',
    'App\\Models\\PapEnrichment' => 'App\\Domains\\PAP\\Models\\PapEnrichment',
    'App\\Models\\BudgetLine' => 'App\\Domains\\Budget\\Models\\BudgetLine',
    'App\\Models\\Engagement' => 'App\\Domains\\Commitments\\Models\\Engagement',
    'App\\Models\\PapTask' => 'App\\Domains\\PAP\\Models\\PapTask',
    'App\\Models\\Exercice' => 'App\\Domains\\Budget\\Models\\Exercice',
    'App\\Enums\\BudgetNature' => 'App\\Domains\\Budget\\Enums\\BudgetNature',
    'App\\Enums\\EbStatus' => 'App\\Domains\\Needs\\Enums\\EbStatus',
];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
        continue;
    }
    $code = file_get_contents($path);
    $updated = str_replace(array_keys($replacements), array_values($replacements), $code);
    if ($updated !== $code) {
        file_put_contents($path, $updated);
        echo "MAJ {$path}\n";
    }
}

echo "Termine\n";
