<?php
declare(strict_types=1);
/** Catch migration drift in generated fresh-install DDL. */
foreach (['database/schema.sql','database/install.sql'] as $path) {
    $sql=(string)file_get_contents(dirname(__DIR__).'/'.$path);
    foreach (['companies','users'] as $table) {
        if (!preg_match('/CREATE TABLE(?: IF NOT EXISTS)? '.preg_quote($table,'/').' \((.*?)\) ENGINE/s',$sql,$match)) {
            throw new RuntimeException('Missing table definition for '.$table.' in '.$path);
        }
        $hasVersion=(bool)preg_match('/\bauth_version\s+BIGINT\s+UNSIGNED\b/i',$match[1]);
        if ($table==='users' && !$hasVersion) throw new RuntimeException('User session version missing from '.$path);
        if ($table==='companies' && $hasVersion) throw new RuntimeException('Session version incorrectly stored on companies in '.$path);
    }
}
echo "Clean-install auth_version table placement checks passed\n";
