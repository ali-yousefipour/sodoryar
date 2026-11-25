<?php
declare(strict_types=1);

/** Render only after the document has been loaded through expense_scope(). */
function render_expense_financial_actions(int $tenant,array $x): void {
    if(!expense_extensions_ready()){
        if(expense_manager())echo '<section class="card"><p>برای تسویه و ابطال هزینه، مهاجرت ۰۲۷ لازم است.</p></section>';
        return;
    }
    $id=(int)$x['id'];$branch=(int)$x['branch_id'];$actor=(int)user()['id'];
    $approved=$x['approval_status']==='approved';
    $due=$approved?expense_employee_due($tenant,$id):0;
    $settlements=query('SELECT s.*,a.title account_title,r.name register_name FROM expense_settlements s LEFT JOIN company_bank_accounts a ON a.id=s.account_id AND a.company_id=s.company_id LEFT JOIN cash_shifts sh ON sh.id=s.cash_shift_id AND sh.company_id=s.company_id LEFT JOIN cash_registers r ON r.id=sh.register_id AND r.company_id=sh.company_id WHERE s.company_id=? AND s.expense_id=? ORDER BY s.id',[$tenant,$id])->fetchAll();
    ?>
    <?php if($approved||$settlements):?>
    <section class="card"><h2>تسویه هزینه کارکنان</h2><p>مانده قابل بازپرداخت به <?=e($x['full_name'])?>: <strong><?=money_toman($due)?></strong></p>
    <?php if($settlements):?><div style="overflow:auto"><table><thead><tr><th>شماره تسویه</th><th>تاریخ</th><th>روش</th><th>منبع</th><th>مبلغ</th></tr></thead><tbody><?php foreach($settlements as $s):?><tr><td><?=e(fa_digits($s['id']))?></td><td><?=e(jalali_datetime($s['created_at']))?></td><td><?=e(expense_method($s['method']))?></td><td><?=e($s['account_title']??$s['register_name']??'—')?></td><td><?=money_toman($s['amount'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
    <?php if(expense_manager()&&$approved&&$due>0):
        $banks=query('SELECT id,title FROM company_bank_accounts WHERE company_id=? AND is_active=1 AND (branch_id IS NULL OR branch_id=?) ORDER BY title',[$tenant,$branch])->fetchAll();
        $cash=query("SELECT s.id,r.name title FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.company_id=? AND s.operator_id=? AND s.status='open' AND r.is_active=1 AND r.branch_id=? ORDER BY r.name",[$tenant,$actor,$branch])->fetchAll();
        if(!$banks&&!$cash):?><p>برای ثبت تسویه، حساب بانکی مجاز یا صندوق فعال متعلق به شما در این شعبه لازم است.</p><?php endif;
        foreach(['bank'=>$banks,'cash'=>$cash] as $method=>$sources):if(!$sources)continue;?>
        <details><summary>تسویه از <?=e(expense_method($method))?></summary>
        <form method="post" action="?page=expenses&amp;detail=<?=e($id)?>" class="form-grid"><?=csrf_field()?>
        <input type="hidden" name="action" value="settle"><input type="hidden" name="id" value="<?=e($id)?>"><input type="hidden" name="method" value="<?=e($method)?>"><input type="hidden" name="request_key" value="<?=e(bin2hex(random_bytes(32)))?>">
        <label>مبلغ تسویه (تومان)<input name="amount" inputmode="numeric" required value="<?=e(money($due))?>"></label>
        <label>منبع پرداخت<select name="source" required><option value="">انتخاب کنید</option><?php foreach($sources as $source):?><option value="<?=e($source['id'])?>"><?=e($source['title'])?></option><?php endforeach;?></select></label>
        <label><input type="checkbox" name="payment_confirmed" value="1" required> پرداخت واقعی انجام شده و مبلغ را تأیید می‌کنم.</label><button type="submit">ثبت تسویه</button>
        </form></details>
        <?php endforeach;endif;?></section>
    <?php endif;?>
    <?php render_expense_returns($tenant,$x); ?>
    <?php if(expense_manager()&&in_array($x['approval_status'],['approved','pending','rejected','draft'],true)):?>
    <section class="card"><h2>ابطال سند هزینه</h2>
    <details><summary>ابطال با حفظ تاریخچه</summary><p>ابتدا تمام پرداخت‌های نقدی، بانکی و تسویه کارکنان را با تأیید دریافت واقعی وجه برگشت دهید. ابطال سند هیچ وجهی به صندوق یا بانک اضافه نمی‌کند.</p>
    <form method="post" action="?page=expenses&amp;detail=<?=e($id)?>" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?=e($id)?>">
    <label>دلیل ابطال<textarea name="reason" required minlength="5" maxlength="500"></textarea></label>
    <label><input type="checkbox" required> ابطال و آثار مالی آن را تأیید می‌کنم.</label><button type="submit">ثبت ابطال</button></form></details></section>
    <?php endif;?>
    <section class="card"><h2>تاریخچه سند</h2><div style="overflow:auto"><table><thead><tr><th>تاریخ</th><th>رویداد</th><th>کاربر</th><th>توضیح</th></tr></thead><tbody>
    <?php $labels=['submitted'=>'ثبت هزینه','approved'=>'تأیید','rejected'=>'رد','settled'=>'تسویه','void'=>'ابطال'];foreach(query('SELECT ev.*,u.full_name FROM expense_events ev LEFT JOIN users u ON u.id=ev.actor_id WHERE ev.company_id=? AND ev.expense_id=? ORDER BY ev.id',[$tenant,$id])->fetchAll() as $ev):?><tr><td><?=e(jalali_datetime($ev['created_at']))?></td><td><?=e($labels[$ev['event_type']]??$ev['event_type'])?></td><td><?=e($ev['full_name']??'—')?></td><td><?=e($ev['reason']??'—')?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php
}
