<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/localization.php';
require __DIR__.'/../src/modules.php';

$accepted=['123456','1234567','12345678','123456789','1234567890','0000000000'];
$rejected=['','12345','12345678901','12345x','12_4567','123456%','123456_','１２３４５６','123456 ','-123456'];
foreach ($accepted as $value) {
    if (!valid_driver_lookup_prefix($value)) { fwrite(STDERR,'Rejected valid prefix: '.$value.PHP_EOL); exit(1); }
}
foreach ($rejected as $value) {
    if (valid_driver_lookup_prefix($value)) { fwrite(STDERR,'Accepted invalid prefix: '.$value.PHP_EOL); exit(1); }
}
echo "Driver national-code prefix validation passed.".PHP_EOL;
