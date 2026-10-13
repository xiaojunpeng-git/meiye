# 第 6 轮 A：多指标条件与会员当前资产，本地固定验收版

- 基线 HEAD：`89dafea89329c6f7ee1e580730a48a6c11de4355`
- 业务范围：同一会员人群最多 8 个不同注册指标的全部满足／任一满足条件；余额=账户本金+赠金，权益金额=现有会员详情剩余项目金额，总资产=余额+权益金额；“总余额”是总资产别名。模型请求结果未知时，商家端明确提示原问题稍后重试，不把传输失败当成缺少业务条件。
- 源码与测试的逐文件 SHA-256：`R6A-multi-metric-assets-fixed-2026-10-13.sha256`；该清单自身 SHA-256 `6981dd0319c1d8dd0ba699e2117a65e399e0e8e8f74b528ba7801cb0cf71bb47`。
- 目标文件相对 HEAD 的 Git 补丁 SHA-256：`3f5a673231a2d1782b25d435fa82c7cc34dde5d25b7fc1d808c566260dafde76`。部分目标文件在本轮前已有其他任务的未提交改动；该指纹仅标识验收时整个目标文件差异，提交时必须逐块核对，不能整文件夹暂存。

## 自测

1. `php tests/mohe-ai/condition-set.php`：130 项通过；5 指标顺序、单位换算、总余额最长词匹配、8 项上限和第 9 项拒绝、OR 列表中未知到店日期显示“未记录”。
2. `php tests/mohe-ai/gateway-integration.php`：183 项通过；传输 UNKNOWN 保留未知尝试，不重复执行业务读取。
3. `php tests/mohe-ai/opt1-transport-recovery-gateway.php`：38 项通过；HTTP 100 中间态超时不自动重发。
4. `php tests/mohe-ai/r63-failure-guidance.php`、`php tests/mohe-ai/semantic-binding-guard.php`、`node tests/mohe-ai/opt3-browser-failure-contract.mjs`：通过。
5. 本地 `ruihao` 数据库只读脚本 `tests/mohe-ai/current-assets-local-readonly.php`：账户余额与收银余额权威读数一致，权益与总资产按分相加；资产组合、到店天数与资产的 AND/OR、5 指标列表的完整值以及 count/list 一致性均通过。仅连本地容器，未连接或修改瑞昊生产实例。
6. 本地只读性能样本：306 人纯资产条件约 0.59 秒；4,697 人约 9.16 秒；全门店“90 天未到店且总资产至少 500 元”约 1.78 秒。样本数值不是生产性能承诺。
7. 涉及文件 PHP 语法及 `git diff --check` 通过。

## 边界与后续

- 本阶段支持扁平 AND/OR 和不同指标；同一指标重复区间、嵌套布尔组合、各条件独立时间、筛选人群后的汇总／排序仍需下一阶段实现和验证，不把本阶段称为完整方案。
- 无历史到店记录的天数是未知，不按 0 天算。纯资产条件在十万级全门店人群下尚无合格的响应时间证据；Reader 保留完整人数口径，不以截断冒充答案。
- `tests/mohe-ai/metric-registry-contract.php` 停在既有手机员工业绩源码静态断言；`tests/mohe-ai/gateway-components.php` 停在既有“最高前五家店”词汇投影断言，均不涉及本闭环改动位置。本阶段没有修改这些断言来制造通过。
- 注释已在余额权威读数、资产派生、会员粒度权限与条件交集、词汇包含关系、UNKNOWN 传输提示等变更处补充。新增资产 Reader 有调用，原单项权益索引仍服务原单项查询；本阶段未替代可删除的旧入口。

## 交付分组

- 前端源码：无。
- 后端源码：`后端代码/app/services/ai/` 中网关、两份意图合同、条件编译、注册计划编译、模型合同、回答渲染；`后端代码/app/services/cashier/v3/checkout/provider/CashierV3MemberBalanceProvider.php`；`后端代码/app/services/metric/MetricDictionaryServices.php`；`后端代码/app/services/query/metric/` 中注册表、查询视图、Reader、新增当前资产 Reader。
- 数据库迁移：无。自动化测试：清单所列 5 个 PHP 测试文件。工程脚本：无。生成物：无。验收证据：本文件及 SHA-256 清单。
- 状态：开发和上述本地自测完成，待产品经理对这个固定阶段版本明确确认验收；未 commit、未部署、未 push。
