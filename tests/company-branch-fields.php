<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/company_resources.php';
function ascii_digits(string $text): string {return strtr($text,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);}
$valid=company_branch_fields(['name'=>'شعبه دوم','postal_code'=>'۱۲۳۴۵۶۷۸۹۰','mobile'=>'۰۹۱۲۳۴۵۶۷۸۹']);
if($valid['postal_code']!=='1234567890'||$valid['mobile']!=='09123456789') throw new RuntimeException('Digit normalization failed');
foreach([['name'=>''],['name'=>'شعبه','postal_code'=>'123'],['name'=>'شعبه','phone'=>'<script>'],['name'=>str_repeat('x',181)]] as $input){
    $rejected=false;
    try {company_branch_fields($input);} catch(RuntimeException $ex){$rejected=true;}
    if(!$rejected)throw new RuntimeException('Invalid branch field accepted');
}
echo "Branch field validation passed\n";
