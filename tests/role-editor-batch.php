<?php
declare(strict_types=1);

/** Guard against per-role queries and cross-company grants in the IAM editor. */
$source=(string)file_get_contents(__DIR__.'/../src/modules.php');
$start=strpos($source,"if (\$page==='roles') { require_admin(); require_permission('roles.manage'); ?>");
$end=$start===false?false:strpos($source,"if (\$page==='bills')", $start);
if($start===false || $end===false) throw new RuntimeException('Role editor section is missing.');
$section=substr($source,$start,$end-$start);
if (!str_contains($section,"SELECT role_id,permission_key FROM company_role_permissions WHERE company_id=? ORDER BY role_id,permission_key")
    || !str_contains($section,"[\$tenant])->fetchAll(PDO::FETCH_ASSOC)")
    || !str_contains($section,"\$grants=\$roleGrants[(int)\$role['id']]??[]")) {
    throw new RuntimeException('Tenant-scoped batched role grants are required.');
}
if (str_contains($section,"SELECT permission_key FROM company_role_permissions WHERE role_id=? AND company_id=?")) {
    throw new RuntimeException('Per-role IAM permission query must not recur.');
}
if (substr_count($section, '<details class="permission-group"') !== 2
    || substr_count($section, '<summary><?=e($group)?></summary>') !== 2
    || substr_count($section, '</div></details><?php endforeach; ?></div></fieldset>') !== 2) {
    throw new RuntimeException('Company role permission groups must remain accessible collapsible sections in both forms.');
}
echo "Role editor batch grant query contract passed\n";
