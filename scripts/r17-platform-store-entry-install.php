<?php
declare(strict_types=1);

/**
 * 第 17 轮逐实例文件安装器。仅接受固定清单中的平台、门店正式入口和五组业务接口文件；
 * 先备份每个原件，再按指纹比较切换，入口 HTML 最后切换。数据库与旧预览入口不在范围内。
 */
$directory = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$expectedManifest = $argv[3] ?? '';
if (!preg_match('~^/www/backups/(rh|008|010)/r17-store-entry-([a-f0-9]{8})$~D', $directory, $parts)
    || !in_array($mode, ['prepare', 'install', 'verify', 'rollback'], true)
    || !preg_match('/^[a-f0-9]{64}$/D', $expectedManifest)) {
    throw new RuntimeException('固定实例、模式和清单指纹必填');
}
$manifestPath = $directory . '/payload/manifest.json';
if (!is_file($manifestPath) || hash_file('sha256', $manifestPath) !== $expectedManifest) {
    throw new RuntimeException('发布清单指纹不符');
}
$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$site = $parts[1];
$root = '/www/wwwroot/' . ($site === 'rh' ? 'rh.cc3798.com' : $site . '.cc3798.com');
if (($manifest['site'] ?? null) !== $site || substr((string)($manifest['commit'] ?? ''), 0, 8) !== $parts[2]
    || !is_array($manifest['targets'] ?? null) || !$manifest['targets']) {
    throw new RuntimeException('实例或提交范围不符');
}

/** 后端仅开放本轮实际修改的路径；静态资源只允许正式入口下的构建文件。 */
function r17Destination(string $key, string $root): string
{
    if (strpos($key, '..') !== false || strpos($key, "\0") !== false || strpos($key, '\\') !== false) {
        throw new RuntimeException('不安全的目标路径');
    }
    $backend = [
        'app/controller/admin/v1/store/PlatformStoreEntry.php',
        'app/controller/cashier/v3/PlatformStoreEntry.php',
        'app/services/cashier/v3/CashierV3StoreLoginServices.php',
        'app/services/cashier/v3/PlatformStoreEntryBridgeServices.php',
        'app/services/report/GroupManagementDashboardServices.php',
        'app/services/target/TargetSchemeReportServices.php',
        'route/admin.php',
        'route/cashier-v3.php',
    ];
    $allowed = in_array($key, $backend, true)
        || $key === 'public/system.html'
        || $key === 'public/view_cashier_v3/index.html'
        || preg_match('~^public/view_admin/(?:js|css|img|fonts|media)/[A-Za-z0-9_./-]+$~D', $key)
        || preg_match('~^public/view_cashier_v3/assets/[A-Za-z0-9_.-]+$~D', $key);
    if (!$allowed) throw new RuntimeException('超出本轮发布范围: ' . $key);
    $destination = $root . '/' . $key;
    if (is_link($destination)) throw new RuntimeException('拒绝覆盖符号链接: ' . $key);
    $parent = dirname($destination);
    while (!file_exists($parent)) $parent = dirname($parent);
    $real = realpath($parent);
    if ($real === false || ($real !== $root && strpos($real, $root . '/') !== 0)) {
        throw new RuntimeException('目标路径越出实例: ' . $key);
    }
    return $destination;
}

/** 空值只表示文件原先不存在，新资源回滚时删除；历史资源不清理。 */
function r17Digest(string $path): ?string
{
    clearstatcache(true, $path);
    return is_file($path) ? hash_file('sha256', $path) : null;
}

/** 原子切换保留站点文件所有者，避免新的前端资源被应用进程拒绝读取。 */
function r17Put(string $source, string $destination): void
{
    if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) {
        throw new RuntimeException('不能创建目标目录');
    }
    $next = $destination . '.r17-' . getmypid();
    if (file_exists($next) || is_link($next) || !copy($source, $next)) {
        throw new RuntimeException('不能准备原子替换文件');
    }
    chmod($next, 0644);
    $ownerPath = is_file($destination) ? $destination : dirname($destination);
    chown($next, fileowner($ownerPath));
    chgrp($next, filegroup($ownerPath));
    if (!rename($next, $destination)) throw new RuntimeException('原子切换失败');
}

$targets = $manifest['targets'];
// 先落后端与静态资源，两个入口文件最后切换；manifest 保持固定且不改变业务值。
uksort($targets, static function (string $left, string $right): int {
    $priority = static function (string $key): int {
        if ($key === 'public/system.html') return 2;
        if ($key === 'public/view_cashier_v3/index.html') return 3;
        return 1;
    };
    return ($priority($left) <=> $priority($right)) ?: strcmp($left, $right);
});
foreach ($targets as $key => $entry) {
    r17Destination($key, $root);
    if (!is_array($entry) || !preg_match('/^[a-f0-9]{64}$/D', (string)($entry['after'] ?? ''))
        || (($entry['before'] ?? null) !== null && !preg_match('/^[a-f0-9]{64}$/D', (string)$entry['before']))) {
        throw new RuntimeException('目标指纹格式错误: ' . $key);
    }
}
$recordPath = $directory . '/prepared.json';
if ($mode === 'prepare') {
    if (file_exists($recordPath)) throw new RuntimeException('本轮回滚点已经存在');
    $record = [];
    foreach ($targets as $key => $entry) {
        $destination = r17Destination($key, $root);
        $source = $directory . '/payload/' . $key;
        if (r17Digest($destination) !== ($entry['before'] ?? null)
            || r17Digest($source) !== $entry['after']) throw new RuntimeException('现网或候选指纹变化: ' . $key);
        $backup = $directory . '/before/' . hash('sha256', $key);
        if ($entry['before'] !== null) {
            if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0700, true);
            if (!copy($destination, $backup) || r17Digest($backup) !== $entry['before']) {
                throw new RuntimeException('原文件备份失败: ' . $key);
            }
        }
        $record[$key] = $entry + ['backup' => $backup];
    }
    if (file_put_contents($recordPath, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        throw new RuntimeException('回滚清单写入失败');
    }
    chmod($recordPath, 0600);
} else {
    $record = is_file($recordPath) ? json_decode((string)file_get_contents($recordPath), true) : null;
    if (!is_array($record) || array_keys($record) !== array_keys($targets)) {
        throw new RuntimeException('缺少本轮独立回滚点');
    }
    foreach ($targets as $key => $entry) {
        if ($record[$key]['after'] !== $entry['after'] || ($record[$key]['before'] ?? null) !== ($entry['before'] ?? null)) {
            throw new RuntimeException('回滚清单与发布清单不一致');
        }
        if ($entry['before'] !== null && r17Digest($record[$key]['backup']) !== $entry['before']) {
            throw new RuntimeException('回滚原件损坏: ' . $key);
        }
    }
    if ($mode === 'install') {
        foreach ($targets as $key => $entry) {
            if (r17Digest(r17Destination($key, $root)) !== ($entry['before'] ?? null)
                || r17Digest($directory . '/payload/' . $key) !== $entry['after']) {
                throw new RuntimeException('安装前发生并发变更: ' . $key);
            }
        }
        $installed = [];
        try {
            foreach ($targets as $key => $entry) {
                r17Put($directory . '/payload/' . $key, r17Destination($key, $root));
                $installed[] = $key;
            }
        } catch (Throwable $error) {
            foreach (array_reverse($installed) as $key) {
                $destination = r17Destination($key, $root);
                if (r17Digest($destination) !== $targets[$key]['after']) continue;
                if ($targets[$key]['before'] === null) unlink($destination);
                else r17Put($record[$key]['backup'], $destination);
            }
            throw $error;
        }
    } elseif ($mode === 'rollback') {
        foreach ($targets as $key => $entry) {
            if (!in_array(r17Digest(r17Destination($key, $root)), [$entry['before'] ?? null, $entry['after']], true)) {
                throw new RuntimeException('回滚前发生并发变更: ' . $key);
            }
        }
        foreach (array_reverse($targets, true) as $key => $entry) {
            $destination = r17Destination($key, $root);
            if (r17Digest($destination) !== $entry['after']) continue;
            if ($entry['before'] === null) unlink($destination);
            else r17Put($record[$key]['backup'], $destination);
        }
    }
    foreach ($targets as $key => $entry) {
        $expected = $mode === 'rollback' ? ($entry['before'] ?? null) : $entry['after'];
        if (r17Digest(r17Destination($key, $root)) !== $expected) {
            throw new RuntimeException('切换后指纹不符: ' . $key);
        }
    }
}
echo 'R17_' . strtoupper($mode) . '_PASS targets=' . count($targets) . PHP_EOL;
