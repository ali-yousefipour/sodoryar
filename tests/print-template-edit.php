<?php
declare(strict_types=1);
$modules=(string)file_get_contents(__DIR__.'/../src/modules.php');
$checks=[
 "if (\$page === 'templates' && \$action === 'edit')",
 "require_admin();",
 "SELECT id,title,paper_type,header_text,footer_text,layout_json FROM print_templates WHERE id=? AND company_id=? FOR UPDATE",
 "UPDATE print_templates SET title=?,paper_type=?,header_text=?,footer_text=?,layout_json=? WHERE id=? AND company_id=?",
 "audit('template.edit'",
 "data-dialog-open=\"editTemplate",
 "name=\"roll_width_mm\"",
 "Migration شماره ۰۱۲"
];
foreach($checks as $check) {
    if(!str_contains($modules,$check))throw new RuntimeException('Template edit contract missing: '.$check);
}
echo "Company print-template editing contracts passed\n";
