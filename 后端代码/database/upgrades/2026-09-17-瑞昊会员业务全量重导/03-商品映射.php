<?php
declare(strict_types=1);

/**
 * Map current JSJ card, project and goods masters to ruihao carriers.
 * Missing masters are reported for the controlled carrier steps before any
 * member asset is imported; no asset may be silently dropped by a join.
 */
$args = array_fill_keys(array_slice($argv, 1), true);
if (!isset($args['--execute'], $args['--allow-ruihao'], $args['--direct-jsj'])) {
    fwrite(STDERR, "Refusing to write without --execute --allow-ruihao --direct-jsj\n");
    exit(2);
}
$env = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW);
$db = $env['DATABASE'] ?? [];
$target = getenv('RH_TARGET_DATABASE') ?: (string)($db['DATABASE'] ?? '');
if ($target !== 'ruihao') throw new RuntimeException("target guard failed: {$target}");
$pdo = new PDO(
    'mysql:host=' . $db['HOSTNAME'] . ';port=' . $db['HOSTPORT'] . ';dbname=ruihao;charset=utf8mb4',
    $db['USERNAME'], $db['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS map_rh_product (
    source_table varchar(64) NOT NULL,
    source_id varchar(50) NOT NULL,
    target_product_id int unsigned NOT NULL,
    batch_no varchar(64) NOT NULL,
    resolution_method varchar(64) NOT NULL,
    PRIMARY KEY(source_table,source_id), KEY idx_target(target_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS mig_jsj_20260917_product_resolution (
    source_table varchar(64) NOT NULL,
    source_id varchar(50) NOT NULL,
    target_product_id int unsigned NOT NULL DEFAULT 0,
    status varchar(32) NOT NULL,
    method varchar(64) NOT NULL DEFAULT '',
    candidates varchar(1000) NOT NULL DEFAULT '',
    updated_at datetime NOT NULL,
    PRIMARY KEY(source_table,source_id), KEY idx_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

function rowsBySource(PDO $pdo, string $sql): array
{
    $out = [];
    foreach ($pdo->query($sql) as $row) {
        $out[(string)$row['source_id']][] = (int)$row['target_id'];
    }
    foreach ($out as &$ids) {
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);
    }
    return $out;
}

$sets = [
    'bd_CardClass' => [
        // Read the live JSJ source at execution time.  No JSJ fact snapshot is
        // persisted in ruihao; the tables below only record target mappings.
        'needed' => "SELECT DISTINCT CONVERT(cd.CardClassId USING utf8mb4) source_id
            FROM jsj.bd_CardDetail cd
            JOIN jsj.bd_ProjectAndTimes pt ON pt.CardDetailId=cd.Id
            JOIN jsj.bd_ProjectSet ps ON ps.Id=pt.ProjectId
            WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>='2026-09-17' OR cd.EffectiveDate IS NULL)
              AND cd.pflag<>'0' AND COALESCE(pt.ShiFouUse,'')='' AND pt.Quantity-COALESCE(pt.UseQuantity,0)>0
              AND cd.CardClassId<>'0091000112'",
        'mapped' => "SELECT n.source_id,p.id target_id FROM (%s) n JOIN mig_rh_source_map m ON m.source_table='bd_CardClass' AND m.target_type='store_product' AND m.source_pk COLLATE utf8mb4_general_ci=n.source_id JOIN eb_store_product p ON p.id=m.target_id AND p.type=0 AND p.product_type=5 AND p.is_del=0",
        'product_type' => 5, 'child_prefix' => 'RH_CARD_UNUSED:', 'keyword_prefixes' => ['RH_CARDCLASS:', 'RH_CARD_TECH:'],
        'source_name_sql' => "SELECT CONVERT(Id USING utf8mb4) source_id,CardKindName source_name FROM jsj.bd_CardClass",
    ],
    'bd_ProjectSet' => [
        'needed' => "SELECT DISTINCT CONVERT(pt.ProjectId USING utf8mb4) source_id
            FROM jsj.bd_CardDetail cd
            JOIN jsj.bd_ProjectAndTimes pt ON pt.CardDetailId=cd.Id
            JOIN jsj.bd_ProjectSet ps ON ps.Id=pt.ProjectId
            WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>='2026-09-17' OR cd.EffectiveDate IS NULL)
              AND cd.pflag<>'0' AND COALESCE(pt.ShiFouUse,'')='' AND pt.Quantity-COALESCE(pt.UseQuantity,0)>0
              AND cd.CardClassId<>'0091000112'
            UNION
            SELECT DISTINCT CONVERT(p.ProjectId USING utf8mb4)
            FROM jsj.SS_SendProject p JOIN jsj.bd_ProjectSet ps ON ps.Id=p.ProjectId
            WHERE p.StartDate<='2026-09-17' AND p.EffectiveDate>='2026-09-17'
              AND p.Times-COALESCE(p.ConsumeQuantity,0)>0 AND p.Fstate='0'",
        'mapped' => "SELECT n.source_id,p.id target_id FROM (%s) n JOIN mig_rh_source_map m ON m.source_table='bd_ProjectSet' AND m.target_type='store_product' AND m.source_pk COLLATE utf8mb4_general_ci=n.source_id JOIN eb_store_product p ON p.id=m.target_id AND p.type=0 AND p.product_type=6 AND p.is_del=0",
        'product_type' => 6, 'child_prefix' => 'RH_PROJECT:', 'keyword_prefixes' => ['RH_PROJECT:', 'RH_PROJECT_PLATFORM:'],
        'source_name_sql' => "SELECT CONVERT(Id USING utf8mb4) source_id,ProjectName source_name FROM jsj.bd_ProjectSet",
    ],
    'bd_goods' => [
        'needed' => "SELECT DISTINCT source_id FROM (
            SELECT CONVERT(g.GoodsId USING utf8mb4) source_id FROM jsj.SS_SendGoods g JOIN jsj.bd_goods bg ON bg.Id=g.GoodsId
             WHERE g.EffectiveDate>='2026-09-17' AND g.Fstate='0' AND g.Quantity-COALESCE(g.ConsumeQuantity,0)>0
            UNION SELECT CONVERT(g.GoodsId USING utf8mb4) FROM jsj.SS_StoreGoods g JOIN jsj.bd_goods bg ON bg.Id=g.GoodsId
             WHERE g.EffectiveDate>='2026-09-17' AND g.Quantity-COALESCE(g.ConsumeQuantity,0)>0 AND g.Fstate<>'-1' AND COALESCE(g.Salefstate,'')<>'作废'
            UNION SELECT CONVERT(g.GoodsId USING utf8mb4) FROM jsj.SS_PreSaleGoods g JOIN jsj.bd_goods bg ON bg.Id=g.GoodsId
             WHERE g.Quantity-COALESCE(g.ConsumeQuantity,0)>0 AND g.Fstate<>'-1'
        ) needed_goods",
        'mapped' => "SELECT n.source_id,p.id target_id FROM (%s) n JOIN mig_rh_source_map m ON m.source_table='bd_goods' AND m.target_type='store_product' AND m.source_pk COLLATE utf8mb4_general_ci=n.source_id JOIN eb_store_product p ON p.id=m.target_id AND p.type=0 AND p.product_type=0 AND p.is_del=0",
        'product_type' => 0, 'child_prefix' => 'RH_GOODS:', 'keyword_prefixes' => ['RH_GOODS:'],
        'source_name_sql' => "SELECT CONVERT(Id USING utf8mb4) source_id,GoodsName source_name FROM jsj.bd_goods",
    ],
];
$upsertMap = $pdo->prepare("INSERT INTO map_rh_product(source_table,source_id,target_product_id,batch_no,resolution_method)
    VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE target_product_id=VALUES(target_product_id),batch_no=VALUES(batch_no),resolution_method=VALUES(resolution_method)");
$upsertResolution = $pdo->prepare("INSERT INTO mig_jsj_20260917_product_resolution
    (source_table,source_id,target_product_id,status,method,candidates,updated_at) VALUES(?,?,?,?,?,?,NOW())
    ON DUPLICATE KEY UPDATE target_product_id=VALUES(target_product_id),status=VALUES(status),method=VALUES(method),candidates=VALUES(candidates),updated_at=NOW()");

$summary = [];
$pdo->beginTransaction();
try {
    foreach ($sets as $sourceTable => $cfg) {
        $needed = array_map('strval', $pdo->query($cfg['needed'])->fetchAll(PDO::FETCH_COLUMN));
        $neededSet = array_fill_keys($needed, true);
        $evidence = ['mapped' => rowsBySource($pdo, sprintf($cfg['mapped'], $cfg['needed'])), 'children' => [], 'keyword' => [], 'name' => []];

        $prefix = $cfg['child_prefix'];
        $childStmt = $pdo->prepare("SELECT p.keyword,p.pid target_id FROM eb_store_product p JOIN eb_store_product root ON root.id=p.pid
            WHERE p.type=1 AND p.product_type=? AND p.is_del=0 AND root.type=0 AND root.product_type=? AND root.is_del=0
              AND p.keyword LIKE ?");
        $childStmt->execute([$cfg['product_type'], $cfg['product_type'], $prefix . '%:STORE:%']);
        foreach ($childStmt as $row) {
            $beforeStore = explode(':STORE:', (string)$row['keyword'], 2)[0];
            $sourceId = substr($beforeStore, strlen($prefix));
            if (isset($neededSet[$sourceId])) $evidence['children'][$sourceId][(int)$row['target_id']] = true;
        }

        $rootStmt = $pdo->prepare("SELECT id,keyword,store_name FROM eb_store_product WHERE type=0 AND product_type=? AND is_del=0");
        $rootStmt->execute([$cfg['product_type']]);
        $targetNames = [];
        foreach ($rootStmt as $row) {
            $keyword = (string)$row['keyword'];
            foreach ($cfg['keyword_prefixes'] as $rootPrefix) {
                if (strpos($keyword, $rootPrefix) === 0) {
                    $sourceId = substr($keyword, strlen($rootPrefix));
                    if (substr($sourceId, -9) === ':PLATFORM') $sourceId = substr($sourceId, 0, -9);
                    if (isset($neededSet[$sourceId])) $evidence['keyword'][$sourceId][(int)$row['id']] = true;
                }
            }
            $targetNames[trim((string)$row['store_name'])][] = (int)$row['id'];
        }
        foreach ($pdo->query($cfg['source_name_sql']) as $row) {
            $sourceId = (string)$row['source_id'];
            if (!isset($neededSet[$sourceId])) continue;
            foreach ($targetNames[trim((string)$row['source_name'])] ?? [] as $id) $evidence['name'][$sourceId][$id] = true;
        }
        foreach (['children', 'keyword', 'name'] as $method) {
            foreach ($evidence[$method] as &$ids) {
                $ids = array_keys($ids);
                sort($ids);
            }
        }
        $counts = ['mapped' => 0, 'blocked' => 0];
        foreach ($needed as $sourceId) {
            $chosen = [];
            $method = '';
            foreach (['mapped', 'children', 'keyword', 'name'] as $candidateMethod) {
                $ids = $evidence[$candidateMethod][$sourceId] ?? [];
                if (count($ids) === 1) {
                    $chosen = $ids;
                    $method = $candidateMethod;
                    break;
                }
                if (count($ids) > 1) {
                    // Continue to a stronger discriminator only for duplicate keywords/names.
                    if (in_array($candidateMethod, ['keyword', 'name'], true)) {
                        $chosen = $ids;
                        $method = $candidateMethod;
                    } else {
                        $chosen = $ids;
                        $method = $candidateMethod;
                        break;
                    }
                }
            }
            if (count($chosen) === 1) {
                $targetId = (int)$chosen[0];
                $upsertMap->execute([$sourceTable, $sourceId, $targetId, 'JSJ-20260917-R1', $method]);
                $upsertResolution->execute([$sourceTable, $sourceId, $targetId, 'MAPPED', $method, (string)$targetId]);
                $counts['mapped']++;
            } else {
                $upsertResolution->execute([$sourceTable, $sourceId, 0, 'BLOCKED', $method, implode(',', $chosen)]);
                $counts['blocked']++;
            }
        }
        $summary[$sourceTable] = $counts;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
echo json_encode(['summary' => $summary, 'blocked' => $pdo->query("SELECT * FROM mig_jsj_20260917_product_resolution WHERE status='BLOCKED'")->fetchAll()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
