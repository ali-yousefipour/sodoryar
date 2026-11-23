<?php
declare(strict_types=1);

$source=(string)file_get_contents(__DIR__.'/../public/index.php');
$start=strpos($source, '$navItems=[[\'dashboard\'');
$end=strpos($source, '$navGroup=null;', $start);
if ($start===false || $end===false) throw new RuntimeException('Navigation definition missing');
$definition=substr($source,$start,$end-$start);
$grants=[];
$role='operator';
function is_super(): bool { global $role; return $role==='superadmin'; }
function can(string $permission): bool { global $grants; return in_array($permission,$grants,true); }
function user(): array { global $role; return ['role'=>$role]; }

foreach (['superadmin','company_admin','operator'] as $role) {
    foreach ([false,true] as $tenant) {
        foreach ([[],['customers.view','invoices.view','invoices.create','periods.manage','bills.view','reports.view','pos.view']] as $grants) {
            eval($definition);
            $seen=[];$previous=null;$pages=[];
            foreach ($navItems as [$page,$label,$icon,$category]) {
                if ($category!==$previous && isset($seen[$category])) throw new RuntimeException('Repeated navigation category: '.$category);
                if (isset($pages[$page])) throw new RuntimeException('Duplicate navigation destination');
                $seen[$category]=true;$previous=$category;$pages[$page]=true;
            }
            if (!$tenant && (isset($pages['invoices']) || isset($pages['customers']))) throw new RuntimeException('Tenant links leaked to global context');
            if (!$grants && isset($pages['invoices'])) throw new RuntimeException('Invoice permission ignored');
            if ($role==='operator' && (isset($pages['roles']) || isset($pages['settings']))) throw new RuntimeException('Manager links leaked to operator');
        }
    }
}
echo "Navigation categories remain contiguous without changing role/permission guards\n";
