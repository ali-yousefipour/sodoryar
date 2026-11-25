<?php
declare(strict_types=1);
function ascii_digits(string $text): string {return strtr($text,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);}
require dirname(__DIR__).'/src/invoice_payments.php';
require dirname(__DIR__).'/src/cashbox.php';
require dirname(__DIR__).'/src/expenses.php';
function calculation_check(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
$item=['title'=>'کالای آزمایشی','quantity'=>'۱.۲۵۰','unit_price'=>'۱٬۲۰۰','unit'=>'عدد'];
$result=expense_items_calculate([$item],'200','100');
calculation_check($result['subtotal']===1500 && $result['total_amount']===1600 && $result['items'][0]['quantity_milli']===1250,'Exact decimal quantity calculation failed');
$half=$item;$half['quantity']='0.005';$half['unit_price']='100';
calculation_check(expense_items_calculate([$half])['total_amount']===1,'Half-toman rounding must be deterministic');
$below=$half;$below['quantity']='0.004';$below['unit_price']='1000';
calculation_check(expense_items_calculate([$below])['total_amount']===4,'Fractional quantity lost precision');
$invalid=[[],array_fill(0,201,$item),[array_merge($item,['quantity'=>'0'])],[array_merge($item,['quantity'=>'-1'])],[array_merge($item,['quantity'=>'1.0001'])],[array_merge($item,['quantity'=>'1e2'])],[array_merge($item,['quantity'=>'1,000'])],[array_merge($item,['quantity'=>'1000000'])],[array_merge($item,['unit_price'=>'0'])],[array_merge($item,['unit_price'=>'1000000000001'])],[array_merge($item,['unit_price'=>(string)PHP_INT_MAX])],[array_merge($item,['title'=>''])],[array_merge($item,['title'=>str_repeat('x',181)])],[array_merge($item,['quantity'=>'999999.999','unit_price'=>'1000000000000'])],['invalid']];
foreach($invalid as $items){$rejected=false;try{expense_items_calculate($items);}catch(RuntimeException $ex){$rejected=true;}calculation_check($rejected,'Invalid or overflowing expense item accepted');}
foreach([['-1','0'],['0','1500'],['0','1501'],['1000000000000000','0']] as [$extra,$discount]){$rejected=false;try{expense_items_calculate([$item],$extra,$discount);}catch(RuntimeException $ex){$rejected=true;}calculation_check($rejected,'Invalid extra/discount accepted');}
calculation_check(expense_items_calculate([$item],'0','1499')['total_amount']===1,'Small positive total was rejected');
echo "Expense calculation exact quantity, rounding, discount and overflow boundaries passed\n";
