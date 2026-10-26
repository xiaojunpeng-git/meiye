# R42 瑞昊部署与线上真人验收记录（2026-09-25）

## 发布范围

- 目标实例：瑞昊，`https://rh.cc3798.com`，独立数据库 `ruihao`。
- 固定源码版本：`d7f833d4`（包含 `0fc0d7d9`、`1f0617e0`、`d7f833d4`）。
- 本轮只部署魔核 AI 后端运行时代码；无数据库迁移、无前端构建、无小程序变更，其他客户实例未改动。
- 运行时代码：
  - `后端代码/app/services/ai/AiGatewayServices.php`
  - `后端代码/app/services/ai/semantic/AiExactRankingCollectionAdmission.php`
  - `后端代码/app/services/query/metric/MetricDefinitionRegistry.php`

## 业务闭环

1. “会员业绩最高的前五名详情”拆成可信排行与会员权益详情两段执行；排行使用统一指标事实，权益读取覆盖该会员所有门店。
2. “这个月项目、产品、卡项业绩最高的分别是什么”按注册表对象集合执行，分别输出项目、产品、卡项结果，不依赖整句写死。
3. 对模型理解阶段增加一次受总预算约束的传输级恢复；模型协议错误不盲目重试。
4. 生产首轮会员排行详情仍被模型合同波动阻断后，增加注册表驱动的封闭确定性准入：仅在对象、方向、数量、详情、日期和默认指标均可由注册表唯一确定时跳过模型；显式指标、开放分析和歧义问题仍进入模型路径。

## 部署与回滚

- 首次部署备份：`/www/backups/rh.cc3798.com/20260925-220107-r42-1f0617e0`
- 补充修复备份：`/www/backups/rh.cc3798.com/20260925-221011-r42-d7f833d4`
- 补充包 SHA-256：`240de69d...`
- 最终远端文件 SHA-256：
  - `AiGatewayServices.php`：`2a2a908b88cc49268e2659fc0f4d0cc88040acf85a4c623a3c6b4af7814b22c4`
  - `AiExactRankingCollectionAdmission.php`：`9929b607cc8239cbb04715676706611bdfad070b8d2969bd34cd69eb5891f53e`
- 服务状态：Swoole `manager_count=1`；execution、export、supervisor 三个服务均为 `active`；页面 HTTP 200。

## 部署异常与恢复

补充部署后曾误执行框架级 `php think clear`，该命令同时清除了 `runtime/private/mohe-ai` 中的实例主密钥，导致已保存的 API Key 密文无法解密，新 Run 以 `AI_MODEL_CONFIG_INVALID` 失败。业务数据库及业务代码未受损。

处置：

1. 停止继续使用框架级清理命令，并确认线上无活动 Run。
2. 经产品经理明确授权，将本地当前已验证可用的同一 SiliconFlow API Key 通过进程标准输入直接迁移至瑞昊配置存储；密钥未写入命令参数、聊天、证据或终端输出。
3. 由线上当前实例主密钥重新加密保存，配置版本从 6 更新到 7；模型、启用状态、外部处理授权及 `sanitized-question-v1` 范围保持不变。
4. 重启 Swoole 与三个魔核 AI worker；线上配置可解密，活动 Run 为 0。
5. 管理端“测试连接（可能消耗客户额度）”显示：`本次连接与结构化响应测试通过，不代表账户余额充足。`

后续发布约束：不得再对站点执行 `php think clear`；若确需清理缓存，只能清理明确的 `runtime/cache` 范围，并必须保护 `runtime/private`。

## 线上真人问答验收

两条测试均从瑞昊管理端工作台新对话发起，对话记录保留。

### 会员排行详情

- 问题：`会员业绩最高的前五名详情`
- 页面用时：1 秒。
- Run：`97cc994bd35121a99455fe23d26ba5d249709819ffafa2d0`
- 状态：`COMPLETED`；服务端生命周期 875ms；客户端完整答复 1246ms。
- 执行：模型调用 0，工具调用 2；诊断 `deterministic_registered_ranking_collection_admitted`。
- 结果：正确返回前五名现金业绩排行，并追加 5 位会员的全部门店权益汇总。

### 项目、产品、卡项分别最高

- 问题：`这个月项目、产品、卡项业绩最高的分别是什么`
- 页面用时：2 秒。
- Run：`f84ca73319afe1a6cd694f9874a42e5e108e20722b74c011`
- 状态：`COMPLETED`；服务端生命周期 1346ms；客户端完整答复 2216ms。
- 执行：模型调用 0，工具调用 3；诊断 `deterministic_registered_ranking_collection_admitted`。
- 结果：分别展示项目排行、产品排行、卡项排行，各自给出第一名、销售额、统计时间及表格。

## 结论

R42 在瑞昊线上已完成配置恢复、连接测试与两条真实业务问答验收。最终版本未新增客户写死判断，确定性路径由能力注册表和唯一性约束驱动；未知或有歧义的自然语言仍由模型理解。
