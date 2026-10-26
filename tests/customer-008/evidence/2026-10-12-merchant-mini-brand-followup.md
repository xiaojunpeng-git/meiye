# 008 商家端小程序登录文案增补验收

## 基线与范围

- 原验收闭环本地提交：`2ecb83fa2b7be69bd4efed786879f43fd3771ceb`。本增补只修正该版本中登录页写死的“已连接瑞昊正式服务”，改为“当前为小程序正式环境”；不更改登录逻辑、接口、数据口径或其他页面。
- 目标仅为 AppID `wx14666197911d052b`；瑞昊、007、012 不上传或部署。新增文案描述构建环境，不假称已完成网络连接。
- 增补文件：前端源码 `前端代码/mobile-vue3/src/app/pages/bootstrap/index.uvue`，自动化测试 `tests/mobile-vue3/js/source-boundary.test.mjs`。后端源码、数据库迁移、工程脚本：无本次变更；构建包是临时生成物，不进入 Git。页面内增补注释说明客户身份与网络连通性边界。

## 固定指纹与自测

- `前端代码/mobile-vue3/src/app/pages/bootstrap/index.uvue` SHA-256：`df6f30255c1bec6582a90059c53bad804950e66c39c1f52342ef77712e75facc`。
- `tests/mobile-vue3/js/source-boundary.test.mjs` SHA-256：`b1b2114200bd637af1aaab23d5cb1cb369eb2a68e09a957fa950fa4390c27ec4`。
- 两文件相对基线提交的补丁 SHA-256：`853faad7a4b8166ee168a3f939d8e690b9bc923490c1a3f248491bb20243cb8c`。
- Node 定向测试 `mini-program (API origin|login status)`：2/2 通过；`git diff --check` 通过。当前工作区其他任务的变更不进入本增补。
- 从基线提交导出的隔离构建目录仅叠加上述登录页源码，HBuilderX 5.26 `launch mp-weixin --compile true`：20 页编译成功。生成运行配置仍仅为 `https://008.cc3798.com`，生成登录页已变为中性文案；微信开发者工具模拟器可显示该文案。此包尚不是提交后的重新构建结果，不得直接上传。

## 008 线上准备及未完成事项

- `mohe_008` 的 `eb_system_config` 中 `station_open`（id 328）原值为空；已在备份后仅将此行设为 `1`，并只删除 `008:system_config:station_open` 缓存键。目标 Redis 前缀 `008:`，未改 007、012 或瑞昊。
- 008 独立库完整导出：`/www/backups/008.cc3798.com/20261012-merchant-mini-station-open-2ecb83fa/mohe_008-before.sql`，SHA-256 `1bcc8b11cd0286454293616418b3d4f8e83b7d3a0d459326b37214e4039d8daf`。回滚点为原配置行空值及该快照，不需影响其他实例。
- 开站后公网 `GET /api/mobile/merchant/bootstrap` 返回预期的移动协议缺请求头 `CONTRACT_HEADER_INVALID`，不再返回 `410010 站点升级中`；`/admin/login` HTTP 200。此项仅说明站点开关已恢复，不等于商家账号真机登录验收。
- 微信工具首次 CLI 上传固定基线包返回 `41002 appid missing`，没有上传成功；随后在工具“基本信息”对目标 AppID 执行“修改/确定”，显示“修改项目APPID成功”。产品经理已确认上述增补固定版本，并授权精准本地提交后仅上传到目标 AppID；须从新 commit 重建，不提交审核、不发布正式版、不推送。
