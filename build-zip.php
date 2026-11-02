<?php
// Run: php build-zip.php           builds weble-mcp.zip for GitHub Releases (updates itself from GitHub).
//      php build-zip.php --wporg   builds wporg/weble-mcp.zip for wordpress.org: no GitHub updater, no Update URI,
//                                  because wordpress.org delivers updates itself and rejects plugins that don't use it.
$wporg = in_array('--wporg', $argv, true);
$src = __DIR__ . '/weble-mcp';
$out = __DIR__ . ($wporg ? '/wporg/weble-mcp.zip' : '/weble-mcp.zip');
if ($wporg && !is_dir(dirname($out))) mkdir(dirname($out));
@unlink($out);
$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE) !== true) exit("Cannot create $out\n");
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
foreach ($files as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
    if ($wporg && ($rel === 'includes/updater.php' || strpos($rel, 'assets/brand/') === 0)) continue; // wordpress.org serves its own icon and banners
    if ($wporg && $rel === 'weble-mcp.php') {
        $zip->addFromString('weble-mcp/' . $rel, preg_replace('/^ \* Update URI:.*\R/m', '', file_get_contents($f->getPathname())));
        continue;
    }
    $zip->addFile($f->getPathname(), 'weble-mcp/' . $rel);
}
$zip->close();
echo "Built $out\n";
