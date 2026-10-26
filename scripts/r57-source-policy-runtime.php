<?php
/** Read actual deployed policy inside a server-enforced read-only transaction.
 * Never select a real member, create a session, confirm a booking or take money.
 */
$site = $argv[1] ?? '';
$map = ['rh' => ['rh.cc3798.com', 'ruihao', '3306'], '008' => ['008.cc3798.com', 'mohe_008', '3307'], '010' => ['010.cc3798.com', 'mohe_010_app', '3307']];
if (!isset($map[$site])) throw new RuntimeException('Explicit instance required');
[$domain, $database, $port] = $map[$site]; $root = '/www/wwwroot/' . $domain;
chdir($root); require $root . '/vendor/autoload.php'; (new \think\App($root . '/'))->initialize();
$cfg = config('database.connections.mysql');
if ($cfg['database'] !== $database || (string)$cfg['hostport'] !== $port || $cfg['prefix'] !== 'eb_') throw new RuntimeException('Wrong database');
\think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY'); \think\facade\Db::startTrans();
try {
    $policy = new \app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices();
    $state = $policy->read();
    if (!$state['configured'] || $state['mode'] !== 'manual' || !$state['enabled'] || $state['inheritPrevious']) throw new RuntimeException('Unexpected initial source mode');
    $choices = $policy->initialChoices('0', 1, 0);
    foreach (['purchase', 'recharge', 'repayment'] as $kind) if ((int)$choices[$kind]['primarySourceId'] !== 0) throw new RuntimeException('Manual mode inherited a source');
    echo json_encode(['result' => 'PASS', 'site' => $site, 'mode' => $state['mode'], 'enabled' => true, 'inheritPrevious' => false,
        'manualChoicesEmpty' => true, 'serverReadOnly' => true, 'businessWrites' => 0], JSON_UNESCAPED_SLASHES), PHP_EOL;
} finally { \think\facade\Db::rollback(); }
