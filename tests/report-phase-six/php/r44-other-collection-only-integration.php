<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

(new \think\App('/var/www/html/'))->initialize();

use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

function checkOtherCollectionReport(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: {$message}\n";
}

$report = new StoreUnifiedReportPhaseSixServices();
$range = ['start' => '2026-10-11', 'end' => '2026-10-11'];
$normal = $report->query('phase_six_other_multi_payment', [175], $range);
$export = $report->query('phase_six_other_multi_payment', [175], $range, ['_internal_all' => true]);
$normalColumns = array_column($normal['columns'], 'key');
$exportColumns = array_column($export['columns'], 'key');
checkOtherCollectionReport(!in_array('unit_price', $normalColumns, true)
    && !in_array('unit_price', $exportColumns, true)
    && in_array('领取日期', $normalColumns, true)
    && in_array('领取数量', $normalColumns, true)
    && in_array('备注', $normalColumns, true),
    'unit price is absent from query, export and editing metadata while other manual columns remain');
$annotationRules = new \app\services\report\StoreOperationsReportAnnotationServices();
$fieldKey = new ReflectionMethod($annotationRules, 'fieldKey');
$fieldKey->setAccessible(true);
try {
    $fieldKey->invoke($annotationRules, 'phase_six_other_multi_payment', 'unit_price');
    throw new RuntimeException('removed unit price still accepts writes');
} catch (InvalidArgumentException $error) {
    checkOtherCollectionReport($error->getMessage() === '该报表字段不允许编辑',
        'removed unit price cannot be written through the report annotation API');
}
$byItem = [];
foreach ($normal['records'] as $row) {
    $byItem[(string)$row['item_name']][] = $row;
}
checkOtherCollectionReport(count($byItem['贵族'] ?? []) === 1
    && ($byItem['贵族'][0]['receivable_amount'] ?? null) === '120'
    && ($byItem['贵族'][0]['other_collection_amount'] ?? null) === '120',
    'historical other-collection tender shows the sale receivable and actual allocated tender');
checkOtherCollectionReport(count($byItem['昊美M'] ?? []) === 1
    && ($byItem['昊美M'][0]['receivable_amount'] ?? null) === '10000'
    && ($byItem['昊美M'][0]['other_collection_amount'] ?? null) === '10000',
    'noncash other-collection tender shows its actual settlement amount without calling it cash performance');
$sourceExplanations = array_column($normal['columns'], 'source_explanation', 'key');
checkOtherCollectionReport(str_contains((string)($sourceExplanations['other_collection_amount'] ?? ''), '不代表现金业绩')
    && str_contains((string)($sourceExplanations['category'] ?? ''), '改分类不追改旧单')
    && !isset($sourceExplanations['cash_amount'])
    && !str_contains(implode(' ', $sourceExplanations), '事实'),
    'column source descriptions explain the business meaning without internal jargon');
checkOtherCollectionReport(str_starts_with((string)$byItem['贵族'][0]['annotation_subject_key'], 'phase_six:payment:')
    && strlen((string)$byItem['昊美M'][0]['annotation_subject_key']) <= 128,
    'historical manual annotations retain their key and new rows have valid stable keys');
checkOtherCollectionReport(($normal['summary_row']['receivable_amount'] ?? null) === '10120'
    && ($normal['summary_row']['other_collection_amount'] ?? null) === '10120'
    && ($export['summary_row']['receivable_amount'] ?? null) === '10120'
    && ($export['summary_row']['other_collection_amount'] ?? null) === '10120'
    && count($normal['records']) === count($export['records']),
    'query, export and server-side summary share the same tender scope');
checkOtherCollectionReport($report->query('phase_six_other_multi_payment', [174], $range)['records'] === [],
    'another store cannot read these rows');

$draft = Db::name('cashier_v3_checkout_payment_draft')
    ->where('payment_draft_id', 'CKP-506f941ccc966bb3118ff85b1a3d85ab5b95dd20')->find();
checkOtherCollectionReport((bool)$draft, 'zero-amount probe has a committed other-collection tender');
Db::startTrans();
try {
    // The temporary local probe proves amount is not an eligibility filter; it never commits.
    Db::name('cashier_v3_checkout_payment_draft')->where('id', (int)$draft['id'])->update(['amount_cents' => 0]);
    $zero = $report->query('phase_six_other_multi_payment', [175], $range);
    $found = array_values(array_filter($zero['records'],
        static fn(array $row): bool => ($row['item_name'] ?? '') === '昊美M'));
    checkOtherCollectionReport(count($found) === 1 && ($found[0]['other_collection_amount'] ?? null) === '0'
        && ($found[0]['receivable_amount'] ?? null) === '10000',
        'zero-amount other-collection tender remains visible');
    Db::name('cashier_v3_checkout_payment_draft')->where('id', (int)$draft['id'])
        ->update(['payment_method' => 'cash']);
    $otherTenderOnly = $report->query('phase_six_other_multi_payment', [175], $range);
    checkOtherCollectionReport(!in_array('昊美M', array_column($otherTenderOnly['records'], 'item_name'), true),
        'the report excludes a settled order when its tender is not other collection');
} finally {
    Db::rollback();
}
checkOtherCollectionReport((int)Db::name('cashier_v3_checkout_payment_draft')
    ->where('id', (int)$draft['id'])->value('amount_cents') === (int)$draft['amount_cents'],
    'the temporary zero-amount probe rolled back');

Db::startTrans();
try {
    // A second committed tender is visible only inside this local transaction and is never retained.
    $second = $draft;
    unset($second['id']);
    $second['payment_draft_id'] = 'CKP-' . substr(hash('sha256', 'r44-report-second-tender'), 0, 40);
    $second['payment_authority_key'] = 'r44-report-second-tender';
    $second['payment_fingerprint'] = hash('sha256', 'r44-report-second-tender');
    $second['sort_no'] = (int)$draft['sort_no'] + 1;
    $second['amount_cents'] = 0;
    Db::name('cashier_v3_checkout_payment_draft')->insert($second);
    $twoTenders = $report->query('phase_six_other_multi_payment', [175], $range);
    $sameItem = array_values(array_filter($twoTenders['records'],
        static fn(array $row): bool => ($row['item_name'] ?? '') === '昊美M'));
    checkOtherCollectionReport(count($sameItem) === 2
        && ($twoTenders['summary_row']['receivable_amount'] ?? null) === '10120'
        && ($twoTenders['summary_row']['other_collection_amount'] ?? null) === '10120',
        'two other-collection tenders show two rows without doubling the receivable total');
} finally {
    Db::rollback();
}
checkOtherCollectionReport(!Db::name('cashier_v3_checkout_payment_draft')
    ->where('payment_draft_id', 'CKP-' . substr(hash('sha256', 'r44-report-second-tender'), 0, 40))->find(),
    'the second-tender probe rolled back');

$split = new ReflectionMethod($report, 'otherCollectionAmountsBySaleFact');
$split->setAccessible(true);
$parts = $split->invoke($report, [
    ['payment_draft_id' => 'T-1', 'sale_fact_id' => 'S-1', 'tender_amount_cents' => 101,
        'sale_amount_cents' => 600, 'debt_amount_cents' => 0, 'allocated_amount_cents' => null],
    ['payment_draft_id' => 'T-1', 'sale_fact_id' => 'S-2', 'tender_amount_cents' => 101,
        'sale_amount_cents' => 400, 'debt_amount_cents' => 0, 'allocated_amount_cents' => null],
]);
checkOtherCollectionReport(($parts['T-1']['S-1'] ?? null) === 61
    && ($parts['T-1']['S-2'] ?? null) === 40
    && array_sum($parts['T-1']) === 101,
    'multi-item other-collection amount is allocated once with cent-preserving remainder');
