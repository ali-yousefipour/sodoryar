<?php
declare(strict_types=1);
$bootstrap=(string)file_get_contents(__DIR__.'/../src/bootstrap.php');
$client=(string)file_get_contents(__DIR__.'/../public/assets/app.js');
if (!str_contains($bootstrap,"img-src \\'self\\' data: blob:;")) {
    throw new RuntimeException('CSP must allow local blob image previews.');
}
if (!str_contains($client,'URL.createObjectURL(file)')) {
    throw new RuntimeException('Logo preview object URL not found.');
}
if (!str_contains($client,'URL.revokeObjectURL(previewUrl)')) {
    throw new RuntimeException('Logo object URL cleanup missing.');
}
echo "CSP logo preview contract passed\n";
