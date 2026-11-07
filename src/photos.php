<?php
declare(strict_types=1);

function photo_directory(): string {
    return dirname(__DIR__) . '/uploads/user-photos';
}

function user_photo_table_ready(): bool {
    static $ready=null;
    if ($ready===null) {
        try { query('SELECT 1 FROM user_photos LIMIT 0'); $ready=true; }
        catch (PDOException $ex) { error_log('Sodoryar user photo migration pending: '.$ex->getCode()); $ready=false; }
    }
    return $ready;
}

function validate_photo_target(int $target): array {
    $person=query('SELECT id,company_id FROM users WHERE id=? AND is_active=1',[$target])->fetch();
    if (!$person) { http_response_code(404); exit('کاربر پیدا نشد'); }
    if ($target !== (int)user()['id']) {
        require_admin();
        if (!is_super() && (int)$person['company_id'] !== tenant_id()) { http_response_code(403); exit('دسترسی مجاز نیست'); }
    }
    return $person;
}

function save_profile_photo(int $target): void {
    if (!user_photo_table_ready()) throw new RuntimeException('جدول تصاویر کاربران نصب نشده است؛ ابتدا میگرشن شماره ۰۰۵ را در phpMyAdmin اجرا کنید.');
    validate_photo_target($target);
    $file=$_FILES['photo']??null;
    if (!is_array($file) || (int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('فایل تصویر دریافت نشد.');
    $size=(int)($file['size']??0);
    if ($size<100 || $size>5*1024*1024 || !is_uploaded_file((string)$file['tmp_name'])) throw new RuntimeException('تصویر باید کمتر از ۵ مگابایت باشد.');
    if (!extension_loaded('gd')) throw new RuntimeException('افزونه GD روی هاست فعال نیست.');
    $info=@getimagesize((string)$file['tmp_name']);
    if (!$info || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true) || $info[0]>2400 || $info[1]>2400) throw new RuntimeException('تصویر JPG، PNG یا WebP با ابعاد حداکثر ۲۴۰۰ پیکسل انتخاب کنید.');
    $source=@imagecreatefromstring((string)file_get_contents($file['tmp_name']));
    if (!$source) throw new RuntimeException('تصویر قابل خواندن نیست.');
    $side=min(imagesx($source),imagesy($source));
    $x=(int)((imagesx($source)-$side)/2); $y=(int)((imagesy($source)-$side)/2);
    $thumbnail=imagecreatetruecolor(320,320);
    imagecopyresampled($thumbnail,$source,0,0,$x,$y,320,320,$side,$side);
    imagedestroy($source);
    $directory=photo_directory();
    if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('پوشه تصاویر قابل ایجاد نیست.');
    $key=bin2hex(random_bytes(16)).'.jpg';
    $path=$directory.'/'.$key;
    if (!imagejpeg($thumbnail,$path,78)) { imagedestroy($thumbnail); throw new RuntimeException('ذخیره تصویر ناموفق بود.'); }
    imagedestroy($thumbnail);
    chmod($path,0600);
    try {
        query('INSERT INTO user_photos(user_id,storage_key,mime_type) VALUES(?,?,?) ON DUPLICATE KEY UPDATE storage_key=VALUES(storage_key),mime_type=VALUES(mime_type)',[$target,$key,'image/jpeg']);
    } catch(Throwable $ex) { @unlink($path); throw $ex; }
    audit('user.photo.update',(string)$target);
}

function serve_profile_photo(int $target): never {
    require_login(); validate_photo_target($target);
    if (!user_photo_table_ready()) { http_response_code(404); exit; }
    $record=query('SELECT storage_key FROM user_photos WHERE user_id=?',[$target])->fetch();
    if (!$record || !preg_match('/^[a-f0-9]{32}\.jpg$/',(string)$record['storage_key'])) { http_response_code(404); exit; }
    $path=photo_directory().'/'.$record['storage_key'];
    if (!is_file($path)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Length: '.(string)filesize($path));
    readfile($path); exit;
}
