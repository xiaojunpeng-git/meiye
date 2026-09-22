# R29 门店手艺人消耗作废排除闭环

## 产品经理确认的业务结果

- 服务一旦作废，该笔服务的原金额和冲销金额在本报表的任何日期都不显示。
- 汇总、合计、导出、明细读取和订单中心下钻使用同一范围，只显示当前仍有效、未作废的服务。
- 原始服务事实、业绩事实、冲销事实和作废操作继续保留，业务审计不删除、不改写历史。

## 数据边界检查

- 权威数据源：`cashier_v3_performance_fact` 的人员分配事实；作废状态由成功的 `cashier_v3_service_record_void_operation` 判定。
- 稳定关联：租户、门店、`checkout_request_id`、`source_line_id`，不使用姓名或页面文字匹配。
- 事实粒度：每位员工、每条服务来源行的人员业绩分配事实。
- 统计时点：经营报表按“当前仍有效服务”展示；服务作废后，无论原业务日期或冲销日期，都从经营结果排除。
- 冲销与审计：不删除正向或反向事实；只在本报表及其下钻读取路径排除完整作废链。
- 权限：继续使用原门店、组织和订单中心数据权限；浏览器即使传入全部数据，下钻后端仍强制排除作废服务。
- 对账：同一过滤同时进入汇总、明细、导出和下钻，避免汇总可见但明细不可解释。
- 数据库迁移：无。

## 源码与测试文件

### 前端源码

- `前端代码/cashier-v3/src/views/StoreBusinessReportView.vue`
- `前端代码/cashier-v3/src/views/OrderCenterView.vue`

### 后端源码

- `后端代码/app/services/report/StoreReportNormalDataScopeServices.php`
- `后端代码/app/services/query/metric/RegisteredMetricReadServices.php`
- `后端代码/app/services/report/StoreUnifiedReportServices.php`
- `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php`

### 自动化测试

- `tests/cashier-v3/php/craftsman-consumption-drilldown-contract.php`
- `tests/cashier-v3/php/craftsman-service-record-drilldown-mysql.php`

### 生成物

- 本地 Vue 构建产物仅用于验证，不作为开发源码提交。

## 自测结果

- 相关 PHP 文件语法检查：通过。
- `php tests/cashier-v3/php/craftsman-consumption-drilldown-contract.php`：通过。
- `BACKEND_ROOT=/var/www/html php tests/cashier-v3/php/craftsman-service-record-drilldown-mysql.php`（本地容器只读真实数据）：
  - 当前有效服务下钻：通过。
  - 作废服务原事实及冲销事实在所有报表日期排除：通过。
  - 订单中心报表下钻在请求 `dataScope=all` 时仍排除作废服务：通过。
- `node tests/cashier-v3/js/store-business-report-contract.mjs`：41 项通过、0 项失败。
- `npm run build`（`前端代码/cashier-v3`）：通过；仅有既有包体积提示。
- `git diff --check`：通过。
- 页面核对：报表未出现负数冲销金额；“列名取值来源”明确说明作废后原金额和冲销金额在任何日期都不显示；下钻提示只显示当前有效、未作废服务，并保留“返回报表”按钮。

## 交付状态

- 本地开发与自测完成。
- 待产品经理对固定版本确认验收。
- 未提交、未部署、未推送；瑞昊线上版本未变化。
- 当前工作区还存在其他任务改动；后续提交时必须逐文件、逐补丁暂存。本闭环涉及的 `StoreUnifiedReportServices.php` 同时含有既有的“项目数优先读取 decimal”改动，提交前必须按已验收闭环边界处理，不能误夹带。
