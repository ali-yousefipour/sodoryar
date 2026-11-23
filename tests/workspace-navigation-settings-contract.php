<?php
declare(strict_types=1);

$index=(string)file_get_contents(__DIR__.'/../public/index.php');
$modules=(string)file_get_contents(__DIR__.'/../src/modules.php');
$css=(string)file_get_contents(__DIR__.'/../public/assets/workspace-refresh.css');
$workflow=(string)file_get_contents(__DIR__.'/../.github/workflows/deploy-shared-host.yml');

foreach (['نمای کلی','عملیات و صدور','گزارش‌ها و نظارت','کاربران و دسترسی','تنظیمات و پیکربندی','حساب کاربری'] as $group) {
    if (!str_contains($index, $group)) throw new RuntimeException('Missing menu category: '.$group);
}
foreach (["can('customers.view')","can('invoices.view')","can('reports.view')","user()['role']!=='operator'","can('pos.view')"] as $guard) {
    if (!str_contains($index, $guard)) throw new RuntimeException('Navigation access guard missing: '.$guard);
}
if (!str_contains($index, "if (\$page!=='login'): ?><link rel=\"stylesheet\" href=\"assets/workspace-refresh.css")
    || !str_contains($index, "hash_file('sha256',__DIR__.'/assets/workspace-refresh.css')")) {
    throw new RuntimeException('Application-only workspace stylesheet must be versioned.');
}
foreach (['settings-workspace','settings-index','settings-form','settings-savebar','id="settings-company"','id="settings-commission"','id="settings-invoice"'] as $marker) {
    if (!str_contains($modules, $marker)) throw new RuntimeException('Settings layout incomplete: '.$marker);
}
foreach (['company_name','company_phone','company_website','company_address',
          'commission_enabled','commission_type','commission_rate','commission_period',
          'commission_limit','commission_over_limit_rate','currency','invoice_footer'] as $name) {
    if (!str_contains($modules,'name="'.$name.'"')) throw new RuntimeException('Settings field missing: '.$name);
}
foreach (['.settings-workspace','.settings-index','.settings-panel','.settings-savebar',
          '@media(max-width:900px)','@media(max-width:620px)','@media(prefers-reduced-motion:reduce)'] as $style) {
    if (!str_contains($css,$style)) throw new RuntimeException('Workspace CSS contract missing: '.$style);
}
if (!str_contains($workflow,'login-experience.css workspace-refresh.css login-ui.js app.js')) {
    throw new RuntimeException('Live workspace stylesheet must be hash-verified during deploy.');
}
echo "Navigation, settings form, responsive workspace and publication contracts passed\n";
