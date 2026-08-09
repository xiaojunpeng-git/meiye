<?php

require __DIR__ . '/mysql-fixture.php';

use think\facade\Db;

$mode = $argv[1] ?? '';
$workerLabel = $argv[2] ?? 'worker';
if ($mode !== 'concurrent-create') {
    fwrite(STDERR, "UNKNOWN_WORKER_MODE={$mode}\n");
    exit(2);
}

try {
    fwrite(STDERR, "CARE_WORKER_STAGE=booting\n");
    careMysqlBoot();
    $connectionRows = Db::query('SELECT CONNECTION_ID() AS connection_id');
    $connectionId = (int)($connectionRows[0]['connection_id'] ?? 0);
    if ($connectionId <= 0) {
        throw new RuntimeException('WORKER_CONNECTION_ID_MISSING');
    }
    fwrite(STDERR, "CARE_WORKER_LABEL={$workerLabel}\n");
    fwrite(STDERR, "CARE_WORKER_CONNECTION_ID={$connectionId}\n");
    fwrite(STDERR, "CARE_WORKER_STAGE=calling-service\n");
    $result = careMysqlService()->createTask(
        careMysqlActor(),
        careMysqlCreateTaskCommand(9001)
    );
    fwrite(STDERR, "CARE_WORKER_STAGE=service-returned\n");
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('WORKER_RESULT_JSON_FAILED');
    }
    echo 'CARE_WORKER_RESULT=' . $json . "\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, 'CARE_WORKER_ERROR=' . get_class($throwable)
        . ':' . $throwable->getMessage() . "\n");
    exit(1);
}
