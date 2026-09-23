# R36 魔核 AI 独立 Excel 导出：本地自测与固定版本

- 状态：开发与自测完成，待产品经理确认验收；未 commit、未部署、未 push、未上传小程序体验版。
- 基线 HEAD：`24d9024fa65ad046b83dda8eaebba680e9231188`。验收范围仅限下列 R36 文件；工作区其他脏文件不属于本闭环。
- 追加常驻 worker 后的 16 个已跟踪相关文件当前 `git diff --binary` SHA-256：`7bf533c710906e1cac4ce73ee361eedf57020adcb06c6b7db16b3bb02a21ddda`；新测试和 systemd 单元以表中 SHA-256 固定。`scripts/start-local.sh` 原有数据库环境和前端代理改动属于其他工作，仅本轮新增的导出 worker 两个 hunk 可进入本闭环提交。
- 口径：问题始终按屏幕回答提交；答案发布后，用户勾选 Excel 才创建独立文件任务。文件排队、生成、失败或下载不更改已发布的答案。断网恢复不在本轮范围。

## 闭环文件与 SHA-256

后端源码：

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/controller/ai/AiHttpActions.php` | `afc7d6ef240a5707e8f3e0672c11060664fc12391b4949c5a7754fb249d0f822` |
| `后端代码/app/services/ai/AiGatewayServices.php` | `39120949304304d25f2ef2e90444e5b041bafd20875f26a4c40b65a6c037ea0d` |
| `后端代码/app/services/ai/execution/AiExportRuntime.php` | `0fb1b7858bbc695dc316683309847e90f0f9aac04248124ed6aecdaf8aad9be0` |
| `后端代码/app/services/ai/execution/AiRunStore.php` | `9e7e79d25055abeed3398f4e7c771fb730ea426448616b6a11edc4db5982d4a4` |
| `后端代码/app/services/query/UnifiedQueryExportTaskServices.php` | `cbfd807303451a940beed7593fb0643d2ecf2e6e0e5d0c3be4198a0666b1cbcf` |
| `后端代码/route/a-ai.php` | `dfa5097a8befcaaa2c96cd46524ff40123207e8e2f5225cc373c507ffe8fb0ce` |

前端源码：

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/shared/mohe-ai/browser-entry.mjs` | `72e9c2d93380f53a1f3eaba96624d57143ac4b8cd4207f93051589470791d49b` |
| `前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue` | `0e19792d820a1167d9b27425a7f1b8da4b084c81d6718c2a04a1150a221eaa91` |

自动化测试：

| 文件 | SHA-256 |
| --- | --- |
| `tests/mohe-ai/http-routing-contract.php` | `989584f5d1e763b2e449babfbab3adf824af8db659c33b45f5529e0248e51097` |
| `tests/mohe-ai/mobile-ai-entry-transport-contract.php` | `03a83223b1699b128867acd6b2329f6a032b09741763a2e28a54e5177e1bc87c` |
| `tests/mohe-ai/query-export-runtime-mysql.php` | `a5d080383d34a7d55c13715b0f4273bb8609cb9ab2828ab62bbcab15e795f2d2` |
| `tests/mohe-ai/run-all.sh` | `77769c672f153b832fbc93c4d771b195bb3a06fe2fbd066e9682cadc4187bf6b` |
| `tests/mohe-ai/browser-independent-export-contract.mjs` | `4eef76abd66516f7f3657a6abf0510f1e438116ac9b20bdc187ea8426912ae59` |
| `tests/mohe-ai/r36-local-excel-live.php` | `39b70e4be1ec1558ed8a1ce77f3fb2b173eaa9e6e377d57423df5cb494c2c40b` |
| `tests/mohe-ai/gateway-review-regressions.php` | `f426638597394e35e4eca5de6db0b2f8d2e1764ec8d475d9a80d36dc11024d06` |

数据库迁移：无新增迁移；本地 `ruihao` 应用了已有的 `2026-09-08-魔核AI运行状态/04-共享导出来源隔离.sql`，只新增来源隔离字段，历史 3 条任务保持 `REPORT`。操作前备份：`/tmp/mohe-r36-export-backup.AAfd2O/eb_unified_query_export_task.sql`，SHA-256：`60df0b2d1e65a5fd2df2e27a57f53e7d5adef6ed25560b6037cb15fb4da8f9ec`。

工程脚本：

| 文件 | SHA-256 |
| --- | --- |
| `scripts/start-local.sh` | `238911c3c813bebbba9cadf4b78673a33af96571bfbe738003ef77440f0d6de5` |
| `scripts/install-mohe-ai-systemd.sh` | `a4896e321d44bfa1a6933acef10425359dfdc0c0c3ec279db2d2b878b1a0f2a4` |
| `scripts/systemd/mohe-ai-export-worker@.service` | `2d87245d8fb44cbef032c1a7ff299ae161b697277369acdec04b639841491f91` |
| `scripts/deploy-backend.sh` | `a7e6a222b8bb0e45b4993024b55fd18baf1cdbf99821390dfa11de31b79a47c3` |

生成物：HBuilderX 本地编译产物（忽略目录）和浏览器下载的 `/Users/xiaojunpeng/Downloads/经营数据 (8).xlsx`；不纳入源码。验收证据：本文件。

## 自测结果

1. `bash tests/mohe-ai/run-all.sh`：通过；包括隔离 MySQL 真实导出任务、浏览器 DOM/HTTP 合同与移动端传输合同。完整日志保存在本机 `/tmp/mohe-r36-run-all.log`。
2. `bash tests/mohe-ai/run-query-mysql.sh`：通过，160 项隔离 MySQL 5.7.44 检查。覆盖重复创建、答案先发布、文件成功/失败不影响答案。
3. 本地真实网关问答：`这个月现金业绩多少`，答案 1.6 秒完成；独立 Excel 任务 1.1 秒完成；下载响应成功，原答案引用未变化。该记录只保留耗时与状态，不保存私有业务数据。
4. 本地 Chrome 真人操作：勾选 Excel、提问、先看到答案，再看到“下载 Excel”，点击下载。下载文件经 `file`、`unzip -t` 和 PhpSpreadsheet 读取验证为有效 XLSX，指标、期间和数值与页面答案一致。
5. 商家端小程序源码经 HBuilderX uni-app x 编译通过；未在手机真机上测试，也未上传体验版。`bash tests/mobile-vue3/run-all.sh` 有 22 项既有缺失文件/manifest WEB 基线失败，目标 AI 传输测试单独通过 40 项，不能将整套移动测试写为通过。
   - 用户登录后，在微信开发者工具的小程序模拟器中打开魔核 AI，实际界面显示“Excel 暂未开放”，开关禁用。源码 `mobile-runtime-config.uts` 的 `MP-WEIXIN` 分支固定连接 `https://rh.cc3798.com`；本轮新后端尚未部署到该实例，因此当前登录状态不能用于本轮 Excel 端到端验收。未向线上发送新问答，也未改动线上配置。
6. `git diff --check`、本轮 PHP 语法检查通过。
7. 常驻队列补测：停止原先 2 个临时 worker，只保留 `mohe-ai-export-worker` 容器（`--restart unless-stopped`）；本地真实网关再次通过：答案 1.5 秒，Excel 1.1 秒，下载成功且答案引用未变化。`bash -n` 检查 3 个启动/部署脚本通过，魔核 AI 全套 `run-all.sh` 重跑通过。
8. 瑞昊现网只读检查：运行着问答 worker 和 supervisor，但**没有**独立 Excel worker；线上相关 PHP 文件指纹与本地新版本不同。因此当前体验版开关禁用是版本/运行条件未就绪，不能仅改前端开关。没有执行远程写操作。
9. 本地重启验证：`docker restart mohe-ai-export-worker` 后容器自动恢复运行；再做真实网关问答，答案 1.0 秒、Excel 1.6 秒，下载与答案不可变性检查通过。

## 代码质量与边界

- 新增/修改注释文件：`AiExportRuntime.php`、`AiRunStore.php`、`UnifiedQueryExportTaskServices.php`、`browser-entry.mjs`、`mohe-ai-entry.uvue`、`r36-local-excel-live.php`；注释说明独立任务、已发布答案不可回写、权限/绑定和进度边界。
- 旧 `screen_and_xlsx` 服务端兼容路径仍被旧客户端契约引用；新网页和小程序均走 screen + 独立 Excel。待线上客户端替代验收、引用扫描和稳定验证后，再单独删除旧路径；本轮不提前破坏兼容。
- 断网恢复、跨设备找回不在本轮范围。本地 worker 已改为容器自动拉起；瑞昊须先安装独立 systemd worker、完成适用迁移与独立备份，固定 commit 后才能部署并测试，不能据此声称线上已可用。
