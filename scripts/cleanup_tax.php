<?php
$files = [
    'app/Http/Controllers/Api/General/TaxController.php',
    'app/Http/Requests/Tax/StoreTaxRequest.php',
    'app/Http/Requests/Tax/UpdateTaxRequest.php',
    'app/Http/Requests/Tax/UpdateOrderTaxOverrideRequest.php',
    'app/Http/Resources/Tax/TaxResource.php',
    'app/Services/Tax/TaxClassMap.php',
    'app/Services/Tax/TaxResolver.php',
    'app/Services/Tax/TaxService.php',
    'app/Enums/TaxMode.php',
    'app/Enums/TaxOverrideType.php',
    'app/DTOs/Tax/TaxResolution.php',
];
foreach ($files as $f) {
    $p = __DIR__.'/../'.$f;
    if (file_exists($p)) {
        unlink($p);
        echo "deleted $f\n";
    } else {
        echo "not found $f\n";
    }
}
$dirs = ['app/Http/Requests/Tax','app/Http/Resources/Tax'];
foreach ($dirs as $d) {
    $p = __DIR__.'/../'.$d;
    if (is_dir($p) && count(scandir($p))==2) { rmdir($p); echo "rmdir $d\n"; }
}
