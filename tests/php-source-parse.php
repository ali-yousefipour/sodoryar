<?php
declare(strict_types=1);

/**
 * Parse all source files without executing application bootstrap or connecting to MySQL.
 * This detects invalid PHP string quoting in reporting SQL as well as other ParseError cases.
 */
$root=dirname(__DIR__);
$folders=['public','src','scripts','tests','integration'];
$files=0;
foreach ($folders as $folder) {
    $dir=$root.'/'.$folder;
    if (!is_dir($dir)) continue;
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if (!$item->isFile() || strtolower($item->getExtension())!=='php') continue;
        $path=$item->getPathname();
        $source=file_get_contents($path);
        if ($source===false) throw new RuntimeException('Could not read '.$path);
        try {
            token_get_all($source,TOKEN_PARSE);
        } catch (ParseError $ex) {
            throw new RuntimeException('PHP syntax invalid in '.substr($path,strlen($root)+1).': '.$ex->getMessage(),0,$ex);
        }
        $files++;
    }
}
if ($files<12) throw new RuntimeException('PHP source audit discovered too few files: '.$files);
echo "PHP source parser passed for $files files\n";
