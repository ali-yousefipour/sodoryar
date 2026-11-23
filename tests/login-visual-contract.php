<?php
declare(strict_types=1);

$index=(string)file_get_contents(__DIR__.'/../public/index.php');
$css=(string)file_get_contents(__DIR__.'/../public/assets/login-experience.css');
if (!str_contains($index, "if (\$page==='login'): ?><link rel=\"stylesheet\" href=\"assets/login-experience.css")
    || !str_contains($index, "hash_file('sha256',__DIR__.'/assets/login-experience.css')")) {
    throw new RuntimeException('The branded login stylesheet must be login-only and cache-busted.');
}
foreach (['.login-layout','.login-showcase','.login-card','.login-form','.captcha-question','.login-submit','@media(max-width:850px)','@media(max-width:430px)','@media(prefers-reduced-motion:reduce)'] as $required) {
    if (!str_contains($css,$required)) throw new RuntimeException('Login redesign is missing style contract: '.$required);
}
foreach (['name="username"','name="password"','name="captcha_answer"','<?=csrf_field()?>','role="alert"','autocomplete="current-password"'] as $required) {
    if (!str_contains($index,$required)) throw new RuntimeException('Login security/accessibility contract changed: '.$required);
}
$browser=(string)file_get_contents(__DIR__.'/../scripts/browser-financial-e2e.py');
foreach (['wait_until="domcontentloaded"', 'css_response.status == 200', 'b".login-layout" in css_response.body()', 'for width in (320, 390, 768, 1440)'] as $required) {
    if (!str_contains($browser,$required)) throw new RuntimeException('Sign-in browser QA is missing a required production-safe check: '.$required);
}
if (str_contains($browser, 'wait_until="networkidle"')) {
    throw new RuntimeException('Login browser QA must not depend on external font network idleness.');
}
echo "Responsive scoped sign-in design and security form contracts passed\n";
