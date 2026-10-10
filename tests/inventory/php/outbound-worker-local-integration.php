<?php
declare(strict_types=1);

/** Validate a local browser-created R52 task; temporary revocation changes are rolled back. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
(new \think\App($backend . '/'))->initialize();
use think\facade\Db;
use app\services\product\inventory\query\InventoryOperationalUnifiedQueryWorkerContextResolver;
use app\services\product\inventory\query\InventoryOutboundWorkerIdentity;
use app\services\query\UnifiedQueryRuntime;

$config = config('database.connections.mysql');
if (!is_file('/.dockerenv') || $config['hostname'] !== 'mysql' || $config['database'] !== 'ruihao') throw new RuntimeException('local_database_required');
$task = Db::name('unified_query_export_task')->where('task_no', (string)getenv('R52_TASK_NO'))->where('page_code', 'inventory_outbound')->find();
if (!$task || $task['status'] !== 'succeeded') throw new RuntimeException('explicit_successful_local_task_required');
$runtime = UnifiedQueryRuntime::runtime();
$rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($runtime['exportStorage']->absolutePath($task['storage_key']))->getActiveSheet()->toArray();
if (count($rows) !== 2 || !in_array('R52自测对象', $rows[1], true)) throw new RuntimeException('R52_selftest_workbook_required');
$passed = 0;
$check = static function ($name, $ok) use (&$passed) { if (!$ok) throw new RuntimeException($name); $passed++; echo "PASS {$name}\n"; };
$reject = static function (callable $f) { try { $f(); return false; } catch (Throwable $e) { return true; } };
$resolver = new InventoryOperationalUnifiedQueryWorkerContextResolver();
$identity = new InventoryOutboundWorkerIdentity();
$context = $resolver->resolve($task);
$check('worker restores typed organization session', $context['scope_dimensions']['inventory_actor_type'] === ['organization']);
$check('frozen query exports internal, not unsent external filter', in_array('对内出库', $rows[1], true) && !in_array('对外出库', $rows[1], true));
$check('authenticated creator can download', $runtime['exports']->resolveDownloadDescriptor($context, $task['task_no'])['storageKey'] === $task['storage_key']);
$foreign = $context; $foreign['account_id']++; $foreign['operator_id']++;
$check('other account cannot download', $reject(fn() => $runtime['exports']->resolveDownloadDescriptor($foreign, $task['task_no'])));
$bad = $task; $bad['tenant_id'] = 'foreign';
$check('foreign tenant task rejected', $reject(fn() => $resolver->resolve($bad)));
$bad = $task; $bad['origin_store_id'] = 999999;
$check('foreign store task rejected', $reject(fn() => $identity->profile($bad)));
$bad = $task; $bad['frozen_scope'] = '{}';
$check('missing typed identity rejected', $reject(fn() => $identity->profile($bad)));
$session = Db::name('cashier_v3_store_session')->where('id', (int)$task['operator_id'])->find();
Db::startTrans();
try {
    Db::name('cashier_v3_store_session')->where('id', (int)$session['id'])->update(['expire_time' => 1]);
    $check('expired session rejected by worker', $reject(fn() => $identity->profile($task)));
    Db::name('cashier_v3_store_session')->where('id', (int)$session['id'])->update(['expire_time' => $session['expire_time'], 'auth_version' => (int)$session['auth_version'] + 1]);
    $check('revoked employee auth version rejected', $reject(fn() => $identity->profile($task)));
} finally { Db::rollback(); }
$check('session unchanged after tests', $identity->profile($task)['id'] === (int)$session['id']);
echo "R52_WORKER passed={$passed} failed=0\n";
