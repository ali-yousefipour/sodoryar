<?php
declare(strict_types=1);
$controller=(string)file_get_contents(__DIR__.'/../public/index.php');
$expected=[
 "if ((int)\$reportData['summary']['qty']>10000)",
 "activity_report(\$tenant,\$reportFilter,10001)",
 "if (count(\$rows)>10000)"
];
foreach($expected as $needle) {
    if(!str_contains($controller,$needle)) throw new RuntimeException('Missing Excel completeness guard: '.$needle);
}
echo "Excel export completeness contracts passed\n";
