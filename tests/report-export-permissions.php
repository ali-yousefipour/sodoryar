<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/bootstrap.php';

$catalog=permission_catalog();
foreach (['reports.view','reports.export','activity.view','activity.export'] as $key) {
    if (!array_key_exists($key,$catalog)) {
        throw new RuntimeException('Missing permission: '.$key);
    }
}
if ($catalog['reports.view']===$catalog['reports.export']) {
    throw new RuntimeException('Financial view and export labels should be distinct.');
}

$index=file_get_contents(dirname(__DIR__).'/public/index.php');
$reporting=file_get_contents(dirname(__DIR__).'/src/reporting.php');
if ($index===false || $reporting===false) {
    throw new RuntimeException('Unable to load report sources.');
}
if (!str_contains($index,"require_permission(\$page==='reports' ? 'reports.export' : 'activity.export')")) {
    throw new RuntimeException('Excel export is missing the mandatory server-side permission guard.');
}
if (!str_contains($reporting,"can(\$page === 'reports' ? 'reports.export' : 'activity.export')")) {
    throw new RuntimeException('Export controls are not gated by the effective permission.');
}

echo "Independent Excel export permission regression checks passed\n";
