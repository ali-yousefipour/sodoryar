<?php
declare(strict_types=1);
function expense_default_categories(): array {
    return ['اجاره و شارژ'=>'expense','آب، برق، گاز و ارتباطات'=>'expense','ملزومات اداری'=>'expense','حمل و نقل'=>'expense','تعمیر و نگهداری'=>'expense','پذیرایی'=>'expense','حقوق و دستمزد'=>'expense','عوارض و مالیات'=>'expense','هزینه نقدی انتقال‌یافته'=>'expense','خرید دارایی'=>'asset','پیش‌پرداخت'=>'prepayment','خرید موجودی'=>'inventory'];
}
function expense_ensure_defaults(int $tenant): void {
    if($tenant!==tenant_id()) throw new RuntimeException('شرکت مجاز نیست.');
    $owned=!db()->inTransaction();
    if($owned)db()->beginTransaction();
    try{
        if(!query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch())throw new RuntimeException('شرکت معتبر نیست.');
        if(query('SELECT company_id FROM expense_policies WHERE company_id=?',[$tenant])->fetch()){if($owned)db()->commit();return;}
        foreach(expense_default_categories() as $name=>$kind){
            query('INSERT INTO expense_categories(company_id,name,accounting_type) SELECT ?,?,? WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE company_id=? AND name=?)',[$tenant,$name,$kind,$tenant,$name]);
        }
        query("INSERT INTO expense_policies(company_id,approval_mode,approval_threshold) SELECT ?,'all',0 WHERE NOT EXISTS (SELECT 1 FROM expense_policies WHERE company_id=?)",[$tenant,$tenant]);
        if($owned)db()->commit();
    }catch(Throwable $ex){if($owned && db()->inTransaction())db()->rollBack();throw $ex;}
}
function expense_categories(int $tenant,bool $activeOnly=true): array {
    if($tenant!==tenant_id())throw new RuntimeException('شرکت مجاز نیست.');
    return query('SELECT id,name,accounting_type,is_active FROM expense_categories WHERE company_id=?'.($activeOnly?' AND is_active=1':'').' ORDER BY name,id',[$tenant])->fetchAll();
}
function expense_policy(int $tenant): array {
    if($tenant!==tenant_id())throw new RuntimeException('شرکت مجاز نیست.');
    $policy=query('SELECT approval_mode,approval_threshold FROM expense_policies WHERE company_id=?',[$tenant])->fetch();
    if(!$policy)throw new RuntimeException('تنظیمات هزینه شرکت آماده نیست.');
    return $policy;
}
function expense_category_values(array $input): array {
    $name=trim((string)($input['name']??''));
    $kind=(string)($input['accounting_type']??'');
    if($name==='' || mb_strlen($name)>160 || !in_array($kind,['expense','asset','prepayment','inventory'],true))throw new RuntimeException('عنوان یا طبقه‌بندی هزینه معتبر نیست.');
    return [$name,$kind];
}
function expense_settings_post(string $action): never {
    require_permission('expenses.manage');
    $tenant=tenant_id();
    expense_ensure_defaults($tenant);
    db()->beginTransaction();
    try{
        query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
        if($action==='policy'){
            $mode=(string)($_POST['approval_mode']??'');
            if(!in_array($mode,['none','all','threshold'],true))throw new RuntimeException('روش تأیید هزینه معتبر نیست.');
            $threshold=cashbox_amount($_POST['approval_threshold']??'0',true);
            query('UPDATE expense_policies SET approval_mode=?,approval_threshold=? WHERE company_id=?',[$mode,$threshold,$tenant]);
        }elseif($action==='category'){
            [$name,$kind]=expense_category_values($_POST);
            query('INSERT INTO expense_categories(company_id,name,accounting_type) VALUES(?,?,?)',[$tenant,$name,$kind]);
        }elseif($action==='category_edit'){
            $id=filter_var($_POST['category_id']??null,FILTER_VALIDATE_INT);
            $category=$id?query('SELECT id,accounting_type FROM expense_categories WHERE company_id=? AND id=? FOR UPDATE',[$tenant,$id])->fetch():false;
            if(!$category)throw new RuntimeException('دسته‌بندی مجاز نیست.');
            [$name,$kind]=expense_category_values($_POST);
            if($kind!==$category['accounting_type'] && query('SELECT id FROM expenses WHERE company_id=? AND category_id=? LIMIT 1',[$tenant,$id])->fetch())throw new RuntimeException('طبقه‌بندی دسته دارای سند قابل تغییر نیست؛ دسته جدید بسازید.');
            query('UPDATE expense_categories SET name=?,accounting_type=?,is_active=? WHERE company_id=? AND id=?',[$name,$kind,isset($_POST['is_active'])?1:0,$tenant,$id]);
        }else throw new RuntimeException('عملیات تنظیمات هزینه معتبر نیست.');
        audit('expenses.settings.'.$action,json_encode(['category_id'=>(int)($_POST['category_id']??0),'actor'=>(int)user()['id']],JSON_THROW_ON_ERROR),$tenant);
        db()->commit();
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
    redirect('expenses','تنظیمات هزینه ذخیره شد.');
}
function expense_accounting_options(string $selected='expense'): string {
    $html='';
    foreach(['expense'=>'هزینه جاری','asset'=>'دارایی','prepayment'=>'پیش‌پرداخت','inventory'=>'موجودی کالا'] as $kind=>$name)$html.='<option value="'.$kind.'"'.($kind===$selected?' selected':'').'>'.$name.'</option>';
    return $html;
}
function render_expense_settings(int $tenant): void {
    require_permission('expenses.manage');
    expense_ensure_defaults($tenant);
    $policy=expense_policy($tenant);
    echo '<div class="heading"><div><h1>تنظیمات هزینه‌های شرکت</h1><p class="muted">دارایی، موجودی کالا و پیش‌پرداخت جدا از هزینه جاری گزارش می‌شوند. تنظیم تأیید برای اسناد جدید اعمال می‌شود.</p></div></div>';
    echo '<section class="card"><h2>روال تأیید هزینه</h2><form method="post" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="policy"><label>نیاز به تأیید<select name="approval_mode">';
    foreach(['none'=>'بدون تأیید','all'=>'همه اسناد','threshold'=>'بالاتر از سقف مشخص'] as $mode=>$label)echo '<option value="'.$mode.'"'.($policy['approval_mode']===$mode?' selected':'').'>'.$label.'</option>';
    echo '</select></label><label>سقف بدون تأیید (تومان)<input name="approval_threshold" value="'.e($policy['approval_threshold']).'" inputmode="numeric" data-money-input required></label><button>ذخیره روال</button></form></section>';
    echo '<section class="card"><h2>دسته‌بندی جدید</h2><form method="post" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="category"><label>عنوان<input name="name" maxlength="160" required></label><label>طبقه حسابداری<select name="accounting_type">'.expense_accounting_options().'</select></label><button>ثبت دسته</button></form></section>';
    echo '<section class="card"><h2>دسته‌بندی‌ها</h2>';
    foreach(expense_categories($tenant,false) as $category){
        echo '<details><summary>'.e($category['name']).' — '.((int)$category['is_active']?'فعال':'غیرفعال').'</summary><form method="post" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="category_edit"><input type="hidden" name="category_id" value="'.(int)$category['id'].'"><label>عنوان<input name="name" maxlength="160" value="'.e($category['name']).'" required></label><label>طبقه حسابداری<select name="accounting_type">'.expense_accounting_options($category['accounting_type']).'</select></label><label><input type="checkbox" name="is_active" value="1"'.((int)$category['is_active']?' checked':'').'>فعال</label><button>ذخیره دسته</button></form></details>';
    }
    echo '</section>';
}
