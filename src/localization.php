<?php
declare(strict_types=1);

/** Convert a valid Gregorian ISO date to a Persian Solar Hijri date, without intl extension. */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array {
    $monthDays=[31,28,31,30,31,30,31,31,30,31,30,31];
    $gy2=$gm>2?$gy+1:$gy;
    $days=355666+365*$gy+intdiv($gy2+3,4)-intdiv($gy2+99,100)+intdiv($gy2+399,400)+$gd;
    for ($i=0;$i<$gm-1;$i++) $days+=$monthDays[$i];
    $jy=-1595+33*intdiv($days,12053); $days%=12053;
    $jy+=4*intdiv($days,1461); $days%=1461;
    if ($days>365) { $jy+=intdiv($days-1,365); $days=($days-1)%365; }
    if ($days<186) { $jm=1+intdiv($days,31); $jd=1+$days%31; }
    else { $jm=7+intdiv($days-186,30); $jd=1+($days-186)%30; }
    return [$jy,$jm,$jd];
}

function jalali_datetime(?string $value, bool $dateOnly=false): string {
    $value=trim((string)$value);
    if ($value==='') return '—';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/', $value,$m)) return fa_digits($value);
    [$jy,$jm,$jd]=gregorian_to_jalali((int)$m[1],(int)$m[2],(int)$m[3]);
    $result=sprintf('%04d/%02d/%02d',$jy,$jm,$jd);
    if (!$dateOnly && isset($m[4])) $result.=' '.sprintf('%02d:%02d',$m[4],$m[5]);
    return fa_digits($result);
}

function jalali_to_gregorian_date(string $jalali): string {
    $plain=strtr(trim($jalali),['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    if (!preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/',$plain,$parts)) throw new RuntimeException('تاریخ شمسی باید به صورت ۱۴۰۵/۰۸/۱۶ باشد.');
    $target=sprintf('%04d/%02d/%02d',(int)$parts[1],(int)$parts[2],(int)$parts[3]);
    if ((int)$parts[2]<1 || (int)$parts[2]>12 || (int)$parts[3]<1 || (int)$parts[3]>31) throw new RuntimeException('تاریخ شمسی نامعتبر است.');
    $start=new DateTimeImmutable(((int)$parts[1]+620).'-01-01',new DateTimeZone('UTC'));
    $lo=0; $hi=1100;
    while ($lo<=$hi) {
        $mid=intdiv($lo+$hi,2); $dt=$start->modify('+'.$mid.' days');
        [$y,$m,$d]=gregorian_to_jalali((int)$dt->format('Y'),(int)$dt->format('n'),(int)$dt->format('j'));
        $value=sprintf('%04d/%02d/%02d',$y,$m,$d);
        if ($value===$target) return $dt->format('Y-m-d');
        if ($value<$target) $lo=$mid+1; else $hi=$mid-1;
    }
    throw new RuntimeException('تاریخ شمسی معتبر نیست.');
}

/** Parse localized integer money inputs without changing the stored numeric unit. */
function parse_money_input(mixed $value, int $minimum=0, int $maximum=100000000000): int|false {
    if (!is_scalar($value)) return false;
    $raw=ascii_digits(trim((string)$value));
    // Never turn malformed grouping or decimal input (for example "1,5")
    // into a different amount ("15"). Allow only integer digits or complete
    // 3-digit thousands groups with localized separators.
    if (!preg_match('/^[0-9]+$/D',$raw)
        && !preg_match('/^[0-9]{1,3}(?:[,٬، ][0-9]{3})+$/uD',$raw)) return false;
    $digits=str_replace([',','٬','،',' '], '', $raw);
    if ($digits==='' || strlen($digits)>15) return false;
    $number=filter_var($digits,FILTER_VALIDATE_INT,['options'=>['min_range'=>$minimum,'max_range'=>$maximum]]);
    return $number===false?false:(int)$number;
}

/** Validate invoice-selected service identifiers before building an IN query. */
function normalize_invoice_service_ids(mixed $submitted): array {
    if (!is_array($submitted) || count($submitted)>500) {
        throw new RuntimeException('تعداد خدمات انتخاب‌شده یا ساختار درخواست نامعتبر است.');
    }
    $ids=[];
    foreach ($submitted as $value) {
        if (!is_string($value) && !is_int($value)) {
            throw new RuntimeException('شناسه خدمت انتخاب‌شده نامعتبر است.');
        }
        $raw=(string)$value;
        if (!preg_match('/^[1-9][0-9]*$/D',$raw)) {
            throw new RuntimeException('شناسه خدمت انتخاب‌شده نامعتبر است.');
        }
        $id=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false) {
            throw new RuntimeException('شناسه خدمت انتخاب‌شده خارج از محدوده معتبر است.');
        }
        $ids[$id]=$id;
    }
    return array_values($ids);
}

/** Validate ad-hoc invoice rows with the same monetary parser as fixed services. */
function normalize_invoice_extra_rows(mixed $titles,mixed $prices): array {
    if (!is_array($titles) || !is_array($prices) || count($titles)>10 || count($prices)>10) {
        throw new RuntimeException('حداکثر ۱۰ ردیف خدمات موردی مجاز است.');
    }
    if (array_keys($titles)!==array_keys($prices)) {
        throw new RuntimeException('ردیف‌های خدمات موردی ناقص یا ناسازگار هستند.');
    }
    $rows=[];
    foreach ($titles as $key=>$value) {
        if (!is_string($value) || !is_scalar($prices[$key])) {
            throw new RuntimeException('ردیف خدمات موردی نامعتبر است.');
        }
        $title=trim($value);
        $raw=trim((string)$prices[$key]);
        if ($title==='' && $raw==='') continue;
        $amount=parse_money_input($raw,1,100000000000);
        if ($title==='' || mb_strlen($title)>200 || $amount===false) {
            throw new RuntimeException('عنوان و مبلغ خدمت موردی معتبر نیست.');
        }
        $rows[]=['title'=>$title,'price'=>$amount];
    }
    return $rows;
}

/** Sum integer monetary lines safely; never silently round an overflowing invoice. */
function sum_invoice_line_amounts(array $lines): int {
    $total=0;
    foreach ($lines as $line) {
        if (!is_array($line) || !array_key_exists('price',$line) || !is_scalar($line['price'])) {
            throw new RuntimeException('مبلغ یکی از ردیف‌های فاکتور نامعتبر است.');
        }
        $raw=(string)$line['price'];
        if (!preg_match('/^(?:0|[1-9][0-9]*)$/D',$raw)) {
            throw new RuntimeException('مبلغ یکی از ردیف‌های فاکتور نامعتبر است.');
        }
        $amount=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if ($amount===false || $amount>PHP_INT_MAX-$total) {
            throw new RuntimeException('مجموع مبالغ فاکتور بیش از محدوده مجاز است.');
        }
        $total+=$amount;
    }
    return $total;
}

/** Integer milli-percent units: 1.500% => 1500, with no binary float. */
function commission_rate_milli_percent(mixed $raw): int {
    if (!is_scalar($raw)) throw new RuntimeException('نرخ پورسانت نامعتبر است.');
    $text=str_replace('٫','.',ascii_digits(trim((string)$raw)));
    if (!preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\\.[0-9]{1,3})?$/D',$text)) {
        throw new RuntimeException('نرخ پورسانت باید بین صفر تا صد و حداکثر سه رقم اعشار باشد.');
    }
    $parts=explode('.',$text,2);
    $milli=(int)$parts[0]*1000+(int)str_pad($parts[1]??'',3,'0',STR_PAD_RIGHT);
    if ($milli>100000) throw new RuntimeException('نرخ پورسانت نباید بیشتر از صد درصد باشد.');
    return $milli;
}

/** Apply percentage(s) precisely to whole toman, rounding only once at the end. */
function commission_amount_toman(int $sales,int $limit,int $baseRate,int $extraRate,bool $threshold): int {
    if ($sales<0 || $limit<0 || $baseRate<0 || $baseRate>100000 || $extraRate<0 || $extraRate>100000) {
        throw new RuntimeException('مبلغ یا نرخ محاسبه پورسانت معتبر نیست.');
    }
    $base=$threshold?min($sales,$limit):$sales;
    $extra=$threshold?($sales-$base):0;
    $quotient=intdiv($base,100000)*$baseRate + intdiv($extra,100000)*$extraRate;
    $remainder=($base%100000)*$baseRate + ($extra%100000)*$extraRate;
    return $quotient + intdiv($remainder+50000,100000);
}

/** Preserve searchable Latin payment references; localize only numeric references. */
function display_financial_reference(mixed $reference): string {
    $value=(string)($reference??'');
    return preg_match('/[A-Za-z]/',$value) ? $value : fa_digits($value);
}

/** Display monetary values in the existing stored unit (toman). */
function money_toman(int|string $amount): string {
    return money($amount).' تومان';
}

/** Date-aware conversion for common table fields; other values keep their meaning. */
function ui_value(mixed $value, string $key=''): string {
    $value=(string)($value??'');
    $key=strtolower($key);
    if ($key==='period_key' && preg_match('/^1[34]\\d{2}-\\d{2}(?:-\\d{2})?$/',$value)) return fa_digits(str_replace('-','/',$value));
    $dateFields=['created_at','updated_at','deleted_at','archived_at','invoice_date','payment_date','paid_at','issued_at','approved_at','expires_at','due_date','date','timestamp','start_date','end_date','from_date','to_date'];
    if ((in_array($key,$dateFields,true) || preg_match('/(?:_date|_at)$/',$key))
        && preg_match('/^\\d{4}-\\d{2}-\\d{2}(?:[ T]\\d{2}:\\d{2})?/',$value)) {
        return jalali_datetime($value);
    }
    return fa_digits($value);
}

/** Convert an integer amount stored in toman to Persian words without floating point. */
function toman_in_words(int|string $amount): string {
    $number=(int)$amount;
    if ($number<0) throw new InvalidArgumentException('Negative invoice amounts are not supported.');
    if ($number===0) return 'صفر تومان';
    $ones=['','یک','دو','سه','چهار','پنج','شش','هفت','هشت','نه'];
    $teens=['ده','یازده','دوازده','سیزده','چهارده','پانزده','شانزده','هفده','هجده','نوزده'];
    $tens=['','','بیست','سی','چهل','پنجاه','شصت','هفتاد','هشتاد','نود'];
    $hundreds=['','صد','دویست','سیصد','چهارصد','پانصد','ششصد','هفتصد','هشتصد','نهصد'];
    $scales=['','هزار','میلیون','میلیارد','هزار میلیارد','کوادریلیون'];
    $groups=[];
    $scale=0;
    while ($number>0) {
        $triplet=$number%1000;
        if ($triplet!==0) {
            $parts=[];
            $h=intdiv($triplet,100);
            $rem=$triplet%100;
            if ($h) $parts[]=$hundreds[$h];
            if ($rem>=10 && $rem<20) $parts[]=$teens[$rem-10];
            else {
                $t=intdiv($rem,10);
                $u=$rem%10;
                if ($t) $parts[]=$tens[$t];
                if ($u) $parts[]=$ones[$u];
            }
            $part=implode(' و ',$parts);
            if ($scale && isset($scales[$scale])) $part.=' '.$scales[$scale];
            array_unshift($groups,$part);
        }
        $number=intdiv($number,1000);
        $scale++;
    }
    return implode(' و ',$groups).' تومان';
}
