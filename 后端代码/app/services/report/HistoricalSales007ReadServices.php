<?php
declare(strict_types=1);
namespace app\services\report;
use think\facade\Db;

/** One read-only source for exact 007 sale populations. Legacy rows keep original staff allocations;
 * imported V3 sales are excluded by callers to prevent counting the same original twice.
 * Dates follow the approved migration's paid-time business-day rule, never creation time.
 */
final class HistoricalSales007ReadServices
{
    // Epoch arithmetic fixes Asia/Shanghai (+08:00), independent of the connection time_zone.
    private const BUSINESS_DATE_SQL="COALESCE(h.business_date,DATE(DATE_ADD('1970-01-01 00:00:00',INTERVAL (NULLIF(o.pay_time,0)+28800) SECOND)))";
    /** Read one store at a time so the same authorized population remains bounded. */
    public function read(string $kind,array $stores,array $range,int $employee=0):array
    {
        if(!in_array($kind,['store_items','products','store_quantities'],true))throw new \InvalidArgumentException('旧销售报表类型错误');
        foreach($stores as$id)if(!is_int($id)||$id<1)throw new \InvalidArgumentException('旧销售授权门店错误');
        foreach(['start','end']as$k)if(!isset($range[$k])||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$range[$k])||date('Y-m-d',strtotime($range[$k]))!==$range[$k])throw new \InvalidArgumentException('旧销售业务日期错误');
        if($range['start']>$range['end']||$employee<0)throw new \InvalidArgumentException('旧销售筛选错误');
        $rows=[];$gaps=[];
        foreach(array_values(array_unique($stores))as$store){
            $sql=$this->sql($kind,$store,$range,$employee);
            foreach(Db::query($sql)as$r){$r['source_kind']='historical_007_sale';$rows[]=$r;}
            // Unknown payment dates cannot be silently attributed outside the requested period.
            $unknown=str_replace(self::BUSINESS_DATE_SQL." BETWEEN '{$range['start']}' AND '{$range['end']}'",'h.id IS NULL AND IFNULL(o.pay_time,0)<=0',$sql);
            $unknown=preg_replace('/ORDER BY `(?:日期|门店)`[^;]*;?$/D','',$unknown);
            if(Db::query('SELECT 1 FROM ('.rtrim($unknown,"; \n").') unknown_dates LIMIT 1'))$gaps[]=['code'=>'HISTORICAL_SALE_BUSINESS_DATE_MISSING','store_id'=>$store];
            if(count($rows)>10000)throw new \RuntimeException('历史销售超过10000行，请缩小查询范围');
        }
        return ['rows'=>$rows,'gaps'=>$gaps,'complete'=>!$gaps];
    }

    /** 复用原单身份、业务日期和授权范围；数量指标另遵循007有效现金原单资格。 */
    private function sql(string $kind,int $store,array $range,int $employee):string
    {
        $sql=$kind==='products'?self::PRODUCT_SQL:($kind==='store_quantities'
            ? 'SELECT o.store_id, SUM(IFNULL(c.cart_num,0)) AS `销售数量` '.self::STORE_POPULATION_SQL.' GROUP BY o.store_id'
            : self::STORE_SQL);
        if ($kind === 'store_quantities') {
            // 007门店统计的销售项目数排除纯欠款与旧卡录入；混合支付至少有一笔
            // 非欠款、非旧卡的有效支付才计入。仅限定数量指标，不改其他报表金额公式。
            $sql = str_replace(
                ['IFNULL(o.cash_choose, 0) <> 9', 'co2.cash_choose <> 9 AND co2.active_pay <> 3'],
                ['IFNULL(o.cash_choose, 0) NOT IN (9, 10)',
                    "co2.cash_choose NOT IN (9, 10) AND (co2.pay_sub_type IS NULL OR co2.pay_sub_type <> 'debt') AND co2.active_pay <> 3"],
                $sql
            );
        }
        $table=static function($name){$value=Db::name($name)->getTable();if(!preg_match('/^[A-Za-z0-9_]+$/D',$value))throw new \LogicException('旧销售表名错误');return$value;};
        $h=$table('cashier_v3_sales_order');$receipt=$table('cashier_v3_card_purchase_receipt');$op=$table('cashier_v3_order_lifecycle_operation');
        $proof=ReportLegacyArchiveProofServices::visibilitySql('o','h',$op,$receipt);
        $join=" LEFT JOIN {$h} h ON h.tenant_id='0' AND h.order_id=CONCAT('IMP007-',o.id,'-sale') AND h.source_document_type='legacy_store_order' AND h.source_document_id=CAST(o.id AS CHAR) AND h.store_id=o.store_id ";
        $sql=str_replace('FROM eb_store_order o','FROM eb_store_order o'.$join,$sql);
        $sql=str_replace('o.is_system_del = 0',$proof,$sql);
        $sql=preg_replace("/o.add_time >= UNIX_TIMESTAMP\('\{\{date_start\}\}'\)\s+AND o.add_time <= UNIX_TIMESTAMP\('\{\{date_end\}\}'\)/",self::BUSINESS_DATE_SQL." BETWEEN '{$range['start']}' AND '{$range['end']}'",$sql);
        $sql=str_replace("FROM_UNIXTIME(o.add_time, '%Y-%m-%d %H:%i:%s')",self::BUSINESS_DATE_SQL,$sql);
        $scope="AND o.store_id={$store} AND NOT EXISTS(SELECT 1 FROM {$receipt} nr JOIN {$h} nh ON nh.tenant_id=nr.tenant_id AND nh.order_id=nr.sales_order_id AND nh.store_id=nr.store_id AND nh.member_id=nr.member_id WHERE nr.tenant_id='0' AND nr.legacy_order_id=o.id AND nr.store_id=o.store_id AND nr.member_id=o.uid AND nr.status='completed' AND nh.source_document_type IN('cashier_snapshot','sales_order'))";
        if($employee>0&&$kind==='products')$scope.=" AND EXISTS(SELECT 1 FROM ".$table('system_store_staff')." es WHERE es.id=sales.staff_id AND es.employee_id={$employee})";
        $sql=str_replace(['{{store_where_order}}','{{category}}','{{name}}','{{salesperson}}'],[$scope,'','',''],$sql);
        $sql=preg_replace_callback('/\beb_([A-Za-z0-9_]+)\b/',static function($m)use($table){return$table($m[1]);},$sql);
        return $sql;
    }

    private const STORE_SQL = <<<'SQL'
SELECT
    store_id, `门店`,
    `分类`,
    `名称`,SUM(`销售数量`) AS `销售数量`,
    ROUND(SUM(`现金业绩`), 2) AS `销售业绩`

FROM (
    SELECT
        o.store_id AS store_id, ss.name AS `门店`,
        IFNULL(
            (
                SELECT GROUP_CONCAT(DISTINCT pct.cate_name ORDER BY pct.sort DESC, pct.id SEPARATOR ',')
                FROM eb_store_product_category pct
                WHERE IFNULL(IFNULL(NULLIF(parent.cate_id, ''), cp.cate_id), '') <> ''
                  AND FIND_IN_SET(
                      pct.id,
                      REPLACE(IFNULL(NULLIF(parent.cate_id, ''), cp.cate_id), ' ', '')
                  )
            ),
            '未分类'
        ) AS `分类`,
        IFNULL(NULLIF(parent.store_name, ''), cp.store_name) AS `名称`,
        CASE
            WHEN o.cash_choose = 9 THEN 0
            WHEN o.pay_type = 'combination' THEN GREATEST(
                0,
                LEAST(
                    IFNULL(
                        NULLIF(c.cash_pay_amount, 0),
                        IF(o.pay_price > 0, ROUND(o.cash_pay_price * c.pay_price / o.pay_price, 2), IFNULL(o.cash_pay_price, 0))
                    ),
                    o.pay_price - IFNULL(o.yue_pay_price, 0)
                    - IFNULL((SELECT SUM(co.price) FROM eb_combination_order co WHERE co.order_id = o.id AND co.cash_choose = 9), 0)
                )
            )
            ELSE IFNULL(
                NULLIF(c.cash_pay_amount, 0),
                IF(o.pay_price > 0, ROUND(o.cash_pay_price * c.pay_price / o.pay_price, 2), IFNULL(o.cash_pay_price, 0))
            )
        END AS `现金业绩`,
        IFNULL(c.cart_num, 0) AS `销售数量`,
        CASE
            WHEN o.uid = 0 OR IFNULL(o.service_object, '') = '朋友' THEN CONCAT('g_', o.id, '_', c.id)
            ELSE CONCAT(DATE(FROM_UNIXTIME(o.add_time)), '_', o.uid)
        END AS `客户标识`
SQL
        . self::STORE_POPULATION_SQL . <<<'SQL'
) sales_detail
GROUP BY store_id, `门店`, `分类`, `名称`
ORDER BY `门店`, `分类`, `名称`;
SQL;
    /** 数量与品项表共用基础原单范围；数量另排纯欠款，不改变日期、归属及作废规则。
     * 混合支付资格用不相关的 IN 集合，使数据库一次读取可用原单；不得逐销售行全扫支付明细，
     * 也不能直接 JOIN 放大同单多种支付方式的销售数量。
     */
    private const STORE_POPULATION_SQL = <<<'SQL'
    FROM eb_store_order o
    INNER JOIN eb_store_order_cart_info c ON c.oid = o.id AND IFNULL(c.cart_type, 0) = 0
    INNER JOIN eb_store_product cp ON cp.id = c.product_id
    LEFT JOIN eb_store_product parent ON parent.id = cp.pid AND cp.pid > 0
    INNER JOIN eb_system_store ss ON ss.id = o.store_id AND ss.is_del = 0
    WHERE o.paid = 1
      AND o.is_system_del = 0
      AND o.is_del = 0
      AND o.refund_status IN (0, 3)
      AND IFNULL(o.is_auto, 0) <> 1
      AND (o.pid IN (0, -2) OR o.pid > 0)
      AND o.order_type = 0
      AND cp.product_type IN (0, 5, 6)
      AND (
          (o.pay_type <> 'combination' AND IFNULL(o.cash_choose, 0) <> 9)
          OR (
              o.pay_type = 'combination'
              AND o.id IN (
                  SELECT co2.order_id FROM eb_combination_order co2
                  WHERE co2.cash_choose <> 9 AND co2.active_pay <> 3
              )
          )
      )
      AND o.add_time >= UNIX_TIMESTAMP('{{date_start}}')
      AND o.add_time <= UNIX_TIMESTAMP('{{date_end}}')
      {{store_where_order}}
      AND (
          '{{category}}' = ''
          OR IFNULL((SELECT GROUP_CONCAT(DISTINCT pct.cate_name ORDER BY pct.sort DESC, pct.id SEPARATOR ',')
               FROM eb_store_product_category pct
               WHERE IFNULL(IFNULL(NULLIF(parent.cate_id, ''), cp.cate_id), '') <> ''
                 AND FIND_IN_SET(pct.id, REPLACE(IFNULL(NULLIF(parent.cate_id, ''), cp.cate_id), ' ', ''))
              ), '未分类') LIKE CONCAT('%', '{{category}}', '%')
      )
      AND IFNULL(NULLIF(parent.store_name, ''), cp.store_name) LIKE CONCAT('%', '{{name}}', '%')
      AND IFNULL(NULLIF(parent.store_name, ''), cp.store_name) <> ''
SQL;
    private const PRODUCT_SQL = <<<'SQL'
SELECT
    d.store_id,d.old_order_id,d.old_cart_detail_id,d.staff_id,d.sale_date AS `日期`,
    d.store_name AS `门店`,
    d.customer_name AS `客户名称`,
    d.product_name AS `商品名称`,
    p.payment_method AS `支付方式`,
    ROUND(IF(p.payment_method = '现金', d.sales_yeji, d.balance_yeji), 2) AS `金额`,
    d.sales_name AS `销售人`
FROM (
    SELECT
        o.store_id,o.id old_order_id,c.id old_cart_detail_id,sales.staff_id,
        FROM_UNIXTIME(o.add_time, '%Y-%m-%d %H:%i:%s') AS sale_date,
        COALESCE(NULLIF(ss.name, ''), CONCAT('门店#', o.store_id)) AS store_name,
        COALESCE(
            NULLIF(o.real_name, ''),
            NULLIF(u.real_name, ''),
            NULLIF(u.nickname, ''),
            IF(o.uid > 0, CONCAT('会员#', o.uid), '游客')
        ) AS customer_name,
        COALESCE(
            CASE WHEN IFNULL(c.product_type, 0) = 0 THEN NULLIF(parent.store_name, '') END,
            CASE WHEN IFNULL(c.product_type, 0) = 0 THEN NULLIF(cp.store_name, '') END,
            NULLIF(
                CASE
                    WHEN c.cart_info LIKE '%"store_name":"%'
                    THEN SUBSTRING_INDEX(SUBSTRING_INDEX(c.cart_info, '"store_name":"', -1), '"', 1)
                    WHEN c.cart_info LIKE '%"title":"%'
                    THEN SUBSTRING_INDEX(SUBSTRING_INDEX(c.cart_info, '"title":"', -1), '"', 1)
                    ELSE ''
                END,
                ''
            ),
            NULLIF(parent.store_name, ''),
            NULLIF(cp.store_name, ''),
            '商品已删除'
        ) AS product_name,
        sales.staff_name AS sales_name,
        sales.sales_yeji AS sales_yeji,
        sales.balance_yeji AS balance_yeji,
        CASE
            WHEN IFNULL(c.cash_pay_amount, 0) > 0 THEN c.cash_pay_amount
            WHEN IFNULL(c.yue_pay_amount, 0) = 0
                 AND IFNULL(c.card_upgrade_amount, 0) = 0
                 AND IFNULL(c.debt_amount, 0) = 0
                 AND IFNULL(o.cash_pay_price, 0) > 0
                 AND IFNULL(o.pay_price, 0) > 0
            THEN ROUND(o.cash_pay_price * c.pay_price / o.pay_price, 2)
            ELSE 0
        END AS cash_amount,
        CASE
            WHEN IFNULL(c.yue_pay_amount, 0) > 0 THEN c.yue_pay_amount
            WHEN IFNULL(c.cash_pay_amount, 0) = 0
                 AND IFNULL(c.card_upgrade_amount, 0) = 0
                 AND IFNULL(c.debt_amount, 0) = 0
                 AND IFNULL(o.yue_pay_price, 0) > 0
                 AND IFNULL(o.pay_price, 0) > 0
            THEN ROUND(o.yue_pay_price * c.pay_price / o.pay_price, 2)
            ELSE 0
        END AS balance_amount
    FROM eb_store_order o
    INNER JOIN eb_store_order_cart_info c
        ON c.oid = o.id
        AND IFNULL(c.cart_type, 0) = 0
    LEFT JOIN eb_system_store ss ON ss.id = o.store_id
    LEFT JOIN eb_user u ON u.uid = o.uid
    LEFT JOIN eb_store_product cp ON cp.id = c.product_id
    LEFT JOIN eb_store_product parent ON parent.id = cp.pid AND cp.pid > 0
    INNER JOIN (
        SELECT
            order_id,
            cart_id,
            staff_id,
            MAX(staff_name) AS staff_name,
            SUM(yeji) AS sales_yeji,
            SUM(IFNULL(deduct_card_yeji, 0)) AS balance_yeji
        FROM eb_staff_yeji
        WHERE type = 2
          AND status = 0
        GROUP BY order_id, cart_id, staff_id
    ) sales ON sales.order_id = o.id AND sales.cart_id = c.cart_id
    WHERE o.paid = 1
      AND o.is_system_del = 0
      AND o.is_del = 0
      AND o.refund_status IN (0, 3)
      AND IFNULL(o.is_auto, 0) <> 1
      AND (o.pid IN (0, -2) OR o.pid > 0)
      AND o.order_type = 0
      AND IFNULL(c.product_type, 0) = 0
      AND ('{{salesperson}}' = '' OR sales.staff_name LIKE CONCAT('%', '{{salesperson}}', '%'))
      AND (
          (o.pay_type <> 'combination' AND IFNULL(o.cash_choose, 0) <> 9)
          OR (
              o.pay_type = 'combination'
              AND EXISTS (
                  SELECT 1
                  FROM eb_combination_order co
                  WHERE co.order_id = o.id
                    AND co.cash_choose <> 9
              )
          )
      )
      AND o.add_time >= UNIX_TIMESTAMP('{{date_start}}')
      AND o.add_time <= UNIX_TIMESTAMP('{{date_end}}')
      {{store_where_order}}
) d
CROSS JOIN (
    SELECT '现金' AS payment_method
    UNION ALL
    SELECT '余额' AS payment_method
) p
WHERE (p.payment_method = '现金' AND d.cash_amount > 0)
   OR (p.payment_method = '余额' AND d.balance_amount > 0)
ORDER BY `日期` DESC, `门店`, `商品名称`;
SQL;
}
