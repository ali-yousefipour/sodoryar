<?php
declare(strict_types=1);
function company_resources_guard(): int {
    require_admin();
    return tenant_id();
}
function company_resources_post(string $page,string $action): never {
    $tenant=company_resources_guard();
    if (!branches_ready()) throw new RuntimeException('ابتدا مهاجرت شعب را اجرا کنید.');
    $actor=(int)user()['id'];
    db()->beginTransaction();
    try {
        query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
        if ($page==='branches' && $action==='add') {
            $name=trim((string)($_POST['name']??''));
            $num=filter_var(ascii_digits((string)($_POST['number']??'')),FILTER_VALIDATE_INT,['options'=>['min_range'=>2,'max_range'=>999999]]);
            if (!$num || $name==='' || mb_strlen($name)>180) throw new RuntimeException('نام و شماره شعبه معتبر نیست.');
            query('INSERT INTO branches(company_id,branch_number,branch_code,name) VALUES(?,?,?,?)',[$tenant,$num,str_pad((string)$num,3,'0',STR_PAD_LEFT),$name]);
        } elseif ($page==='branches' && $action==='rename') {
            $id=branch_require($tenant,$actor,$_POST['branch_id']??null);
            $name=trim((string)($_POST['name']??''));
            if($name===''||mb_strlen($name)>180)throw new RuntimeException('نام شعبه معتبر نیست.');
            query('UPDATE branches SET name=? WHERE id=? AND company_id=?',[$name,$id,$tenant]);
        } elseif ($page==='branch_users' && $action==='assign') {
            $id=branch_require($tenant,$actor,$_POST['branch_id']??null);
            $uid=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT);
            if(!$uid||!query('SELECT id FROM users WHERE id=? AND company_id=? AND is_active=1 FOR UPDATE',[$uid,$tenant])->fetch())throw new RuntimeException('کاربر معتبر نیست.');
            $default=isset($_POST['is_default'])?1:0;
            if($default)query('UPDATE user_branch_assignments SET is_default=0 WHERE company_id=? AND user_id=?',[$tenant,$uid]);
            query('INSERT INTO user_branch_assignments(company_id,user_id,branch_id,is_default,is_active) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE is_active=1,is_default=VALUES(is_default)',[$tenant,$uid,$id,$default]);
        } elseif ($page==='bank_accounts' && $action==='bank') {
            $name=trim((string)($_POST['name']??''));
            if($name===''||mb_strlen($name)>160)throw new RuntimeException('نام بانک معتبر نیست.');
            query('INSERT INTO banks(name) VALUES(?) ON DUPLICATE KEY UPDATE name=VALUES(name)',[$name]);
        } elseif ($page==='bank_accounts' && $action==='account') {
            if(!database_table_exists('company_bank_accounts'))throw new RuntimeException('ابتدا مهاجرت حساب بانکی را اجرا کنید.');
            $branch=($_POST['branch_id']??'')===''?null:branch_require($tenant,$actor,$_POST['branch_id']);
            $bank=filter_var($_POST['bank_id']??null,FILTER_VALIDATE_INT);
            $title=trim((string)($_POST['title']??''));
            $number=trim(ascii_digits((string)($_POST['account_number']??'')));
            $iban=strtoupper(preg_replace('/\s+/u','',ascii_digits((string)($_POST['iban']??''))));
            if(!$bank||!query('SELECT id FROM banks WHERE id=? AND is_active=1',[$bank])->fetch()||$title===''||mb_strlen($title)>160||($number!==''&&!preg_match('/^[0-9-]{1,40}$/D',$number))||($iban!==''&&!preg_match('/^IR[0-9]{24}$/D',$iban))||($number===''&&$iban===''))throw new RuntimeException('اطلاعات حساب بانکی معتبر نیست.');
            if($iban!==''){
                $reordered=substr($iban,4).'1827'.substr($iban,2,2);
                $remainder=0;
                foreach(str_split($reordered) as $digit)$remainder=($remainder*10+(int)$digit)%97;
                if($remainder!==1)throw new RuntimeException('شماره شبا معتبر نیست.');
            }
            query('INSERT INTO company_bank_accounts(company_id,branch_id,bank_id,title,account_number,iban) VALUES(?,?,?,?,?,?)',[$tenant,$branch,$bank,$title,$number?:null,$iban?:null]);
        } else throw new RuntimeException('عملیات نامعتبر است.');
        audit('company.resources.'.$page.'.'.$action,json_encode(['actor'=>$actor],JSON_THROW_ON_ERROR),$tenant);
        db()->commit();
    } catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
    redirect($page,'اطلاعات با موفقیت ثبت شد.');
}
function resource_options(array $rows,string $label='name'): string {
    $html='';
    foreach($rows as $row)$html.='<option value="'.(int)$row['id'].'">'.e($row[$label]).'</option>';
    return $html;
}
function render_company_resources(string $page): void {
    $tenant=company_resources_guard();
    if(!branches_ready()){echo '<div class="card">ابتدا مهاجرت ۰۲۴ شعب را اجرا کنید.</div>';return;}
    $branches=branch_choices($tenant,(int)user()['id']);
    $options=resource_options($branches);
    $csrf=csrf_field();
    if($page==='branches'){
        echo '<h1>مدیریت شعب</h1><div class="card"><h2>تعریف شعبه</h2><form method="post" class="form-grid">'.$csrf.'<input type="hidden" name="action" value="add"><label>شماره شعبه<input name="number" inputmode="numeric" required></label><label>نام شعبه<input name="name" maxlength="180" required></label><button>ثبت شعبه</button></form></div><div class="card"><h2>ویرایش نام شعبه</h2><form method="post" class="form-grid">'.$csrf.'<input type="hidden" name="action" value="rename"><label>شعبه<select name="branch_id" required>'.$options.'</select></label><label>نام جدید<input name="name" maxlength="180" required></label><button>ذخیره</button></form></div>';
        render_table(['شماره','کد','نام','فعال'],query('SELECT branch_number,branch_code,name,is_active FROM branches WHERE company_id=? ORDER BY branch_number',[$tenant])->fetchAll());
    } elseif($page==='branch_users'){
        $users=query('SELECT id,full_name FROM users WHERE company_id=? AND is_active=1 ORDER BY full_name',[$tenant])->fetchAll();
        echo '<h1>تخصیص کاربران به شعب</h1><div class="card"><form method="post" class="form-grid">'.$csrf.'<input type="hidden" name="action" value="assign"><label>کاربر<select name="user_id" required>'.resource_options($users,'full_name').'</select></label><label>شعبه<select name="branch_id" required>'.$options.'</select></label><label><input type="checkbox" name="is_default" value="1">شعبه پیش‌فرض</label><button>ثبت تخصیص</button></form></div>';
        render_table(['کاربر','شعبه','پیش‌فرض','فعال'],query('SELECT u.full_name,b.name,a.is_default,a.is_active FROM user_branch_assignments a JOIN users u ON u.id=a.user_id AND u.company_id=a.company_id JOIN branches b ON b.id=a.branch_id AND b.company_id=a.company_id WHERE a.company_id=? ORDER BY u.full_name,b.branch_number',[$tenant])->fetchAll());
    } elseif($page==='bank_accounts'){
        echo '<h1>حساب‌های بانکی شرکت</h1>';
        if(!database_table_exists('company_bank_accounts')){echo '<div class="card">ابتدا مهاجرت ۰۲۵ را اجرا کنید.</div>';return;}
        $banks=query('SELECT id,name FROM banks WHERE is_active=1 ORDER BY name')->fetchAll();
        echo '<div class="card"><h2>تعریف بانک</h2><form method="post" class="form-grid">'.$csrf.'<input type="hidden" name="action" value="bank"><label>نام بانک<input name="name" maxlength="160" required></label><button>ثبت بانک</button></form></div><div class="card"><h2>تعریف حساب</h2><form method="post" class="form-grid">'.$csrf.'<input type="hidden" name="action" value="account"><label>عنوان حساب<input name="title" maxlength="160" required></label><label>بانک<select name="bank_id" required>'.resource_options($banks).'</select></label><label>شعبه<select name="branch_id"><option value="">مشترک شرکت</option>'.$options.'</select></label><label>شماره حساب<input name="account_number" maxlength="40"></label><label>شبا<input name="iban" dir="ltr" maxlength="26"></label><button>ثبت حساب</button></form></div>';
        render_table(['عنوان','بانک','شعبه','شماره حساب','شبا'],query("SELECT a.title,k.name bank,COALESCE(b.name,'مشترک شرکت') branch,a.account_number,a.iban FROM company_bank_accounts a JOIN banks k ON k.id=a.bank_id LEFT JOIN branches b ON b.id=a.branch_id AND b.company_id=a.company_id WHERE a.company_id=? ORDER BY a.id DESC",[$tenant])->fetchAll());
    }
}
