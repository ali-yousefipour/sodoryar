<?php
declare(strict_types=1);
$html=(string)file_get_contents(__DIR__.'/../public/index.php');
$style=(string)file_get_contents(__DIR__.'/../public/assets/iam-ux.css');
if (!str_contains($html,'<progress max="<?=e((int)$max)?>" value="<?=e((int)$row[\'invoice_count\'])?>"')) {
    throw new RuntimeException('Dashboard must use native progress with bounded count.');
}
if (preg_match('/\bstyle\s*=\s*["\']/i',$html)) {
    throw new RuntimeException('Inline style attributes are blocked by the current CSP.');
}
if (!str_contains($style,'.ranking-track progress::-webkit-progress-value') ||
    !str_contains($style,'.ranking-track progress::-moz-progress-bar')) {
    throw new RuntimeException('Native progress styling missing.');
}
echo "Dashboard CSP and progress accessibility contracts passed\n";
