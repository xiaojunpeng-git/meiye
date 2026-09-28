# R51 点名门店技师追问本地修复记录（2026-09-28）

记录时状态：本地代码与电脑端真人自测通过；当时待产品经理对本固定版本确认验收，未 commit、未部署、未 push；连接瑞昊线上的小程序尚不能验证这份本地后端代码。此后产品经理要求提交本固定版本；提交不等于部署或小程序验证。此前失败记录见 `R51-LATENCY-LOCAL-2026-09-28.md`，不删除或改写。

## 范围与固定版本

- 基线 HEAD：`4f637434531656ec1712c1208bb7a76e222f62d3`。
- 后端源码：`AiGatewayServices.php`、`contract/AiIntentResultContract.php`、`contract/AiIntentUnderstandingContract.php`、`model/SiliconFlowClient.php`（均位于 `后端代码/app/services/ai/`）。
- 自动化测试：`tests/mohe-ai/intent-understanding-contract.php`、`tests/mohe-ai/result-reference.php`。
- 数据库迁移、前端源码、工程脚本、生成物：无。仅新增本验收证据文件；未改事实源、统计口径、SQL、权限或客户端。
- SHA-256：`AiGatewayServices.php` `2a51f73f8425c6847ad82dc455e65cea58f02767661964c034f2ce575d5ad515`；`AiIntentResultContract.php` `b7c73eb11da3ed0fa8e78c253fbb5255bea4a95875a8760744148008e24c4fbd`；`AiIntentUnderstandingContract.php` `e96af0efa71858d590ce2243d42dd8bff949a913294844db9359a1cc9c3e104d`；`SiliconFlowClient.php` `f17d9a766f05e8058f2d2f1af5a45b9079b6a5a50e7227b69f243b219ede5437`；`intent-understanding-contract.php` `2a7900392c952d2ef30b5a4ee3346aa43c373c80b19401e0bad86409b8752998`；`result-reference.php` `44558cf05317804692fd714ceb937fa9501a75396bf7fce053b033050c4f4541`。
- 业务注释已更新于上述三个契约／网关文件及模型客户端，解释匿名岗位作为人员筛选、唯一当前门店引用、独立范围筛选及提示契约边界。

## 原因与修复

原追问“肥西水晶城这个门店技师项目数最多的是哪个，最低的是哪个”在同会话先前已把“技师”验证为人员岗位筛选，但模型下一轮把它写成可排名的 `position` 对象。绑定阶段因此出现 `person_to_position` 的对象类型冲突；另有模型只引用“这个门店”而未引用当前匿名门店标记，以及换门店时清空当前岗位筛选的问题。

修复仅在当前问题有唯一匿名岗位标记、已接受的理解明确引用它、且无其他分析对象冲突时，将该岗位承载为“人员排行 + 岗位条件”；保留匿名条件交由授权目录解析。当前门店的证据引文未包含匿名标记时，只在问题中恰有一个当前门店标记且理解已接受 `store_term` 时补全引用；多门店不猜。切换门店不再抹掉本轮显式岗位筛选。诊断仅保存固定枚举，不保存客户原句或私有名称。没有新增短语写死、并行查询路径或客户端规则。

## 验证

- PHP 契约：`intent-understanding-contract.php` 184 checks、`result-reference.php`、`gateway-integration.php` 160 checks、`gateway-components.php` 140 checks、`personnel-analysis.php` 56 checks、`context-delta-gateway.php` 92 checks，均通过；修改的 PHP 文件语法检查与 `git diff --check` 通过。
- 电脑端本地真实问答：先问“这个月现金业绩最高的是哪个门店”，约 1 秒；继续问“这个门店技师做的单数最多的是哪个，最低的是哪个”，约 25 秒，沿用该门店并以项目数返回双向排名。
- 原会话连续两次追问“肥西水晶城这个门店技师项目数最多的是哪个，最低的是哪个”均成功，分别约 28 秒（run `29786710614643ef468ca5dcf5d4de24351bddc5fb5b4d3a`）和 24 秒（run `e0696bd965800d90d3bf5417a06efa6ebf68a461710952af`）；门店、岗位、项目数和最高／最低范围保留。
- 新对话直接问同一句在最终代码下成功，约 44 秒（run `1ea92874730b185d18eff2f0abd070234cc50ecd9ae899ef`）：项目数最低许长娥 0 项、最高汤静静 24 项，时间 2026-09-01 至 2026-09-28，人员范围为有手艺人资格的在职人员。此结果在新会话中验证了没有依赖上一轮门店或岗位。
- 保留中间失败样本：早期约 37 秒失败，原因为绑定对象类型冲突；一次新会话虽返回数值但丢失“技师”岗位筛选，因此不计通过。中间试探分支已删除，未并存。

## 未完成与性能风险

新会话成功样本仍耗时 44 秒：理解约 16.7 秒、绑定约 10.8 秒、一次绑定格式修复约 10.2 秒、目录约 3.6 秒、数据查询约 0.35 秒。主要耗时在模型调用，不能将本次业务正确性修复宣称为速度达标，也不为偶发格式错误增加未经验证的特判。待产品经理确认本版本并另行授权部署后，才可用连接瑞昊的小程序验证对应后端；现阶段不声称手机端已通过。
