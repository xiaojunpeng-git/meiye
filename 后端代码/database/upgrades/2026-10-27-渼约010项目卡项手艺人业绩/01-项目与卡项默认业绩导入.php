<?php
declare(strict_types=1);

/**
 * Copy 010's already imported legacy project commission amount into the V3
 * project default, then freeze that value on active card-project definitions.
 * Missing source amounts keep the card's existing unit-price mode. Issued cards
 * read the current definition for future services; completed facts are untouched.
 */
use think\facade\Db;

const TARGET_ROOT = '/www/wwwroot/010.cc3798.com';
const EXPECTED_DATABASE = 'mohe_010_app';
const UPGRADE_KEY = '20261027-010-project-card-craftsman-default-v1';
// Frozen from the read-only 010 preflight; apply refuses any source/target drift.
const EXPECTED_PLAN_SHA256 = 'a17dd75924f36c034b0d709835ee11e46d0905ad19435847e0df00bafed70d89';

/** Build a deterministic, reviewable plan from the target's own configuration. */
function buildPlan(array $sources, array $rules, array $relations): array
{
    if (count($sources) !== 139 || count($relations) !== 1938) {
        throw new RuntimeException('010 commission or active card baseline changed');
    }
    $projectAmounts = [];
    $projectManifest = [];
    $excluded = [];
    foreach ($sources as $source) {
        $sourceId = (int)$source['source_id'];
        $projectId = (int)$source['product_id'];
        if ($source['project_exists'] === null) {
            $excluded[] = [$sourceId, $projectId];
            continue;
        }
        if ((int)$source['pid'] !== 0 || (int)$source['product_type'] !== 6 ||
            $projectId <= 0 || $source['yeji'] === null || (int)$source['yeji'] < 0) {
            throw new RuntimeException("Commission {$sourceId} has an invalid project or amount");
        }
        if (isset($projectAmounts[$projectId])) {
            throw new RuntimeException("Project {$projectId} has duplicate commission settings");
        }
        // The legacy amount is in whole yuan; all new monetary facts use cents.
        $amountCents = (int)$source['yeji'] * 100;
        $projectAmounts[$projectId] = $amountCents;
        $projectManifest[] = [$sourceId, $projectId, $amountCents, (int)$source['is_del']];
    }
    if (count($projectManifest) !== 135 || count($excluded) !== 4) {
        throw new RuntimeException('010 valid commission source set changed');
    }
    $existingRules = [];
    foreach ($rules as $rule) {
        $projectId = (int)$rule['project_id'];
        if (!isset($projectAmounts[$projectId])) continue;
        if ($rule['craftsman_default_unit_amount_cents'] !== null) {
            throw new RuntimeException("Project {$projectId} already has a manual default");
        }
        $existingRules[] = [(int)$rule['id'], $projectId, null];
    }
    $cardManifest = [];
    $manual = [];
    $fallback = [];
    foreach ($relations as $relation) {
        $relationId = (int)$relation['id'];
        $masterId = (int)$relation['project_pid'] > 0
            ? (int)$relation['project_pid'] : (int)$relation['product_id'];
        if ($relation['project_exists'] === null || (int)$relation['project_product_type'] !== 6 ||
            (string)$relation['craftsman_default_mode'] !== 'default_unit_price' ||
            (int)$relation['craftsman_default_unit_amount_cents'] !== 0) {
            throw new RuntimeException("Card relation {$relationId} has changed or is invalid");
        }
        $target = $projectAmounts[$masterId] ?? null;
        $cardManifest[] = [$relationId, $masterId, $target];
        if ($target === null) $fallback[] = $relationId;
        else $manual[] = [$relationId, $target];
    }
    if (count($manual) !== 1790 || count($fallback) !== 148) {
        throw new RuntimeException('010 card source/fallback split changed');
    }
    $manifest = [
        'format' => '010-project-card-craftsman-default-v1',
        'projects' => $projectManifest,
        'excluded_sources' => $excluded,
        'existing_rules' => $existingRules,
        'active_card_projects' => $cardManifest,
    ];
    $sha = hash('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    return compact('sha', 'projectAmounts', 'manual', 'fallback', 'existingRules');
}

/** The exact columns in this snapshot are the values the migration may write. */
function inspectTarget(bool $lock = false): array
{
    $identity = Db::query('SELECT DATABASE() AS db')[0];
    if (($identity['db'] ?? '') !== EXPECTED_DATABASE) {
        throw new RuntimeException('010 target database identity mismatch');
    }
    $markerQuery = Db::name('database_upgrade_log')->where('upgrade_key', UPGRADE_KEY);
    if ($lock) $markerQuery->lock(true);
    $marker = $markerQuery->find();
    if ($marker) {
        if ((string)$marker['sql_checksum'] !== EXPECTED_PLAN_SHA256) {
            throw new RuntimeException('Existing batch marker has another plan checksum');
        }
        return ['status' => 'already_applied', 'sha' => EXPECTED_PLAN_SHA256];
    }
    // Applying holds source, project-rule and active relation rows until commit,
    // so a concurrent edit cannot change the approved inputs midway through the batch.
    $suffix = $lock ? ' FOR UPDATE' : '';
    $sources = Db::query('SELECT c.id AS source_id,c.product_id,c.yeji,p.id AS project_exists,' .
        'p.pid,p.product_type,p.is_del FROM eb_yeji_commission c ' .
        'LEFT JOIN eb_store_product p ON p.id=c.product_id ORDER BY c.id' . $suffix);
    $rules = Db::query("SELECT id,project_id,craftsman_default_unit_amount_cents " .
        "FROM eb_cashier_v3_project_performance_rule WHERE tenant_id='0' ORDER BY project_id,id" . $suffix);
    $relations = Db::query('SELECT r.id,r.product_id,r.craftsman_default_mode,' .
        'r.craftsman_default_unit_amount_cents,p.id AS project_exists,' .
        'p.pid AS project_pid,p.product_type AS project_product_type ' .
        'FROM eb_store_card_related r LEFT JOIN eb_store_product p ON p.id=r.product_id ' .
        'WHERE r.status=1 AND r.product_type=6 ORDER BY r.id' . $suffix);
    $plan = buildPlan($sources, $rules, $relations);
    return ['status' => 'ready'] + $plan;
}

$mode = $argv[1] ?? '';
if ($mode === '--self-test') {
    // Synthetic rows exercise the two monetary outcomes and fail-closed guards
    // without needing a database or altering the approved 010 baseline.
    $sources = [];
    for ($i = 1; $i <= 135; $i++) {
        $sources[] = ['source_id' => $i, 'product_id' => 1000 + $i,
            'yeji' => 100 + $i, 'project_exists' => 1000 + $i,
            'pid' => 0, 'product_type' => 6, 'is_del' => 0];
    }
    for ($i = 136; $i <= 139; $i++) {
        $sources[] = ['source_id' => $i, 'product_id' => 0, 'yeji' => 1,
            'project_exists' => null, 'pid' => null, 'product_type' => null, 'is_del' => null];
    }
    $relations = [];
    for ($i = 1; $i <= 1938; $i++) {
        $projectId = $i <= 1790 ? 1001 : 9000;
        $relations[] = ['id' => $i, 'product_id' => $projectId,
            'craftsman_default_mode' => 'default_unit_price',
            'craftsman_default_unit_amount_cents' => 0,
            'project_exists' => $projectId, 'project_pid' => 0,
            'project_product_type' => 6];
    }
    $plan = buildPlan($sources, [], $relations);
    if (count($plan['projectAmounts']) !== 135 || count($plan['manual']) !== 1790 ||
        count($plan['fallback']) !== 148 || $plan['manual'][0] !== [1, 10100]) {
        throw new RuntimeException('Source amount or fallback self-test failed');
    }
    $relations[0]['craftsman_default_mode'] = 'manual_amount';
    try { buildPlan($sources, [], $relations); throw new RuntimeException('Manual override was overwritten'); }
    catch (RuntimeException $error) {
        if ($error->getMessage() === 'Manual override was overwritten') throw $error;
    }
    $relations[0]['craftsman_default_mode'] = 'default_unit_price';
    $sources[1]['product_id'] = $sources[0]['product_id'];
    try { buildPlan($sources, [], $relations); throw new RuntimeException('Duplicate source was accepted'); }
    catch (RuntimeException $error) {
        if ($error->getMessage() === 'Duplicate source was accepted') throw $error;
    }
    echo "PASS source value, default-price fallback, existing manual and duplicate guards\n";
    exit(0);
}
if (!in_array($mode, ['--dry-run', '--apply'], true) ||
    getenv('TARGET_SITE_ROOT') !== TARGET_ROOT || PHP_SAPI !== 'cli') {
    throw new RuntimeException('Expected --dry-run/--apply with the exact 010 site root');
}
$codeVersion = $argv[2] ?? '';
if ($mode === '--apply' && !preg_match('/^[0-9a-f]{40}$/', $codeVersion)) {
    throw new RuntimeException('Approved committed source hash required for apply');
}
require TARGET_ROOT . '/vendor/autoload.php';
$app = new \think\App(TARGET_ROOT . '/');
$app->env->load(TARGET_ROOT . '/.env');
$app->initialize();

if ($mode === '--dry-run') {
    Db::execute('SET TRANSACTION READ ONLY');
    Db::startTrans();
    try {
        $plan = inspectTarget();
        echo json_encode(['status' => $plan['status'], 'sha' => $plan['sha'],
            'projects' => count($plan['projectAmounts'] ?? []),
            'manual_card_rows' => count($plan['manual'] ?? []),
            'default_price_card_rows' => count($plan['fallback'] ?? [])],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    } finally {
        Db::rollback();
    }
    exit(0);
}

Db::startTrans();
try {
    $plan = inspectTarget(true);
    if ($plan['status'] === 'already_applied') {
        Db::rollback();
        echo "PASS already_applied; no rows changed\n";
        exit(0);
    }
    if ($plan['sha'] !== EXPECTED_PLAN_SHA256) {
        throw new RuntimeException('010 source or target differs from the accepted fixed plan');
    }
    $now = time();
    foreach ($plan['projectAmounts'] as $projectId => $amountCents) {
        // Preserve other project rule fields; a pre-existing explicit value blocks the batch.
        $existing = null;
        foreach ($plan['existingRules'] as $rule) {
            if ($rule[1] === (int)$projectId) { $existing = $rule[0]; break; }
        }
        if ($existing !== null) {
            $affected = Db::name('cashier_v3_project_performance_rule')
                ->where('id', $existing)->whereNull('craftsman_default_unit_amount_cents')
                ->update(['craftsman_default_unit_amount_cents' => $amountCents,
                    'updated_at' => $now, 'current_version' => Db::raw('current_version+1')]);
        } else {
            $affected = Db::name('cashier_v3_project_performance_rule')->insert([
                'tenant_id' => '0', 'project_id' => $projectId,
                'consumption_mode' => 'actual_entitlement_amount',
                'consumption_configured_unit_amount_cents' => 0,
                'labor_mode' => 'project_configured_amount',
                'labor_configured_unit_amount_cents' => 0,
                'craftsman_default_unit_amount_cents' => $amountCents,
                'current_version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if ((int)$affected !== 1) throw new RuntimeException("Project {$projectId} write failed");
    }
    foreach ($plan['manual'] as [$relationId, $amountCents]) {
        $affected = Db::name('store_card_related')->where('id', $relationId)
            ->where('status', 1)->where('product_type', 6)
            ->where('craftsman_default_mode', 'default_unit_price')
            ->where('craftsman_default_unit_amount_cents', 0)
            ->update(['craftsman_default_mode' => 'manual_amount',
                'craftsman_default_unit_amount_cents' => $amountCents]);
        if ((int)$affected !== 1) throw new RuntimeException("Card relation {$relationId} write failed");
    }
    // Explicitly check every fallback before commit; no unknown source becomes manual zero.
    foreach ($plan['fallback'] as $relationId) {
        $row = Db::name('store_card_related')->where('id', $relationId)
            ->field('craftsman_default_mode,craftsman_default_unit_amount_cents')->find();
        if (!$row || $row['craftsman_default_mode'] !== 'default_unit_price' ||
            (int)$row['craftsman_default_unit_amount_cents'] !== 0) {
            throw new RuntimeException("Fallback relation {$relationId} changed");
        }
    }
    $badProjects = Db::query("SELECT COUNT(*) AS n FROM eb_yeji_commission c " .
        "JOIN eb_store_product p ON p.id=c.product_id " .
        "LEFT JOIN eb_cashier_v3_project_performance_rule v ON v.tenant_id='0' AND v.project_id=p.id " .
        "WHERE p.pid=0 AND p.product_type=6 AND " .
        "(v.id IS NULL OR v.craftsman_default_unit_amount_cents<>c.yeji*100)")[0];
    $badCards = Db::query('SELECT COUNT(*) AS n FROM eb_store_card_related r ' .
        'JOIN eb_store_product p ON p.id=r.product_id ' .
        'JOIN eb_yeji_commission c ON c.product_id=IF(p.pid=0,p.id,p.pid) ' .
        "WHERE r.status=1 AND r.product_type=6 AND " .
        "(r.craftsman_default_mode<>'manual_amount' OR " .
        "r.craftsman_default_unit_amount_cents<>c.yeji*100)")[0];
    if ((int)$badProjects['n'] !== 0 || (int)$badCards['n'] !== 0) {
        throw new RuntimeException('Project or active card post-write reconciliation failed');
    }
    Db::name('database_upgrade_log')->insert([
        'upgrade_key' => UPGRADE_KEY,
        'title' => '010 项目及卡项默认手艺人业绩导入',
        'task_file' => '后端代码/database/upgrades/2026-10-27-渼约010项目卡项手艺人业绩/01-项目与卡项默认业绩导入.php',
        'sql_checksum' => EXPECTED_PLAN_SHA256,
        'code_version' => $codeVersion,
        'executed_at' => date('Y-m-d H:i:s'),
        'executed_by' => 'Codex',
        'result_note' => '135 project defaults; 1790 active card rows manual; 148 retain unit price',
    ]);
    Db::commit();
    echo "PASS 010 project/card defaults imported; completed service facts unchanged\n";
} catch (Throwable $error) {
    Db::rollback();
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
