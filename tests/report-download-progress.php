<?php
declare(strict_types=1);
$client=(string)file_get_contents(__DIR__.'/../public/assets/report-progress.js');
$filter=(string)file_get_contents(__DIR__.'/../src/reporting.php');
$layout=(string)file_get_contents(__DIR__.'/../public/index.php');
$required=[
 'real bytes'=>str_contains($client,'loaded+=value.byteLength'),
 'content length'=>str_contains($client,"response.headers.get('Content-Length')"),
 'incomplete response detected'=>str_contains($client,'loaded!==total'),
 'MIME verification'=>str_contains($client,"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"),
 'AJAX progressive loading'=>str_contains($client,'response.body.getReader'),
 'localized percentage'=>str_contains($client,"toLocaleString('fa-IR')"),
 'status announcer'=>str_contains($filter,'data-report-progress role="status" aria-live="polite"'),
 'script load'=>str_contains($layout,'report-progress.js'),
];
foreach($required as $name=>$found) {
 if(!$found) throw new RuntimeException('Missing report progress contract: '.$name);
}
echo "Real-byte report progress checks passed\n";
