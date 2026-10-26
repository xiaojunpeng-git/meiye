# R51 新对话人员排名耗时：本地修复与验收记录（2026-09-28）

基线 HEAD：`909a9392b5d2be8ab28662c4da252ee6a16c26d7`。本记录对应未提交的本地工作区版本；未部署瑞昊、未推送、未上传小程序体验版，待产品经理按本文件版本确认验收。

## 范围与口径

- 固定真人问题：`这个月肥西水晶城这个门店技师项目数最多的是哪个，最低的是哪个`，每次使用电脑端新对话。
- 原成功样本 44 秒：首段理解约 16.7 秒，第二次模型绑定约 10.8 秒，绑定修复约 10.2 秒，人员目录约 3.6 秒，事实查询约 0.35 秒。修复原因是模型误把点名门店或角色标记填入 `scope/store_term`。
- 快速路径仅在首段理解已经给出完整人员排名、单一期段、有限名次，且问题中只有一个注册指标精确匹配时跳过第二次模型绑定。门店和角色仍由现有本地目录、权限和查询链路解析；任何歧义、额外条件或上下文追问均回原流程。
- 首段模型超时窗口由 `22 秒 + 相同大请求再试 8 秒` 改为单次 30 秒；超时仍不执行事实查询，不伪造答案。

## 真人结果（本地 18081）

| Run | 页面用时 | 状态 | 关键事实 |
| --- | ---: | --- | --- |
| `ec1edded3854d72e47f383c2d20f65d11ec52a011ec3c7b3` | 21 秒 | 完成 | 无 `bind_intent`；门店、人员范围和排名正确 |
| `83dcbc855aeef2bd23519ad75381ac1f41026ac033e82924` | 21 秒 | 完成 | 无 `bind_intent`；同口径 |
| `4e66fbf3c2947363a73348198a4f1ce41514b687fce8f184` | 35 秒 | 失败 | 旧 `22+8` 首段请求两次传输超时，事实查询未执行；失败记录保留 |
| `85810d3115dc1a4ab38b388345b9543a83a50c03c7081770` | 22 秒 | 完成 | 新 30 秒窗口；首段 15.9 秒、目录 3.8 秒、查询 0.36 秒 |
| `c7070beb3c57f7dc316bb254d224258da4e167914fe80e6f` | 17 秒 | 完成 | 首段 11.8 秒、目录 3.7 秒、查询 0.32 秒 |
| `c4a3b856429863f9f45cf5bdef167c58419ebd3ca6c5520e` | 服务端约 21 秒；隐藏测试页显示 87 秒 | 完成 | 后台浏览器测试页轮询被延后，不能当作正常可见页面耗时 |
| `cef4f332ef3e867c4e63716a70caf4bead75a02d896cc33b` | 18 秒 | 完成 | 可见电脑页面端到端复测；首段 13.0 秒、目录 3.8 秒、查询 0.37 秒 |

上述成功答案均为：2026-09-01 至 2026-09-28、肥西水晶城、当前在职有手艺人资格人员；项目数最低许长娥 0 项，最高汤静静 24 项。相同数值并列规则未变。隐藏浏览器导致的 87 秒已如实保留，不用于宣称用户可见端稳定达标；可见页面再测为 18 秒。第三方模型仍可能偶发 30 秒传输超时，不能以本次有限样本保证零超时。

## 自动化与代码边界

- `intent-understanding-contract.php`：191 检查通过；`result-reference.php` 通过。
- `gateway-integration.php`：155 检查通过；`gateway-components.php`：140；`personnel-analysis.php`：56；`context-delta-gateway.php`：92；`runtime-contract.php`：105。
- `AiGatewayServices.php` 与 `AiIntentResultContract.php` PHP 语法检查、上述文件 `git diff --check` 通过。
- 本次更新了以上两份业务代码中的快速路径、超时和门店标记边界注释；测试文件覆盖正反门禁、未知结果不重放。删除已无用的短窗口重试方法、常量和旧测试预期。
- 未修改前端、数据库迁移、工程脚本或生成物。其他任务已有脏工作区改动未纳入本轮。

固定版本 SHA-256：

```text
e21d94b4fc7ee288bce1869cb39e0187f49985d4ab603f154eaacf0d1ab37b6c  后端代码/app/services/ai/AiGatewayServices.php
9dc5d42acf9a3f0398f956a1b13ce33e2e0d4f5587c15eb9ea6b2947e58fc439  后端代码/app/services/ai/contract/AiIntentResultContract.php
eb46206c6b127100309e9619c3c2f9a50ae6b8e1767c55163b8736e5b3d8004e  tests/mohe-ai/intent-understanding-contract.php
412b55284effb9f6ebaeb52a036784e78de460ceee731de068d240e01d20975a  tests/mohe-ai/result-reference.php
83f31ff51bed4cfb90e01d6c311fea3b668cb2ce2cc8ad80fe996d6fda674a08  tests/mohe-ai/gateway-integration.php
40418a5c6cfad0ce610fb5d11de62e56e6228714bd10e72395c5c38d3f801460  tests/mohe-ai/runtime-contract.php
```

六文件补丁指纹：`c38448edc1379de17bec05edad8cd134fdd1b3afe8a72366c046e984c31496d7`。
