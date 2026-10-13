<?php
/**
 * Disposable MySQL integration proof for the old-money read projection.
 * Only randomly named local databases are created; no production connection is used.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../scripts/import/customer_008_morestore_balance_history.php';
// Exercise the production union SQL against the disposable target database.
class BalanceTestDb
{
    public static $pdo;
    public static function connect() { return new class { public function getConfig(string $key): string { return $key === 'prefix' ? 'eb_' : ''; } }; }
    public static function query(string $sql): array { return self::$pdo->query($sql)->fetchAll(); }
}
class_alias(BalanceTestDb::class, 'think\\facade\\Db');
require_once __DIR__ . '/../../../后端代码/app/services/mobile/customer/MobileCustomerAssetRecordServices.php';

$password = (string)getenv('M008_TEST_PASSWORD');
if ($password === '') throw new RuntimeException('M008_TEST_PASSWORD is required');
$root = new PDO('mysql:host=127.0.0.1;port=13318;charset=utf8mb4','root',$password,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$suffix = bin2hex(random_bytes(4));
$sourceName = 'balance_src_' . $suffix;
$targetName = 'balance_dst_' . $suffix;
$root->exec('CREATE DATABASE `' . $sourceName . '` CHARACTER SET utf8mb4');
$root->exec('CREATE DATABASE `' . $targetName . '` CHARACTER SET utf8mb4');
try {
    $source = new PDO('mysql:host=127.0.0.1;port=13318;dbname='.$sourceName.';charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $target = new PDO('mysql:host=127.0.0.1;port=13318;dbname='.$targetName.';charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $source->exec('CREATE TABLE eb_user_money (id int PRIMARY KEY,uid int,link_id varchar(32),type varchar(64),title varchar(64),number decimal(12,2),balance decimal(12,2),pm tinyint,mark varchar(512),add_time int,store_id int,status tinyint)');
    $source->exec('CREATE TABLE eb_system_store (id int PRIMARY KEY,name varchar(120))');
    $source->exec('CREATE TABLE eb_user (uid int PRIMARY KEY,now_money decimal(12,2))');
    $source->exec("INSERT INTO eb_system_store VALUES (30,'旧门店甲'),(46,'旧门店乙')");
    $source->exec('INSERT INTO eb_user VALUES (421,13692.00),(2143,0.88)');
    $target->exec('CREATE TABLE mig_morestore_20261013_member_map (source_uid int PRIMARY KEY,target_uid int,source_balance_yuan decimal(12,2))');
    $target->exec('INSERT INTO mig_morestore_20261013_member_map VALUES (421,421,13692.00),(2143,2143,0.00)');
    $target->exec('CREATE TABLE eb_user (uid int PRIMARY KEY,now_money decimal(12,2))');
    $target->exec('INSERT INTO eb_user VALUES (421,13692.00)');
    $target->exec('CREATE TABLE eb_cashier_v3_balance_fact (id int PRIMARY KEY,tenant_id varchar(8),member_id int,fact_type varchar(32),status varchar(32),store_id int,settled_at int,occurred_at int,fact_id varchar(64),business_date date,balance_change_type varchar(64),principal_delta_cents int,bonus_delta_cents int,principal_after_cents int,bonus_after_cents int,order_no_snapshot varchar(64),source_document_type varchar(64),store_name_snapshot varchar(120),operator_name_snapshot varchar(120))');
    $target->exec("INSERT INTO eb_cashier_v3_balance_fact VALUES (1,'0',421,'balance_changed','effective',108,1735290000,1735290000,'fact1','2024-12-27','recharge_credit',100,0,1369300,0,'order1','recharge','新门店','员工')");
    $target->exec('CREATE TABLE eb_database_upgrade_log (upgrade_key varchar(100) PRIMARY KEY,title varchar(100),task_file varchar(255),sql_checksum char(64),code_version char(40),executed_at datetime,executed_by varchar(30),result_note text,created_at datetime)');
    foreach (explode(';', (string)file_get_contents(BALANCE_SQL)) as $statement) {
        if (trim($statement) !== '') $target->exec($statement);
    }
    $insert = $source->prepare('INSERT INTO eb_user_money VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    for ($i=1;$i<=47;$i++) {
        $insert->execute([$i,421,(string)$i,$i===1?'recharge':'pay_product',$i===1?'用户充值渼约币':'渼约币支付购买商品',100,13692-$i,$i===1?1:0,'旧单'.$i,1735285004+$i,$i%2?30:46,1]);
    }
    $insert->execute([48,999,'','recharge','孤儿',20,20,1,'',1735286000,30,1]);
    $insert->execute([49,421,'','recharge','草稿',20,20,1,'',1735286001,30,0]);
    [$rows,$skipped,$hash,$skipHash] = balanceSnapshot($source,[421=>421],1735300000);
    if (count($rows)!==47 || count($skipped)!==1 || $skipped[0][0]!==48) throw new RuntimeException('Snapshot count or orphan audit differs');
    if ($rows[0]['store_name_snapshot']!=='旧门店甲' || $rows[1]['store_name_snapshot']!=='旧门店乙') throw new RuntimeException('Old store names differ');
    balanceAccountGuard($source,$target);
    putenv('MOHE_RELEASE_COMMIT='.str_repeat('a',40));
    balanceApply($target,$rows,$skipped,$hash,$skipHash,1735300000);
    $result = balanceVerify($target);
    if ($result['source_rows']!==48 || $result['imported_rows']!==47 || $result['skipped_rows']!==1 || $result['popo_rows']!==47) throw new RuntimeException('Verification differs');
    if ((string)$target->query('SELECT now_money FROM eb_user WHERE uid=421')->fetchColumn()!=='13692.00') throw new RuntimeException('Current balance was changed');
    BalanceTestDb::$pdo = $target;
    $service = (new ReflectionClass(\app\services\mobile\customer\MobileCustomerAssetRecordServices::class))->newInstanceWithoutConstructor();
    $merged = new ReflectionMethod($service,'mergedBalancePage');
    $first = $merged->invoke($service,421,[108],1,20);
    $last = $merged->invoke($service,421,[108],3,20);
    $oldOnly = $merged->invoke($service,421,[999],1,20);
    if (count($first)!==20 || count($last)!==8 || (int)$first[0]['origin']!==0
        || (int)$first[1]['origin']!==1 || (int)$oldOnly[0]['origin']!==1) {
        throw new RuntimeException('Merged chronology, pagination or store scope differs');
    }
    $source->exec('UPDATE eb_user SET now_money=13693 WHERE uid=421');
    try { balanceAccountGuard($source,$target); throw new RuntimeException('Expected stale account guard'); }
    catch (RuntimeException $error) { if ($error->getMessage()==='Expected stale account guard') throw $error; }
    echo "PASS 008 legacy balance import, orphan audit, digest, current-balance guard, merged pagination and store scope\n";
} finally {
    $root->exec('DROP DATABASE `' . $sourceName . '`');
    $root->exec('DROP DATABASE `' . $targetName . '`');
}
