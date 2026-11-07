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

function ui_value(mixed $value, string $key=''): string {
    $value=(string)($value??'');
    if ($key==='period_key' && preg_match('/^1[34]\d{2}-\d{2}(?:-\d{2})?$/',$value)) return fa_digits(str_replace('-','/',$value));
    if (in_array($key,['created_at','period_key','date','timestamp'],true) && preg_match('/^\d{4}-\d{2}/',$value)) return jalali_datetime($value);
    return fa_digits($value);
}
