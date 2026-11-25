<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/localization.php';
require dirname(__DIR__).'/src/invoice_payments.php';
require dirname(__DIR__).'/src/expenses.php';

[$items,$total]=expense_items_normalize([
    ['title'=>'چای','quantity'=>'۲','unit'=>'بسته','price'=>'۲۵۰٬۰۰۰'],
    ['title'=>'قند','quantity'=>'٣','unit'=>'بسته','price'=>'۱۰۰٬۰۰۰'],
]);
if($total!==800000||$items[0][5]!==500000||$items[1][5]!==300000)throw new RuntimeException('Expense integer calculation failed');
foreach([
    [],
    [['title'=>'invalid','quantity'=>'0','price'=>'1']],
    [['title'=>'invalid','quantity'=>'1','price'=>'0']],
    [['title'=>'invalid','quantity'=>'1000001','price'=>'1']],
    [['title'=>'invalid','quantity'=>'2','price'=>(string)PHP_INT_MAX]],
    [['title'=>'invalid','quantity'=>'1','price'=>(string)PHP_INT_MAX],['title'=>'overflow','quantity'=>'1','price'=>'1']],
    [['title'=>'invalid','quantity'=>'1e6','price'=>'1']],
    [['title'=>'invalid','quantity'=>'1','price'=>'1,00']],
    [['title'=>'','quantity'=>'1','price'=>'1']],
    ['invalid'],
] as $invalid){
    $rejected=false;try{expense_items_normalize($invalid);}catch(RuntimeException $ex){$rejected=true;}
    if(!$rejected)throw new RuntimeException('Invalid expense item or overflow accepted');
}
foreach(['expenses.view','expenses.create','expenses.export'] as $permission)
    if(!operator_permission_assignable($permission))throw new RuntimeException('Expense permission cannot be delegated independently');
echo "PASS: expense exact Persian/Arabic amounts, invalid inputs, overflow and independent grants\n";
