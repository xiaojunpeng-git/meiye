# R40 部署前阻断修复与真人自测记录

日期：2026-09-25
状态：开发与 Codex 自测完成；产品经理已确认验收并授权 commit、部署瑞昊及线上验证后 push。本文记录的固定源码尚待提交，部署结果另行登记。

## 本次范围

1. 已有排行答案后的自然续问“前三名呢”应保留已签名的指标、对象、期间和方向，只把行数改为 3。
2. 原句“这个月的门店现金业绩、消耗业绩、退款金额分别是多少”在新对话中应稳定返回三个聚合指标。
3. 在上一轮为“各门店”分组明细时，再问上述三指标原句必须切回聚合结果，不能错误继承门店分组。

## 实现边界

- 排名数量只在已有、已验证的排行上下文中走本地闭合语义；独立完整问题仍由自然语言模型理解，未新增完整问句映射。
- 多指标“分别”通过当前问题中的精确注册指标、共同登记的概览主体、公开对象别名和语法量词共同判断；没有按客户、问句或三项指标写死。
- “各门店／每门店／逐门店／不同门店”仍明确保持分组明细。
- 模型产生的空白或未注册指标占位不再阻断精确注册指标绑定；排除条件和聚合条件不会被清理。
- 修复上下文展示方式引导闭包遗漏变量；没有保留平行旧实现。

## 自动化回归

以下测试全部通过：

- `registry-execution.php`：103 checks
- `gateway-components.php`：139 checks
- `query-read-view.php`：64 checks
- `personnel-analysis.php`：56 checks
- `state-store-contract.php`：51 checks
- `gateway-integration.php`：155 checks
- `export-runtime-contract.php`：完整组合通过
- `metric-registry-contract.php`：89 checks
- `condition-set.php`：80 checks
- `member-detail-continuation.php`：22 checks
- `object-detail-continuation.php`：31 checks
- `intent-understanding-contract.php`：144 checks
- `semantic-binding-guard.php`：12 checks
- `breakdown-query.php`：40 checks
- `context-delta-gateway.php`：88 checks
- `gateway-review-regressions.php`：79 checks
- PHP 语法检查与 `git diff --check`：通过

## 本地真人测试

本地入口：`http://127.0.0.1:18081/admin/setting/mohe-ai`

### 1. 三指标新对话

- 问题：`这个月的门店现金业绩、消耗业绩、退款金额分别是多少`
- 页面：用时 20 秒，展示“本期经营概览”，含现金业绩、退款业绩、消耗业绩，无门店分组表。
- Run：`0f3b90645bae96539cd16d8c90d787b8fc5ffb7e12660bb4`
- 私有证据查询：`query_shape=summary`，三个指标顺序为 `cash_performance / refund_performance / consume_amount`，`business_filters=[]`。

### 2. 连续问答清除错误分组

- 前置问题：`这个月各门店现金业绩和销售额分别是多少`，页面正确返回门店分组表。
- 续问：`这个月的门店现金业绩、消耗业绩、退款金额分别是多少`
- 页面：用时 25 秒，切换为“本期经营概览”，没有沿用门店分组。
- Run：`2ae7bf27c9705c6c6c0340c01b0e2465486e50b46724dfd0`
- 私有证据查询：`query_shape=summary`，`business_filters=[]`。

### 3. 前三名自然续问

- 前置问题：`这个月销售额最高的门店是`，返回第 1 名。
- 续问：`前三名呢`
- 页面：用时 2 秒，返回第 1、2、3 名三行。
- Run：`0491ff10f192aaa5be3ee28b6f8f55dfefbb47c5624ce5c8`
- 私有证据查询：`query_shape=ranking`，`sales_amount`，`ranking={direction:top, limit:3}`。

## 固定版本

- 基线 HEAD：`128bd2622b2d2151f65c704c18212fdfd73f376b`
- 补丁指纹：`c81a4a0ed183593668a01f70040dcdd133d08ad35be19658a8a29a8cf76dbdda`
- `后端代码/app/services/ai/AiGatewayServices.php`：`a09a9f6956ba472a56dd17584814c48dc9943b7bc6d563f92b4c6c052c50ec64`
- `后端代码/app/services/query/metric/MetricDefinitionRegistry.php`：`3756359de5b58bf385c8aa2d710d9dcb92167ad48f1cf499fcf19ace83081f46`
- `tests/mohe-ai/context-delta-gateway.php`：`45262a6f3261a7ec938ad091f4084549b1867cb08dfbb8d3dd87128ec8125fbc`
- `tests/mohe-ai/gateway-review-regressions.php`：`77355b88db2356f67b3f06fab6b8e14be134bd923db89378a1f5b92a6fb1ae78`

## 代码与产物分组

- 后端源码：`AiGatewayServices.php`、`MetricDefinitionRegistry.php`
- 自动化测试：`context-delta-gateway.php`、`gateway-review-regressions.php`
- 数据库迁移：无
- 前端源码：无
- 工程脚本：无
- 生成物：无
- 验收证据：本文件

本次新增或更新的关键业务注释位于 `AiGatewayServices.php`，说明排行上下文、精确指标占位清理、共同概览主体和多指标分配语义的安全边界。
