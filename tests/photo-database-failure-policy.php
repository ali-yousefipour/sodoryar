<?php
declare(strict_types=1);

// Regression: a missing optional table is expected, but database outages and
// permission errors must never be treated as a missing photo migration.
$source=(string)file_get_contents(__DIR__.'/../src/photos.php');
$start=strpos($source, 'function user_photo_table_ready(): bool {');
$end=strpos($source, '\nfunction validate_photo_target(', $start === false ? 0 : $start);
if ($start===false || $end===false) {
    throw new RuntimeException('Photo-table readiness implementation is missing.');
}
$function=substr($source,$start,$end-$start);
foreach ([
    "if ((string)\$ex->getCode() === '42S02') \$ready=false;",
    'else throw $ex;'
] as $contract) {
    if (!str_contains($function,$contract)) {
        throw new RuntimeException('Photo storage must distinguish missing schema from operational database failures.');
    }
}
echo "Photo storage database failure policy passed\n";
