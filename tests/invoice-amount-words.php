<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/localization.php';
$cases=[
 0=>'صفر تومان',
 1=>'یک تومان',
 10=>'ده تومان',
 11=>'یازده تومان',
 20=>'بیست تومان',
 101=>'صد و یک تومان',
 1000=>'یک هزار تومان',
 1234=>'یک هزار و دویست و سی و چهار تومان',
 1000000=>'یک میلیون تومان',
 230405=>'دویست و سی هزار و چهارصد و پنج تومان'
];
foreach($cases as $number=>$words) {
    $actual=toman_in_words($number);
    if($actual!==$words) throw new RuntimeException("Incorrect Persian words for $number: $actual");
}
try { toman_in_words(-1); throw new RuntimeException('Negative amount accepted.'); }
catch(InvalidArgumentException $expected) {}
echo "Persian invoice amount words tests passed\n";
