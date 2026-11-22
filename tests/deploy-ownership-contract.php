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
if (!str_contains($workflow, 'needs: [source-validation, database-integration]')
    || !str_contains($workflow, "needs.source-validation.result == 'success'")
    || !str_contains($workflow, "needs.database-integration.result == 'success'")) {
    throw new RuntimeException('FTPS upload must require both successful source/regression and isolated database integration jobs.');
}
if (!str_contains($workflow, 'run: bash scripts/validate-release.sh')) {
    throw new RuntimeException('Independent source/regression release gate must run before deployment.');
}
if (substr_count($workflow, 'FTPS_CA_PEM: ${{ secrets.FTPS_CA_PEM }}') !== 2
    || substr_count($workflow, 'openssl x509 -in "$ca_file" -noout') !== 2
    || substr_count($workflow, 'ca_setting="set ssl:ca-file $ca_file"') !== 2
    || substr_count($workflow, '          $ca_setting') !== 2) {
    throw new RuntimeException('Both FTPS phases must support validated custom CA certificates without disabling TLS verification.');
}
if (substr_count($workflow, "vars.SODORYAR_FTP_REMOTE_DIR || ''") !== 2
    || substr_count($workflow, 'Unsafe or unspecified Sodoryar deployment directory.') !== 2
    || substr_count($workflow, '"$FTP_REMOTE_DIR" == "/taxiranico.ir"') !== 2
    || substr_count($workflow, '"$FTP_REMOTE_DIR" == "/service-yar.ir"') !== 2
    || substr_count($workflow, '"$FTP_REMOTE_DIR" == "/taxiranico.ir/"*') !== 2
    || substr_count($workflow, '"$FTP_REMOTE_DIR" == "/service-yar.ir/"*') !== 2
    || substr_count($workflow, '"$FTP_REMOTE_DIR" == "/khat-yar.ir/"*') !== 2
    || substr_count($workflow, '"$FTP_REMOTE_DIR" == "/app/"*') !== 2
    || str_contains($workflow, 'FTP_REMOTE_DIR: .')
    || substr_count($workflow, 'lftp --user "$FTP_USER" --password "$FTP_PASS"') !== 2) {
    throw new RuntimeException('Shared-host deployment must use a scoped directory and safely passed FTP credentials in both stages.');
}
if (!str_contains($workflow, 'Validate isolated Sodoryar target and public origin before upload')
    || substr_count($workflow, "vars.SODORYAR_PUBLIC_BASE_URL || ''") !== 4
    || substr_count($workflow, 'Specify an independent HTTPS Sodoryar domain in SODORYAR_PUBLIC_BASE_URL.') !== 3
    || str_contains($workflow, 'https://taxiranico.ir/assets/')
    || str_contains($workflow, 'https://taxiranico.ir/sodoryar-version.json')
    || !str_contains($workflow, '${SODORYAR_PUBLIC_BASE_URL}/sodoryar-version.json')
    || !str_contains($workflow, '${SODORYAR_PUBLIC_BASE_URL}/assets/invoice-redesign.css')
    || !str_contains($workflow, '${SODORYAR_PUBLIC_BASE_URL}/assets/$file')) {
    throw new RuntimeException('Deployment validation must target only the separately configured Sodoryar public domain.');
}
echo "Release ownership, TLS and migration gate contracts passed\n";
