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

## 首次线上验收发现与补修

- 首次候选提交：`73a35de47e17ebbfb48f3a2fcb9b63a1938f5090`。
- 瑞昊发布批次：`/www/backups/rh.cc3798.com/20260925-r38-r40-73a35de4-jriQ7o/`；20 个原文件已备份，1 个新增文件单独登记，发布归档 SHA-256 为 `3e761bad30f8dc00c34c771ae77df0fd0377ac63b7713ddedf3098a86fe302d0`。
- 三指标新对话在线通过：23 秒返回聚合概览。连续问答在线失败：前一问为各门店双指标时，同一句三指标追问仍返回门店分组；Run `7c0f35b52119cb5647750bf48385c47e39990d42c9f68396`。
- 脱敏运行结构确认三个精确指标均已绑定，但真实模型把一个经营主体的三个指标额外包装成三个可选 `groups`；旧保护分支因此跳过聚合粒度协调，继承了上一问的 `breakdown/store`。这不是指标数据或门店权限问题。
- 线上验收未通过后已立即恢复上述批次的 20 个旧文件，并移出新增文件；Swoole、执行 Worker、Excel Worker 和 Supervisor 均恢复运行，未 push。

补修不按问句或门店写死：仅当每个模型分组恰好拥有一个不同的当前精确注册指标、所有指标共享唯一登记概览主体、分组最多共享期间，并且没有对象、筛选、排行、条件或响应形式载体时，才把错误的“每指标一组”折叠回同一主体聚合。任何真实多对象或带独立分析对象的分组保持原样。

补修回归全部通过：15 组核心测试、Excel/异步导出运行契约、PHP 语法和 `git diff --check`；`gateway-review-regressions.php` 增至 81 项，包含真实模型分组形态和多对象不折叠反例。

- 补修基线：`73a35de47e17ebbfb48f3a2fcb9b63a1938f5090`
- 补修补丁指纹：`7f15a7859355e1b0f0028876c277a2ff911cb81178df2e84b9c0567f4d993b1a`
- `后端代码/app/services/ai/AiGatewayServices.php`：`396029b59619d1cd93b27ca2645209aaa9db725d933d8b613c10049c7fced737`
- `tests/mohe-ai/gateway-review-regressions.php`：`ff9211e26f725e1c5c5e78f71af8c97778db989286ef00ea7441224b0b2843d3`

## 第二次线上验收与最终根因修正

- 第二次候选提交：`3fc260e34673cc1aca790979b35f86bb6f05a95c`。
- 瑞昊发布批次：`/www/backups/rh.cc3798.com/20260925-r38-r40-3fc260e3-Quq14U/`；发布归档 SHA-256 为 `4e3c7d21937560dd7b22d556f9bb9791529d3b69bca0397bb44a22806770c57c`，部署前备份归档 SHA-256 为 `d38284c7fa91f7361fd925e3412a77b98aacced3c24dfb11c126b1a3263a8938`。
- 连续问答在线复测仍失败：前置门店分组用时 12 秒，三指标续问用时 20 秒，仍返回门店分组。失败后再次按该批次精确回滚，Swoole、执行 Worker、Excel Worker 和 Supervisor 均恢复运行，未 push。
- 第二次结果证明“可选 groups 包装”只是表象。最终根因是执行顺序：多指标粒度协调发生在完整注册指标校正之前；真实模型额外生成的第四个未注册“门店经营指标”占位当时仍存在，使粒度协调提前跳过。后续指标校正虽然删除了该占位并还原三个精确指标，却没有再次执行粒度判断，因此错误继承上一问的门店分组。
- 最终修正把多指标粒度协调移动到完整注册指标校正之后。该顺序不依赖门店、固定问句或固定三指标；人员、会员和其他登记对象同样走统一的“先确认当前指标，再决定聚合或分组”流程。代码注释明确保护这一模型契约边界。
- 新增与线上结构一致的回归：三个精确指标分组外加一个通用指标回声；先清理未注册占位，再确认切换为聚合并清除继承分组。同时保留真实多对象分组不折叠的反例。
- 最终自动化结果：全部 16 组核心/导出测试通过；`gateway-review-regressions.php` 增至 82 项；PHP 语法与 `git diff --check` 通过。

最终修正固定信息：

- 基线 HEAD：`3fc260e34673cc1aca790979b35f86bb6f05a95c`
- 代码与测试补丁指纹：`d37900706191d5b75635c46465b0783939d29f697f3e2c7cfb0542e7a0d0b691`
- `后端代码/app/services/ai/AiGatewayServices.php`：`53007e8b21da642b2d2e753a8546d32bf6f59484ed72f42dda5aabefedd99087`
- `tests/mohe-ai/gateway-review-regressions.php`：`bcc9a69f84baf8a6b901f48a9884556db1711478a39d9538bc19860f3e40243d`

## 第三次线上验收与同对象分组载体修正

- 第三次候选提交：`c12bfc3e`；发布批次：`/www/backups/rh.cc3798.com/20260925-r38-r40-c12bfc3e-dHideC/`。
- 发布归档 SHA-256：`88c40673f50c35f1a4982f93bfebd2f630c86ebee46cab4e23dd66d3625c71b0`；部署前备份归档 SHA-256：`7668efdb603054baead9c114a3a4c3a22f18dc7808939140238e1c721c5327e9`。
- 生产前置门店分组问法用时 15 秒并正确返回；三指标续问用时 23 秒但仍错误返回门店分组，Run `d3582800795fe36f47a7323f0c3f92ee6860e378ad136073`。失败后立即按本批次精确回滚，服务全部恢复，未 push。
- 脱敏运行证据确认：三个注册指标及三条绑定均完整，最终查询却仍为 `breakdown` 且 `business_filters.object_kind=store`。这排除了指标缺失、读取失败和上下文合并顺序问题。
- 最终遗漏是分组安全门禁过窄：真实模型不只把每个指标分组，还在每个指标组重复同一个 `store + analysis + breakdown` 载体。原门禁只允许指标和期间字段，因此把“同对象、同操作的重复载体”误当成独立多主题并拒绝折叠。
- 修正后只在每组恰有一个不同的当前精确指标、所有对象载体均为同一个分析对象、所有操作载体均为同一种 summary/breakdown，且不存在选择关系、排行、条件或其他响应形式时折叠。组内对象与当前问题解析出的登记对象不一致，或不同组出现不同对象/操作时仍保持独立。
- 新增两个针对性回归：相同 `store + breakdown` 载体可折叠；不同分析对象即使操作相同也不得折叠。全套 16 组自动化通过，`gateway-review-regressions.php` 增至 84 项。

最终候选固定信息：

- 基线 HEAD：`c12bfc3e`
- 代码与测试补丁指纹：`fede67a1519967ae2e71ade4631d095af66dbeaa75feb39af0682b2aa48b943f`
- `后端代码/app/services/ai/AiGatewayServices.php`：`9d43260220672a386454eff7d953b09116dca0e598f80f42881f351c8bdafa12`
- `tests/mohe-ai/gateway-review-regressions.php`：`6673775769fcdef9002e79b6b5afa4319011b5ca5ebdc11ef14381342ffa252c`
