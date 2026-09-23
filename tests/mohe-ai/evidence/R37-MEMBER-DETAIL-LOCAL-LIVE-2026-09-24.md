# R37 会员条件筛选与权益连续追问本地真人自测

- 日期：2026-09-24（Asia/Shanghai）
- 环境：本地平台端 `http://127.0.0.1:18081/admin/setting/mohe-ai`
- 执行人：Codex 在真实页面手动输入并观察完整回答
- 边界：仅本地开发与自测；未 commit、未部署、未 push，不代表产品经理验收

## 提交确认

- 上述边界为自测交付时状态。产品经理在收到固定版本与自测结果后明确要求 `commit`，本次据此提交该固定版本。
- 提交前逐一核对下列 11 个源码及测试文件的 SHA-256，全部与交付指纹一致。
- 提交基线：`4e50d72bcd258fa92e3c64179cd43890524b6d6e`。
- 提交范围：8 个后端源码文件、3 个自动化测试文件及本验收记录；无前端、数据库迁移、工程脚本或构建生成物。
- 其他任务的工作区改动保留；本次授权仅为本地提交。

## 真人链路

1. 输入：“最近30天到店至少2次，而且实际收款销售额达到1万元的会员有哪些”。
2. 结果：25 秒完成，回答“符合全部2项条件的客户共有8人”，并展示 8 位会员的“客户到店次数”和“实际收款销售额”名单。时间范围为 2026-08-26 至 2026-09-24。
3. 紧接输入：“第一个会员的权益明细给我看一下”。
4. 结果：10 秒完成，精确引用上一轮签名名单的首位会员，展示会员权益汇总与有效卡项明细。
5. 隐私核对：回答未显示手机号、内部会员 ID 或其他联系方式；对象来自已验证的原名单，未按同名或新结果猜测。

## 技术回归

- `condition-set.php`：79 项通过（含“有哪些”名单形态和“有多少个”计数形态）。
- `member-detail-continuation.php`：6 项通过。
- `skill-semantic-projection.php`：19 项通过。
- `intent-understanding-contract.php`：141 项通过。
- `gateway-integration.php`：155 项通过。
- `gateway-review-regressions.php`：69 项通过、0 失败。
- `git diff --check`：通过。

## 修复要点

- 明确的名单/计数措辞只校正条件查询的回答形态，不选择指标、阈值、对象、时间或权限。模糊或冲突表达仍由模型理解。
- 条件名单仍经过注册指标、统一 Reader、数据权限和最多 100 行的服务端边界。
- 权益追问只能解析已签名原结果中的会员与顺序，读取前再次刷新当前权限。
- 修正了异步 Worker 中将“已刷新权限快照”错当成“可再刷新上下文”的错误，不放宽权限校验。

## 固定版本 SHA-256

- `AiIntentUnderstandingContract.php`: `9cd88a1ba4936eff2aa9aa52a247f7afe9ca7e875539c758c46cb446cea94215`
- `AiIntentResultContract.php`: `bad82c347927be0ef8f66cf1022000b36e633c4a131c49356c5037c58cd0fce8`
- `MemberDetailContinuationResolver.php`: `366fc36709f832438b89e532b74f550e92f6857d2f1f196c6326d31d7e124cd7`
- `AiMemberDetailAnswerRenderer.php`: `457213d79ed7215485bc7394ff043e62b3cedc055a82aff6cf3cc1b6c54b95c2`
- `AiConditionResponseFormResolver.php`: `ad39e7261b38a96f1c23c61e2b1a2c3a479813d2110468c04a38b80b89976fed`
- `AiGatewayServices.php`: `cb0c7104247b3a0767766da24434b09b150f3f46c3ff2f8b9f6233295c5c498c`
- `MetricDictionaryServices.php`: `fe5ba2748819412ac6c99118b255b3959ce6fe739dc12b300e5c2305b5c015e6`
- `MetricSemanticCatalog.php`: `ad4c1725689bc43dee18c8e011f3daf297cc3f6951df6ddbb41a249a70b2b31b`
- `member-detail-continuation.php`: `96ccb7da18397a34fd3a6a359fdb49091448a3228709256a24ce18f76f4d4491`
- `condition-set.php`: `c701b54a019672842b72cbbde44be3aad1a977cff1c8da7d69524d7e7e4aa85d`
- `skill-semantic-projection.php`: `ea7611757fcf84550b3a26f67c65757d4b34d64728bebf6eb326337067806e3d`
