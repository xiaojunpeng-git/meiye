# 瑞昊“今天经营得怎么样”意图协议修复证据

- 验收日期：2026-09-20
- 目标实例：瑞昊 `rh.cc3798.com`
- 问题：模型偶发把“今天”等日期表达重复投射到 `metric_codes`，导致理解协议在查询前拒绝执行。
- 修复原则：仅在已有独立合法日期载体、且指标候选全部为纯日期表达时删除重复投射；不选择业务指标，不绕过语义绑定和权限校验。

## 固定版本

- `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php`
  - SHA-256：`47ef0cd2cb36533abe7205e0a86ab45e13cbbfcd541cb23f7d947e21605b89c3`
- `tests/mohe-ai/intent-understanding-contract.php`
  - SHA-256：`18f041de57a5d43484ac51632f5e00d31fff725ed2984ef861666a22bc1296a4`
- `scripts/deploy-backend.sh`
  - SHA-256：`8452b42be37ae15f0302efb7cb995fc911984bcb04f75d3c6213d4c390132843`

## 自动化回归

- 意图理解合同：127 项通过。
- 网关复核回归：63 项通过。
- 查询上下文：64 项通过。
- 上下文增量网关：78 项通过。
- 旧上下文移除审计：全部通过，`AUDIT_FAILURES=0`。
- `git diff --check`：通过。

## 真人操作

- 新会话两次提问“今天经营得怎么样”：均正常生成完整经营概览，日期为 2026-09-20。
- 回归提问“今天现金业绩多少”：仅返回明确指定的现金业绩，证明日期清理没有吞掉真实指标。

## 部署清理

部署脚本将 `app/services/ai/` 作为源码拥有的完整命名空间镜像同步。部署时删除服务器上源码已经不存在的旧规划器／校验器，避免新旧链路并存；实例配置、运行目录、上传文件和依赖目录不在删除范围内。

## 瑞昊线上验收

- 部署前 AI 源码回滚包：`/www/backup/mohe-rh/20260920_223047-r29-ai/ai-before.tar.gz`
- 回滚包 SHA-256：`f65809f60c40d04ee9b21324516c487f56cae3d0b01fa164a51659ab88eee31a`
- 线上协议文件 SHA-256：`47ef0cd2cb36533abe7205e0a86ab45e13cbbfcd541cb23f7d947e21605b89c3`
- 已删除源码不存在的 `AiQueryPlanInputValidator.php` 与 `AiFollowupQueryPlanner.php`。
- 已重启 Swoole、`mohe-ai-execution-worker@1` 与 `mohe-ai-supervisor`，三类进程均恢复运行。
- 真人提问“今天经营得怎么样”：成功返回当日完整经营概览。
- 同一窗口追问“今天现金业绩多少”：成功只返回现金业绩，统计时间为 2026-09-20。
