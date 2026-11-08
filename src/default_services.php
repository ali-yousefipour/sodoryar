<?php
declare(strict_types=1);

/** Initial tariffs in toman. Copied into each new company's editable services. */
function default_company_services(): array {
    return [
        ['ابطال پروانه بهره برداری',80000],
        ['ابطال پروانه تاکسیرانی',100000],
        ['ارسال درخواست تاییدیه حراست',130000],
        ['ارسال سمپا',80000],
        ['استعلام بیمه یا معاینه فنی',40000],
        ['استعلام محل سکونت با قبض',40000],
        ['اصلاح اطلاعات خودرویی',80000],
        ['اصلاح اطلاعات فردی',80000],
        ['بارگزاری عکس بازرسی',80000],
        ['برچسب کد بهره برداری با اعداد',80000],
        ['بررسی و ارسال مدارک به اماکن',200000],
        ['پرداخت آبونمان و تخلفات',16500],
        ['تشکیل پرونده ویژه',180000],
        ['تمدید پروانه تاکسیرانی',200000],
        ['ثبت کمکی',160000],
        ['چاپ انواع معرفی نامه از سامانه',50000],
        ['چاپ کارت هوشمند تاکسیرانی',80000],
        ['حذف کمکی',60000],
        ['درخواست رزرو خط',80000],
        ['درخواست کارت المثنی',80000],
        ['درخواست معافیت آبونمان',44000],
        ['صدور پروانه تاکسیرانی',485000],
        ['صدور و چاپ برگه مرخصی',65000],
        ['صدور و چاپ مجوز تردد',100000],
        ['فرم استعلام سلامت',25000],
        ['فرم بهداشت طب کار',85000],
        ['گرفتن عکس پرسنلی تاکسیران',35000],
        ['نامه های بازنشستگی',60000],
        ['نقل و انتقال',550000],
    ];
}

function initialize_company_catalog(int $companyId, string $companyName): void {
    foreach (default_company_services() as [$title,$price]) {
        query('INSERT INTO services(company_id,title,price) VALUES(?,?,?)',[$companyId,$title,$price]);
    }
    query('INSERT INTO print_templates(company_id,title,paper_type,header_text,footer_text,layout_json,is_default) VALUES(?,?,?,?,?,?,1)',[
        $companyId,'قالب پیش‌فرض '.$companyName,'a5','فاکتور خدمات','با سپاس از مراجعه شما',
        json_encode(['font'=>'Vazirmatn','order'=>['header','invoice_number','date','customer','items','total','payment','footer']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
    ]);
    query('INSERT INTO app_settings(company_id,setting_key,setting_value) VALUES(?,?,?)',[$companyId,'currency','toman']);
}
