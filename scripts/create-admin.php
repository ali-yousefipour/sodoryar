<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
if ($argc !== 3) { fwrite(STDERR,"Usage: php scripts/create-admin.php <username> <full-name>\nPassword will be read from stdin.\n"); exit(1); }
$username = $argv[1]; $name = $argv[2];
if (!preg_match('/^[a-zA-Z0-9_.-]{3,100}$/',$username)) { fwrite(STDERR,"Invalid username\n"); exit(1); }
fwrite(STDOUT,"Password (min 12 characters): ");
$password = trim((string)fgets(STDIN));
if (strlen($password)<12) { fwrite(STDERR,"Password too short\n"); exit(1); }
query('INSERT INTO users(company_id,username,password_hash,full_name,role) VALUES(NULL,?,?,?,?)',[$username,password_hash($password,PASSWORD_DEFAULT),$name,'superadmin']);
fwrite(STDOUT,"Superadmin created. Remove this script from public deployment.\n");
