<?php
declare(strict_types=1);

/**
 * 从010补导所用封存库提取007门店统计的最小原始事实。全程只读，不连接007实时库。
 * 输出的是原单/员工分配/项目明细及资格，不是待回填的合计数字；后端统一按这些明细重算。
 * 金额保留历史分精度，不能用010后来整数归整的收银记录替换来源。
 */
$options = getopt('', ['archive:', 'sha256:', 'schema:', 'start:', 'end:']);
date_default_timezone_set('Asia/Shanghai');
$schema = $options['schema'] ?? '';
$archive = $options['archive'] ?? '';
$expected = $options['sha256'] ?? '';
$start = $options['start'] ?? '';
$end = $options['end'] ?? '';
if ($schema !== 'mohe_010_final_20261031'
    || $archive !== '/www/backup/codex-010/007-final-frozen-20261031-235347.sql.gz'
    || $expected !== 'a101785a572130cbb7197acfadc612bd78b4f49dc6e4253bda52b7c2b9534724'
    || $start !== '2026-10-01' || $end !== '2026-10-31'
    || hash_file('sha256', $archive) !== $expected) throw new RuntimeException('SOURCE_SEAL_MISMATCH');
$credentials = parse_ini_file('/root/.mysql57-008.cnf', true, INI_SCANNER_RAW)['client'];
$pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$schema.';charset=utf8mb4',
    $credentials['user'] ?? 'root', $credentials['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
unset($credentials);
$pdo->exec("SET time_zone = '+08:00'");
$pdo->exec('SET TRANSACTION READ ONLY');
$pdo->beginTransaction();
try {
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $schema) throw new RuntimeException('WRONG_SOURCE');
    $from = strtotime($start.' 00:00:00'); $to = strtotime($end.' 23:59:59');
    $q = static fn(string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $prefix = 'eb_';
    // 使用封存时的门店、分类和人员资格，禁止按当前名称猜测历史身份。
    $stores = $q("SELECT id,name FROM {$prefix}system_store WHERE salary_status=1 ORDER BY id");
    $storeList = implode(',', array_map('intval', array_column($stores, 'id')));
    if ($storeList === '') throw new RuntimeException('NO_SOURCE_STORES');
    $cooperation = "SELECT r.product_id FROM {$prefix}store_product_relation r JOIN {$prefix}store_product_category c ON c.id=r.relation_id WHERE r.type=1 AND c.pid=0 AND c.cate_name IN ('合作方','合作类')";
    $period = "b.add_time BETWEEN $from AND $to AND b.store_id IN ($storeList)";
    $parent = '(b.pid IN (0,-2) OR b.pid>0)';
    $cashScope = "((b.pay_type<>'combination' AND b.cash_choose NOT IN (9,10)) OR (b.pay_type='combination' AND b.id IN (SELECT order_id FROM {$prefix}combination_order WHERE cash_choose NOT IN (9,10) AND (pay_sub_type IS NULL OR pay_sub_type<>'debt') AND active_pay<>3)))";
    $orders = $q("SELECT b.id source_id,b.order_id source_no,b.store_id,b.uid member_id,b.service_object,b.add_time occurred_at,FROM_UNIXTIME(b.add_time,'%Y-%m-%d') business_date,b.order_type,b.pay_price,b.cash_pay_price,b.cash_choose,b.pay_type,b.paid,b.refund_status,b.is_system_del,b.pid,b.source,b.link_id FROM {$prefix}store_order b WHERE $period ORDER BY b.id");
    $byId = [];
    foreach ($orders as $row) {
        $row += ['cash_amount'=>'0.00','cooperation_cash'=>'0.00','consumption_amount'=>'0.00',
            'inside_consumption_amount'=>'0.00','service_customer'=>0,'new_customer'=>0,'casual_customer'=>0];
        $row['source_hash'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $byId[(int)$row['source_id']] = $row;
    }
    $cashRows = $q("SELECT b.id,b.pay_price amount FROM {$prefix}store_order b WHERE $period AND $parent AND b.paid=1 AND b.is_system_del=0 AND b.refund_status=0 AND b.order_type IN (0,1) AND b.cash_choose<>9 AND b.pay_type IN ('weixin','alipay','cash','offline')");
    $combined = $q("SELECT a.id line_id,b.id,a.price amount,a.cash_choose,a.active_pay FROM {$prefix}combination_order a JOIN {$prefix}store_order b ON b.id=a.order_id WHERE $period AND $parent AND b.is_system_del=0 AND b.refund_status=0 AND b.order_type IN (0,1) AND b.pay_type='combination' AND a.cash_choose<>9 AND a.active_pay<>3");
    foreach (array_merge($cashRows,$combined) as $row) {
        $id=(int)$row['id'];
        $byId[$id]['cash_amount']=bcadd($byId[$id]['cash_amount'],(string)$row['amount'],2);
    }
    // 原007先汇总同单有效付款，再按购物车应付占比截取到分；不能逐支付行截尾。
    $carts=$q("SELECT c.id,c.oid,c.product_id,c.pay_price,c.cart_type,IF(c.product_id IN ($cooperation),1,0) cooperation FROM {$prefix}store_order_cart_info c JOIN {$prefix}store_order b ON b.id=c.oid WHERE $period AND c.cart_type=0 ORDER BY c.id");
    $cartAmounts=[];
    foreach($carts as $r){
        $id=(int)$r['oid'];$cartAmounts[$id]=$cartAmounts[$id]??['total'=>'0.00','cooperation'=>'0.00'];
        $cartAmounts[$id]['total']=bcadd($cartAmounts[$id]['total'],(string)$r['pay_price'],2);
        if((int)$r['cooperation']===1)$cartAmounts[$id]['cooperation']=bcadd($cartAmounts[$id]['cooperation'],(string)$r['pay_price'],2);
    }
    foreach($cartAmounts as $id=>$amounts) if(bccomp($amounts['total'],'0',2)>0 && bccomp($amounts['cooperation'],'0',2)>0)
        $byId[$id]['cooperation_cash']=bcmul($byId[$id]['cash_amount'],bcdiv($amounts['cooperation'],$amounts['total'],6),2);
    $services=$q("SELECT b.id,b.pay_price,IF(b.link_id IN (SELECT id FROM {$prefix}store_order_writeoff WHERE status=0 AND product_id IN ($cooperation)),0,1) inside_eligible FROM {$prefix}store_order b WHERE $period AND b.order_type=2 AND b.refund_status=0 AND b.paid=1 AND b.is_system_del=0");
    foreach($services as $r){$id=(int)$r['id'];$byId[$id]['service_customer']=1;$byId[$id]['consumption_amount']=$r['pay_price'];
        if((int)$r['inside_eligible']===1)$byId[$id]['inside_consumption_amount']=$r['pay_price'];}
    $customers=$q("SELECT b.id,IF(b.uid>0 AND b.cash_pay_price>=298,1,0) is_new FROM {$prefix}store_order b WHERE $period AND $parent AND b.paid=1 AND b.refund_status=0 AND b.is_system_del=0 AND b.order_type IN (0,1) AND b.source IN (SELECT id FROM {$prefix}cash_source WHERE id NOT IN (6,7,11,12)) AND $cashScope AND NOT EXISTS (SELECT 1 FROM {$prefix}staff_yeji y JOIN {$prefix}system_store_staff s ON s.id=y.staff_id AND s.is_hezuofang=1 WHERE y.order_id=b.id AND y.status=0 AND y.store_id=b.store_id AND y.type IN (1,2))");
    foreach($customers as $r)$byId[(int)$r['id']][(int)$r['is_new']===1?'new_customer':'casual_customer']=1;
    $staff=$q("SELECT a.id source_id,a.order_id,a.link_id,a.staff_id,a.store_id,a.created_time,UNIX_TIMESTAMP(a.created_time) occurred_at,DATE(a.created_time) business_date,a.type,a.is_dian,a.status,a.yeji,IFNULL(b.uid,0) member_id,CASE WHEN a.type=3 AND TRIM(IFNULL(w.service_object,''))<>'' THEN w.service_object ELSE IFNULL(b.service_object,'') END service_object,IF(a.type<3 AND s.is_fencheng=1,a.yeji,0) partner_amount,IF(a.type=3 AND a.is_dian=1,1,0) designated_customer FROM {$prefix}staff_yeji a LEFT JOIN {$prefix}system_store_staff s ON s.id=a.staff_id LEFT JOIN {$prefix}store_order b ON b.id=a.order_id LEFT JOIN {$prefix}store_order_writeoff w ON w.id=a.link_id AND a.type=3 WHERE a.status=0 AND a.store_id IN ($storeList) AND a.created_time BETWEEN '$start 00:00:00' AND '$end 23:59:59' AND ((a.type<3 AND s.is_fencheng=1) OR (a.type=3 AND a.is_dian=1)) ORDER BY a.id");
    $sales=$q("SELECT c.id source_id,'sale' fact_kind,c.oid order_id,c.product_id,b.store_id,b.add_time occurred_at,FROM_UNIXTIME(b.add_time,'%Y-%m-%d') business_date,c.cart_num quantity FROM {$prefix}store_order b JOIN {$prefix}store_order_cart_info c ON c.oid=b.id AND IFNULL(c.cart_type,0)=0 JOIN {$prefix}store_product cp ON cp.id=c.product_id LEFT JOIN {$prefix}store_product parent ON parent.id=cp.pid AND cp.pid>0 WHERE $period AND $parent AND b.paid=1 AND b.is_system_del=0 AND b.is_del=0 AND b.refund_status IN (0,3) AND IFNULL(b.is_auto,0)<>1 AND b.order_type=0 AND cp.product_type IN (0,5,6) AND IFNULL(NULLIF(parent.store_name,''),cp.store_name)<>'' AND $cashScope ORDER BY c.id");
    $writeoffs=$q("SELECT id source_id,'writeoff' fact_kind,oid order_id,product_id,relation_id store_id,add_time occurred_at,FROM_UNIXTIME(add_time,'%Y-%m-%d') business_date,writeoff_num quantity FROM {$prefix}store_order_writeoff WHERE status=0 AND relation_id IN ($storeList) AND add_time BETWEEN $from AND $to ORDER BY id");
    // 仅保留影响指标的原单；被排除记录和相关配置仍在封存原件中，可按哈希重放取证。
    $facts=array_values(array_filter($byId,static fn(array $r):bool => bccomp($r['cash_amount'],'0',2)!==0
        || (int)$r['service_customer']===1 || (int)$r['new_customer']===1 || (int)$r['casual_customer']===1));
    $result=['format'=>'legacy-store-summary-facts-v1','source_schema'=>$schema,'source_archive_sha256'=>$expected,
        'period'=>['start'=>$start,'end'=>$end],'stores'=>$stores,'orders'=>$facts,'staff'=>$staff,
        'quantities'=>array_merge($sales,$writeoffs),
        'source_evidence'=>['payment_lines'=>$combined,'cart_amounts'=>$carts]];
    $result['payload_sha256']=hash('sha256',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} finally { $pdo->rollBack(); }
