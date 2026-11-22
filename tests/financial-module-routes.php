<?php
declare(strict_types=1);

/** Prevent orphaned finance modules: navigation, routing and backend handlers must stay wired. */
$index=(string)file_get_contents(__DIR__.'/../public/index.php');
$modules=(string)file_get_contents(__DIR__.'/../src/modules.php');
$contracts=[
    "Allowed bill and POS page routes" => strpos($index, "'receipt','bills','pos','commissions'")!==false,
    "Bill and POS POST dispatcher" => strpos($index, "'profile','roles','bills','pos'],true)) handle_module_post")!==false,
    "Bill and POS render dispatcher" => strpos($index, "'profile','roles','bills','pos'],true)")!==false,
    "Bill navigation gated by permission" => strpos($index, "if (can('bills.view'))")!==false,
    "POS navigation gated by permission" => strpos($index, "if (can('pos.view'))")!==false,
    "Bill page permission guard" => strpos($modules, "'bills'=>'bills.view'")!==false,
    "POS page permission guard" => strpos($modules, "'pos'=>'pos.view'")!==false,
    "Bill POST authorizations" => strpos($modules, "require_permission('bills.create')")!==false,
    "Bill delete and restore permissions" => strpos($modules, "'permanent_delete'=>'bills.permanent_delete'")!==false,
];
foreach($contracts as $label=>$okay) {
    if(!$okay) throw new RuntimeException($label.' is not wired correctly.');
}
echo "Financial module route contracts passed\n";
