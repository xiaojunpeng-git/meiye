# 第 6 轮 B：同指标区间与分组条件，本地固定验收版

- 基线 HEAD：`b00e65ffef0016518e419ec9832704955aa51055`（R6A 已验收并提交）。
- 固定范围：同一注册指标可有多个独立阈值；最多 8 个条件可用有界表达式组合“且／或”（最多 3 层，每个条件位置恰好出现一次）；意图理解、注册绑定、计划、Reader 和回答保持同一条件顺序及关系。会员条件按同一授权会员集合计数和列示，历史到店天数缺失仍是未知，期间事实缺失的零值条件可匹配。
- 逐文件 SHA-256：`R6B-condition-expression-fixed-2026-10-13.sha256`。相对 HEAD 的目标路径 Git 差异指纹：`412efcc9d95cdc8921f293634da39792e2f05698fef09883a2272578dfcc9e01`。新增表达式类由逐文件 SHA-256 固定；上述 Git 差异指纹不包含未跟踪新文件。
- 共享 Reader 文件还含其他任务的未提交分类报表及退款改动；该固定版本按整文件 SHA 标识现场，未来提交必须逐块暂存 R6B 差异，不得夹带这些改动。

## 自测结果

1. `php tests/mohe-ai/condition-set.php`：136 项通过，覆盖同一指标上下限、混合 AND/OR 分组、不得遗漏表达式叶子、理解结果到注册绑定的条件保真、回答只显示一次重复指标。
2. `php tests/mohe-ai/gateway-integration.php`：183 项通过；`php tests/mohe-ai/opt1-transport-recovery-gateway.php`：38 项通过；`php tests/mohe-ai/r63-failure-guidance.php`、`php tests/mohe-ai/semantic-binding-guard.php`、`node tests/mohe-ai/opt3-browser-failure-contract.mjs` 均通过。
3. 本地容器内的 `ruihao` 数据库只读验证：`tests/mohe-ai/current-assets-local-readonly.php` 通过。余额重复边界、服务次数零值及重复边界、到店年龄与资产的分组、OR 分支均与原授权会员集合的结果一致；count 和 list 一致。仅使用本地容器，未连接或修改生产实例。
4. 本阶段 12 个 PHP 文件语法检查及目标路径 `git diff --check` 通过；没有数据库迁移或远程操作。

## 边界与后续

- 本阶段的时间范围仍由整次查询统一提供。不同条件各自的时间范围、筛选后同一人群的返回指标汇总／排序，需要后续独立阶段实现与验收。
- 完整授权会员集合的分组 OR 查询可能遍历较大目录；本地测试证明结果口径，未提供生产规模响应时间保证。超时沿用 R6A 的明确重试提示，不会以部分结果冒充完整答案。
- 本次更新了语义载体、重复指标绑定、表达式、会员粒度零值、回答历史记录说明的业务注释。旧扁平条件路径仍服务普通查询，有调用，未发现由本阶段完整替代可删除的业务入口。

## 交付分组

- 前端源码：无。后端源码：两份 AI 意图合同、条件与计划编译器、工作流提示、模型修复提示、回答渲染、查询视图、注册 Reader、新增 `MetricConditionExpression.php`。
- 数据库迁移：无。自动化测试：`tests/mohe-ai/condition-set.php`、`tests/mohe-ai/current-assets-local-readonly.php`。工程脚本：无。生成物：无。验收证据：本文件及 SHA-256 清单。
- 状态：开发与上述本地自测完成，待产品经理对这个固定阶段版本确认验收；R6B 未 commit、未部署、未 push。
