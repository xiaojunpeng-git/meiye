<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use think\facade\Db;

/**
 * 赠送项目使用情况只读取已发放的准确权益明细和核销／冲销事实。
 * 列表身份只用于定位赠送，不允许客户端提交权益明细或会员 ID 扩大查询。
 */
final class CashierV3GiftProjectUsageQueryServices
{
    private const PAGE_SIZE = 20;

    public function query(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $recordId = trim((string)($payload['recordId'] ?? ''));
        if (!$scope->hasFeature('cashier.v3.order_center')
            || preg_match('/^(v3-direct-gift|v3-gift|gift):([A-Za-z0-9:._-]{1,160})$/D', $recordId, $match) !== 1) {
            throw self::notFound();
        }
        $allowed = $scope->narrowVisibleStores(isset($payload['storeIds']) && is_array($payload['storeIds']) ? $payload['storeIds'] : null);
        if ($allowed === []) throw self::notFound();
        $source = $match[1] === 'gift'
            ? $this->legacySource((int)$match[2], $allowed)
            : $this->v3Source($match[1], $match[2], $allowed, $scope);
        if (!$source) throw self::notFound();

        $detailId = $source['detailId'];
        $holderId = $source['holderId'];
        $memberId = $source['memberId'];
        $issued = $source['issuedQuantity'];
        $remaining = $source['remainingQuantity'];
        // 核销事实保留历史；反向事实只抵消有效使用，不能抹掉原核销时间与单号。
        $writeoff = Db::name('cashier_v3_entitlement_writeoff_fact')
            ->where('tenant_id', $scope->tenantId())->where('member_id', $memberId)
            ->where('source_detail_id', $detailId)->where('origin_order_id', $source['orderId'])
            ->where('status', 'effective');
        if ($holderId > 0) $writeoff->where('holder_id', $holderId);
        $all = (clone $writeoff)->field('writeoff_id,quantity')->order('id', 'asc')->select()->toArray();
        $total = count($all);
        $page = min(max(1, (int)($payload['page'] ?? 1)), max(1, (int)ceil($total / self::PAGE_SIZE)));
        $rows = (clone $writeoff)->field('id,writeoff_id,quantity,settled_at,document_no_snapshot')
            ->order('settled_at', 'desc')->order('id', 'desc')->page($page, self::PAGE_SIZE)->select()->toArray();
        $ids = array_column($all, 'writeoff_id');
        $reversals = $ids ? Db::name('cashier_v3_entitlement_reversal_fact')
            ->where('tenant_id', $scope->tenantId())->where('member_id', $memberId)
            ->where('source_detail_id', $detailId)->whereIn('original_writeoff_id', $ids)
            ->field('original_writeoff_id,quantity')->select()->toArray() : [];
        $restored = [];
        foreach ($reversals as $reversal) {
            $key = (string)$reversal['original_writeoff_id'];
            $restored[$key] = ($restored[$key] ?? 0) + (int)$reversal['quantity'];
        }
        $ledgerUsed = 0;
        foreach ($all as $fact) {
            $quantity = (int)$fact['quantity'];
            $reversed = (int)($restored[(string)$fact['writeoff_id']] ?? 0);
            if ($quantity <= 0 || $reversed < 0 || $reversed > $quantity) throw self::incomplete();
            $ledgerUsed += $quantity - $reversed;
        }
        // 作废只撤销剩余权益，不能把归零的余次当成已核销；老数据缺少逐笔事实时保留未知口径。
        $usageKnown = !$source['voided'] || $match[1] !== 'gift';
        $projectedUsed = $source['voided'] ? $ledgerUsed : $issued - $remaining;
        if ($issued < 1 || $remaining < 0 || $remaining > $issued || $ledgerUsed > $issued || $ledgerUsed > $projectedUsed) {
            throw self::incomplete();
        }
        $records = [];
        foreach ($rows as $fact) {
            $quantity = (int)$fact['quantity'];
            $reversed = (int)($restored[(string)$fact['writeoff_id']] ?? 0);
            $records[] = [
                'usageId' => (string)$fact['writeoff_id'],
                'usedAt' => (int)$fact['settled_at'] > 0 ? date('Y-m-d H:i:s', (int)$fact['settled_at']) : null,
                'quantity' => $quantity,
                'restoredQuantity' => $reversed,
                'usageStatus' => $reversed === $quantity ? '已作废' : ($reversed > 0 ? '部分作废' : '已使用'),
                'serviceRecordNo' => trim((string)$fact['document_no_snapshot']) ?: null,
            ];
        }
        return [
            'recordId' => $recordId, 'giftRecordNo' => $source['giftRecordNo'], 'projectName' => $source['projectName'],
            'issuedQuantity' => $issued, 'usedQuantity' => $usageKnown ? $projectedUsed : null,
            'remainingQuantity' => $source['voided'] ? 0 : $remaining,
            'unrecordedQuantity' => $usageKnown ? $projectedUsed - $ledgerUsed : null,
            'usageKnown' => $usageKnown,
            'availabilityStatus' => $source['voided'] ? '已作废' : '有效',
            'records' => $records, 'total' => $total, 'page' => $page, 'pageSize' => self::PAGE_SIZE,
            'dataAsOf' => time(),
        ];
    }

    /** V3 赠送必须沿事实、主单、明细和实体投影的同一身份查找，禁止按项目名称关联。 */
    private function v3Source(string $prefix, string $factId, ?array $allowed, CashierV3DataScopeContext $scope): ?array
    {
        $kind = $prefix === 'v3-direct-gift' ? 'direct_gift' : 'recharge_gift';
        $base = $kind === 'direct_gift' ? 'cashier_v3_direct_gift' : 'cashier_v3_recharge_gift';
        $query = Db::name('cashier_v3_gift_fact')->alias('f')
            ->join($base . '_authority a', 'a.tenant_id=f.tenant_id AND a.gift_id=f.source_id')
            ->join($base . '_item i', 'i.gift_id=f.source_id AND i.item_id=f.source_detail_id')
            ->where('f.tenant_id', $scope->tenantId())->where('f.gift_fact_id', $factId)
            ->where('f.source_type', $kind)->where('f.gift_kind', 'project')->where('f.status', 'effective')
            ->whereIn('a.status', ['issued', 'voided'])->whereIn('i.status', ['issued', 'voided']);
        if ($allowed !== null) $query->whereIn('f.store_id', $allowed);
        $gift = $query->field('f.member_id,f.store_id,f.quantity,f.content_name_snapshot,a.gift_no,a.status AS authority_status,i.status AS item_status,i.legacy_order_id,i.card_holder_id,i.benefit_detail_id,i.quantity AS item_quantity')->find();
        if (!$gift) return null;
        $detailId = (int)$gift['benefit_detail_id'];
        $holderId = (int)$gift['card_holder_id'];
        $orderId = (int)$gift['legacy_order_id'];
        $memberId = (int)$gift['member_id'];
        if ($detailId <= 0 || $holderId <= 0 || $orderId <= 0 || (int)$gift['quantity'] !== (int)$gift['item_quantity']) throw self::incomplete();
        $detail = Db::name('store_order_cart_info')->where('id', $detailId)->where('oid', $orderId)->where('uid', $memberId)
            ->where('relation_id', (int)$gift['store_id'])->where('source_type', 'cashier_v3_' . $kind)
            ->field('write_times,write_surplus_times')->find();
        $holder = Db::name('user_card_holder')->where('id', $holderId)->where('oid', $orderId)->where('uid', $memberId)
            ->where('store_id', (int)$gift['store_id'])->field('write_times,write_surplus_times')->find();
        $order = Db::name('store_order')->where('id', $orderId)->where('uid', $memberId)
            ->where('store_id', (int)$gift['store_id'])->where('channel_type', 'cashier_v3_' . $kind)
            ->where('paid', 1)->field('id')->find();
        // 赠送数量是选购份数；项目权益总次数还要乘 SKU 原配置次数，不能直接拿赠送数量算余次。
        if (!$detail || !$holder || !$order || (int)$detail['write_times'] < 1
            || (int)$holder['write_times'] !== (int)$detail['write_times']
            || (int)$detail['write_surplus_times'] !== (int)$holder['write_surplus_times']) throw self::incomplete();
        return ['detailId' => $detailId, 'holderId' => $holderId, 'orderId' => $orderId, 'memberId' => $memberId,
            'issuedQuantity' => (int)$detail['write_times'], 'remainingQuantity' => (int)$detail['write_surplus_times'],
            'giftRecordNo' => (string)$gift['gift_no'], 'projectName' => (string)$gift['content_name_snapshot'],
            'voided' => (string)$gift['authority_status'] === 'voided' || (string)$gift['item_status'] === 'voided'];
    }

    /** 老赠送只用原订单行的稳定 ID；无逐笔事实时如实保留当前余次并提示明细缺口。 */
    private function legacySource(int $detailId, ?array $allowed): ?array
    {
        if ($detailId <= 0) return null;
        $query = Db::name('store_order_cart_info')->alias('i')->join('store_order o', 'o.id=i.oid')
            ->where('i.id', $detailId)->where('i.is_gift', 1)->where('i.product_type', 6)
            ->where('o.paid', 1)->where('o.is_system_del', 0)
            ->where('i.cart_info', 'not like', '%cashier_v3_recharge_gift%')
            ->where('i.cart_info', 'not like', '%cashier_v3_direct_gift%');
        if ($allowed !== null) $query->whereIn('o.store_id', $allowed);
        $row = $query->field('i.id,i.oid,i.uid,i.cart_info,i.cart_num,i.write_times,i.write_surplus_times,o.order_id,o.store_id,o.terminal_action')->find();
        if (!$row) return null;
        $snapshot = json_decode((string)$row['cart_info'], true);
        $issued = (int)$row['write_times'] > 0 ? (int)$row['write_times'] : (int)$row['cart_num'];
        return ['detailId' => (int)$row['id'], 'holderId' => 0, 'orderId' => (int)$row['oid'], 'memberId' => (int)$row['uid'],
            'issuedQuantity' => $issued, 'remainingQuantity' => (int)$row['write_surplus_times'],
            'giftRecordNo' => 'GIFT-' . (string)$row['id'],
            'projectName' => is_array($snapshot) ? (string)($snapshot['productInfo']['store_name'] ?? $snapshot['store_name'] ?? $snapshot['name'] ?? '赠送项目') : '赠送项目',
            'voided' => (int)$row['terminal_action'] !== 0];
    }

    private static function notFound(): CashierV3CommandException
    { return new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '该赠送项目不存在或当前无权查看。'); }

    private static function incomplete(): CashierV3CommandException
    { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '赠送项目使用资料不完整，请联系管理员核对。'); }
}
