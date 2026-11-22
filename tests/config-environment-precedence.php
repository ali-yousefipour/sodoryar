<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

$key='SODORYAR_TEST_CONFIG_OVERRIDE';
putenv($key.'=0');
if (config($key,'fallback') !== '0') throw new RuntimeException('Environment value 0 was discarded.');
putenv($key.'=');
if (config($key,'fallback') !== '') throw new RuntimeException('Empty environment override was discarded.');
putenv($key);
if (config($key,'fallback') !== 'fallback') throw new RuntimeException('Absent environment variable did not use fallback.');
echo "Environment configuration precedence passed\n";
