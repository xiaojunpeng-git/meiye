<?php
declare(strict_types=1);
namespace app\services\report;

/** 六张门店表的接口适配；仅转换查询合同，不另算指标或执行旧报表SQL。 */
final class RoundFour007ReportServices
{
    private const READERS = [
        'basic_store_summary'=>StoreSummary007ReadServices::class,
        'basic_cross_store_settlement'=>CrossStoreSettlement007ReadServices::class,
        'basic_store_item_sales'=>StoreItemSales007ReadServices::class,
        'basic_item_consumption'=>ItemConsumption007ReadServices::class,
        'basic_writeoff_detail'=>WriteoffDetail007ReadServices::class,
        'basic_product_sales'=>ProductSales007ReadServices::class,
    ];
    public static function codes(): array { return array_keys(self::READERS); }
    /** 元数据直接复用领域唯一声明，目录启动不读业务表。 */
    public function definitions(string $code): array
    {
        $reader=self::reader($code);
        return $reader::definitions();
    }
    /** 授权范围来自Host，日期来自已验证请求；不接受前端指定来源或认证指标。 */
    public function read(string $code,array $stores,array $input): array
    {
        $reader=self::reader($code);
        $mode=$input['_report_scope']['mode']??'none';
        if (!in_array($mode,['all','stores'],true)) throw new \InvalidArgumentException('当前账号没有该报表数据权限');
        $source=(new $reader())->read($stores,['start'=>$input['start_date'],'end'=>$input['end_date']]);
        $source['data_gaps']=$source['data_gaps']??$source['gaps']??[];
        // 项目消耗原表有首行合计；门店统计/跨店在表上方汇总，其他表无合计。
        $source['table_layout']=['summary_position'=>$code==='basic_item_consumption'?'first_row':'none'];
        $cardKeys = $code==='basic_store_summary'
            ? ['cash_performance','actual_performance','inside_cash_performance','consumption_performance',
                'inside_consumption_performance','customer_count','designated_customer_count','new_customer_count']
            : ($code==='basic_cross_store_settlement'?['stored_value_amount','card_amount']:[]);
        $source['summary_cards']=[];
        if ($cardKeys) {
            $fields=array_column($source['fields'],null,'key');
            $source['sum_fields']=$cardKeys;
            foreach($cardKeys as $key) $source['summary_cards'][]=['key'=>$key,'label'=>$fields[$key]['label'],'money'=>$fields[$key]['money']];
        } elseif ($code!=='basic_item_consumption') $source['sum_fields']=[];
        return $source;
    }
    private static function reader(string $code): string
    {
        if (!isset(self::READERS[$code])) throw new \InvalidArgumentException('未配置该门店报表');
        return self::READERS[$code];
    }
}
