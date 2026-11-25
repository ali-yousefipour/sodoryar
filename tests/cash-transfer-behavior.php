<?php
declare(strict_types=1);
// In-memory transaction double exercises posting/replay/rollback without production data.
final class TransferTestDb {
    public array $transfers=[],$legs=[];
    public array $shifts=[1=>['id'=>1,'status'=>'open','opening_cash'=>1000,'branch_id'=>10,'is_active'=>1],2=>['id'=>2,'status'=>'open','opening_cash'=>0,'branch_id'=>20,'is_active'=>1]];
    public array $allowed=[10,20];
    public bool $failDestination=false;
    private ?array $snapshot=null;
    public function beginTransaction(): void {$this->snapshot=[$this->transfers,$this->legs];}
    public function commit(): void {$this->snapshot=null;}
    public function rollBack(): void {[$this->transfers,$this->legs]=$this->snapshot;$this->snapshot=null;}
    public function inTransaction(): bool {return $this->snapshot!==null;}
    public function lastInsertId(): string {return (string)count($this->transfers);}
}
$testDb=new TransferTestDb;
function db(): TransferTestDb {global $testDb;return $testDb;}
function require_permission(string $permission): void {}
function branches_ready(): bool {return true;}
function database_table_exists(string $table): bool {return true;}
function branch_require(int $tenant,int $actor,mixed $branch): int {
    if($tenant!==1 || !in_array($branch,db()->allowed,true))throw new RuntimeException('Unauthorized branch');
    return $branch;
}
function audit(string $action,string $payload): void {}
function query(string $sql,array $params=[]): object {
    $rows=[];
    if(str_starts_with($sql,'SELECT s.id,s.status')){
        if($params[0]===1)foreach(db()->shifts as $id=>$row)if(in_array($id,[$params[1],$params[2]],true))$rows[]=$row;
    }elseif(str_starts_with($sql,'SELECT id,source_shift_id')){
        foreach(db()->transfers as $row)if($row['request_token']===$params[1])$rows[]=$row;
    }elseif(str_starts_with($sql,'SELECT COUNT(p.id)')){$rows=[['receipts'=>0,'cash'=>0,'pos'=>0]];
    }elseif(str_contains($sql,'SUM(CASE WHEN direction=')){
        $row=['incoming'=>0,'outgoing'=>0];foreach(db()->legs as $leg)if($leg['shift']===$params[1])$row[$leg['direction']==='in'?'incoming':'outgoing']+=$leg['amount'];$rows=[$row];
    }elseif(str_starts_with($sql,'INSERT INTO cash_transfers')){
        db()->transfers[]=['id'=>count(db()->transfers)+1,'source_shift_id'=>$params[1],'destination_shift_id'=>$params[2],'amount'=>$params[3],'reason'=>$params[4],'request_token'=>$params[6]];
    }elseif(str_starts_with($sql,'INSERT INTO cash_shift_adjustments')){
        if(db()->failDestination && $params[2]==='in')throw new RuntimeException('Simulated destination failure');
        db()->legs[]=['shift'=>$params[1],'direction'=>$params[2],'amount'=>$params[3],'transfer'=>$params[6]];
    }else throw new LogicException('Unexpected query: '.$sql);
    return new class($rows) {
        public function __construct(private array $rows){}
        public function fetchAll(): array {return $this->rows;}
        public function fetch(): array|false {return $this->rows[0]??false;}
    };
}
require dirname(__DIR__).'/src/cashbox.php';
function check(bool $condition,string $message): void {if(!$condition)throw new LogicException($message);}
function rejected(callable $operation): void {try{$operation();}catch(RuntimeException $ex){return;}throw new LogicException('Invalid transfer accepted');}
$token=str_repeat('a',64);
$id=cashbox_transfer(1,1,2,7,300,'انتقال روزانه',$token);
check($id===1 && count(db()->legs)===2,'Expected one transfer and two movements');
check(db()->legs[0]['direction']==='out' && db()->legs[1]['direction']==='in','Wrong movement directions');
check(db()->legs[0]['amount']===db()->legs[1]['amount'],'Unbalanced transfer');
check(cashbox_transfer(1,1,2,7,300,'انتقال روزانه',$token)===$id && count(db()->legs)===2,'Replay duplicated posting');
rejected(fn()=>cashbox_transfer(1,1,2,7,301,'انتقال روزانه',$token));
rejected(fn()=>cashbox_transfer(1,1,2,7,701,'بیش از موجودی',str_repeat('b',64)));
rejected(fn()=>cashbox_transfer(1,1,1,7,10,'همان صندوق',str_repeat('b',64)));
rejected(fn()=>cashbox_transfer(2,1,2,7,10,'شرکت دیگر',str_repeat('b',64)));
db()->allowed=[10];rejected(fn()=>cashbox_transfer(1,1,2,7,10,'شعبه غیرمجاز',str_repeat('b',64)));db()->allowed=[10,20];
db()->shifts[2]['status']='pending';rejected(fn()=>cashbox_transfer(1,1,2,7,10,'صندوق بسته',str_repeat('b',64)));db()->shifts[2]['status']='open';
db()->failDestination=true;rejected(fn()=>cashbox_transfer(1,1,2,7,10,'آزمون بازگشت',str_repeat('b',64)));
check(count(db()->legs)===2 && count(db()->transfers)===1 && !db()->inTransaction(),'Failure left partial postings');
echo "Cash transfer posting, replay, balance, authorization and rollback tests passed\n";
