<?php
declare(strict_types=1);
function expense_scope(int $tenant): array {
    if($tenant!==tenant_id())throw new RuntimeException('شرکت معتبر نیست.');
    $sql='e.company_id=?';$args=[$tenant];
    if(!expense_manager()){
        $sql.=' AND (e.created_by=?';$args[]=(int)user()['id'];
        if(expense_workflow_ready()){$sql.=" OR EXISTS(SELECT 1 FROM expense_branch_approvers ap JOIN expense_policies pol ON pol.company_id=ap.company_id AND pol.approval_mode='branch' WHERE ap.company_id=e.company_id AND ap.branch_id=e.branch_id AND ap.user_id=? AND ap.approval_limit>=e.total_amount)";$args[]=(int)user()['id'];}
        $sql.=') AND EXISTS(SELECT 1 FROM user_branch_assignments a JOIN branches b ON b.id=a.branch_id AND b.company_id=a.company_id WHERE a.company_id=e.company_id AND a.branch_id=e.branch_id AND a.user_id=? AND a.is_active=1 AND b.is_active=1)';$args[]=(int)user()['id'];
    }
    return [$sql,$args];
}
function expense_handle_post(string $action): never {
    require_permission('expenses.view');
    if($action==='policy'){expense_policy_save(tenant_id(),$_POST);redirect('expenses','سیاست تأیید ذخیره شد.');}
    if($action==='approver'){expense_branch_approver_save(tenant_id(),$_POST);redirect('expenses','مجوز تأیید شعبه به‌روزرسانی شد.');}
    if($action==='change'){
        $reason=trim((string)($_POST['reason']??''));if(mb_strlen($reason)<5||mb_strlen($reason)>500)throw new RuntimeException('دلیل درخواست اصلاح بین ۵ تا ۵۰۰ نویسه باشد.');
        [$scope,$args]=expense_scope(tenant_id());$args[]=(int)($_POST['id']??0);
        $x=query('SELECT e.id FROM expenses e WHERE '.$scope.' AND e.id=?',$args)->fetch();if(!$x)throw new RuntimeException('سند مجاز نیست.');
        expense_event(tenant_id(),(int)$x['id'],'change.requested',$reason);redirect('expenses','درخواست اصلاح ثبت شد. اطلاعات مالی تغییر نکرد.');
    }
    if($action==='create'){$id=expense_create($_POST);if(isset($_FILES['receipt'])&&($_FILES['receipt']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)expense_attach(tenant_id(),$id,$_FILES['receipt']);if(isset($_POST['print'])){header('Location: ?page=expenses&detail='.$id.'&print=1');exit;}$state=(string)query('SELECT approval_status FROM expenses WHERE company_id=? AND id=?',[tenant_id(),$id])->fetchColumn();redirect('expenses',$state==='draft'?'پیش‌نویس هزینه ذخیره شد.':($state==='approved'?'هزینه قطعی ثبت شد.':'هزینه در انتظار تأیید ثبت شد.'));}
    if($action==='review'){expense_review((int)($_POST['id']??0),(string)($_POST['decision']??''),trim((string)($_POST['reason']??'')));redirect('expenses','تصمیم ثبت شد.');}
    if($action==='settle'){expense_settle((int)($_POST['id']??0),$_POST);redirect('expenses','تسویه ثبت شد.');}
    if($action==='return'){expense_return((int)($_POST['id']??0),$_POST);redirect('expenses','دریافت برگشت وجه با حفظ سند اولیه ثبت شد.');}
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
function expense_rows(int $tenant,int $limit=500): array {
    $limit=max(1,min(10001,$limit));
    [$where,$args]=expense_scope($tenant);
    $branch=(int)($_GET['branch']??0);if($branch){$where.=' AND e.branch_id=?';$args[]=$branch;}
    $status=(string)($_GET['status']??'');if(in_array($status,['pending','approved','rejected','void','draft'],true)){$where.=' AND e.approval_status=?';$args[]=$status;}
    $search=trim((string)($_GET['q']??''));if($search!==''){$where.=' AND e.title LIKE ?';$args[]='%'.$search.'%';}
    foreach(['from'=>'>=','to'=>'<='] as $k=>$op){if(trim((string)($_GET[$k]??''))!==''){$where.=" AND e.expense_date $op ?";$args[]=jalali_to_gregorian_date((string)$_GET[$k]);}}
    return query('SELECT e.*,b.name branch_name,c.name category_name,u.full_name FROM expenses e JOIN branches b ON b.id=e.branch_id AND b.company_id=e.company_id JOIN expense_categories c ON c.id=e.category_id AND c.company_id=e.company_id JOIN users u ON u.id=e.created_by AND u.company_id=e.company_id WHERE '.$where.' ORDER BY e.expense_date DESC,e.id DESC LIMIT '.$limit,$args)->fetchAll();
}
function render_expenses(int $tenant): void {
    require_permission('expenses.view');
    if(!expenses_ready()){echo '<div class="card">ساختار هزینه‌ها آماده نیست؛ مهاجرت ۰۲۶ بررسی شود.</div>';return;}
    $actor=(int)user()['id'];$branches=branch_choices($tenant,$actor);
    $cats=query('SELECT * FROM expense_categories WHERE company_id=? ORDER BY name',[$tenant])->fetchAll();
    $edit=(int)($_GET['edit']??0);$draft=null;$draftData=null;
    if($edit){
        if(!can('expenses.create')||!expense_workflow_ready()){echo '<div class="card">ویرایش پیش‌نویس مجاز یا آماده نیست.</div>';return;}
        $draft=query("SELECT * FROM expenses WHERE company_id=? AND id=? AND created_by=? AND approval_status IN ('draft','rejected')",[$tenant,$edit,$actor])->fetch();
        if(!$draft){echo '<div class="card">پیش‌نویس قابل ویرایش یافت نشد.</div>';return;}
        $draftData=['items'=>query('SELECT * FROM expense_items WHERE company_id=? AND expense_id=? ORDER BY id',[$tenant,$edit])->fetchAll(),'payments'=>query('SELECT * FROM expense_payments WHERE company_id=? AND expense_id=? ORDER BY id',[$tenant,$edit])->fetchAll()];
    }
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
        <?php if(isset($x['subtotal'])):?><p>جمع اقلام: <?=money_toman($x['subtotal'])?> | هزینه جانبی: <?=money_toman($x['extra_amount'])?> | تخفیف: <?=money_toman($x['discount_amount'])?></p><?php endif;?>
        <h3>جمع: <?=money_toman($x['total_amount'])?></h3><table><thead><tr><th>روش</th><th>مبلغ</th><th>پیگیری</th></tr></thead><tbody><?php foreach($payments as $p):?><tr><td><?=e(expense_method($p['method']))?></td><td><?=money_toman($p['amount'])?></td><td><?=e($p['tracking']??'—')?></td></tr><?php endforeach;?></tbody></table></div>
        <?php if(expense_can_review($x)&&$x['approval_status']==='pending'):?><form method="post" class="card form-grid"><?=csrf_field()?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?=e($detail)?>"><label>توضیح تصمیم<input name="reason" maxlength="500"></label><button name="decision" value="approved">تأیید و ثبت آثار مالی</button><button name="decision" value="rejected">رد هزینه</button></form><?php endif;?>
        <?php if(expense_workflow_ready()): ?>
        <?php if((int)$x['created_by']===$actor&&can('expenses.create')&&in_array($x['approval_status'],['draft','rejected'],true)):?><a href="?page=expenses&amp;edit=<?=e($detail)?>">ویرایش و ارسال مجدد هزینه</a><?php endif; ?>
        <section class="card"><h3>پیوست‌ها</h3><?php foreach(query('SELECT id,original_name FROM expense_attachments WHERE company_id=? AND expense_id=? ORDER BY id',[$tenant,$detail])->fetchAll() as $a):?><p><a href="?page=expense_attachment&amp;id=<?=e($a['id'])?>"><?=e($a['original_name'])?></a></p><?php endforeach; ?></section>
        <?php endif; ?>
        <details class="card"><summary>درخواست اصلاح سند</summary><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="change"><input type="hidden" name="id" value="<?=e($detail)?>"><label>دلیل درخواست<textarea name="reason" required minlength="5" maxlength="500"></textarea></label><button>ثبت درخواست اصلاح</button></form></details>
        <?php render_expense_financial_actions($tenant,$x); ?>
        <?php if(isset($_GET['print'])):?><span data-expense-auto-print hidden></span><?php endif;return;
    }
    $rows=expense_rows($tenant);
    $reportLink='?'.http_build_query(array_merge(array_intersect_key($_GET,array_flip(['q','branch','from','to','status'])),['page'=>'expenses','export'=>'xlsx']));
    $shifts=query("SELECT s.id,r.name,r.branch_id FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.company_id=? AND s.operator_id=? AND s.status='open' AND r.is_active=1",[$tenant,$actor])->fetchAll();
    $accounts=query('SELECT id,title,branch_id FROM company_bank_accounts WHERE company_id=? AND is_active=1 ORDER BY title',[$tenant])->fetchAll();
    ?>
    <section class="card"><h2>هزینه‌ها</h2><a href="?page=expense_reports">گزارش‌های تخصصی هزینه‌ها</a> <?php if(can('expenses.export')):?><a href="<?=e($reportLink)?>">خروجی اکسل نتایج</a><?php endif;?> <button type="button" data-print-receipt>چاپ گزارش</button><?php if($branches&&!is_super()&&can('expenses.create')):?><button type="button" id="expenseOpen">ثبت هزینه جدید</button><?php else:?><p>برای ثبت هزینه، کاربر شرکت باید به شعبه فعال تخصیص داشته باشد.</p><?php endif;?>
    <form method="get" class="form-grid"><input type="hidden" name="page" value="expenses"><label>جستجو<input name="q" value="<?=e($_GET['q']??'')?>"></label><label>شعبه<select name="branch"><option value="0">همه شعب مجاز</option><?php foreach($branches as $b):?><option value="<?=e($b['id'])?>" <?=((int)($_GET['branch']??0)===(int)$b['id'])?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></label><label>از تاریخ شمسی<input name="from" placeholder="۱۴۰۵/۰۸/۰۱" value="<?=e($_GET['from']??'')?>"></label><label>تا تاریخ شمسی<input name="to" value="<?=e($_GET['to']??'')?>"></label><label>وضعیت<select name="status"><option value="">همه</option><?php foreach(['draft','pending','approved','rejected','void'] as $s):?><option value="<?=e($s)?>" <?=($_GET['status']??'')===$s?'selected':''?>><?=e(expense_status($s))?></option><?php endforeach;?></select></label><button>فیلتر</button></form>
    <div class="table-wrap"><table><thead><tr><th>سند</th><th>تاریخ</th><th>شعبه</th><th>عنوان</th><th>دسته‌بندی</th><th>کاربر</th><th>مبلغ</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach($rows as $x):?><tr><td><?=fa_digits($x['id'])?></td><td><?=e(jalali_datetime($x['expense_date'],true))?></td><td><?=e($x['branch_name'])?></td><td><?=e($x['title'])?></td><td><?=e($x['category_name'])?></td><td><?=e($x['full_name'])?></td><td><?=money_toman($x['total_amount'])?></td><td><?=e(expense_status($x['approval_status']))?></td><td><a href="?page=expenses&amp;detail=<?=e($x['id'])?>">جزئیات و چاپ</a></td></tr><?php endforeach;?></tbody></table></div><small>حداکثر ۵۰۰ سند آخر مطابق فیلتر</small></section>
    <?php if(expense_manager()):?><details class="card"><summary>مدیریت دسته‌بندی‌ها</summary><?php foreach($cats as $c):?><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="category"><input type="hidden" name="id" value="<?=e($c['id'])?>"><input type="hidden" name="classification" value="<?=e($c['classification'])?>"><label>نام<input name="name" value="<?=e($c['name'])?>" required></label><label><input type="checkbox" name="active" <?=$c['is_active']?'checked':''?>> فعال</label><button>ذخیره</button></form><?php endforeach;?><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="category"><label>نام جدید<input name="name" required></label><label>طبقه‌بندی<select name="classification"><option value="expense">هزینه جاری</option><option value="asset">دارایی</option><option value="inventory">موجودی</option><option value="prepayment">پیش‌پرداخت</option></select></label><button>افزودن</button></form></details><?php endif;?>
    <?php render_expense_policy_settings($tenant,$branches); ?>
    <dialog id="expenseDialog" class="expense-dialog" aria-labelledby="expenseDialogTitle" dir="rtl"><form method="post" id="expenseForm" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="create"><input type="hidden" name="request_key" value="<?=e(bin2hex(random_bytes(32)))?>"><input type="hidden" name="id" value="<?=e($draft['id']??0)?>"><input type="hidden" name="version" value="<?=e($draft['version']??0)?>"><h2 id="expenseDialogTitle">ثبت / ویرایش هزینه</h2><p id="expenseStep" aria-live="polite"></p>
    <section data-exp-step="0" class="form-grid"><label>شعبه<select name="branch_id" id="expenseBranch" required><?php foreach($branches as $b):?><option value="<?=e($b['id'])?>" <?=((int)($draft['branch_id']??0)===(int)$b['id'])?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></label><label>تاریخ شمسی<input name="expense_date" value="<?=e(jalali_datetime($draft['expense_date']??date('Y-m-d'),true))?>" required></label><label>دسته‌بندی<select name="category_id" required><?php foreach($cats as $c):if(!$c['is_active'])continue;?><option value="<?=e($c['id'])?>" <?=((int)($draft['category_id']??0)===(int)$c['id'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></label><label>عنوان<input name="title" required maxlength="180" value="<?=e($draft['title']??'')?>"></label><label>توضیحات<textarea name="notes" maxlength="4000"><?=e($draft['notes']??'')?></textarea></label><?php if(expense_workflow_ready()):?><label>تصویر رسید / PDF (حداکثر ۵ مگابایت)<input type="file" name="receipt" accept="image/jpeg,image/png,application/pdf"></label><?php endif;?></section>
    <section data-exp-step="1" hidden><div id="expenseItems"></div><button type="button" id="expenseAddItem">افزودن قلم</button><?php if(expense_workflow_ready()):?><div class="form-grid"><label>هزینه جانبی (تومان)<input name="extra_amount" id="expenseExtra" inputmode="numeric" value="<?=e(money($draft['extra_amount']??0))?>"></label><label>تخفیف (تومان)<input name="discount_amount" id="expenseDiscount" inputmode="numeric" value="<?=e(money($draft['discount_amount']??0))?>"></label></div><?php endif;?><p>جمع اقلام: <strong id="expenseTotal">۰</strong> تومان</p></section>
    <section data-exp-step="2" hidden><div id="expensePayments"></div><button type="button" id="expenseAddPayment">افزودن روش پرداخت</button><p>جمع پرداخت: <strong id="expensePaid">۰</strong> تومان</p></section>
    <section data-exp-step="3" hidden><h3>بررسی نهایی</h3><pre id="expensePreview" class="expense-preview"></pre><p>قطعی‌شدن سند مطابق سیاست تأیید شرکت انجام می‌شود؛ پیش‌نویس اثر مالی ندارد.</p></section>
    <footer class="expense-wizard-actions"><button type="button" id="expenseCancel">انصراف</button><button type="button" id="expenseBack">مرحله قبل</button><button type="button" id="expenseNext">مرحله بعد</button><?php if(expense_workflow_ready()):?><button type="submit" id="expenseDraft" name="mode" value="draft" hidden>ذخیره پیش‌نویس</button><?php endif;?><button type="submit" id="expenseSave" hidden>ذخیره هزینه</button><button type="submit" name="print" value="1" id="expensePrint" hidden>ذخیره و چاپ</button></footer></form></dialog>
    <script type="application/json" id="expenseSources"><?=json_encode(['cash'=>$shifts,'bank'=>$accounts,'draft'=>$draftData],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?></script>
    <script src="assets/expenses.js" defer></script>
    <?php
}
