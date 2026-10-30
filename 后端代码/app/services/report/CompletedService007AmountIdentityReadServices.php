<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

/**
 * Internal 007 order bridge input: one exact V3 completed service and its
 * settled consumption amount per row. It never equates an old suborder ID to
 * a V3 ID or changes the amount semantics of the visible consumption report.
 */
final class CompletedService007AmountIdentityReadServices
{
    private $serviceReader;
    private $amountReader;
    private $snapshotReader;
    private $coverageReader;
    private $immediateReader;
    private $legacyReader;

    /** Injectable bounded readers let contracts prove identity and fail-closed behavior without a database. */
    public function __construct(?callable $serviceReader = null, ?callable $amountReader = null,
        ?callable $snapshotReader = null, ?callable $coverageReader = null, ?callable $immediateReader = null,
        ?callable $legacyReader = null)
    {
        $this->serviceReader = $serviceReader ?: static function (array $stores, array $range): array {
            return RegisteredMetricReadServices::withCurrentReportProductCategory()
                ->categoryRows('completed_service_item_count', '0', $stores, $range);
        };
        $this->amountReader = $amountReader ?: static function (array $stores, array $range): array {
            return RegisteredMetricReadServices::withCurrentReportProductCategory()
                ->categoryRows('consume_amount', '0', $stores, $range);
        };
        $this->snapshotReader = $snapshotReader ?: [$this, 'snapshots'];
        $this->coverageReader = $coverageReader ?: [new ItemConsumption007SourceCoverageServices(), 'read'];
        $this->immediateReader = $immediateReader ?: [new SaleProjectConsumption007AmountReadServices(), 'read'];
        $this->legacyReader = $legacyReader ?: [$this, 'legacySources'];
    }

    /**
     * The caller supplies only server-authorized stores. Old writeoff coverage
     * is checked over those stores without an old-date restriction; result
     * dates come exclusively from V3 business_date. Any gap retracts all rows.
     *
     * @return array{rows:array,complete:bool,gaps:array,source_coverage:array}
     */
    public function read(array $stores, array $range): array
    {
        $stores = $this->stores($stores);
        $range = $this->range($range);
        if (!$stores) return ['rows'=>[], 'complete'=>true, 'gaps'=>[], 'source_coverage'=>
            ['complete'=>true, 'legacy_candidates'=>0, 'gaps'=>[]]];
        return Db::transaction(function () use ($stores, $range): array {
            $coverage = ($this->coverageReader)($stores, $range);
            if (!($coverage['complete'] ?? false)) return $this->failClosed($coverage, $coverage['gaps'] ?? []);
            $services = ($this->serviceReader)($stores, $range);
            $amounts = ($this->amountReader)($stores, $range);
            $this->cap($services); $this->cap($amounts);
            $snapshots = ($this->snapshotReader)($stores, array_column($services, 'service_fact_id'));
            $this->cap($snapshots);
            $immediate = ($this->immediateReader)($stores, array_values($snapshots), $amounts);
            $amounts = array_merge($amounts, $immediate['rows'] ?? []);
            $this->cap($amounts);
            $legacy = ($this->legacyReader)($snapshots);
            return $this->project($services, $amounts, $snapshots, $stores, $range,
                $coverage, $immediate['gaps'] ?? [], $legacy);
        });
    }

    /** Fetch source identity only for already-scoped registered service IDs. */
    private function snapshots(array $stores, array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_filter($ids))), 500) as $chunk) {
            $rows = Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', '0')
                ->whereIn('store_id', $stores)->whereIn('service_fact_id', $chunk)
                ->field('service_fact_id,store_id,member_id,service_object,business_date,service_status,'
                    .'source_document_type,source_document_id,document_id,checkout_request_id,'
                    .'source_line_id,project_id,quantity')->limit(10001)->select()->toArray();
            $this->cap($rows);
            foreach ($rows as $row) {
                $id = (string)$row['service_fact_id'];
                if (isset($out[$id])) throw new \LogicException('服务事实身份重复');
                $out[$id] = $row;
            }
        }
        return $out;
    }

    /** Legacy source IDs must be checked row by row, including a bad ID from outside the authorized store. */
    private function legacySources(array $snapshots): array
    {
        $ids = [];
        foreach ($snapshots as $row) {
            if (!in_array((string)($row['source_document_type'] ?? ''),
                ['legacy_writeoff', 'legacy_paid_project'], true)) continue;
            $id = (string)($row['source_document_id'] ?? '');
            if (ctype_digit($id) && (int)$id > 0) $ids[(int)$id] = (int)$id;
        }
        $out = [];
        foreach (array_chunk(array_values($ids), 500) as $chunk) {
            $rows = Db::name('store_order_writeoff')->whereIn('id', $chunk)
                ->field('id,oid,relation_id,uid,product_id,writeoff_num,status')->select()->toArray();
            foreach ($rows as $row) $out[(int)$row['id']] = $row;
        }
        return $out;
    }

    /**
     * Exact checkout line attribution uses the same registered consumption
     * metric and immediate-sale bridge as the two visible service reports.
     * It does not multiply a shared amount across several service facts.
     */
    private function project(array $services, array $amounts, array $snapshots, array $stores,
        array $range, array $coverage, array $initialGaps, array $legacy): array
    {
        $allowed = array_fill_keys($stores, true);
        $money = []; $moneyIds = []; $gaps = $initialGaps;
        foreach ($amounts as $fact) {
            $store = (int)($fact['store_id'] ?? 0);
            if (!isset($allowed[$store])) throw new \LogicException('消费金额越过授权门店');
            $factId = (string)($fact['fact_id'] ?? '');
            if ($factId === '' || isset($moneyIds[$factId])) throw new \LogicException('消费金额事实身份异常');
            $moneyIds[$factId] = true;
            $key = $this->lineKey($fact);
            $value = $fact['amount_cents'] ?? null;
            if (!is_int($value) && !(is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value))) {
                $gaps[] = ['code'=>'CONSUMPTION_AMOUNT_INVALID', 'fact_id'=>$factId];
                continue;
            }
            if (abs((int)$value) > 9007199254740991 ||
                abs($money[$key] ?? 0) > 9007199254740991 - abs((int)$value)) {
                $gaps[] = ['code'=>'CONSUMPTION_AMOUNT_OUT_OF_RANGE', 'fact_id'=>$factId];
                continue;
            }
            $money[$key] = ($money[$key] ?? 0) + (int)$value;
        }
        $rows = []; $serviceIds = []; $lineIds = [];
        foreach ($services as $fact) {
            $id = (string)($fact['service_fact_id'] ?? '');
            $store = (int)($fact['store_id'] ?? 0);
            if ($id === '' || isset($serviceIds[$id]) || !isset($allowed[$store]))
                throw new \LogicException('服务事实身份或权限异常');
            $serviceIds[$id] = true;
            $snapshot = $snapshots[$id] ?? null;
            if (!$snapshot || (int)$snapshot['store_id'] !== $store ||
                (string)($snapshot['service_status'] ?? '') !== 'completed' ||
                (isset($fact['source_line_id']) && (string)$fact['source_line_id'] !== (string)$snapshot['source_line_id']) ||
                (isset($fact['project_id']) && (int)$fact['project_id'] !== (int)$snapshot['project_id']) ||
                !is_numeric($fact['quantity'] ?? null) || !is_numeric($snapshot['quantity'] ?? null) ||
                bccomp((string)$fact['quantity'], (string)$snapshot['quantity'], 6) !== 0) {
                $gaps[] = ['code'=>'SERVICE_SOURCE_IDENTITY_MISSING', 'service_fact_id'=>$id];
                continue;
            }
            $day = (string)($snapshot['business_date'] ?? '');
            if ($day < $range['start'] || $day > $range['end'] ||
                !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)) {
                $gaps[] = ['code'=>'SERVICE_BUSINESS_DATE_MISMATCH', 'service_fact_id'=>$id];
                continue;
            }
            $key = $this->lineKey($snapshot);
            if (isset($lineIds[$key])) {
                $gaps[] = ['code'=>'SERVICE_LINE_AMBIGUOUS', 'service_fact_id'=>$id];
                continue;
            }
            $lineIds[$key] = true;
            if (!array_key_exists($key, $money)) {
                $gaps[] = ['code'=>'CONSUMPTION_AMOUNT_FACT_MISSING', 'service_fact_id'=>$id];
                continue;
            }
            $type = (string)($snapshot['source_document_type'] ?? '');
            $sourceId = (string)($snapshot['source_document_id'] ?? '');
            if (!in_array($type, ['legacy_writeoff', 'legacy_paid_project', 'sales_order', 'direct'], true)
                || ($type !== 'direct' && (!ctype_digit($sourceId) || (int)$sourceId <= 0))) {
                $gaps[] = ['code'=>'SERVICE_SOURCE_UNRECOGNIZED', 'service_fact_id'=>$id];
                continue;
            }
            $legacyOrigin = null;
            if (in_array($type, ['legacy_writeoff', 'legacy_paid_project'], true)) {
                $old = $legacy[(int)$sourceId] ?? null;
                if (!$old || (int)$old['status'] !== 0 ||
                    (int)$old['relation_id'] !== $store ||
                    (int)$old['uid'] !== (int)$snapshot['member_id'] ||
                    (int)$old['product_id'] !== (int)$snapshot['project_id'] ||
                    !is_numeric($old['writeoff_num'] ?? null) ||
                    bccomp((string)$old['writeoff_num'], (string)$snapshot['quantity'], 6) !== 0) {
                    $gaps[] = ['code'=>'LEGACY_SERVICE_IDENTITY_MISMATCH', 'service_fact_id'=>$id];
                    continue;
                }
                $legacyOrigin = (int)$old['oid'];
            }
            $amount = $money[$key]; unset($money[$key]);
            $rows[] = [
                'service_fact_id'=>$id, 'source_document_type'=>$type, 'source_document_id'=>$sourceId,
                'store_id'=>$store, 'business_date'=>$day, 'amount_cents'=>$amount,
                'member_id'=>(int)$snapshot['member_id'], 'service_object'=>(string)$snapshot['service_object'],
                'document_id'=>(string)$snapshot['document_id'],
                'checkout_request_id'=>(string)$snapshot['checkout_request_id'],
                'source_line_id'=>(string)$snapshot['source_line_id'],
                'project_id'=>(int)$snapshot['project_id'], 'quantity'=>$snapshot['quantity'],
                'legacy_origin_order_id'=>$legacyOrigin,
            ];
        }
        foreach ($money as $key => $amount) $gaps[] = ['code'=>'CONSUMPTION_ORPHAN_AMOUNT',
            'source_line_key'=>$key, 'amount_cents'=>$amount];
        if ($gaps !== []) return $this->failClosed($coverage, $gaps);
        usort($rows, static fn(array $left, array $right): int => strcmp($left['service_fact_id'], $right['service_fact_id']));
        return ['rows'=>$rows, 'complete'=>true, 'gaps'=>[], 'source_coverage'=>$coverage];
    }

    private function failClosed(array $coverage, array $gaps): array
    {
        return ['rows'=>[], 'complete'=>false, 'gaps'=>$gaps ?: [[
            'code'=>'SERVICE_AMOUNT_SOURCE_UNVERIFIED', 'message'=>'服务金额与来源身份不可完整核实。']],
            'source_coverage'=>$coverage];
    }

    private function lineKey(array $row): string
    {
        return json_encode([(int)($row['store_id'] ?? 0), (string)($row['checkout_request_id'] ?? ''),
            (string)($row['source_line_id'] ?? '')]);
    }

    private function stores(array $stores): array
    {
        $ids = [];
        foreach ($stores as $id) {
            if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int)$id <= 0)
                throw new \InvalidArgumentException('授权门店无效');
            $ids[(int)$id] = (int)$id;
        }
        return array_values($ids);
    }

    private function range(array $range): array
    {
        foreach (['start', 'end'] as $key) {
            $value = $range[$key] ?? null;
            $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if (!$date || $date->format('Y-m-d') !== $value)
                throw new \InvalidArgumentException('日期须为有效YYYY-MM-DD');
        }
        if ($range['start'] > $range['end'] || $range['start'] < StoreUnifiedReportServices::COVERAGE_START)
            throw new \InvalidArgumentException('日期范围无效或早于V3覆盖期');
        return ['start'=>$range['start'], 'end'=>$range['end']];
    }

    private function cap(array $rows): void
    {
        if (count($rows) > 10000) throw new \InvalidArgumentException('服务源数据超过10000行，请缩小范围');
    }
}
