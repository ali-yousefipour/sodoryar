<?php
declare(strict_types=1);

/** Append-only issue review journal. A resolved annotation never removes the detected exception. */
function financial_issue_reviews_ready(): bool {
    static $ready=null;
    if($ready===null)$ready=(bool)query("SHOW TABLES LIKE 'financial_issue_reviews'")->fetchColumn();
    return $ready;
}
function financial_issue_fingerprint(int $tenant,array $issue): string {
    return hash('sha256',$tenant.'|'.($issue['kind']??'').'|'.($issue['invoice']??'').'|'.($issue['detail']??''));
}
function financial_issue_latest_reviews(int $tenant,array $issues): array {
    if(!financial_issue_reviews_ready()||!$issues)return [];
    $keys=array_values(array_unique(array_map(static fn($i)=>financial_issue_fingerprint($tenant,$i),$issues)));
    $marks=implode(',',array_fill(0,count($keys),'?'));
    $rows=query('SELECT issue_key,status,note,reviewed_at,reviewed_by FROM financial_issue_reviews
        WHERE company_id=? AND issue_key IN ('.$marks.') ORDER BY id DESC',array_merge([$tenant],$keys))->fetchAll();
    $latest=[];
    foreach($rows as $row)if(!isset($latest[$row['issue_key']]))$latest[$row['issue_key']]=$row;
    return $latest;
}
function financial_issue_review_submit(int $tenant,int $actor,string $key,string $status,string $note,string $start,string $end): void {
    require_permission('finance.reconcile');
    if(!financial_issue_reviews_ready())throw new RuntimeException('ثبت بررسی مالی نیازمند اجرای مهاجرت ۰۲۲ است.');
    if(!preg_match('/^[a-f0-9]{64}$/D',$key)||!in_array($status,['investigating','resolved','reopened'],true)
       ||mb_strlen($note)<5||mb_strlen($note)>500)throw new RuntimeException('نتیجه یا توضیح مغایرت نامعتبر است.');
    $filters=finance_preset_input(['report_type'=>'finance_exceptions','date_from'=>$start,'date_to'=>$end]);
    $issues=financial_control_exceptions($tenant,['date_from'=>$filters['from'],'date_to'=>$filters['to']],200);
    $match=null;
    foreach($issues as $issue)if(hash_equals(financial_issue_fingerprint($tenant,$issue),$key)){$match=$issue;break;}
    if(!$match)throw new RuntimeException('مغایرت انتخاب‌شده در بازه بررسی‌شده وجود ندارد؛ گزارش را به‌روزرسانی کنید.');
    db()->beginTransaction();
    try{
        query('INSERT INTO financial_issue_reviews
            (company_id,issue_key,issue_type,invoice_number,issue_summary,status,note,reviewed_by)
            VALUES(?,?,?,?,?,?,?,?)',[$tenant,$key,$match['kind'],$match['invoice'],
                mb_substr($match['detail'],0,700),$status,$note,$actor]);
        audit('finance.issue.review',json_encode(['issue_key'=>$key,'status'=>$status,'note'=>$note],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        db()->commit();
    }catch(Throwable $ex){db()->rollBack();throw $ex;}
}
