<?php

declare(strict_types=1);

/**
 * 仅本地验证补交汇总行的既有手动字段闭环；所有注释写入在外层事务回滚。
 * 不创建业务单、不改收款/权益事实；显式启用且要求传入真实补交单号。
 */
if (getenv('R13_LOCAL_TEST') !== '1') throw new RuntimeException('Explicit local test flag required');
$receiptNo = trim((string)getenv('R13_REPAYMENT_NO'));
if ($receiptNo === '') throw new RuntimeException('Repayment number required');
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
(new \think\App(rtrim($backend, '/\\') . '/'))->initialize();
date_default_timezone_set('Asia/Shanghai');

use app\services\report\StoreOperationsReportAnnotationServices as Annotation;
use app\services\report\StoreUnifiedReportServices as Report;
use think\facade\Db;

/** 测试失败必须非零退出，不由框架错误页吞掉失败状态。 */
function r13AnnotationCheck(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . PHP_EOL;
}

/** 拒绝用例必须确实抛出业务校验异常，不能把其他运行错误当作权限生效。 */
function r13AnnotationReject(callable $action, string $label): void
{
    $rejected = false;
    try { $action(); } catch (InvalidArgumentException $error) { $rejected = true; }
    r13AnnotationCheck($rejected, $label);
}

$before = [Db::name(Annotation::ANNOTATION_TABLE)->count(), Db::name(Annotation::ANNOTATION_AUDIT_TABLE)->count()];
$failed = false;
Db::startTrans();
try {
    $fact = Db::name('cashier_v3_payment_fact')->where('order_no_snapshot', $receiptNo)
        ->where('status', 'effective')->where('fact_direction', 'forward')->find();
    r13AnnotationCheck(is_array($fact), 'real local repayment exists');
    $store = (int)$fact['store_id'];
    $context = ['tenant_id' => (string)$fact['tenant_id'], 'store_ids' => [$store],
        'operator_id' => 0, 'operator_name' => 'R13 rollback test'];
    $input = ['report' => 'cross_industry_customer_summary', 'start_date' => $fact['business_date'],
        'end_date' => $fact['business_date'], '_internal_all' => true, '_report_scope' => ['mode' => 'stores']];
    $readRow = static function () use ($store, $input, $fact): array {
        $result = (new Report())->query([$store], $input);
        $rows = array_values(array_filter($result['records'], static fn(array $row): bool => (int)$row['member_id'] === (int)$fact['member_id']));
        if (count($rows) !== 1) throw new RuntimeException('Expected exactly one member row');
        return $rows[0];
    };
    $row = $readRow();
    r13AnnotationCheck($row['annotation_subject_type'] === 'cross_industry_repayment', 'pure repayment row has a real receipt anchor');
    $base = ['report_code' => $input['report'], 'subject_type' => $row['annotation_subject_type'],
        'subject_key' => $row['annotation_subject_key'], 'store_id' => $store];
    $service = new Annotation();
    $prefix = 'r13-local-' . bin2hex(random_bytes(8));
    foreach (['customer_acquired_at' => '2026-10-02', 'partner_store_name' => 'R13本地回滚验证'] as $field => $value) {
        $payload = $base + ['field_key' => $field, 'field_value' => $value,
            'expected_version' => (int)$row[$field . '_version'], 'idempotency_key' => $prefix . '-' . $field];
        $saved = $service->saveAnnotation($context, $payload);
        $reloaded = $readRow();
        r13AnnotationCheck($reloaded[$field] === $value && (int)$reloaded[$field . '_version'] === (int)$saved['version'], $field . ' saves and survives fresh report query');
        $replayed = $service->saveAnnotation($context, $payload);
        r13AnnotationCheck($replayed['replayed'] && $replayed['version'] === $saved['version'], $field . ' duplicate request replays without another version');
        r13AnnotationReject(static fn() => $service->saveAnnotation($context, array_replace($payload, [
            'expected_version' => 99999999, 'idempotency_key' => $prefix . '-stale-' . $field,
        ])), $field . ' rejects stale version');
        $edited = $service->saveAnnotation($context, array_replace($payload, [
            'field_value' => $field === 'customer_acquired_at' ? '2026-10-03' : 'R13回滚修改',
            'expected_version' => $saved['version'], 'idempotency_key' => $prefix . '-edit-' . $field,
        ]));
        r13AnnotationCheck((int)$edited['version'] === (int)$saved['version'] + 1, $field . ' edit increments version');
        $service->saveAnnotation($context, array_replace($payload, [
            'field_value' => '', 'expected_version' => $edited['version'], 'idempotency_key' => $prefix . '-clear-' . $field,
        ]));
        $cleared = $readRow();
        r13AnnotationCheck($cleared[$field] === '' && $cleared['annotation_subject_key'] === $base['subject_key'], $field . ' clear persists without changing member anchor');
    }
    $payload = $base + ['field_key' => 'partner_store_name', 'field_value' => 'must reject',
        'expected_version' => 0, 'idempotency_key' => $prefix . '-deny'];
    r13AnnotationReject(static fn() => $service->saveAnnotation(array_replace($context, ['store_ids' => [2147483647]]), $payload), 'other store cannot write');
    r13AnnotationReject(static fn() => $service->saveAnnotation(array_replace($context, [
        'authorization_mode' => 'self_participant', 'participant_employee_id' => 2147483647,
    ]), $payload), 'unrelated employee cannot write');
    r13AnnotationReject(static fn() => $service->saveAnnotation($context, array_replace($payload, ['subject_key' => 'R13-nonexistent'])), 'invented receipt cannot write');
    r13AnnotationReject(static fn() => $service->saveAnnotation(array_replace($context, ['tenant_id' => 'R13-other']), $payload), 'other tenant cannot write');
    $notG = Db::name('cashier_v3_payment_fact')->alias('p')->join('cashier_v3_business_source s', 's.id=p.business_source_primary_id')
        ->where('p.store_id', $store)->where('p.source_document_type', 'debt_repayment')->where('s.name', 'not like', 'G%')->value('p.fact_id');
    r13AnnotationCheck((string)$notG !== '', 'negative fixture has other-source repayment');
    r13AnnotationReject(static fn() => $service->saveAnnotation($context, array_replace($payload, ['subject_key' => $notG])), 'other-source repayment cannot become G annotation');
    r13AnnotationCheck($service->listAnnotations($context, ['report_code' => $input['report'], 'subject_key' => $base['subject_key']]) !== [], 'saved fields are available through annotation read API');
    r13AnnotationCheck($service->listAnnotations(array_replace($context, ['store_ids' => [2147483647]]), ['report_code' => $input['report'], 'subject_key' => $base['subject_key']]) === [], 'annotation read API preserves store scope');
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
} finally {
    Db::rollback();
}
$after = [Db::name(Annotation::ANNOTATION_TABLE)->count(), Db::name(Annotation::ANNOTATION_AUDIT_TABLE)->count()];
if ($before !== $after) { fwrite(STDERR, 'Rollback count mismatch' . PHP_EOL); exit(1); }
echo 'PASS annotation and audit writes fully rolled back' . PHP_EOL;
if ($failed) exit(1);
