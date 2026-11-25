<?php
declare(strict_types=1);
function e(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function csrf_field(): string {return '<input type="hidden" name="csrf" value="fixture">';}
function can(string $permission): bool {return true;}
function query(string $sql,array $params=[]): object {return new class {function fetchAll(): array{return [['id'=>1,'name'=>'هزینه اداری','accounting_type'=>'expense']];}};}
require dirname(__DIR__).'/src/expense_page.php';
$_SERVER['REQUEST_METHOD']='GET';$_GET=[];$_SESSION=[];
ob_start();
render_expense_wizard(1,[['id'=>1,'name'=>'مرکزی'],['id'=>2,'name'=>'شعبه دوم']],[['id'=>11,'name'=>'صندوق مرکزی','branch_id'=>1],['id'=>22,'name'=>'صندوق دوم','branch_id'=>2]],[['id'=>31,'name'=>'حساب شرکت','branch_id'=>null]]);
$html=(string)ob_get_clean();
if(($argv[1]??'')==='--render'){echo $html;exit;}
if(substr_count($html,'data-expense-stage=')!==4 || !str_contains($html,'data-expense-date-proxy') || !str_contains($html,'name="expense_token"'))throw new RuntimeException('Expense wizard structure incomplete');
echo "Expense wizard rendering contract passed\n";
