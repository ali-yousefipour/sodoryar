<?php
declare(strict_types=1);
final class SettlementTestDb {
 public array $headers=[],$items=[];
 public bool $failItems=false,$accountValid=true;
 public array $receipts=[['id'=>1,'amount'=>200,'branch_id'=>10,'paid_at'=>'2026-01-01 10:00:00'],['id'=>2,'amount'=>300,'branch_id'=>10,'paid_at'=>'2026-01-01 10:00:00']];
 private ?array $snapshot=null;
 public function beginTransaction(): void {$this->snapshot=[$this->headers,$this->items];}
 public function commit(): void {$this->snapshot=null;}
 public function rollBack(): void {[$this->headers,$this->items]=$this->snapshot;$this->snapshot=null;}
 public function inTransaction(): bool {return $this->snapshot!==null;}
 public function lastInsertId(): string {return (string)count($this->headers);}
}
$settleDb=new SettlementTestDb;
function db(): SettlementTestDb {global $settleDb;return $settleDb;}
function require_admin(): void {}
function database_table_exists(string $table): bool {return true;}
function branch_require(int $tenant,int $actor,mixed $branch): int {if($branch!==10)throw new RuntimeException('Unauthorized branch');return $branch;}
function audit(string $event,string $data): void {}
function query(string $sql,array $params=[]): object {
 $rows=[];
 if(str_starts_with($sql,'SELECT p.id FROM pos_settings')){if(db()->accountValid && $params===[1,4,5])$rows=[['id'=>4]];}
 elseif(str_starts_with($sql,'SELECT id,request_digest')){foreach(db()->headers as $header)if($header['key']===$params[1])$rows[]=$header;}
 elseif(str_starts_with($sql,'SELECT p.id,p.amount')){if($params[0]===1 && $params[1]===4)foreach(db()->receipts as $receipt)if(in_array($receipt['id'],array_slice($params,2),true))$rows[]=$receipt;}
 elseif(str_starts_with($sql,'SELECT payment_id')){foreach(db()->items as $item)if(in_array($item['payment_id'],array_slice($params,1),true))$rows[]=$item;}
 elseif(str_starts_with($sql,'INSERT INTO pos_bank_settlements')){db()->headers[]=['id'=>count(db()->headers)+1,'key'=>$params[6],'request_digest'=>$params[7]];}
 elseif(str_starts_with($sql,'INSERT INTO pos_bank_settlement_items')){if(db()->failItems)throw new RuntimeException('Simulated item failure');db()->items[]=['payment_id'=>$params[2],'amount'=>$params[3]];}
 elseif(str_starts_with($sql,'SELECT x.payment_id')){if(db()->items)$rows=[['payment_id'=>1]];}
 else throw new LogicException('Unexpected query '.$sql);
 return new class($rows){public function __construct(private array $rows){} public function fetch(): array|false{return $this->rows[0]??false;}public function fetchAll(): array{return $this->rows;}};
}
require dirname(__DIR__).'/src/pos_settlements.php';
function check_settle(bool $condition,string $message): void {if(!$condition)throw new LogicException($message);}
function reject_settle(callable $fn): void {try{$fn();}catch(RuntimeException $ex){return;}throw new LogicException('Invalid settlement accepted');}
$key=str_repeat('a',64);
$id=pos_settlement_record(1,8,4,5,[2,1],500,'2026-01-02','BANK-1',$key);
check_settle($id===1 && count(db()->items)===2,'Expected one header and two receipt matches');
check_settle(pos_settlement_record(1,8,4,5,[1,2],500,'2026-01-02','BANK-1',$key)===1 && count(db()->items)===2,'Replay duplicated settlement');
reject_settle(fn()=>pos_settlement_record(1,8,4,5,[1,2],499,'2026-01-02','BANK-1',$key));
reject_settle(fn()=>pos_settlement_record(1,8,4,5,[1],200,'2026-01-02','BANK-2',str_repeat('b',64)));
reject_settle(fn()=>pos_settlement_record(2,8,4,5,[1],200,'2026-01-02','BANK-2',str_repeat('b',64)));
reject_settle(fn()=>pos_settlement_record(1,8,4,5,[1,1],400,'2026-01-02','BANK-2',str_repeat('b',64)));
reject_settle(fn()=>pos_settlement_record(1,8,4,5,[1],199,'2026-01-02','BANK-2',str_repeat('b',64)));
reject_settle(fn()=>pos_settlement_record(1,8,4,5,[1],200,'2025-12-31','BANK-2',str_repeat('b',64)));
db()->beginTransaction();reject_settle(fn()=>pos_assert_invoice_unsettled(1,3));db()->rollBack();
db()->receipts[]=['id'=>3,'amount'=>100,'branch_id'=>10,'paid_at'=>'2026-01-01 10:00:00'];db()->failItems=true;
reject_settle(fn()=>pos_settlement_record(1,8,4,5,[3],100,'2026-01-02','BANK-2',str_repeat('b',64)));
check_settle(count(db()->headers)===1 && count(db()->items)===2 && !db()->inTransaction(),'Item failure left partial settlement');
echo "POS bank settlement exact amounts, replay, ownership, prior settlement and rollback tests passed\n";
