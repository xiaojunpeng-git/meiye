<?php
declare(strict_types=1);

/**
 * Direct JSJ preflight.  It deliberately reads jsj in place and creates no
 * source snapshot or migration copy in the target database.
 */
$args = array_fill_keys(array_slice($argv, 1), true);
if (!isset($args['--verify'], $args['--allow-ruihao'], $args['--direct-jsj'])) {
    fwrite(STDERR, "Refusing to run without --verify --allow-ruihao --direct-jsj\n");
    exit(2);
}
$cutoff = getenv('RH_JSJ_CUTOFF') ?: '2026-09-17 00:00:00';
if (strtotime($cutoff) === false) {
    throw new RuntimeException('RH_JSJ_CUTOFF must be a valid datetime');
}

$env = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW);
$db = $env['DATABASE'] ?? [];
$target = getenv('RH_TARGET_DATABASE') ?: (string)($db['DATABASE'] ?? '');
if ($target !== 'ruihao') {
    throw new RuntimeException("target guard failed: {$target}");
}
$pdo = new PDO(
    'mysql:host=' . $db['HOSTNAME'] . ';port=' . $db['HOSTPORT'] . ';dbname=ruihao;charset=utf8mb4',
    $db['USERNAME'],
    $db['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');

$queries = [
    'card_details' => "SELECT COUNT(*) AS rows,MAX(IssueDate) AS latest_business_at,MAX(Id) AS max_source_id FROM jsj.bd_CardDetail",
    'ordinary_rights' => "SELECT COUNT(*) AS rows,MAX(cd.IssueDate) AS latest_business_at,MAX(pt.Id) AS max_source_id
        FROM jsj.bd_CardDetail cd JOIN jsj.bd_ProjectAndTimes pt ON pt.CardDetailId=cd.Id JOIN jsj.bd_ProjectSet ps ON ps.Id=pt.ProjectId
        WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>=DATE(?) OR cd.EffectiveDate IS NULL)
          AND cd.pflag<>'0' AND COALESCE(pt.ShiFouUse,'')='' AND pt.Quantity-COALESCE(pt.UseQuantity,0)>0
          AND cd.CardClassId<>'0091000112'",
    'project_gifts' => "SELECT COUNT(*) AS rows,MAX(COALESCE(p.SendDate,p.StartDate)) AS latest_business_at,MAX(p.id) AS max_source_id
        FROM jsj.SS_SendProject p JOIN jsj.bd_ProjectSet ps ON ps.Id=p.ProjectId WHERE p.StartDate<=DATE(?) AND p.EffectiveDate>=DATE(?)
          AND p.Times-COALESCE(p.ConsumeQuantity,0)>0 AND p.Fstate='0'",
    'long_cards' => "SELECT COUNT(*) AS rows,MAX(IssueDate) AS latest_business_at,MAX(Id) AS max_source_id
        FROM jsj.bd_CardDetail WHERE CardClassId='0091000112'",
    'debts' => "SELECT COUNT(*) AS rows,MAX(IssueDate) AS latest_business_at,MAX(Id) AS max_source_id
        FROM jsj.bd_CardDetail WHERE ABS(COALESCE(NonePay,0))>=0.001",
];
$stats = [];
foreach ($queries as $name => $sql) {
    $stmt = $pdo->prepare($sql);
    $parameters = substr_count($sql, '?') === 2 ? [substr($cutoff, 0, 10), substr($cutoff, 0, 10)] : (substr_count($sql, '?') === 1 ? [substr($cutoff, 0, 10)] : []);
    $stmt->execute($parameters);
    $stats[$name] = $stmt->fetch() ?: [];
}
$cardFreshEnough = strtotime((string)$stats['card_details']['latest_business_at']) >= strtotime($cutoff);
$result = [
    'target' => $target,
    'source' => 'jsj',
    'cutoff' => $cutoff,
    'checks' => ['card_details_reach_cutoff' => $cardFreshEnough],
    'stats' => $stats,
    'passed' => $cardFreshEnough,
];
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($cardFreshEnough ? 0 : 1);
