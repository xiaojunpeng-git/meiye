<?php
declare(strict_types=1);
// Read-only, RH-only discovery; expose schema and source labels, never credentials or customer details.
$env = parse_ini_file('/www/wwwroot/rh.cc3798.com/.env', true)['DATABASE'];
if ($env['DATABASE'] !== 'ruihao' || $env['HOSTNAME'] !== '127.0.0.1' || (string)$env['HOSTPORT'] !== '3306') throw new RuntimeException('Wrong instance');
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=ruihao;charset=utf8mb4', $env['USERNAME'], $env['PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction();
try {
    $columns = $db->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,COLUMN_KEY,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='ruihao' AND TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='ruihao' AND (COLUMN_NAME LIKE '%secondary%name%snapshot%' OR COLUMN_NAME LIKE '%source%secondary%name%')) AND (COLUMN_NAME LIKE '%source%' OR COLUMN_KEY='PRI' OR EXTRA LIKE '%on update%') ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll();
    $catalog = $db->query('SELECT id,parent_id,name,status,version FROM eb_cashier_v3_business_source ORDER BY id')->fetchAll();
    $order = $db->query("SELECT order_no,business_source_primary_id,business_source_secondary_id,business_source_primary_name_snapshot,business_source_secondary_name_snapshot,business_source_label_snapshot FROM eb_cashier_v3_sales_order WHERE order_no='XS26101700432'")->fetchAll();
    $tables = [];
    foreach ($columns as $c) $tables[$c['TABLE_NAME']][] = $c['COLUMN_NAME'];
    $counts = [];
    foreach ($tables as $table => $names) {
        if (strpos($table, '_audit') !== false) continue;
        $secondary = array_values(array_filter($names, function ($v) { return strpos($v, 'secondary') !== false && substr($v, -3) === '_id'; }))[0];
        $primary = str_replace('secondary', 'primary', $secondary);
        $secondaryName = str_replace('_id', '_name_snapshot', $secondary);
        $label = array_values(array_filter($names, function ($v) { return strpos($v, 'label_snapshot') !== false; }))[0];
        $counts[$table] = $db->query("SELECT t.`$primary` primary_id,t.`$secondary` secondary_id,t.`$secondaryName` old_name,t.`$label` old_label,s.name current_name,s.parent_id,COUNT(*) n FROM `$table` t LEFT JOIN eb_cashier_v3_business_source s ON s.id=t.`$secondary` WHERE t.`$secondary`>0 GROUP BY t.`$primary`,t.`$secondary`,t.`$secondaryName`,t.`$label`,s.name,s.parent_id")->fetchAll();
    }
    $triggers = $db->query("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='ruihao'")->fetchAll();
    $engines = $db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='ruihao' AND TABLE_NAME IN ('" . implode("','", array_keys($tables)) . "')")->fetchAll();
    echo json_encode(['catalog'=>$catalog,'counts'=>$counts,'engines'=>$engines,'triggers'=>$triggers,'sample'=>$order,
        'migration'=>$db->query('SELECT id,upgrade_key FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 1')->fetchAll()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
} finally { $db->rollBack(); }
