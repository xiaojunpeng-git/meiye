<?php
// 隔离临时表验证旧充值日期；不访问或修改会员、订单及基础配置。
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/'); $app->initialize();
$c = config('database.connections.mysql');
if (!is_file('/.dockerenv') || !in_array($c['hostname'], ['mysql','mohe-mysql'], true)) throw new RuntimeException('local only');
think\facade\Db::query('SELECT 1');
$p = think\facade\Db::connect()->getPdo();
$p->exec('CREATE TEMPORARY TABLE historical_recharge_date_test (id INT, business_date VARCHAR(10) NULL,pay_time BIGINT,add_time BIGINT)');
$timestamp = (new DateTimeImmutable('2026-10-01 00:30:00',new DateTimeZone('Asia/Shanghai')))->getTimestamp();
$insert = $p->prepare('INSERT INTO historical_recharge_date_test VALUES (?,?,?,?)');
foreach ([[1,null,$timestamp,1],[2,'0000-00-00',$timestamp,1],[3,'2026-09-30',$timestamp,1],[4,'',0,$timestamp],[5,null,0,0]] as $row) $insert->execute($row);
$expr = app\services\cashier\v3\order\CashierV3HistoricalRechargeDate::sql();
$checks=0;
foreach (['+00:00','+08:00','-07:00'] as $tz) {
    $p->exec('SET time_zone='.$p->quote($tz));
    $rows = $p->query('SELECT id,'.$expr.' AS business_date FROM historical_recharge_date_test r ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($rows !== [1=>'2026-10-01',2=>'2026-10-01',3=>'2026-09-30',4=>'2026-10-01',5=>null]) throw new RuntimeException('date mismatch '.$tz);
    ++$checks;
    $ids = $p->query('SELECT id FROM historical_recharge_date_test r WHERE '.$expr." >= '2026-10-01' AND ".$expr." <= '2026-10-31' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    if (array_map('intval',$ids)!==[1,2,4]) throw new RuntimeException('filter mismatch');
    ++$checks;
}
if ((int)$p->query('SELECT COUNT(*) FROM historical_recharge_date_test WHERE business_date IS NULL')->fetchColumn()!==2) throw new RuntimeException('source mutated');
++$checks;
// 与生产 Reader 相同的 ThinkPHP 字段、参数化过滤及别名排序，验证表达式未被字段解析破坏。
$query = think\facade\Db::table('historical_recharge_date_test')->alias('r')
    ->whereRaw($expr . ' >= ?', ['2026-10-01'])
    ->field('r.id,' . $expr . ' AS business_date')->order('business_date', 'desc')->order('r.id','desc')->page(1,2);
$sql = $query->buildSql(false);
$built = $p->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if (array_map('intval',array_column($built,'id'))!==[4,2]) throw new RuntimeException('reader query builder mismatch');
++$checks;
echo "PASS $checks checks: explicit date, old empty date, payment/create fallback, unknown time, timezone, filter, no writes\n";
