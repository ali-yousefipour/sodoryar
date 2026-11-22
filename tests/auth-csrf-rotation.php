<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/bootstrap.php';

$before=csrf_token();
if (!preg_match('/^[a-f0-9]{64}$/D',$before)) {
    throw new RuntimeException('Initial CSRF token is malformed.');
}
rotate_csrf_token();
$after=csrf_token();
if ($after===$before || !preg_match('/^[a-f0-9]{64}$/D',$after)) {
    throw new RuntimeException('CSRF token was not rotated after an identity change.');
}
if (!hash_equals($after,(string)$_SESSION['csrf'])) {
    throw new RuntimeException('Rotated token is not in PHP session.');
}
if (csrf_token()!==$after) {
    throw new RuntimeException('New CSRF token does not remain stable within a session.');
}
$controller=(string)file_get_contents(__DIR__.'/../public/index.php');
foreach ([
    'session_regenerate_id(true);'."\n".'                rotate_csrf_token();',
    "\$_SESSION['selected_company'] = (int)\$id; rotate_csrf_token();",
    "unset(\$_SESSION['selected_company']); rotate_csrf_token();",
] as $contract) {
    if (!str_contains($controller,$contract)) {
        throw new RuntimeException('Missing CSRF rotation at a privilege or tenant boundary.');
    }
}
echo "Authentication and tenant transition CSRF rotation passed\n";
