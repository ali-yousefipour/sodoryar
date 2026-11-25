<?php
declare(strict_types=1);
function expense_scope(int $tenant): array {
    $sql='e.company_id=?';$args=[$tenant];
    if(!expense_manager()){$sql.=' AND e.created_by=? AND EXISTS(SELECT 1 FROM user_branch_assignments a JOIN branches b ON b.id=a.branch_id AND b.company_id=a.company_id WHERE a.company_id=e.company_id AND a.branch_id=e.branch_id AND a.user_id=? AND a.is_active=1 AND b.is_active=1)';$args[]=(int)user()['id'];$args[]=(int)user()['id'];}
    return [$sql,$args];
}
function expense_handle_post(string $action): never {
    if($action==='create'){$id=expense_create($_POST);if(isset($_POST['print'])){header('Location: ?page=expenses&detail='.$id.'&print=1');exit;}redirect('expenses','هزینه در انتظار تأیید ثبت شد.');}
    if($action==='review'){expense_review((int)($_POST['id']??0),(string)($_POST['decision']??''),trim((string)($_POST['reason']??'')));redirect('expenses','تصمیم ثبت شد.');}
    if($action==='settle'){expense_settle((int)($_POST['id']??0),$_POST);redirect('expenses','تسویه ثبت شد.');}
    if($action==='void'){expense_void((int)($_POST['id']??0),trim((string)($_POST['reason']??'')));redirect('expenses','سند با حفظ سوابق باطل شد.');}
    if($action==='category'){
        require_admin();$tenant=tenant_id();$name=trim((string)($_POST['name']??''));$class=(string)($_POST['classification']??'');
        if($name===''||mb_strlen($name)>160||!in_array($class,['expense','asset','inventory','prepayment'],true))throw new RuntimeException('دسته‌بندی نامعتبر است.');
        $id=(int)($_POST['id']??0);
        if($id>0)query('UPDATE expense_categories SET name=?,is_active=? WHERE company_id=? AND id=?',[$name,isset($_POST['active'])?1:0,$tenant,$id]);
        else query('INSERT INTO expense_categories(company_id,name,classification) VALUES(?,?,?)',[$tenant,$name,$class]);
        redirect('expenses','دسته‌بندی ذخیره شد.');
    }
    throw new RuntimeException('عملیات نامعتبر است.');
}
function expense_status(string $s): string {return ['pending'=>'در انتظار تأیید','approved'=>'تأییدشده','rejected'=>'ردشده','draft'=>'پیش‌نویس','void'=>'ابطال‌شده'][$s]??$s;}
function expense_method(string $s): string {return ['cash'=>'صندوق نقدی','bank'=>'حساب بانکی','personal'=>'پرداخت شخصی'][$s]??$s;}
function expense_rows(int $tenant): array {
    [$where,$args]=expense_scope($tenant);
    $branch=(int)($_GET['branch']??0);if($branch){$where.=' AND e.branch_id=?';$args[]=$branch;}
    $status=(string)($_GET['status']??'');if(in_array($status,['pending','approved','rejected','void','draft'],true)){$where.=' AND e.approval_status=?';$args[]=$status;}
    $search=trim((string)($_GET['q']??''));if($search!==''){$where.=' AND e.title LIKE ?';$args[]='%'.$search.'%';}
    foreach(['from'=>'>=','to'=>'<='] as $k=>$op){if(trim((string)($_GET[$k]??''))!==''){$where.=" AND e.expense_date $op ?";$args[]=jalali_to_gregorian_date((string)$_GET[$k]);}}
    return query('SELECT e.*,b.name branch_name,c.name category_name,u.full_name FROM expenses e JOIN branches b ON b.id=e.branch_id AND b.company_id=e.company_id JOIN expense_categories c ON c.id=e.category_id AND c.company_id=e.company_id JOIN users u ON u.id=e.created_by AND u.company_id=e.company_id WHERE '.$where.' ORDER BY e.expense_date DESC,e.id DESC LIMIT 500',$args)->fetchAll();
}
function render_expenses(int $tenant): void {
    if(!expenses_ready()){echo '<div class="card">ساختار هزینه‌ها آماده نیست؛ مهاجرت ۰۲۶ بررسی شود.</div>';return;}
    $actor=(int)user()['id'];$branches=branch_choices($tenant,$actor);
    $cats=query('SELECT * FROM expense_categories WHERE company_id=? ORDER BY name',[$tenant])->fetchAll();
    $detail=(int)($_GET['detail']??0);
    if($detail){
        [$scope,$args]=expense_scope($tenant);$args[]=$detail;
        $x=query('SELECT e.*,b.name branch_name,c.name category_name,u.full_name FROM expenses e JOIN branches b ON b.id=e.branch_id AND b.company_id=e.company_id JOIN expense_categories c ON c.id=e.category_id AND c.company_id=e.company_id JOIN users u ON u.id=e.created_by AND u.company_id=e.company_id WHERE '.$scope.' AND e.id=?',$args)->fetch();
        if(!$x){echo '<div class="card">سند یافت نشد یا دسترسی مجاز نیست.</div>';return;}
        $items=query('SELECT * FROM expense_items WHERE company_id=? AND expense_id=? ORDER BY id',[$tenant,$detail])->fetchAll();
        $payments=query('SELECT * FROM expense_payments WHERE company_id=? AND expense_id=? ORDER BY id',[$tenant,$detail])->fetchAll();
        ?>
        <div class="receipt card"><a href="?page=expenses">بازگشت</a><button type="button" data-print-receipt>چاپ سند</button><h2>سند هزینه <?=fa_digits($detail)?></h2><p><?=e($x['title'])?> | <?=e($x['branch_name'])?> | <?=e($x['category_name'])?></p><p>تاریخ هزینه: <?=e(jalali_datetime($x['expense_date'],true))?> | ثبت: <?=e(jalali_datetime($x['created_at']))?> | <?=e($x['full_name'])?></p><p><?=e(expense_status($x['approval_status']))?> — <?=e($x['notes'])?></p>
        <table><thead><tr><th>قلم</th><th>تعداد</th><th>واحد</th><th>قیمت واحد</th><th>جمع</th></tr></thead><tbody><?php foreach($items as $i):?><tr><td><?=e($i['title'])?></td><td><?=fa_digits($i['quantity'])?></td><td><?=e($i['unit'])?></td><td><?=money_toman($i['unit_price'])?></td><td><?=money_toman($i['line_total'])?></td></tr><?php endforeach;?></tbody></table>
        <h3>جمع: <?=money_toman($x['total_amount'])?></h3><table><thead><tr><th>روش</th><th>مبلغ</th><th>پیگیری</th></tr></thead><tbody><?php foreach($payments as $p):?><tr><td><?=e(expense_method($p['method']))?></td><td><?=money_toman($p['amount'])?></td><td><?=e($p['tracking']??'—')?></td></tr><?php endforeach;?></tbody></table></div>
        <?php if(expense_manager()&&$x['approval_status']==='pending'):?><form method="post" class="card form-grid"><?=csrf_field()?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?=e($detail)?>"><label>توضیح تصمیم<input name="reason" maxlength="500"></label><button name="decision" value="approved">تأیید و ثبت آثار مالی</button><button name="decision" value="rejected">رد هزینه</button></form><?php endif;?>
        <?php render_expense_financial_actions($tenant,$x); ?>
        <?php if(isset($_GET['print'])):?><script>window.addEventListener('load',()=>window.print());</script><?php endif;return;
    }
    $rows=expense_rows($tenant);
    $shifts=query("SELECT s.id,r.name,r.branch_id FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.company_id=? AND s.operator_id=? AND s.status='open' AND r.is_active=1",[$tenant,$actor])->fetchAll();
    $accounts=query('SELECT id,title,branch_id FROM company_bank_accounts WHERE company_id=? AND is_active=1 ORDER BY title',[$tenant])->fetchAll();
    ?>
    <section class="card"><h2>هزینه‌ها</h2><?php if($branches&&!is_super()):?><button type="button" id="expenseOpen">ثبت هزینه جدید</button><?php else:?><p>برای ثبت هزینه، کاربر شرکت باید به شعبه فعال تخصیص داشته باشد.</p><?php endif;?>
    <form method="get" class="form-grid"><input type="hidden" name="page" value="expenses"><label>جستجو<input name="q" value="<?=e($_GET['q']??'')?>"></label><label>شعبه<select name="branch"><option value="0">همه شعب مجاز</option><?php foreach($branches as $b):?><option value="<?=e($b['id'])?>" <?=((int)($_GET['branch']??0)===(int)$b['id'])?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></label><label>از تاریخ شمسی<input name="from" placeholder="۱۴۰۵/۰۸/۰۱" value="<?=e($_GET['from']??'')?>"></label><label>تا تاریخ شمسی<input name="to" value="<?=e($_GET['to']??'')?>"></label><label>وضعیت<select name="status"><option value="">همه</option><?php foreach(['pending','approved','rejected','void'] as $s):?><option value="<?=e($s)?>" <?=($_GET['status']??'')===$s?'selected':''?>><?=e(expense_status($s))?></option><?php endforeach;?></select></label><button>فیلتر</button></form>
    <div style="overflow:auto"><table><thead><tr><th>سند</th><th>تاریخ</th><th>شعبه</th><th>عنوان</th><th>دسته‌بندی</th><th>کاربر</th><th>مبلغ</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach($rows as $x):?><tr><td><?=fa_digits($x['id'])?></td><td><?=e(jalali_datetime($x['expense_date'],true))?></td><td><?=e($x['branch_name'])?></td><td><?=e($x['title'])?></td><td><?=e($x['category_name'])?></td><td><?=e($x['full_name'])?></td><td><?=money_toman($x['total_amount'])?></td><td><?=e(expense_status($x['approval_status']))?></td><td><a href="?page=expenses&amp;detail=<?=e($x['id'])?>">جزئیات و چاپ</a></td></tr><?php endforeach;?></tbody></table></div><small>حداکثر ۵۰۰ سند آخر مطابق فیلتر</small></section>
    <?php if(expense_manager()):?><details class="card"><summary>مدیریت دسته‌بندی‌ها</summary><?php foreach($cats as $c):?><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="category"><input type="hidden" name="id" value="<?=e($c['id'])?>"><input type="hidden" name="classification" value="<?=e($c['classification'])?>"><label>نام<input name="name" value="<?=e($c['name'])?>" required></label><label><input type="checkbox" name="active" <?=$c['is_active']?'checked':''?>> فعال</label><button>ذخیره</button></form><?php endforeach;?><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="category"><label>نام جدید<input name="name" required></label><label>طبقه‌بندی<select name="classification"><option value="expense">هزینه جاری</option><option value="asset">دارایی</option><option value="inventory">موجودی</option><option value="prepayment">پیش‌پرداخت</option></select></label><button>افزودن</button></form></details><?php endif;?>
    <dialog id="expenseDialog" style="width:min(920px,95vw);max-height:90vh;overflow:auto;border:0;border-radius:16px;padding:24px;color:inherit;background:var(--surface,#fff)" dir="rtl"><form method="post" id="expenseForm"><?=csrf_field()?><input type="hidden" name="action" value="create"><input type="hidden" name="request_key" value="<?=e(bin2hex(random_bytes(32)))?>"><h2>ثبت هزینه جدید</h2><p id="expenseStep"></p>
    <section data-exp-step="0" class="form-grid"><label>شعبه<select name="branch_id" id="expenseBranch" required><?php foreach($branches as $b):?><option value="<?=e($b['id'])?>"><?=e($b['name'])?></option><?php endforeach;?></select></label><label>تاریخ شمسی<input name="expense_date" value="<?=e(jalali_datetime(date('Y-m-d'),true))?>" required></label><label>دسته‌بندی<select name="category_id" required><?php foreach($cats as $c):if(!$c['is_active'])continue;?><option value="<?=e($c['id'])?>"><?=e($c['name'])?></option><?php endforeach;?></select></label><label>عنوان<input name="title" required maxlength="180"></label><label>توضیحات<textarea name="notes" maxlength="4000"></textarea></label></section>
    <section data-exp-step="1" hidden><div id="expenseItems"></div><button type="button" id="expenseAddItem">افزودن قلم</button><p>جمع اقلام: <strong id="expenseTotal">۰</strong> تومان</p></section>
    <section data-exp-step="2" hidden><div id="expensePayments"></div><button type="button" id="expenseAddPayment">افزودن روش پرداخت</button><p>جمع پرداخت: <strong id="expensePaid">۰</strong> تومان</p></section>
    <section data-exp-step="3" hidden><h3>بررسی نهایی</h3><pre id="expensePreview" style="white-space:pre-wrap;font:inherit"></pre><p>سند پس از تأیید مدیر آثار مالی خواهد داشت.</p></section>
    <footer style="position:sticky;bottom:0;background:inherit;display:flex;gap:8px;flex-wrap:wrap;padding:12px 0"><button type="button" id="expenseCancel">انصراف</button><button type="button" id="expenseBack">مرحله قبل</button><button type="button" id="expenseNext">مرحله بعد</button><button type="submit" id="expenseSave" hidden>ذخیره هزینه</button><button type="submit" name="print" value="1" id="expensePrint" hidden>ذخیره و چاپ</button></footer></form></dialog>
    <script type="application/json" id="expenseSources"><?=json_encode(['cash'=>$shifts,'bank'=>$accounts],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?></script>
    <script src="assets/expenses.js" defer></script>
    <?php
}
