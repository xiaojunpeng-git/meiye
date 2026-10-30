<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/** 007 有效旧核销人口覆盖审计：只认原 writeoff.id 的显式 V3 来源字段。 */
final class ItemConsumption007SourceCoverageServices
{
    private $sourceReader;

    /** 注入只读来源审计供合同测试；生产只比较身份，不读取顾客或金额。 */
    public function __construct(?callable $sourceReader = null)
    {
        $this->sourceReader = $sourceReader ?: [$this,'auditSource'];
    }

    /** 先在授权门店全域查漏；全部旧行唯一映射后，结果日期交给 V3 business_date。 */
    public function read(array $stores, array $range): array
    {
        foreach ($stores as $store) if (!is_int($store) || $store < 1)
            throw new \InvalidArgumentException('授权门店无效');
        if ($stores === []) return ['complete'=>true, 'legacy_candidates'=>0, 'gaps'=>[]];
        $source = ($this->sourceReader)($stores, $range);
        if (!($source['verified'] ?? false)) return ['complete'=>false, 'legacy_candidates'=>null,
            'gaps'=>[$source['gap'] ?? ['code'=>'LEGACY_WRITEOFF_SOURCE_UNVERIFIED',
                'message'=>'旧有效核销与 V3 来源身份不可核实。']]];
        $count = $source['count'] ?? null;
        $unmapped = $source['unmapped'] ?? null;
        $ambiguous = $source['ambiguous'] ?? null;
        $mismatched = $source['mismatched'] ?? null;
        if (!is_int($count) || $count < 0 || !is_int($unmapped) || $unmapped < 0 ||
            !is_int($ambiguous) || $ambiguous < 0 || !is_int($mismatched) || $mismatched < 0 ||
            $unmapped + $ambiguous + $mismatched > $count)
            throw new \LogicException('旧核销映射计数无效');
        $gaps = [];
        if ($unmapped > 0) $gaps[] = ['code'=>'LEGACY_WRITEOFF_V3_UNMAPPED',
            'message'=>'有旧有效核销未按原 writeoff.id 映射到 V3 完成服务事实。',
            'source_count'=>$unmapped];
        if ($ambiguous > 0) $gaps[] = ['code'=>'LEGACY_WRITEOFF_V3_AMBIGUOUS',
            'message'=>'同一原 writeoff.id 映射多条 V3 完成服务事实。',
            'source_count'=>$ambiguous];
        if ($mismatched > 0) $gaps[] = ['code'=>'LEGACY_WRITEOFF_V3_IDENTITY_MISMATCH',
            'message'=>'原 writeoff.id 已有 V3 来源行，但执行门店、项目、会员或完成状态不一致。',
            'source_count'=>$mismatched];
        return ['complete'=>$gaps === [], 'legacy_candidates'=>$count,
            'mapped_candidates'=>$count-$unmapped-$ambiguous-$mismatched, 'gaps'=>$gaps];
    }

    /** 010 导入计划对卡项和非卡项目都保存原 writeoff.id；缺字段即停用。 */
    private function auditSource(array $stores, array $range): array
    {
        try {
            $old = Db::name('store_order_writeoff');
            $fact = Db::name('cashier_v3_entitlement_service_fact');
            if (array_diff(['id','relation_id','product_id','uid','status'], $old->getTableFields()) !== [] ||
                array_diff(['id','tenant_id','store_id','project_id','member_id','source_document_type',
                    'source_document_id','service_status','business_date'], $fact->getTableFields()) !== [])
                return $this->unverified('LEGACY_WRITEOFF_MAPPING_SCHEMA_MISSING');
            $oldTable = $old->getTable(); $factTable = $fact->getTable();
            if (!is_string($oldTable) || !preg_match('/^[A-Za-z0-9_]+$/D', $oldTable) ||
                !is_string($factTable) || !preg_match('/^[A-Za-z0-9_]+$/D', $factTable))
                return $this->unverified('LEGACY_WRITEOFF_MAPPING_SCHEMA_MISSING');
            // Pre-aggregate the indexed V3 source IDs once, then join each old
            // ID. Joining old rows to all service facts before GROUP BY causes
            // a slow nested scan over the full history on 010.
            $storeList = implode(',', array_map('intval', $stores));
            $sql = $this->coverageSql($oldTable, $factTable, $storeList);
            $rows = Db::query($sql);
            if (count($rows) !== 1) return $this->unverified('LEGACY_WRITEOFF_MAPPING_QUERY_FAILED');
            return ['verified'=>true, 'count'=>(int)$rows[0]['legacy_count'],
                'unmapped'=>(int)$rows[0]['unmapped'], 'ambiguous'=>(int)$rows[0]['ambiguous'],
                'mismatched'=>(int)$rows[0]['mismatched']];
        } catch (\Throwable $error) {
            return $this->unverified('LEGACY_WRITEOFF_MAPPING_QUERY_FAILED');
        }
    }

    /**
     * Both import plans persist original writeoff.id. Source type is checked
     * in the V3 pre-aggregation, never inferred from old w.type. MIN is read
     * only for a unique candidate; duplicate IDs are flagged before identity.
     */
    private function coverageSql(string $oldTable, string $factTable, string $storeList): string
    {
        return "SELECT COUNT(*) AS legacy_count, "
            ."COALESCE(SUM(CASE WHEN m.candidate_count IS NULL THEN 1 ELSE 0 END),0) AS unmapped, "
            ."COALESCE(SUM(CASE WHEN m.candidate_count>1 THEN 1 ELSE 0 END),0) AS ambiguous, "
            ."COALESCE(SUM(CASE WHEN m.candidate_count=1 AND (m.mapped_store_id<>w.relation_id "
            ."OR m.mapped_project_id<>w.product_id OR m.mapped_member_id<>w.uid "
            ."OR m.mapped_status<>'completed') THEN 1 ELSE 0 END),0) AS mismatched "
            ."FROM `{$oldTable}` w LEFT JOIN ("
            ."SELECT s.source_document_id,COUNT(*) AS candidate_count,"
            ."MIN(s.store_id) AS mapped_store_id,MIN(s.project_id) AS mapped_project_id,"
            ."MIN(s.member_id) AS mapped_member_id,MIN(s.service_status) AS mapped_status "
            ."FROM `{$factTable}` s WHERE s.tenant_id='0' "
            ."AND s.source_document_type IN ('legacy_writeoff','legacy_paid_project') "
            ."AND s.source_document_id>0 GROUP BY s.source_document_id"
            .") m ON m.source_document_id=w.id "
            ."WHERE w.relation_id IN ({$storeList}) AND w.status=0";
    }

    private function unverified(string $code): array
    {
        return ['verified'=>false,'gap'=>['code'=>$code,
            'message'=>'旧有效核销来源无法验证，不能宣称 V3 服务覆盖完整。']];
    }
}
