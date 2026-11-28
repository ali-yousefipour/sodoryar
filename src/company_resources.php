<?php
declare(strict_types=1);
function company_resources_guard(): int {
    require_admin();
    return tenant_id();
}
/** New tenant resources participate in the caller's company/user transaction. */
function company_initialize_branches(int $tenant): void {
    if(!branches_ready()) return;
    if(!db()->inTransaction()) throw new LogicException('Company branch provisioning requires a transaction.');
    if(!query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch()) throw new RuntimeException('شرکت معتبر نیست.');
    query("INSERT INTO branches(company_id,branch_number,branch_code,name,is_default,is_active) SELECT ?,1,'001','شعبه مرکزی',1,1 WHERE NOT EXISTS (SELECT 1 FROM branches WHERE company_id=? AND branch_number=1)",[$tenant,$tenant]);
    $branch=query('SELECT id FROM branches WHERE company_id=? AND branch_number=1',[$tenant])->fetchColumn();
    query("INSERT INTO cash_registers(company_id,branch_id,name) SELECT ?,?,? WHERE NOT EXISTS (SELECT 1 FROM cash_registers WHERE company_id=? AND branch_id=?)",[$tenant,$branch,'صندوق پیش‌فرض شعبه 1 #'.$branch,$tenant,$branch]);
}
function company_assign_new_user_branch(int $tenant,int $userId): void {
    if(!branches_ready()) return;
    if(!db()->inTransaction()) throw new LogicException('User branch assignment requires a transaction.');
    if(!query('SELECT id FROM users WHERE id=? AND company_id=? FOR UPDATE',[$userId,$tenant])->fetch()) throw new RuntimeException('کاربر متعلق به شرکت نیست.');
    $branch=query('SELECT id FROM branches WHERE company_id=? AND is_active=1 ORDER BY is_default DESC,branch_number LIMIT 1',[$tenant])->fetchColumn();
    if(!$branch) throw new RuntimeException('ابتدا شعبه فعال پیش‌فرض شرکت را تعریف کنید.');
    query('INSERT INTO user_branch_assignments(company_id,user_id,branch_id,is_default,is_active) VALUES(?,?,?,1,1)',[$tenant,$userId,$branch]);
}
function company_branch_fields(array $input): array {
    $fields=['name'=>180,'manager_name'=>180,'phone'=>30,'mobile'=>30,'province'=>100,'city'=>100,'address'=>2000,'postal_code'=>20,'notes'=>4000];
    $values=[];
    foreach($fields as $key=>$length){
        if(isset($input[$key])&&!is_string($input[$key]))throw new RuntimeException('ساختار اطلاعات شعبه نامعتبر است.');
        $value=trim((string)($input[$key]??''));
        if(mb_strlen($value)>$length || ($key==='name' && $value==='')) throw new RuntimeException('اطلاعات شعبه نامعتبر یا طولانی است.');
        if(in_array($key,['phone','mobile','postal_code'],true)) $value=ascii_digits($value);
        if($value!=='' && in_array($key,['phone','mobile'],true) && !preg_match('/^[0-9+() -]{5,30}$/D',$value)) throw new RuntimeException('شماره تماس شعبه معتبر نیست.');
        if($key==='postal_code' && $value!=='' && !preg_match('/^[0-9]{10}$/D',$value)) throw new RuntimeException('کد پستی باید ۱۰ رقم باشد.');
        $values[$key]=$value;
    }
    return $values;
}
function company_branch_form(array $row=[]): string {
    $html='';
    foreach(['name'=>'نام شعبه','manager_name'=>'مسئول شعبه','phone'=>'تلفن','mobile'=>'همراه','province'=>'استان','city'=>'شهر','address'=>'آدرس','postal_code'=>'کد پستی','notes'=>'توضیحات'] as $key=>$label){
        $html.='<label>'.$label.'<input name="'.$key.'" value="'.e($row[$key]??'').'"'.($key==='name'?' required':'').'></label>';
    }
    return $html;
}
function company_resources_post(string $page,string $action): never {
    $tenant=company_resources_guard();
    if (!branches_ready()) throw new RuntimeException('ابتدا مهاجرت شعب را اجرا کنید.');
    $actor=(int)user()['id'];
    db()->beginTransaction();
    try {
        query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
        if ($page==='branches' && $action==='add') {
            $fields=company_branch_fields($_POST);
            $name=$fields['name'];
            $num=filter_var(ascii_digits((string)($_POST['number']??'')),FILTER_VALIDATE_INT,['options'=>['min_range'=>2,'max_range'=>999999]]);
            if (!$num || $name==='' || mb_strlen($name)>180) throw new RuntimeException('نام و شماره شعبه معتبر نیست.');
            query('INSERT INTO branches(company_id,branch_number,branch_code,'.implode(',',array_keys($fields)).') VALUES('.implode(',',array_fill(0,count($fields)+3,'?')).')',array_merge([$tenant,$num,str_pad((string)$num,3,'0',STR_PAD_LEFT)],array_values($fields)));
            $branchId=(int)db()->lastInsertId();
            query('INSERT INTO cash_registers(company_id,branch_id,name) VALUES(?,?,?)',[$tenant,$branchId,'صندوق پیش‌فرض شعبه '.$num.' #'.$branchId]);
        } elseif ($page==='branches' && in_array($action,['rename','edit'],true)) {
            $id=filter_var($_POST['branch_id']??null,FILTER_VALIDATE_INT);
            $row=query('SELECT * FROM branches WHERE id=? AND company_id=? FOR UPDATE',[$id,$tenant])->fetch();
            if(!$row) throw new RuntimeException('شعبه معتبر نیست.');
            if($action==='rename'){
                $fields=company_branch_fields(array_merge($row,['name'=>$_POST['name']??'']));
                $active=(int)$row['is_active'];
            }else{
                $fields=company_branch_fields($_POST);
                $active=isset($_POST['is_active'])?1:0;
            }
            if(!$active && (int)$row['is_default']===1) throw new RuntimeException('شعبه مرکزی پیش‌فرض باید فعال بماند.');
            if(!$active && query("SELECT s.id FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE r.company_id=? AND r.branch_id=? AND s.status IN ('open','pending','rejected') LIMIT 1",[$tenant,$id])->fetch()) throw new RuntimeException('ابتدا شیفت‌های صندوق این شعبه را تسویه کنید.');
            query('UPDATE branches SET '.implode(',',array_map(static fn($key)=>$key.'=?',array_keys($fields))).',is_active=? WHERE id=? AND company_id=?',array_merge(array_values($fields),[$active,$id,$tenant]));
        } elseif ($page==='branch_users' && in_array($action,['assign','revoke'],true)) {
            $id=filter_var($_POST['branch_id']??null,FILTER_VALIDATE_INT);
            if(!query('SELECT id FROM branches WHERE id=? AND company_id=?',[$id,$tenant])->fetch()) throw new RuntimeException('شعبه معتبر نیست.');
            if($action==='assign') branch_require($tenant,$actor,$id);
            $uid=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT);
            if(!$uid||!query('SELECT id FROM users WHERE id=? AND company_id=? AND is_active=1 FOR UPDATE',[$uid,$tenant])->fetch())throw new RuntimeException('کاربر معتبر نیست.');
            if($action==='revoke'){
                query('UPDATE user_branch_assignments SET is_active=0,is_default=0 WHERE company_id=? AND user_id=? AND branch_id=?',[$tenant,$uid,$id]);
            }else{
            $default=isset($_POST['is_default'])?1:0;
            if($default)query('UPDATE user_branch_assignments SET is_default=0 WHERE company_id=? AND user_id=?',[$tenant,$uid]);
            query('INSERT INTO user_branch_assignments(company_id,user_id,branch_id,is_default,is_active) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE is_active=1,is_default=VALUES(is_default)',[$tenant,$uid,$id,$default]);
            }
            if(!query('SELECT branch_id FROM user_branch_assignments WHERE company_id=? AND user_id=? AND is_active=1 AND is_default=1',[$tenant,$uid])->fetch()){
                $fallback=query('SELECT a.branch_id FROM user_branch_assignments a JOIN branches b ON b.id=a.branch_id AND b.company_id=a.company_id WHERE a.company_id=? AND a.user_id=? AND a.is_active=1 AND b.is_active=1 ORDER BY b.branch_number LIMIT 1',[$tenant,$uid])->fetchColumn();
                if($fallback)query('UPDATE user_branch_assignments SET is_default=1 WHERE company_id=? AND user_id=? AND branch_id=?',[$tenant,$uid,$fallback]);
            }
        } elseif ($page==='bank_accounts' && in_array($action,['bank','bank_status'],true)) {
            if(!is_super()) throw new RuntimeException('تغییر فهرست سراسری بانک‌ها فقط برای مدیرکل مجاز است.');
            if($action==='bank_status'){
                $bank=filter_var($_POST['bank_id']??null,FILTER_VALIDATE_INT);
                if(!$bank) throw new RuntimeException('بانک معتبر نیست.');
                query('UPDATE banks SET is_active=? WHERE id=?',[isset($_POST['is_active'])?1:0,$bank]);
            }else{
            $name=trim((string)($_POST['name']??''));
            if($name===''||mb_strlen($name)>160)throw new RuntimeException('نام بانک معتبر نیست.');
            query('INSERT INTO banks(name) VALUES(?) ON DUPLICATE KEY UPDATE name=VALUES(name)',[$name]);
            }
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
/** Repair missing shared bank reference data; preserve existing IDs and disabled states. */
function company_bank_directory(): array {
    $names=['بانک ملی ایران','بانک سپه','بانک کشاورزی','بانک مسکن','بانک صنعت و معدن','بانک توسعه صادرات ایران','پست بانک ایران','بانک توسعه تعاون','بانک ملت','بانک صادرات ایران','بانک تجارت','بانک رفاه کارگران','بانک کارآفرین','بانک سامان','بانک اقتصاد نوین','بانک پاسارگاد','بانک پارسیان','بانک سرمایه','بانک سینا','بانک شهر','بانک ایران زمین','بانک گردشگری','بانک دی','بانک قرض الحسنه مهر ایران','بانک خاورمیانه','بانک ایران و ونزوئلا','بانک قرض الحسنه رسالت','مؤسسه اعتباری ملل'];
    $existing=query('SELECT name FROM banks')->fetchAll(PDO::FETCH_COLUMN);
    $normalize=static fn(string $name): string => preg_replace('/[\s\x{200c}\x{200d}]+/u','',str_replace(['ي','ك','ؤ','أ'],['ی','ک','و','ا'],$name));
    $known=array_fill_keys(array_map($normalize,$existing),true);
    foreach($names as $name){
        if(isset($known[$normalize($name)]))continue;
        query('INSERT INTO banks(name) VALUES(?) ON DUPLICATE KEY UPDATE id=id',[$name]);
    }
    return query('SELECT id,name FROM banks WHERE is_active=1 ORDER BY name')->fetchAll();
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
        echo '<h1>مدیریت شعب</h1><div class="card"><h2>تعریف شعبه</h2><form method="post" class="form-grid">'.$csrf.'<input type="hidden" name="action" value="add"><label>شماره شعبه<input name="number" inputmode="numeric" required></label>'.company_branch_form().'<button>ثبت شعبه</button></form></div>';
        $search=trim((string)($_GET['branch_search']??''));
        $state=(string)($_GET['branch_state']??'');
        echo '<form method="get" class="form-grid"><input type="hidden" name="page" value="branches"><label>جستجوی شعبه<input name="branch_search" value="'.e($search).'" maxlength="180"></label><label>وضعیت<select name="branch_state"><option value="">همه</option><option value="1"'.($state==='1'?' selected':'').'>فعال</option><option value="0"'.($state==='0'?' selected':'').'>غیرفعال</option></select></label><button>نمایش</button></form>';
        $sql='SELECT * FROM branches WHERE company_id=?';$params=[$tenant];
        if($search!==''){$sql.=' AND (name LIKE ? OR branch_code LIKE ? OR manager_name LIKE ?)';$params=array_merge($params,array_fill(0,3,'%'.mb_substr($search,0,180).'%'));}
        if(in_array($state,['0','1'],true)){$sql.=' AND is_active=?';$params[]=(int)$state;}
        foreach(query($sql.' ORDER BY branch_number',$params)->fetchAll() as $row){
            echo '<details class="card"><summary>'.e($row['name']).' — '.fa_digits((string)$row['branch_number']).' — '.((int)$row['is_active']?'فعال':'غیرفعال').'</summary><form method="post" class="form-grid">'.$csrf.'<input type="hidden" name="action" value="edit"><input type="hidden" name="branch_id" value="'.(int)$row['id'].'">'.company_branch_form($row).'<label><input type="checkbox" name="is_active" value="1"'.((int)$row['is_active']?' checked':'').'>فعال</label><button>ذخیره شعبه</button></form></details>';
        }
    } elseif($page==='branch_users'){
        $users=query('SELECT id,full_name FROM users WHERE company_id=? AND is_active=1 ORDER BY full_name',[$tenant])->fetchAll();
        echo '<h1>تخصیص کاربران به شعب</h1><div class="card"><form method="post" class="form-grid">'.$csrf.'<input type="hidden" name="action" value="assign"><label>کاربر<select name="user_id" required>'.resource_options($users,'full_name').'</select></label><label>شعبه<select name="branch_id" required>'.$options.'</select></label><label><input type="checkbox" name="is_default" value="1">شعبه پیش‌فرض</label><button>ثبت تخصیص</button></form></div>';
        $assignments=query('SELECT a.*,u.full_name,b.name FROM user_branch_assignments a JOIN users u ON u.id=a.user_id AND u.company_id=a.company_id JOIN branches b ON b.id=a.branch_id AND b.company_id=a.company_id WHERE a.company_id=? ORDER BY u.full_name,b.branch_number',[$tenant])->fetchAll();
        foreach($assignments as $assignment){
            echo '<div class="card"><p>'.e($assignment['full_name']).' — '.e($assignment['name']).' — '.((int)$assignment['is_active']?'فعال':'لغوشده').((int)$assignment['is_default']?' — پیش‌فرض':'').'</p>';
            if((int)$assignment['is_active'])echo '<form method="post">'.$csrf.'<input type="hidden" name="action" value="revoke"><input type="hidden" name="user_id" value="'.(int)$assignment['user_id'].'"><input type="hidden" name="branch_id" value="'.(int)$assignment['branch_id'].'"><button class="secondary">لغو دسترسی شعبه</button></form>';
            echo '</div>';
        }
    } elseif($page==='bank_accounts'){
        echo '<h1>حساب‌های بانکی شرکت</h1>';
        if(!database_table_exists('company_bank_accounts')){echo '<div class="card">ابتدا مهاجرت ۰۲۵ را اجرا کنید.</div>';return;}
        $banks=company_bank_directory();
        if(is_super()){
            echo '<details class="card"><summary>فعال‌سازی و غیرفعال‌سازی بانک‌ها</summary>';
            foreach(query('SELECT id,name,is_active FROM banks ORDER BY name')->fetchAll() as $bank){
                echo '<form method="post" class="form-grid">'.$csrf.'<input type="hidden" name="action" value="bank_status"><input type="hidden" name="bank_id" value="'.(int)$bank['id'].'"><label><input type="checkbox" name="is_active" value="1"'.((int)$bank['is_active']?' checked':'').'>'.e($bank['name']).'</label><button>ذخیره وضعیت بانک</button></form>';
            }
            echo '</details>';
        }

        if(is_super()) echo '<div class="card"><h2>تعریف بانک</h2><form method="post" class="form-grid">'.$csrf.'<input type="hidden" name="action" value="bank"><label>نام بانک<input name="name" maxlength="160" required></label><button>ثبت بانک</button></form></div>';
        echo '<div class="card"><h2>تعریف حساب</h2><form method="post" class="form-grid">'.$csrf.'<input type="hidden" name="action" value="account"><label>عنوان حساب<input name="title" maxlength="160" required></label><label>بانک<select name="bank_id" required><option value="">انتخاب بانک</option>'.resource_options($banks).'</select></label><label>شعبه<select name="branch_id"><option value="">مشترک شرکت</option>'.$options.'</select></label><label>شماره حساب<input name="account_number" maxlength="40"></label><label>شبا<input name="iban" dir="ltr" maxlength="26"></label><button>ثبت حساب</button></form></div>';
        render_table(['عنوان','بانک','شعبه','شماره حساب','شبا'],query("SELECT a.title,k.name bank,COALESCE(b.name,'مشترک شرکت') branch,a.account_number,a.iban FROM company_bank_accounts a JOIN banks k ON k.id=a.bank_id LEFT JOIN branches b ON b.id=a.branch_id AND b.company_id=a.company_id WHERE a.company_id=? ORDER BY a.id DESC",[$tenant])->fetchAll());
    }
}
