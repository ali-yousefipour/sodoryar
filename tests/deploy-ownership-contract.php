<?php
declare(strict_types=1);
$workflow=(string)file_get_contents(__DIR__.'/../.github/workflows/deploy-shared-host.yml');
$marker=strpos($workflow,'Verify FTP destination belongs to Sodoryar before changing files');
$upload=strpos($workflow,'Upload through dedicated FTP account');
$check=strpos($workflow,"grep -Fxq 'SODORYAR_DEPLOY_ROOT'");
if($marker===false||$upload===false||$check===false||!($marker<$check&&$check<$upload)) {
    throw new RuntimeException('FTP target ownership verification must run before every upload.');
}
if(!str_contains($workflow,'_private/.sodoryar-instance')
   || substr_count($workflow,'set ftp:ssl-allow false')<2
   || str_contains($workflow,'set ftp:ssl-force true')
   || str_contains($workflow,'FTPS_CA_PEM')) {
    throw new RuntimeException('Protected destination marker and plain FTP transport configuration must be consistent.');
}
if (!str_contains($workflow, 'needs: [source-validation, database-integration]')
    || !str_contains($workflow, "needs.source-validation.result == 'success'")
    || !str_contains($workflow, "needs.database-integration.result == 'success'")) {
    throw new RuntimeException('FTP upload must require both successful source/regression and isolated database integration jobs.');
}
if (!str_contains($workflow, 'run: bash scripts/validate-release.sh')) {
    throw new RuntimeException('Independent source/regression release gate must run before deployment.');
}
if (substr_count($workflow, "vars.SODORYAR_FTP_REMOTE_DIR || '.'") !== 3
    || substr_count($workflow, 'Unsafe or unspecified Sodoryar deployment directory.') !== 2
    || substr_count($workflow, 'if [[ "$FTP_REMOTE_DIR" != "." ]]') !== 3
    || !str_contains($workflow, 'get "_private/.sodoryar-instance"')
    || substr_count($workflow, 'lftp --user "$FTP_USER" --password "$FTP_PASS"') < 2
    || str_contains($workflow, 'FTP_REMOTE_DIR: /')
    || !str_contains($workflow, 'mirror -R --verbose --no-perms --transfer-all --parallel=2 deploy-payload/public "$FTP_REMOTE_DIR"')) {
    throw new RuntimeException('Dedicated Sodoryar FTP deployment must use only its jailed root and verified credentials.');
}
if (!str_contains($workflow, 'if [[ -f "$marker_file" ]]; then')
    || !str_contains($workflow, 'Existing FTP destination marker does not identify Sodoryar.')
    || !str_contains($workflow, 'sodoryar-ownership-$(openssl rand -hex 16).txt')
    || !str_contains($workflow, 'https://taxiranico.ir/$challenge')
    || !str_contains($workflow, 'FTP root does not serve the declared Sodoryar HTTPS domain.')
    || !str_contains($workflow, 'put "$RUNNER_TEMP/sodoryar-marker" -o "_private/.sodoryar-instance"')
    || !str_contains($workflow, 'New destination marker failed verification.')) {
    throw new RuntimeException('Populated FTP root bootstrap must validate its public origin, preserve existing markers, and verify any new marker.');
}
if (!str_contains($workflow, 'Validate isolated Sodoryar target and public origin before upload')
    || substr_count($workflow, 'SODORYAR_PUBLIC_BASE_URL: https://taxiranico.ir') !== 4
    || str_contains($workflow, 'vars.SODORYAR_PUBLIC_BASE_URL')
    || substr_count($workflow, 'Expected the fixed Sodoryar HTTPS origin.') !== 3
    || str_contains($workflow, 'https://taxiranico.ir/assets/')
    || str_contains($workflow, 'https://taxiranico.ir/sodoryar-version.json')
    || !str_contains($workflow, '${SODORYAR_PUBLIC_BASE_URL}/sodoryar-version.json')
    || !str_contains($workflow, '${SODORYAR_PUBLIC_BASE_URL}/assets/invoice-redesign.css')
    || !str_contains($workflow, '${SODORYAR_PUBLIC_BASE_URL}/assets/$file')) {
    throw new RuntimeException('Deployment verification must use the fixed Sodoryar public origin while preserving FTP ownership checks.');
}
if (!str_contains($workflow, 'Verify private application files cannot be served publicly')
    || !str_contains($workflow, '_private/.env.example')
    || !str_contains($workflow, '_private/src/bootstrap.php')
    || !str_contains($workflow, '_private/.sodoryar-instance')
    || !str_contains($workflow, '403|404|410')) {
    throw new RuntimeException('Deployed private source and environment templates must be probed for public exposure.');
}
if (!str_contains($workflow, 'login-experience.css workspace-refresh.css login-ui.js app.js')
    || !str_contains($workflow, "grep -Fq 'assets/login-experience.css?v='")
    || !str_contains($workflow, "grep -Fq 'class=\"login-layout\"'")) {
    throw new RuntimeException('Release must verify both published login CSS bytes and login HTML references.');
}
$verifyPrivate=strpos($workflow,'Verify deployed private PHP files match the triggering commit');
$verifyPublic=strpos($workflow,'Verify deployed application commit manifest');
if ($verifyPrivate===false || $verifyPublic===false || !($upload<$verifyPrivate && $verifyPrivate<$verifyPublic)
    || !str_contains($workflow, 'mirror -R --verbose --no-perms --transfer-all --parallel=2 deploy-payload/_private')
    || !str_contains($workflow, 'get "_private/src/modules.php"')
    || !str_contains($workflow, 'get "_private/src/bootstrap.php"')
    || !str_contains($workflow, 'get "_private/src/localization.php"')
    || !str_contains($workflow, 'get "_private/src/reporting.php"')
    || !str_contains($workflow, 'Private PHP file $file does not match the commit after FTP upload.')) {
    throw new RuntimeException('Deployment must force and read-back-verify private PHP code before claiming success.');
}
echo "Release ownership, FTP transport and migration gate contracts passed\n";
