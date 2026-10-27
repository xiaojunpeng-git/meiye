<?php
declare(strict_types=1);

/**
 * 在已发布的 010 平台入口上只增加源码已构建验证的报表侧栏样式。
 * 保留线上 system.html 的全部现有 JS/CSS 引用，避免用不同基线的完整构建包覆盖已上线功能。
 * 候选 CSS 须先从前端代码/admin/public 上传到 010 专属备份目录，并核对固定指纹。
 */
if (PHP_SAPI !== 'cli' || !in_array('--apply', $argv, true)) {
    throw new RuntimeException('CLI --apply required');
}
$root = '/www/wwwroot/010.cc3798.com';
$backup = '/www/backups/010/20261026-basic-menu-c617f86e';
$entry = $root . '/public/system.html';
$css = $root . '/public/basic-report-menu.css';
$candidate = $backup . '/basic-report-menu.css.candidate';
$before = $backup . '/system-before-menu-style.html';
$entryTmp = $root . '/public/.system-basic-menu.tmp';
$cssTmp = $root . '/public/.basic-report-menu.css.tmp';
$expectedEntry = '1954722ab061671ba2aed07804dce541e63688a0a879c4530aaa5e3fd518a8fe';
$expectedCss = 'fe77aa3a7a1e87db095fda5665b77f398be96938e53322b937d6597ee0bd5c9f';
$expectedSnapshot = '4c22d4dff1f0fc59743a1e2d47bfb24c744fe0f13808c6cc8af49a83f11cbbff';

// 参数与磁盘身份双重约束，使本脚本不能落到其他客户或改变未授权的前端入口。
if (!in_array('--app-root=' . $root, $argv, true) || !in_array('--backup-dir=' . $backup, $argv, true)
    || realpath($root) !== $root || realpath($backup) !== $backup
    || hash_file('sha256', $backup . '/before.json') !== $expectedSnapshot
    || hash_file('sha256', $entry) !== $expectedEntry
    || hash_file('sha256', $candidate) !== $expectedCss
    || file_exists($css) || file_exists($before) || file_exists($entryTmp) || file_exists($cssTmp)) {
    throw new RuntimeException('010 platform entry, candidate CSS or rollback point mismatch');
}

$html = file_get_contents($entry);
$style = file_get_contents($candidate);
if ($html === false || $style === false || substr_count($html, '</head>') !== 1
    || strpos($html, 'basic-report-menu.css') !== false) {
    throw new RuntimeException('010 platform HTML is not an unmodified expected entry');
}
$updated = str_replace('</head>', '<link rel="stylesheet" href="/basic-report-menu.css"></head>', $html);
$owner = fileowner($entry);
$group = filegroup($entry);

/** 仅创建不存在的候选文件；保留原入口回滚副本，避免部分写入覆盖线上文件。 */
function writeNewReleaseFile(string $path, string $body, int $mode, int $owner, int $group): void
{
    $handle = fopen($path, 'x');
    if ($handle === false) throw new RuntimeException('Cannot create release file');
    try {
        if (fwrite($handle, $body) !== strlen($body)) throw new RuntimeException('Incomplete release file');
        fflush($handle);
    } finally {
        fclose($handle);
    }
    if (!chmod($path, $mode) || !chown($path, $owner) || !chgrp($path, $group)) {
        throw new RuntimeException('Cannot preserve release file permissions');
    }
}

writeNewReleaseFile($before, $html, 0600, $owner, $group);
writeNewReleaseFile($cssTmp, $style, 0644, $owner, $group);
writeNewReleaseFile($entryTmp, $updated, 0644, $owner, $group);
if (!rename($cssTmp, $css) || !rename($entryTmp, $entry)) {
    throw new RuntimeException('010 platform atomic release failed; inspect rollback copy');
}
echo json_encode([
    'site' => '010', 'entry_before' => $expectedEntry,
    'entry_after' => hash_file('sha256', $entry), 'css' => hash_file('sha256', $css),
    'rollback' => $before, 'js_assets_untouched' => true,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
