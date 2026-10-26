# R8D 命名对象业绩与缺失时间问题卡本地验收记录

## 固定版本

- 本任务起始基线 HEAD：761a3f299c9f39dea43d54729fa69efeb3113292。固定时同一共享工作区 HEAD 已由独立库存调拨闭环推进至 9fab469601ec03ae8b4bce3ca73eda07aaa9cc7e；该提交未改动本闭环 AI 文件，本清单文件 SHA 与指纹均按当前内容重新核对。
- 闭环文件清单指纹 SHA-256：27f0eefa96576d9c4562ea28db06216cdce3e3f2dd51d9ba8966c868c2fea86a。按下方源码和测试顺序，以“路径、制表符、文件 SHA-256、换行”拼接后计算。此前产品经理验收的 6d993a3a 版本未包含浏览器及手机卡片修复，本版本需补验后才能本地提交。
- 状态：开发与 Codex 本地自测完成，待产品经理对本固定版本确认验收；未 commit、未部署、未 push。
- 一套 PHP 源码正本；无数据库迁移或生产实例操作。

## 问题与完成范围

瑞昊旧版的 7 条评价问题经线上工作台只读复测，6 条符合业务口径；“扬州大润发的业绩是多少？”连续两次转入模型并超时，分别用时 31 秒、48 秒。

1. 门店完整名及授权目录内唯一的“店／门店”后缀省略名，可与已注册单指标或多指标、单一明确日期组合，直接进入统一 Reader。日期和对象在句中的前后顺序不影响解析。
2. 命名门店只缺日期时，问题卡显示已确认的门店与指标，并提供今天、昨天、本月、上月、自定义日期。自定义日期复用既有严格校验；卡片在同一 Run 内保留原门店 ID 和指标代码，不调用模型猜日期。
3. 员工姓名从当前报表授权的人员目录精确识别。“业绩／现金业绩／销售业绩”在人员粒度绑定统一注册的 staff_sales_yeji，即最终分配给销售人的人员现金业绩，不将门店总现金业绩冒充员工数据。同名员工先选人，缺日期再选时间；统一 Reader 复核权限。
4. 若一个名字同时命中授权门店与员工，不静默选粒度。无权或模糊名称、额外筛选、比较、多时间范围继续走原有受控理解流程。普通无对象指标问题保留原有日期默认行为。
5. 自定义日期的第二张卡保留已确认对象和指标。没有新建业务 SQL、权限旁路或第二套指标计算。
6. 平台浏览器与商家手机问题卡只为语义澄清显示“补充描述”；签名日期和员工选择卡只显示后端下发的选项，避免用户选择一个服务端不接受的值。

## 自测与真人路径

- PHP 7.4.33 本地容器语法：AI 网关、确定性准入及两个新增问题卡规划器均通过。
- deterministic-summary-admission.php：60 项；r8d-store-period-guidance.php：17 项；r8d-named-person-period-card.php：9 项，均通过。覆盖语序、同名、无权名、额外条件、快捷时间、自定义时间、伪造选项拒绝及统一注册计划编译。
- r8c-multi-metric-store-summary.php：9 项；personnel-analysis.php：56 项；query-context.php：67 项；opt1-guidance-gateway.php 与 opt1-transport-recovery-gateway.php：38 项，均通过。git diff --check 通过。
- browser-guidance-contract.mjs：232 项；browser-mobile-guidance-contract.mjs：219 项；browser-entry-contract.mjs 与 browser-workflow-contract.mjs 均通过。前端回归覆盖快捷日期五项原样显示与提交，以及原语义卡继续提供补充描述。手机测试执行实际 UVue 控制器的 TS 降级版本并检查模板分支，不等于原生设备编译或真机验收。
- 本地瑞昊数据库经实例 UUID 核对，在只读回滚事务中，“杜秋梅本月的业绩是多少？”唯一识别一位授权员工，解析为 staff_sales_yeji、2026-10-01 至 2026-10-16；注册 Reader 的金额与有效人员业绩事实独立汇总逐分一致。管慧不在该本地授权目录，不能把合成身份测试冒称为她的真实账号验证。
- Codex 用浏览器实际操作隔离本地测试页，连接真实 AiGatewayServices 与签名澄清流程，使用合成门店／金额且不调用模型：输入“一号的业绩是多少？”→显示门店、现金业绩与时间选项→点本月后完成，查询绑定 cash_performance、门店 1、2026-10-01 至 2026-10-16；再次选择自定义日期，输入 2026-10-01 至 2026-10-15 后完成，门店和指标保持不变。第二张卡仍显示已确认条件。临时测试服务已停止，不属于交付源码。

本地 18081 业务工作台魔核 AI 配置未启用，页面显示“魔核 AI 暂不可用”。本次真人路径是隔离的本地网关测试页，并非业务工作台真实线上问答；瑞昊和渼约 008 仍运行既有版本。正式页面及“管慧”的真实权限和结果须在后续已授权环境单独验收，本记录不声称完成。

## 文件清单与 SHA-256

### 后端源码

- 后端代码/app/services/ai/AiGatewayServices.php — 63df0e51125373644513c8e20daca22ac8405f4ce69357a9b1824f51f2caa302
- 后端代码/app/services/ai/semantic/AiDeterministicSummaryAdmission.php — 5761409b74ed279e9531bb002080eda334c152f2e8468e419773b76d9557a19d
- 后端代码/app/services/ai/execution/AiSummaryPeriodGuidancePlanner.php — d5ff3304e3a9b9e70d0f08b23afebb563876ff20ae5b46d68617a15bd17282e3
- 后端代码/app/services/ai/execution/AiNamedPersonGuidancePlanner.php — d8eec207c47d4115b38185429ebd5fa65ee102f1d1eef442b3921921d95bc946

### 前端源码

- 前端代码/shared/mohe-ai/browser-entry.mjs — 60843bb30c5d2a631d8691bed9359ee20d811a47a73c7bf5d0002baf71a0f540
- 前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue — 91be67c8d37b0069507ec38b2174ca88edc954f074ab77902d182003f59f906d

### 自动化测试

- tests/mohe-ai/deterministic-summary-admission.php — d4153e019e0b81739fd20dbd148451d7503db7ab2896fa76b260d9a409c036b7
- tests/mohe-ai/r8d-store-period-guidance.php — 05e8442bf0def541ae12fe88c7e0c238624fd045c5253305931c9b436f7141db
- tests/mohe-ai/r8d-named-person-period-card.php — 0b479972cd5c7902afd9ddc2cf5a37b6b7dcaae48c1ee21bdc53c0520a46d46e
- tests/mohe-ai/browser-guidance-contract.mjs — 948b6174ffdd1c6102ca3b70940afd72dc3ea3896dbca7d2f9e1668062e6e982
- tests/mohe-ai/browser-mobile-guidance-contract.mjs — 9d2c56571ca4bbfba09e82720234062c2d0cd178d96039dd7b2e603a13dbea8e

### 其他分组

- 数据库迁移：无；工程脚本：无；生成物：无；验收证据：本文件（不计入自引用文件清单指纹）。
- 业务注释更新：上述四个后端文件及两个前端文件。旧目录读取入口 mayContainLeadingStore 经全路径零引用扫描后改名为 mayContainNamedObject，没有保留平行分支。
