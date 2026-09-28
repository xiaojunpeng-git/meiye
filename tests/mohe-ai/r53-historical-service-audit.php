<?php
/**
 * Read-only, local-instance audit of possible completed-service history.
 * Only schema names and aggregate dates/counts are emitted; this cannot
 * certify completeness or authorize a historical fact migration.
 */
require getcwd().'/vendor/autoload.php';
$app=new think\App(); $app->initialize();
$db=(array)config('database.connections.'.config('database.default'));
if (($db['hostname']??'')!=='mysql' || getenv('MOHE_R53_HISTORY_AUDIT')!=='LOCAL_ONLY') exit("Local explicit opt-in required\n");
$pdo=new PDO('mysql:host='.$db['hostname'].';port='.$db['hostport'].';dbname='.$db['database'].';charset=utf8mb4',
    $db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$db['database']) exit("Local database mismatch\n");
foreach (['store_service_record','store_service','store_order_writeoff','store_writeoff_batch','cashier_v3_entitlement_service_fact'] as $logical) {
    $name=$db['prefix'].$logical;
    $statement=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $statement->execute([$name]); $columns=$statement->fetchAll(PDO::FETCH_COLUMN);
    if (!$columns) {echo json_encode(['table'=>$logical,'present'=>false])."\n";continue;}
    $possibleTime=array_values(array_intersect(['business_date','business_time','service_time','service_date','writeoff_time','add_time','create_time','created_at'], $columns));
    $time=$possibleTime[0]??null;
    $row=['table'=>$logical,'columns'=>$columns,'time_column'=>$time];
    if ($time!==null) {
        $quoted='`'.str_replace('`','``',$name).'`';
        $date='`'.str_replace('`','``',$time).'`';
        $row['aggregate']=$pdo->query("SELECT COUNT(*) rows_total,MIN($date) oldest,MAX($date) newest FROM $quoted")->fetch(PDO::FETCH_ASSOC);
    }
    echo json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
}
// The 2026-09-17 authorized member-asset import names jsj as its source,
// but did not import historical service completions. This metadata-only check
// shows whether a local source schema is available for a separate mapping plan.
$source=$pdo->prepare("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?");
$source->execute(['jsj']);
echo json_encode(['historical_source_schema_present'=>(int)$source->fetchColumn()===1])."\n";
