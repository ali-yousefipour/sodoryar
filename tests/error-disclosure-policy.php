<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';

$sqlError=new PDOException("SQLSTATE[42S02]: Table 'private_company_secret' doesn't exist");
$visible=public_operation_error($sqlError);
if(str_contains($visible,'SQLSTATE') || str_contains($visible,'private_company_secret')
    || !str_contains($visible,'خطای داخلی')) {
    throw new RuntimeException('PDO details leaked into user-facing errors.');
}
$expected='فیلد واردشده نامعتبر است.';
if(public_operation_error(new RuntimeException($expected))!==$expected) {
    throw new RuntimeException('Safe validation messages should remain visible.');
}
if(str_contains(public_operation_error(new TypeError('private method and path')),'private')) {
    throw new RuntimeException('Unexpected PHP errors leaked into user-facing responses.');
}
$index=(string)file_get_contents(__DIR__.'/../public/index.php');
if (!str_contains($index,'$error = public_operation_error($ex);')
    || !str_contains($index,'catch (PDOException $ex) {')
    || !str_contains($index,"Sodoryar report database failure")) {
    throw new RuntimeException('Front controller does not apply the centralized SQL error policy.');
}
echo "Safe SQL error presentation behavior passed\n";
