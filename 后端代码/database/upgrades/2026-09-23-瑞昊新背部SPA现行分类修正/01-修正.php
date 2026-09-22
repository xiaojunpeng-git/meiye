<?php

declare(strict_types=1);

// One-instance, one-project configuration correction. Historical service and
// performance facts are immutable and are deliberately never written here.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use think\facade\Db;

$apply = in_array('--apply', $argv, true);
if (count($argv) > 2 || (count($argv) === 2 && !$apply)) {
    fwrite(STDERR, "Usage: php 01-修正.php [--apply]\n");
    exit(2);
}

$app = new think\App(dirname(__DIR__, 3) . '/');
$app->initialize();

Db::startTrans();
try {
    $project = Db::name('store_product')->where('id', 372012)->lock(true)
        ->field('id,pid,type,relation_id,cate_id,store_name')->find();
    if (!$project || (int)$project['pid'] !== 79561 || (int)$project['type'] !== 1
        || (int)$project['relation_id'] !== 133 || (string)$project['store_name'] !== '新背部SPA(手工)') {
        throw new RuntimeException('目标项目身份不匹配，已停止');
    }
    $root = Db::name('store_product_category')->where('id', 26302)
        ->field('id,pid,type,relation_id,cate_name,is_show')->find();
    $target = Db::name('store_product_category')->where('id', 26332)
        ->field('id,pid,type,relation_id,cate_name,is_show')->find();
    if (!$root || (int)$root['pid'] !== 0 || (int)$root['type'] !== 0
        || (int)$root['relation_id'] !== 0 || (string)$root['cate_name'] !== '生美'
        || (int)$root['is_show'] !== 1 || !$target || (int)$target['pid'] !== 26302
        || (int)$target['type'] !== 0 || (int)$target['relation_id'] !== 0
        || (string)$target['cate_name'] !== '卡项' || (int)$target['is_show'] !== 1) {
        throw new RuntimeException('目标分类不是当前可见的生美/卡项，已停止');
    }
    $before = (string)$project['cate_id'];
    if (!in_array($before, ['21560', '26332'], true)) {
        throw new RuntimeException('项目当前分类不是预期旧值或目标值，已停止');
    }
    $changed = 0;
    if ($before === '21560') {
        // CAS keeps the correction scoped to the reviewed project state.
        $changed = Db::name('store_product')->where('id', 372012)
            ->where('pid', 79561)->where('type', 1)->where('relation_id', 133)
            ->where('cate_id', '21560')->update(['cate_id' => '26332']);
        if ($changed !== 1) throw new RuntimeException('项目分类并发变化，已停止');
    }
    $after = (string)Db::name('store_product')->where('id', 372012)->value('cate_id');
    if ($after !== '26332') throw new RuntimeException('项目分类写后核验失败');
    if ($apply) Db::commit(); else Db::rollback();
    echo json_encode([
        'mode' => $apply ? 'APPLIED' : 'DRY_RUN_ROLLED_BACK',
        'project_id' => 372012, 'store_id' => 133,
        'before_category_id' => $before, 'target_category_id' => 26332,
        'changed_rows_in_transaction' => $changed,
        'historical_facts_touched' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $exception) {
    Db::rollback();
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
