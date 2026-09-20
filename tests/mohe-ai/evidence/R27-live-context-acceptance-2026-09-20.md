# 第 27 轮真人上下文验收记录（2026-09-20）

## 验收边界

- 页面：`http://127.0.0.1:18081/admin/setting/mohe-ai`
- 方式：同一个真实浏览器对话窗口连续提问；未清空会话，未替换为接口夹具。
- 数据：使用当前本地瑞昊测试实例的既有只读经营数据；本记录不抄录人员、会员或经营数值。
- 目标：验证完整首答、日期继承、指标切换、排行数量切换、集合上下文、条件名单/计数切换和自然话题切换。

## 5 个话题 × 5 轮结果

| 话题 | 连续提问链路 | 结果 |
| --- | --- | --- |
| 人员排行 | 销售人第一名 → 本月 → 改按服务次数 → 前五名 → 昨天 | 通过；日期、人员范围和排行语义正确继承，指标只替换为服务人次。 |
| 门店排行 | 本月实际业绩最好 → 前三名 → 上月 → 改按现金业绩 → 本月第一名 | 通过；上月超出完整覆盖时按产品规则确认可查询范围，其余续问直接完成。 |
| 项目/卡项/产品集合 | 三类分别最好 → 本月 → 前两名 → 昨天 → 本月 | 通过；三组结果在日期和排行数量续问中对称保留。 |
| 客户条件集合 | 本月达到金额的客户数 → 明细 → 调整阈值 → 最近 30 天 → 有几个 | 通过；同一条件在计数、名单、阈值、日期之间闭环切换。 |
| 经营概览与话题切换 | 今日经营概览 → 昨天 → 本月 → 今日劳动业绩第一名 → 本月 | 通过；前三轮继承概览，第四轮自然切换到人员排行，第五轮只继承新话题。 |

## 真人测试中发现并修复的问题

1. 纯指标续问被模型误带的上文排行字段拦截：已在绑定前仅对注册表证明的单指标续问收敛非当前字段。
2. 非集合请求被模型附带的无效单项 `groups` 元数据拦截：已将可选路由元数据与客户语义解耦。
3. 集合排行的“前两名”只作用于第一组：已增加集合排行续问编译，对每个已签名子查询只替换排行方向/数量。
4. 一次模型调用超过安全等待时间：页面明确提示未执行查询，按原问题重试后完成；未把超时当成业务结果。

## 自动回归

- PHP 语法检查：5 个核心文件通过。
- 后端：`skill-semantic-projection` 18、`semantic-guidance` 96、`personnel-analysis` 54、`context-delta-gateway` 78、`intent-understanding-contract` 125、`gateway-review-regressions` 58、`semantic-binding-guard` 10、`r27-ranking-presentation` 3、`query-read-view` 63，全部通过。
- 前端：设备会话、浏览器工作流与传输、浏览器入口三组合同测试全部通过。
- `git diff --check` 通过。
- 删除/清理零引用扫描：`moheAiContextAnswer`、`moheAiContextSubmission`、`_registry_exact_current_metric`、`canDeferMetricChoice` 均无源码引用。
- 18081 干净重编完成：`Compiled successfully in 163073ms`，页面返回 HTTP 200。

## 固定文件指纹

- `AiGatewayServices.php`: `5812d949144118dbb2d90ac958ae6c193debc88fcf36f463f9dad8e2b71936f2`
- `AiIntentUnderstandingContract.php`: `64c4a3894c691bcb382b3334197940e3667764950ae4526593c58f03a38706dd`
- `AiIntentResultContract.php`: `0bd0f4220d58cc90e53a1fe22111377db576a58364f552a86126c476f25578a3`
- `context-delta-gateway.php`: `3b60abea3d369fcea5856c2389356e2799a556ae9778750691d5d8349178c6b8`
- `intent-understanding-contract.php`: `d8fc92213a1d5aa8d34d5d2ea6ffc600a7b1d87f61c9cee1d44b3ac8e2789107`
- `browser-entry.mjs`: `4919244cb2cdce019020c9c736f3a95541c1e99e1d7f113f5f3b1b2e61bf4f7f`

## 状态

开发、自测与真人测试完成；本文件保存本次实际操作记录。按项目门禁，尚待产品经理对该固定版本明确确认验收，未执行本地提交、部署或推送。
