<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$serviceFile = $root . '/后端代码/app/services/store/RoomSettingsServices.php';
$migrationRoot = $root . '/后端代码/database/upgrades/2026-08-05-门店房间名称乱码修复';

$service = file_get_contents($serviceFile);
if ($service === false || strpos($service, "mb_check_encoding(\$name, 'UTF-8')") === false) {
    fwrite(STDERR, "Room settings must reject invalid UTF-8 names.\n");
    exit(1);
}

foreach (['00-升级清单.md', '01-升级前检查.sql', '02-正式升级.sql', '03-升级后验证.sql', '04-回滚或应急说明.md'] as $file) {
    if (!is_file($migrationRoot . '/' . $file)) {
        fwrite(STDERR, "Missing room-name repair artifact: {$file}\n");
        exit(1);
    }
}

$precheck = file_get_contents($migrationRoot . '/01-升级前检查.sql');
$apply = file_get_contents($migrationRoot . '/02-正式升级.sql');
$postcheck = file_get_contents($migrationRoot . '/03-升级后验证.sql');
if ($precheck === false || $apply === false || $postcheck === false
    || strpos($precheck, 'HEX(remarks)') === false
    || strpos($apply, 'UPDATE eb_table_qrcode') === false
    || strpos($apply, 'id=9 AND store_id=118 AND table_number=9001') === false
    || strpos($apply, 'id=10 AND store_id=118 AND table_number=9002') === false
    || strpos($apply, 'CONVERT(0x5141323032363038303420E9AA8CE694B6E688BFE997B441 USING utf8mb4)') === false
    || strpos($apply, 'CONVERT(0x5141323032363038303420E9AA8CE694B6E688BFE997B442 USING utf8mb4)') === false
    || strpos($postcheck, 'POSTCHECK_OK') === false) {
    fwrite(STDERR, "Room-name repair must remain exact-byte scoped and auditable.\n");
    exit(1);
}

echo "ROOM_SETTINGS_ENCODING_CONTRACT_OK\n";
