<?php
declare(strict_types=1);

namespace app\services\report;

use app\services\query\metric\RegisteredMetricReadServices;

/**
 * 侧栏 007 对齐表复用已登记的 V3 原子指标。这里返回有来源版本的真实事实，
 * 不把名称相似但原单资格、人数去重或商品过滤不同的指标冒充 007 完成值。
 */
final class Sidebar007V3AtomicReadServices
{
    private $reader;

    /** 测试可注入注册指标读取函数；正式路径始终由统一 Reader 计算并受门店范围约束。 */
    public function __construct(?callable $reader = null)
    {
        $this->reader = $reader ?: static function (string $code, array $stores, array $range): array {
            return (new RegisteredMetricReadServices())->storeTotals($code, '0', $stores, $range);
        };
    }

    /**
     * 仅返回审计矩阵所需的六个既有指标。注册 Reader 返回每个门店一行；
     * 缺门店记录或越权/重复行一律拒绝，不能用零填补未覆盖的事实。
     */
    public function read(array $stores, array $range): array
    {
        if ($stores === [] || count($stores) > 10000 || count($stores) !== count(array_unique($stores))) {
            throw new \InvalidArgumentException('原子指标授权门店范围无效');
        }
        foreach ($stores as $id) if (!is_int($id) || $id < 1) {
            throw new \InvalidArgumentException('原子指标门店 ID 无效');
        }
        foreach (['start', 'end'] as $key) {
            $value = $range[$key] ?? null;
            $day = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if (!$day || $day->format('Y-m-d') !== $value) throw new \InvalidArgumentException('原子指标日期无效');
        }
        if ($range['start'] > $range['end'] || $range['start'] < StoreUnifiedReportServices::COVERAGE_START) {
            throw new \InvalidArgumentException('原子指标日期超出覆盖范围');
        }
        $codes = ['sales_collected_amount', 'cash_performance', 'consume_amount',
            'staff_service_num', 'sales_quantity', 'completed_service_item_count'];
        $out = [];
        foreach ($codes as $code) {
            $rows = ($this->reader)($code, $stores, $range);
            if (!is_array($rows)) throw new \LogicException('注册指标结果无效');
            $seen = [];
            foreach ($rows as $row) {
                $id = $row['store_id'] ?? null;
                $value = $row['metric_value'] ?? null;
                if (!is_int($id) || !in_array($id, $stores, true) || isset($seen[$id])
                    || !is_int($value)) throw new \LogicException('注册指标结果越权或无效');
                $seen[$id] = true;
                $out[$id][$code] = $value;
            }
            if (count($seen) !== count($stores)) throw new \LogicException('注册指标门店结果不完整');
        }
        return ['by_store' => $out, 'source' => 'RegisteredMetricReadServices::storeTotals',
            'range' => ['start' => $range['start'], 'end' => $range['end']],
            'parity' => [
                'sales_collected_amount' => '销售收款为售出支付分摊净额；007 原单月现金还包含原充值，且须核对支付资格、退款日期及组合付款。',
                'cash_performance' => '统一现金包含充值与后补款；007 只取原销售与原充值收款，不含后补款。',
                'consume_amount' => '完成服务业绩事实；007 用已支付且未退款的耗卡订单 pay_price，须逐来源对账。',
                'staff_service_num' => '已完成服务的人员分配客次，单位十分之一；007 门店服务客按会员同店同日去重。',
                'sales_quantity' => '全部有效销售行数量；007 只取普通原单、指定项目类型及现金资格。',
                'completed_service_item_count' => '已完成服务项目数量；007 有效核销 writeoff_num 需逐条单位与来源对账。',
            ]];
    }
}
