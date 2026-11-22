<?php
declare(strict_types=1);
$workflow=(string)file_get_contents(__DIR__.'/../.github/workflows/deploy-shared-host.yml');
$marker=strpos($workflow,'Verify FTP destination belongs to Sodoryar before changing files');
$upload=strpos($workflow,'Upload through FTP with host-compatible TLS');
$check=strpos($workflow,"grep -Fxq 'SODORYAR_DEPLOY_ROOT'");
if($marker===false||$upload===false||$check===false||!($marker<$check&&$check<$upload)) {
    throw new RuntimeException('FTP target ownership verification must run before every upload.');
}
if(!str_contains($workflow,'_private/.sodoryar-instance')
   || substr_count($workflow,'set ssl:verify-certificate yes')<2) {
    throw new RuntimeException('Protected deployment marker and TLS identity checks are required.');
}
if(!str_contains($workflow,'needs: database-integration')) {
    throw new RuntimeException('FTPS upload must be gated on isolated MySQL integration.');
}
echo "Release ownership, TLS and migration gate contracts passed\n";
