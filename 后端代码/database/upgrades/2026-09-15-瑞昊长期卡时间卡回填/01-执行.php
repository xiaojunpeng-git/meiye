<?php
declare(strict_types=1);

/* Historical issuance only: no sale/payment/performance facts are created. */
const RH_TIME_CARD_CLASS_ID = '0091000112';
const RH_TIME_CARD_PRODUCT_ID = 289596;
const RH_TIME_CAPACITY = 1000000;
const RH_TIME_PROJECT_KEY = 'RH_TIMECARD_PROJECT_V1';
const RH_MEMBER_BATCH = 'RH-RELOAD-102-MEMBER-V1';

$execute = in_array('--execute', $argv, true);
$inspectCard = '';
foreach ($argv as $argument) if (substr((string)$argument, 0, 15) === '--inspect-card=') $inspectCard = substr((string)$argument, 15);
$allowRuihao = in_array('--allow-ruihao', $argv, true);
$envPath = getenv('MOHE_TIMECARD_ENV_PATH') ?: '/var/www/html/.env';
$env = parse_ini_file($envPath, true);
if (!is_array($env) || empty($env['DATABASE'])) throw new RuntimeException('database env unavailable');
$db = $env['DATABASE'];
$database = (string)($db['DATABASE'] ?? '');
if ($database !== 'ruihao' || !$allowRuihao) throw new RuntimeException('refuse: ruihao requires --allow-ruihao');
$pdo = new PDO('mysql:host='.$db['HOSTNAME'].';port='.$db['HOSTPORT'].';dbname='.$database.';charset=utf8mb4', $db['USERNAME'], $db['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

if ($inspectCard !== '') {
    $holder = $pdo->prepare("SELECT h.id holder_id,h.card_no,h.card_name,h.uid,h.store_id,h.write_start,h.write_end,h.write_times,h.write_surplus_times,o.id order_db_id,o.order_id,o.pay_price,o.mark,o.remark,s.state_id,s.rule_type,s.status,s.valid_from,s.valid_through FROM eb_user_card_holder h JOIN eb_store_order o ON o.id=h.oid LEFT JOIN eb_cashier_v3_card_rule_state s ON s.card_holder_id=h.id WHERE h.card_no=? ORDER BY h.id DESC LIMIT 1");
    $holder->execute([$inspectCard]);
    $record = $holder->fetch(PDO::FETCH_ASSOC);
    $components = [];
    if ($record) {
        $componentsQuery = $pdo->prepare('SELECT component_state_id,project_product_id,project_sku_id,project_sku_unique,project_name_snapshot,status,total_times,remaining_times FROM eb_cashier_v3_card_rule_component WHERE card_holder_id=? ORDER BY id');
        $componentsQuery->execute([(int)$record['holder_id']]);
        $components = $componentsQuery->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['status'=>'inspect','database'=>'ruihao','card'=>$record,'components'=>$components], JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit;
}

function canonicalize($value) {
    if (!is_array($value)) return $value;
    if (array_keys($value) === ($value ? range(0, count($value) - 1) : [])) return array_map('canonicalize', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = canonicalize($item);
    return $value;
}
function canonicalJson(array $value): string {
    $json = json_encode(canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('json encode failed');
    return $json;
}
function timestampOrZero($value): int {
    if (!$value) return 0;
    $time = strtotime((string)$value);
    return $time === false ? 0 : $time;
}
function base36CardNo(int $id): string {
    return 'z'.str_pad(strtolower(base_convert((string)$id, 10, 36)), 6, '0', STR_PAD_LEFT);
}
function safeText($value, int $maxCharacters): string {
    $text = @iconv('UTF-8', 'UTF-8//IGNORE', (string)$value);
    if ($text === false) $text = '';
    if (function_exists('mb_substr')) return (string)mb_substr($text, 0, $maxCharacters, 'UTF-8');
    $cut = @iconv_substr($text, 0, $maxCharacters, 'UTF-8');
    return $cut === false ? '' : $cut;
}

$source = $pdo->prepare("SELECT CONVERT(cd.Id USING utf8mb4) source_card_id,CONVERT(cd.CardNo USING utf8mb4) source_card_no,CONVERT(cd.CustomerId USING utf8mb4) source_customer_id,CONVERT(cd.ShopId USING utf8mb4) source_shop_id,cd.IssueDate,cd.EffectiveDate,COALESCE(cd.fstate,0) fstate,COALESCE(cc.EffectiveDay,0) effective_days
 FROM jsj.bd_CardDetail cd JOIN jsj.bd_CardClass cc ON cc.Id=cd.CardClassId
 WHERE CONVERT(cd.CardClassId USING utf8mb4)=? ORDER BY cd.Id");
$source->execute([RH_TIME_CARD_CLASS_ID]);
$cards = $source->fetchAll(PDO::FETCH_ASSOC);

$maps = $pdo->query('SELECT source_customer_id,target_uid FROM map_rh_customer');
$memberMap = [];
foreach ($maps as $row) $memberMap[(string)$row['source_customer_id']] = (int)$row['target_uid'];
/* Only load members actually referenced by this long-card source batch. */
$sourceCustomerIds = [];
foreach ($cards as $card) $sourceCustomerIds[(string)$card['source_customer_id']] = true;
$uids = [];
foreach (array_keys($sourceCustomerIds) as $sourceCustomerId) {
    $uid = (int)($memberMap[$sourceCustomerId] ?? 0);
    if ($uid > 0) $uids[] = $uid;
}
$uids = array_values(array_unique($uids));
$users = [];
foreach (array_chunk($uids, 500) as $chunk) {
    $stmt = $pdo->prepare('SELECT uid,real_name,phone,belong_store_id,is_del FROM eb_user WHERE uid IN ('.implode(',', array_fill(0, count($chunk), '?')).')');
    $stmt->execute($chunk);
    foreach ($stmt as $row) $users[(int)$row['uid']] = $row;
}
$sourceShopIds = [];
foreach ($cards as $card) $sourceShopIds[(string)$card['source_shop_id']] = true;
$storeMap = [];
$ambiguousSourceShop = [];
foreach (array_chunk(array_keys($sourceShopIds), 500) as $chunk) {
    $stmt = $pdo->prepare('SELECT source_shop_id,target_store_id FROM map_rh_store WHERE confirmed=1 AND source_shop_id IN ('.implode(',', array_fill(0, count($chunk), '?')).')');
    $stmt->execute($chunk);
    foreach ($stmt as $row) {
        $sourceShopId = (string)$row['source_shop_id'];
        $targetStoreId = (int)$row['target_store_id'];
        if (isset($storeMap[$sourceShopId]) && $storeMap[$sourceShopId] !== $targetStoreId) {
            $ambiguousSourceShop[$sourceShopId] = true;
            continue;
        }
        $storeMap[$sourceShopId] = $targetStoreId;
    }
}
$existing = $pdo->prepare('SELECT id FROM eb_store_order WHERE order_id=? LIMIT 1');
$candidates = [];
$blocked = [];
$memberStoreResolved = 0;
$sourceStoreFallback = 0;
foreach ($cards as $card) {
    $uid = (int)($memberMap[(string)$card['source_customer_id']] ?? 0);
    $user = $users[$uid] ?? null;
    $start = timestampOrZero($card['IssueDate']);
    $end = timestampOrZero($card['EffectiveDate']);
    if ($start <= 0 && $end > 0 && (int)$card['effective_days'] > 0) $start = strtotime('-'.(int)$card['effective_days'].' days', $end) ?: 0;
    $orderNo = 'RH-TIMECARD-102-'.(string)$card['source_card_id'];
    $existing->execute([$orderNo]);
    if ($existing->fetchColumn()) continue;
    if ($uid <= 0 || !$user || (int)$user['is_del'] === 1) { $blocked[] = ['card_id'=>$card['source_card_id'], 'reason'=>'missing_member_map']; continue; }
    $memberStoreId = (int)$user['belong_store_id'];
    $fallbackStoreId = (int)($storeMap[(string)$card['source_shop_id']] ?? 0);
    if ($memberStoreId > 0) {
        $storeId = $memberStoreId;
        $memberStoreResolved++;
    } elseif (isset($ambiguousSourceShop[(string)$card['source_shop_id']])) {
        $blocked[] = ['card_id'=>$card['source_card_id'], 'reason'=>'ambiguous_source_store_map']; continue;
    } elseif ($fallbackStoreId > 0) {
        /* No member affiliation exists in this target batch: retain the mapped source-shop issuer. */
        $storeId = $fallbackStoreId;
        $sourceStoreFallback++;
    } else { $blocked[] = ['card_id'=>$card['source_card_id'], 'reason'=>'missing_member_and_source_store']; continue; }
    if ($end <= 0) { $blocked[] = ['card_id'=>$card['source_card_id'], 'reason'=>'missing_effective_end']; continue; }
    $card['uid'] = $uid;
    $card['user'] = $user;
    $card['store_id'] = $storeId;
    $card['start'] = $start;
    $card['end'] = $end;
    $card['order_no'] = $orderNo;
    $candidates[] = $card;
}
$summary = ['database'=>$database,'source_long_cards'=>count($cards),'already_imported'=>count($cards)-count($candidates)-count($blocked),'repairable'=>count($candidates),'member_store_resolved'=>$memberStoreResolved,'source_store_fallback'=>$sourceStoreFallback,'blocked'=>count($blocked),'blocked_samples'=>array_slice($blocked,0,20)];
if (!$execute) { echo json_encode(['status'=>'precheck']+$summary, JSON_UNESCAPED_UNICODE).PHP_EOL; exit; }

$lock = $pdo->query("SELECT GET_LOCK('rh_time_card_migration_v1',30)")->fetchColumn();
if ((int)$lock !== 1) throw new RuntimeException('migration lock unavailable');
try {
    $product = $pdo->prepare("SELECT id FROM eb_store_product WHERE keyword=? AND type=0 AND product_type=6 AND is_del=0 LIMIT 1");
    $product->execute([RH_TIME_PROJECT_KEY]);
    $projectRootId = (int)$product->fetchColumn();
    if ($projectRootId <= 0) {
        $insert = $pdo->prepare("INSERT INTO eb_store_product(store_name,price,unit_name,image,product_type,type,relation_id,pid,cate_id,store_cate_id,is_verify,is_show,is_del,add_time,recommend_image,slider_image,store_info,keyword) VALUES(?,0,?, ?,6,0,0,0,0,0,1,0,0,?,?,?,?,?)");
        $blank = 'https://rh.cc3798.com/static/images/rh-blank.png';
        $insert->execute(['长期项目','次',$blank,time(),$blank,'[]','瑞昊长期卡时间权益迁移专用下架项目',RH_TIME_PROJECT_KEY]);
        $projectRootId = (int)$pdo->lastInsertId();
    }
    $cardSku = $pdo->query('SELECT id,`unique` FROM eb_store_product_attr_value WHERE product_id='.RH_TIME_CARD_PRODUCT_ID.' AND product_type=5 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$cardSku) throw new RuntimeException('long card sku unavailable');
    $childFind = $pdo->prepare('SELECT id FROM eb_store_product WHERE keyword=? AND type=1 AND product_type=6 AND relation_id=? AND is_del=0 LIMIT 1');
    $childInsert = $pdo->prepare("INSERT INTO eb_store_product(store_name,price,unit_name,image,product_type,type,relation_id,pid,cate_id,store_cate_id,is_verify,is_show,is_del,add_time,recommend_image,slider_image,store_info,keyword) VALUES(?,0,?, ?,6,1,?,?,0,0,1,0,0,?,?,?,?,?)");
    $attrInsert = $pdo->prepare("INSERT INTO eb_store_product_attr(product_id,attr_name,attr_values,type) VALUES(?, '规格', '默认', 0)");
    $attrValueInsert = $pdo->prepare("INSERT INTO eb_store_product_attr_value(type,product_id,product_type,suk,stock,sum_stock,price,image,`unique`,cost,ot_price,write_times,is_default_select,is_show,stock_unit,sale_unit,unit_convert,decimal_scale) VALUES(0,?,6,'默认',999999,999999,0,?, ?,0,0,1,1,0,'次','次',1,0)");
    $childSkuFind = $pdo->prepare('SELECT id,`unique` FROM eb_store_product_attr_value WHERE product_id=? AND product_type=6 ORDER BY id LIMIT 1');
    $relationFind = $pdo->prepare('SELECT id FROM eb_store_card_related WHERE card_product_id=? AND product_id=? AND product_attr_unique=? LIMIT 1');
    $relationInsert = $pdo->prepare('INSERT INTO eb_store_card_related(card_product_id,product_id,product_type,product_attr_unique,cost,price,write_times,writeoff_amount,status,add_time) VALUES(?,?,6,?,0,0,0,0,1,?)');
    $orderInsert = $pdo->prepare("INSERT INTO eb_store_order(type,order_id,store_id,uid,real_name,user_phone,total_num,total_price,pay_price,paid,pay_type,status,is_del,add_time,pay_time,mark,remark,`unique`,product_type,shipping_type,verify_code,selected_product) VALUES(11,?,?,?,?,?,1,0,0,1,'migration',0,0,?,?,?,?,?,5,4,?,?)");
    $cartInsert = $pdo->prepare("INSERT INTO eb_store_order_cart_info(uid,oid,cart_id,cart_type,type,relation_id,product_id,product_type,sku_unique,is_card,is_support_refund,cart_num,total_price,settle_price,pay_price,surplus_num,split_surplus_num,write_times,write_surplus_times,write_start,write_end,is_writeoff,cart_info,`unique`,add_time,source_type) VALUES(?,?,?,?,1,?,?,?,?,?,?,?,0,0,0,?,?,?,?,?,?,0,?,?,?,'migration')");
    $holderInsert = $pdo->prepare('INSERT INTO eb_user_card_holder(uid,oid,card_no,card_name,store_id,product_id,product_type,verify_code,write_valid,write_days,write_start,write_end,write_times,write_surplus_times,is_del,add_time) VALUES(?,?,?,?,?,?,5,?,3,0,?,?,?, ?,0,?)');
    $mapInsert = $pdo->prepare('INSERT IGNORE INTO mig_rh_card_no_map(source_card_id) VALUES(?)');
    $mapFind = $pdo->prepare('SELECT id,card_no FROM mig_rh_card_no_map WHERE source_card_id=?');
    $mapSet = $pdo->prepare('UPDATE mig_rh_card_no_map SET card_no=? WHERE source_card_id=? AND card_no IS NULL');
    $holderNo = $pdo->prepare('SELECT id FROM eb_user_card_holder WHERE card_no=? LIMIT 1');
    $mapNo = $pdo->prepare('SELECT source_card_id FROM mig_rh_card_no_map WHERE card_no=? LIMIT 1');
    $stateInsert = $pdo->prepare('INSERT INTO eb_cashier_v3_card_rule_state(state_id,tenant_id,receipt_id,sales_order_id,sales_order_line_id,card_holder_id,member_id,issue_store_id,catalog_product_id,catalog_sku_id,rule_type,rule_version,definition_version,choice_limit,selected_kind_count,shared_total_times,shared_remaining_times,validity_mode,valid_from,valid_through,immutable_fingerprint,definition_snapshot_json,state_version,status,occurred_at,recorded_at,add_time,update_time) VALUES(?,?,?,?,?,?,?,?,?,?,\'time\',1,1,0,0,0,0,3,?,?,?, ?,1,?,?,?,?,?)');
    $componentInsert = $pdo->prepare('INSERT INTO eb_cashier_v3_card_rule_component(component_state_id,tenant_id,rule_state_id,card_holder_id,legacy_detail_id,relation_id,project_product_id,project_sku_id,project_sku_unique,project_type,project_name_snapshot,total_times,remaining_times,writeoff_amount_cents,selection_status,selected_at,selected_store_id,immutable_fingerprint,component_snapshot_json,state_version,status,occurred_at,recorded_at,add_time,update_time) VALUES(?,?,?,?,?,?,?,?,?,6,\'长期项目\',0,0,0,\'not_applicable\',0,0,?,?,1,?,?,?,?,?)');

    $written = 0;
    foreach ($candidates as $card) {
        $pdo->beginTransaction();
        try {
            $storeId = (int)$card['store_id'];
            $childKey = RH_TIME_PROJECT_KEY.':STORE:'.$storeId;
            $childFind->execute([$childKey,$storeId]);
            $childId = (int)$childFind->fetchColumn();
            if ($childId <= 0) {
                $blank = 'https://rh.cc3798.com/static/images/rh-blank.png';
                $childInsert->execute(['长期项目','次',$blank,$storeId,$projectRootId,time(),$blank,'[]','瑞昊长期卡时间权益迁移门店下架项目',$childKey]);
                $childId = (int)$pdo->lastInsertId();
            }
            $childSkuFind->execute([$childId]); $childSku = $childSkuFind->fetch(PDO::FETCH_ASSOC);
            if (!$childSku) {
                $attrInsert->execute([$childId]);
                $attrValueInsert->execute([$childId,'https://rh.cc3798.com/static/images/rh-blank.png',substr(md5('RH-TIMECARD-PROJECT-SKU-'.$childId),0,8)]);
                $childSkuFind->execute([$childId]); $childSku = $childSkuFind->fetch(PDO::FETCH_ASSOC);
            }
            if (!$childSku) throw new RuntimeException('project sku unavailable');
            $relationFind->execute([RH_TIME_CARD_PRODUCT_ID,$childId,$childSku['unique']]);
            $relationId = (int)$relationFind->fetchColumn();
            if ($relationId <= 0) { $relationInsert->execute([RH_TIME_CARD_PRODUCT_ID,$childId,$childSku['unique'],time()]); $relationId=(int)$pdo->lastInsertId(); }
            $mapInsert->execute([$card['source_card_id']]); $mapFind->execute([$card['source_card_id']]); $numberMap=$mapFind->fetch(PDO::FETCH_ASSOC);
            $cardNo = trim((string)($numberMap['card_no'] ?? ''));
            if ($cardNo === '') {
                $candidateNo = trim((string)$card['source_card_no']);
                $usable = preg_match('/^[A-Za-z0-9]{1,7}$/', $candidateNo) === 1;
                if ($usable) { $holderNo->execute([$candidateNo]); $mapNo->execute([$candidateNo]); $usable = !$holderNo->fetchColumn() && !$mapNo->fetchColumn(); }
                $cardNo = $usable ? $candidateNo : base36CardNo((int)$numberMap['id']);
                $mapSet->execute([$cardNo,$card['source_card_id']]);
            }
            $occurredAt = $card['start'] > 0 ? (int)$card['start'] : time();
            $orderInsert->execute([$card['order_no'],$storeId,$card['uid'],safeText($card['user']['real_name'],32),substr((string)$card['user']['phone'],0,18),$occurredAt,$occurredAt,'历史长期时间卡迁移','source_card_detail_id='.$card['source_card_id'],md5($card['order_no']),substr(md5('RH-TIME-VERIFY-'.$card['source_card_id']),0,8),RH_TIME_CARD_PRODUCT_ID]);
            $orderId=(int)$pdo->lastInsertId();
            $baseCartId='RH-TIME-BASE-'.$card['source_card_id'];
            $baseInfo=canonicalJson(['sourceType'=>'rh_time_card_migration','sourceCardDetailId'=>$card['source_card_id'],'cardRuleType'=>'time','productInfo'=>['id'=>RH_TIME_CARD_PRODUCT_ID,'store_name'=>'长期卡','product_type'=>5]]);
            $cartInsert->execute([$card['uid'],$orderId,$baseCartId,0,$storeId,RH_TIME_CARD_PRODUCT_ID,5,$cardSku['unique'],0,1,1,0,0,0,0,$card['start'],$card['end'],$baseInfo,md5($baseCartId),$occurredAt]);
            $componentCartId='RH-TIME-PROJECT-'.$card['source_card_id'];
            $componentInfo=canonicalJson(['sourceType'=>'rh_time_card_migration','sourceCardDetailId'=>$card['source_card_id'],'cardRuleType'=>'time','productInfo'=>['id'=>$childId,'store_name'=>'长期项目','product_type'=>6]]);
            $cartInsert->execute([$card['uid'],$orderId,$componentCartId,2,$storeId,$childId,6,$childSku['unique'],1,0,RH_TIME_CAPACITY,RH_TIME_CAPACITY,RH_TIME_CAPACITY,RH_TIME_CAPACITY,RH_TIME_CAPACITY,$card['start'],$card['end'],$componentInfo,md5($componentCartId),$occurredAt]);
            $detailId=(int)$pdo->lastInsertId();
            $holderInsert->execute([$card['uid'],$orderId,$cardNo,'长期卡',$storeId,RH_TIME_CARD_PRODUCT_ID,substr(md5('RH-TIME-VERIFY-'.$card['source_card_id']),0,12),$card['start'],$card['end'],RH_TIME_CAPACITY,RH_TIME_CAPACITY,$occurredAt]);
            $holderId=(int)$pdo->lastInsertId();
            $receiptId='RH-TIME-102-RECEIPT-'.$card['source_card_id'];
            $lineId='RH-TIME-102-LINE-'.$card['source_card_id'];
            $componentSnapshot=['relationId'=>$relationId,'productId'=>$childId,'skuId'=>(int)$childSku['id'],'skuUnique'=>$childSku['unique'],'productType'=>6,'nameSnapshot'=>'长期项目','skuNameSnapshot'=>'','categoryIdSnapshot'=>0,'categoryNameSnapshot'=>'全部','writeTimes'=>0,'writeoffAmountCents'=>0,'configuredPriceCents'=>0,'configuredCostCents'=>0,'productVersion'=>'rh-time-card-migration-v1','skuVersion'=>'rh-time-card-migration-v1'];
            $definition=['contractVersion'=>'cashier-v3-issued-card-rule-state-v1','receiptId'=>$receiptId,'salesOrderId'=>$card['order_no'],'salesOrderLineId'=>$lineId,'holderId'=>$holderId,'catalogProductId'=>RH_TIME_CARD_PRODUCT_ID,'catalogSkuId'=>(int)$cardSku['id'],'ruleType'=>'time','ruleVersion'=>1,'definitionVersion'=>1,'choiceLimit'=>0,'sharedTimes'=>0,'validity'=>['writeValid'=>3,'writeDays'=>0,'writeStart'=>$card['start'],'writeEnd'=>$card['end']],'components'=>[$componentSnapshot]];
            $stateId='CRS-'.strtoupper(substr(hash('sha256',$receiptId.'|'.$holderId),0,40));
            $stateStatus=(int)$card['fstate'] === -1 ? 'disabled' : 'active';
            $definitionJson=canonicalJson($definition); $definitionFingerprint=hash('sha256',$definitionJson);
            $stateInsert->execute([$stateId,'0',$receiptId,$card['order_no'],$lineId,$holderId,$card['uid'],$storeId,RH_TIME_CARD_PRODUCT_ID,(int)$cardSku['id'],$card['start'],$card['end'],$definitionFingerprint,$definitionJson,$stateStatus,$occurredAt,time(),time(),time()]);
            $stateDbId=(int)$pdo->lastInsertId();
            $componentStateId='CRC-'.strtoupper(substr(hash('sha256',$stateId.'|'.$relationId.'|'.$childId.'|'.$childSku['unique']),0,40));
            $componentJson=canonicalJson($componentSnapshot); $componentFingerprint=hash('sha256',$componentJson);
            $componentInsert->execute([$componentStateId,'0',$stateDbId,$holderId,$detailId,$relationId,$childId,(int)$childSku['id'],$childSku['unique'],$componentFingerprint,$componentJson,$stateStatus,$occurredAt,time(),time(),time()]);
            $pdo->commit(); $written++;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    echo json_encode(['status'=>'ok','written'=>$written,'project_root_id'=>$projectRootId]+$summary, JSON_UNESCAPED_UNICODE).PHP_EOL;
} finally {
    $pdo->query("SELECT RELEASE_LOCK('rh_time_card_migration_v1')");
}
