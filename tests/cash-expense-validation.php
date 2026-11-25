<?php
declare(strict_types=1);
function require_permission(string $permission): void {}
function query(string $sql,array $params=[]): object { return new class { function fetch(): array {return ['Field'=>'expense_branch_id'];} }; }
function branches_ready(): bool {return true;}
require dirname(__DIR__).'/src/cashbox.php';
foreach ([[0,'هزینه معتبر',str_repeat('a',64)],[100,'کم',str_repeat('a',64)],[100,'هزینه معتبر','invalid']] as [$amount,$reason,$token]) {
    try {cashbox_add_expense(1,1,1,$amount,$reason,$token);throw new LogicException('Invalid expense accepted');}
    catch(RuntimeException $ex) {if($ex instanceof LogicException)throw $ex;}
}
echo "Cash expense boundary validation passed\n";
