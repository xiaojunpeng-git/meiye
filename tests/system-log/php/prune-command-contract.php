<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$command = $root . '/后端代码/app/command/PruneSystemLog.php';
$console = $root . '/后端代码/config/console.php';

$source = file_get_contents($command);
$config = file_get_contents($console);
$failed = 0;

function verify(bool $condition, string $message): void
{
    global $failed;
    if ($condition) {
        echo "PASS {$message}\n";
        return;
    }
    $failed++;
    echo "FAIL {$message}\n";
}

verify(strpos($config, "'system-log:prune' => \\app\\command\\PruneSystemLog::class") !== false, 'console command is registered');
verify(strpos($source, "->addOption('execute'") !== false, 'deletion requires an explicit execute option');
verify(strpos($source, "private const DEFAULT_RETENTION_DAYS = 15") !== false, 'default retention is fifteen days');
verify(strpos($source, "Db::name('system_log')") !== false, 'command targets only system_log');
verify(strpos($source, "->where('add_time', '>', 0)") !== false, 'zero-timestamp rows are preserved');
verify(strpos($source, "->where('add_time', '<', \$cutoff)") !== false, 'only expired rows are eligible');
verify(strpos($source, "->limit(\$batchSize)") !== false, 'deletion is batched');
verify(strpos($source, 'store_order') === false && strpos($source, 'user_card') === false, 'command does not mention business fact tables');

exit($failed === 0 ? 0 : 1);
