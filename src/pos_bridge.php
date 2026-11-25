<?php
declare(strict_types=1);
// Fail-closed core: this is NOT a bank-approved payment confirmation service.
function pos_bridge_identity(): array {
    $header=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
    if(!preg_match('/^Bearer ([A-Za-z0-9_-]{32,128})$/D',$header,$m))throw new RuntimeException('Unauthorized');
    $row=query('SELECT id,company_id,branch_id FROM pos_bridges WHERE token_hash=? AND enabled=1 LIMIT 1',[hash('sha256',$m[1])])->fetch();
    if(!$row)throw new RuntimeException('Unauthorized');
    return array_map('intval',$row);
}
function pos_bridge_poll(array $b): ?array {
    db()->beginTransaction();
    try {
        query('UPDATE pos_bridges SET last_seen_at=NOW() WHERE id=? AND company_id=? AND branch_id=?',[$b['id'],$b['company_id'],$b['branch_id']]);
        $expired=query("SELECT id FROM pos_payment_requests WHERE bridge_id=? AND company_id=? AND branch_id=? AND status='processing' AND lease_expires_at<NOW() FOR UPDATE",[$b['id'],$b['company_id'],$b['branch_id']])->fetchAll(PDO::FETCH_COLUMN);
        foreach($expired as $id){
            query("UPDATE pos_payment_requests SET status='unknown',lease_expires_at=NULL WHERE id=?",[$id]);
            query("INSERT INTO pos_transaction_events(request_id,event_type) VALUES(?,'lease_expired_unknown')",[$id]);
        }
        $row=query("SELECT r.id,r.amount_irr,r.request_key,t.psp,t.connection_mode,t.lan_ip,t.lan_port,t.psp_terminal_number FROM pos_payment_requests r JOIN pos_terminals t ON t.id=r.terminal_id AND t.company_id=r.company_id AND t.branch_id=r.branch_id WHERE r.bridge_id=? AND r.company_id=? AND r.branch_id=? AND r.status='queued' AND t.enabled=1 AND t.bridge_id=? ORDER BY r.id LIMIT 1 FOR UPDATE",[$b['id'],$b['company_id'],$b['branch_id'],$b['id']])->fetch();
        if($row){
            query("UPDATE pos_payment_requests SET status='processing',lease_expires_at=DATE_ADD(NOW(),INTERVAL 120 SECOND) WHERE id=?",[$row['id']]);
            query("INSERT INTO pos_transaction_events(request_id,event_type) VALUES(?,'leased')",[$row['id']]);
        }
        db()->commit();return $row?:null;
    }catch(Throwable $e){db()->rollBack();throw $e;}
}
function pos_bridge_report(array $b,array $data): array {
    $id=filter_var($data['request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $result=$data['result']??null;
    if(!$id||!is_string($result)||!in_array($result,['approved','declined','cancelled','unknown'],true))throw new InvalidArgumentException('Invalid result');
    $amount=(string)($data['amount_irr']??'');
    if(!preg_match('/^[1-9][0-9]{0,19}$/D',$amount))throw new InvalidArgumentException('Invalid amount');
    db()->beginTransaction();
    try {
        $req=query('SELECT id,status,amount_irr FROM pos_payment_requests WHERE id=? AND bridge_id=? AND company_id=? AND branch_id=? FOR UPDATE',[$id,$b['id'],$b['company_id'],$b['branch_id']])->fetch();
        if(!$req||$req['status']!=='processing')throw new RuntimeException('Request not pending');
        // Only failure/cancellation with matching amount can be terminal here;
        // even a device-reported approval is UNKNOWN pending authoritative verification.
        $status=($result==='approved'||$result==='unknown'||(string)$req['amount_irr']!==$amount)?'unknown':$result;
        query('UPDATE pos_payment_requests SET status=?,lease_expires_at=NULL WHERE id=?',[$status,$id]);
        query('INSERT INTO pos_transactions(request_id,company_id,branch_id,psp_status,returned_amount_irr) VALUES(?,?,?,?,?)',[$id,$b['company_id'],$b['branch_id'],$result,$amount]);
        query('INSERT INTO pos_transaction_events(request_id,event_type) VALUES(?,?)',[$id,'transport_'.$status]);
        if($status==='unknown')query("INSERT INTO pos_reconciliation_records(request_id,resolution) VALUES(?,'pending') ON DUPLICATE KEY UPDATE request_id=request_id",[$id]);
        db()->commit();return ['status'=>$status,'financial_posted'=>false];
    }catch(Throwable $e){db()->rollBack();throw $e;}
}
