# 第42轮：会员排名与详情复合查询本地自测记录

## 结论

- 日期：2026-09-25（Asia/Shanghai）。
- 环境：本地平台端 `http://127.0.0.1:18081/admin/setting/mohe-ai`，平台管理员登录态、真实本地瑞昊数据、当前配置模型。
- 基线 HEAD：`2c85aabaf01a7210ebcbc250c29fbfe4908cbe24`。
- 状态：开发和本地自测通过；未 commit、未部署、未 push，待产品经理对本固定版本确认验收。
- 原失败原因：同一句同时包含“会员排名集合”和“这些会员的详情”时，系统把详情字段一并交给指标绑定模型，修复尝试又无法覆盖该非指标字段，最终以 `missing_requirement_coverage` 失败。此前仅“条件筛选会员 + 详情”支持两阶段执行，缺少“排名会员 + 详情”的同类编排。

## 实现范围

1. 语义理解仍负责识别自然语言，不增加问题原句切片或固定句式判断。
2. 仅当结构化理解同时证明“会员对象 + 排名/条件集合 + 集合详情”时，先执行已注册的集合查询，再以已验证结果中的会员 ID 读取详情；不再二次按姓名搜索。
3. “会员业绩”未明确口径时，由指标注册表采用“现金业绩”作为首答视角并在答案中明确显示；用户明确“实际收款销售额”等指标时，明确指标优先。
4. 复用既有会员详情读取、权限和展示契约，没有新增数据库表、迁移、前端分支或客户专属判断。
5. 已由通用人口集合编排替代旧的“条件会员详情”方法名和局限；全路径扫描未发现旧方法残留引用。

## 自动化验证

以下 13 个脚本共 948 项检查通过：

- `metric-registry-contract.php`：89 项。
- `member-detail-continuation.php`：23 项。
- `object-detail-continuation.php`：33 项。
- `context-delta-gateway.php`：88 项。
- `gateway-review-regressions.php`：85 项。
- `registry-execution.php`：103 项。
- `semantic-binding-guard.php`：12 项。
- `intent-understanding-contract.php`：144 项。
- `query-context.php`：64 项。
- `binding-object-capability-boundary.php`：6 项。
- `context-metric-rebinding.php`：7 项。
- `gateway-components.php`：139 项。
- `gateway-integration.php`：155 项。

另外完成两个修改 PHP 文件的语法检查与目标文件 `git diff --check`，均通过。

## 真人页面测试（记录保留）

所有测试均通过页面实际输入并等待完整流式回答；对话未删除。

1. 新对话输入“会员业绩最高的前五名详情”
   - Run：`a5919345f4b40d295c4881627b576c6bc0f3f1fa578ac5e7`
   - 结果：完成，页面用时 20 秒；以“现金业绩”明确标注筛选依据，并返回本地当日有业绩的 1 位会员及其所有门店权益汇总。
2. 同一对话先问“这个月销售记录最多的项目前三名”，再问“会员业绩最高的前五名详情”
   - 前置 Run：`aa8f668338474913316c7da7ac3805b253d063069d220382`
   - 目标 Run：`9e64bd0f199d8759ff31e68d337ed0d71b7802b57bebd07a`
   - 结果：完成，目标回答页面用时 22 秒；没有继承项目对象，正确返回会员现金业绩前五名及同一 5 位会员的权益汇总。
3. 新对话输入“这个月会员实际收款销售额最高的前三名，分别看看他们的情况”
   - Run：`fbcd41d102aaf0268b40e07a16f62996d31c2650d62796bd`
   - 结果：完成，页面用时 24 秒；明确指标优先，返回实际收款销售额前三名及同一 3 位会员的权益汇总。

本轮完整回答为 20—24 秒，较原失败样本 35 秒后仍无结果已有实质改善。服务器计数显示两次普通复合问题使用 3 次模型调用，明确指标改写使用 2 次；没有额外详情理解模型调用，详情读取分别约 0.3—0.7 秒。当前主要耗时仍在通用语义理解和指标绑定模型，未为单句继续叠加缓存或捷径，避免牺牲自然语言准确性。

## 数据、权限与源码边界

- 权威统计仍来自统一指标注册表和统一查询执行；会员详情仍由既有会员详情服务读取。
- 先获得受权限约束的排名/筛选结果，再读取其中精确会员；客户端不扩权、不自行计算经营指标。
- 本轮没有数据库结构或业务数据写入，没有 Excel、部署或生产操作。
- 本轮新增/更新职责与边界注释的文件：
  - `后端代码/app/services/ai/AiGatewayServices.php`
  - `后端代码/app/services/query/metric/MetricDefinitionRegistry.php`

## 固定版本指纹

- `7926bbdf69a1bf84f36d64633c613b6e07ab6ecf4d178ca7c022ffef1905dd7d`  `后端代码/app/services/ai/AiGatewayServices.php`
- `72f6b42789237cf1ab27186449be37148276806b68d88eb9309d07e6326a829d`  `后端代码/app/services/query/metric/MetricDefinitionRegistry.php`
- `e6186815e4b093ee5e62ff35ca296e1f62a9a931296363ad45a3698cfc7ec1fd`  `tests/mohe-ai/metric-registry-contract.php`
- `b8dfdc23cb54d8a766708fa3e66bd5b7fc6129c9476923a21347aa7e860b22c0`  `tests/mohe-ai/member-detail-continuation.php`
- `9df65519f2a885a9f3b3402c40e89ac5f39bd1f9834feb8a73d1258438685319`  `tests/mohe-ai/object-detail-continuation.php`

