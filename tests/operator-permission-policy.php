<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

foreach(['users.manage','roles.manage','invalid.permission'] as $key) {
    if (operator_permission_assignable($key)) {
        throw new RuntimeException('Operator can receive privileged/unknown permission: '.$key);
    }
}
foreach(['invoices.create','invoices.view','invoices.print','bills.view','bills.create'] as $key) {
    if (!operator_permission_assignable($key)) {
        throw new RuntimeException('Valid delegated permission blocked: '.$key);
    }
}
echo "Operator grant policy checks passed\n";
