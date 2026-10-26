# R29 门店销售人业绩下钻销售订单自测证据

## 交付范围

- 门店销售人业绩报表的非零每日业绩、合计业绩支持精确下钻。
- 下钻目标为订单中心“销售订单”，按员工 ID、门店 ID、报表日期范围和日序号精确筛选。
- 订单中心提供“返回报表”和“退出报表筛选”，返回时保留报表日期范围。
- 列名取值来源改为业务语言，说明实际业绩分配、退款或人员调整的发生日冲减，以及整单作废排除口径。

## 数据口径与架构核对

- 权威事实：`cashier_v3_performance_fact` 中 `sales_performance_allocated` 且 `status=effective` 的销售业绩事实。
- 事实粒度：人员、门店、订单、业绩发生日；多人分配分别统计。
- 下钻使用员工 ID、门店 ID 和事实日期，不使用姓名或页面显示文本模糊匹配。
- 退款、人员调整可以晚于原订单发生，因此下钻先按业绩事实日期寻找来源订单，不用订单日期再次裁剪。
- 整单作废的原金额和反向事实均只保留审计，不进入经营报表及其下钻。
- 列表、下钻与游标查询均由后端权限范围约束；游标签名包含日期与下钻条件，避免跨筛选复用。

## 源码与测试文件

前端源码：

- `前端代码/cashier-v3/src/views/StoreBusinessReportView.vue`
- `前端代码/cashier-v3/src/views/OrderCenterView.vue`

后端源码：

- `后端代码/app/services/report/StoreUnifiedReportServices.php`
- `后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php`

自动化测试：

- `tests/cashier-v3/php/salesperson-performance-sales-order-drilldown-contract.php`
- `tests/cashier-v3/php/salesperson-performance-sales-order-drilldown-mysql.php`

数据库迁移、工程脚本和生成物：无。

## 自动化自测

- PHP 语法检查：通过。
- `salesperson-performance-sales-order-drilldown-contract.php`：通过。
- `salesperson-performance-sales-order-drilldown-mysql.php`：通过；真实只读数据样本 `2026-09-23 / employee=958 / orders=1`。
- `store-business-report-contract.mjs`：41 项通过，0 项失败。
- `npm run build`（`前端代码/cashier-v3`）：通过；仅有既有 chunk 大小提示。

## 页面自测

固定预览：

`http://127.0.0.1:18091/view_cashier_v3/?fresh=r29-salesperson-order-final#/data/reports/store_salesperson_performance?start_date=2026-09-01&end_date=2026-09-23`

验证步骤与结果：

1. 点击“张丽函”行“3日业绩 5000”。
2. 页面进入订单中心“销售订单”，地址包含员工 `1149`、门店 `133`、日期范围和 `report_day_of_month=3`。
3. 筛选完成后只展示订单 `XS26090300007`；订单销售人显示“汤静静（5000），张丽函（5000）”，可逐笔解释被点击的 5000 元。
4. 页面提示正在查看 `2026-09-03` 对应销售订单；点击“返回报表”恢复原报表及 `2026-09-01` 至 `2026-09-23` 日期范围。
5. “列名取值来源”已显示新的总说明、销售人说明、每日业绩说明和合计业绩说明。

## 注释与提交边界

- 已为前端路由参数边界、返回地址限制、后端事实日期筛选、作废排除和游标签名补充业务注释。
- 当前工作区包含其他任务改动；本闭环尚未获得产品经理对固定版本的验收确认，因此未暂存、未提交、未部署、未推送。
- `StoreUnifiedReportServices.php` 中既有“项目数”未提交改动不属于本闭环，后续提交必须使用按块暂存排除。
