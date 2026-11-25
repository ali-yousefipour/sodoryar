<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/expense_reports.php';
function expense_require_permission(string $action): void {if($action!=='view')throw new LogicException('Missing view permission');}
function expense_require_document(int $tenant,int $id): array {
    if($tenant!==7 || $id!==9)throw new RuntimeException('Unauthorized document');
    return ['id'=>9,'branch_id'=>2,'created_by'=>31,'category_id'=>4,'document_number'=>'EXP-9','approval_status'=>'approved','expense_date'=>'2026-11-25','title'=>'<script>bad</script>','description'=>'Document description','subtotal'=>'1234','extra_amount'=>'0','discount_amount'=>'0','total_amount'=>'1234','notes'=>'Saved notes','created_at'=>'2026-11-25 10:11:12','version'=>2];
}
function e(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function display_financial_reference(mixed $value): string {return (string)$value;}
function fa_digits(mixed $value): string {return (string)$value;}
function jalali_datetime(string $value,bool $time=false): string {return 'JALALI '.$value;}
function money_toman(mixed $value): string {return (string)$value.' T';}
function query(string $sql,array $params=[]): object {
    $rows=[];$column='';
    if(str_contains($sql,'FROM companies'))$column='Test company';
    elseif(str_contains($sql,'FROM branches'))$column='Test branch';
    elseif(str_contains($sql,'FROM users'))$column='Creator';
    elseif(str_contains($sql,'FROM expense_categories'))$column='Test category';
    elseif(str_contains($sql,'FROM expense_items'))$rows=[['title'=>'Paper','description'=>'Item description','quantity_milli'=>1250,'unit'=>'Pack','unit_price'=>'1000','line_total'=>'1250']];
    elseif(str_contains($sql,'FROM expense_payments p')){
        if(str_contains($sql,'account_number') || str_contains($sql,'iban'))throw new RuntimeException('Print requested sensitive bank data');
        $rows=[['method'=>'bank','amount'=>'1234','paid_date'=>'2026-11-25','posted_at'=>'2026-11-25 10:11:12','register_name'=>null,'bank_title'=>'Operating account','tracking_number'=>'TRACK-7']];
    }
    elseif(str_contains($sql,'FROM expense_attachments'))$rows=[['id'=>81,'original_name'=>'receipt.pdf']];
    elseif(str_contains($sql,'FROM expense_events'))$rows=[['created_at'=>'2026-11-25 10:11:12','actor_name'=>'Reviewer','action'=>'approved','reason'=>'Checked']];
    return new class($rows,$column) {public function __construct(private array $rows,private string $column){} function fetchAll(): array{return $this->rows;}function fetchColumn(): string{return $this->column;}};
}
ob_start();render_expense_receipt(7,9);$html=ob_get_clean();
foreach(['Test category','Item description','1.25 Pack','Operating account','TRACK-7','?page=expense_attachment&amp;id=81','receipt.pdf','Reviewer','Checked','Saved notes','JALALI 2026-11-25 10:11:12','receipt-a4','&lt;script&gt;bad&lt;/script&gt;'] as $expected)
    if(!str_contains($html,$expected))throw new RuntimeException('Receipt missing saved field: '.$expected);
if(str_contains($html,'<script>bad</script>'))throw new RuntimeException('Stored HTML escaped incorrectly');
echo "Expense receipt saved details, privacy, attachments and HTML escaping passed\n";
