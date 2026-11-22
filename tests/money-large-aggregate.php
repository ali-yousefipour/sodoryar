<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';

$cases=[
    ['0','۰'],['000','۰'],['1000','۱٬۰۰۰'],['0001234567','۱٬۲۳۴٬۵۶۷'],
    ['-1002003','-۱٬۰۰۲٬۰۰۳'],
    ['9223372036854775807','۹٬۲۲۳٬۳۷۲٬۰۳۶٬۸۵۴٬۷۷۵٬۸۰۷'],
    ['18446744073709551615','۱۸٬۴۴۶٬۷۴۴٬۰۷۳٬۷۰۹٬۵۵۱٬۶۱۵'],
];
foreach ($cases as [$input,$expected]) {
    if (money($input)!==$expected) {
        throw new RuntimeException('Money format lost precision or separators: '.$input);
    }
}
try {
    money('1.50');
    throw new RuntimeException('Fractional amounts must be rejected by the integer-only formatter.');
} catch (InvalidArgumentException $expected) {
}
echo "Exact large aggregate money formatting passed\n";
