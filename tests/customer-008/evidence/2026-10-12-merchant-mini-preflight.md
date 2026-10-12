# 008 商家端小程序上传前检查（产品经理已验收）

## 范围与实例

- 目标仅为渼约正式客户 `008`，微信小程序 AppID `wx14666197911d052b`，接口域名 `https://008.cc3798.com`。瑞昊及 007/012 不变；不提交审核、不发布正式版、不执行 `git push`。
- 唯一源码正本为 `美容源码/`，基线 HEAD `b2d74bd04184d2c15bd74e8635838bc345ceca7b`。本地工作区中已暂存的目标方案页面等其他任务改动不进入候选包。
- 本闭环仅改变小程序接口域名的构建配置入口；不改变订单、会员、指标、权限、业务写入或统计口径。权威源仍是目标实例 `mohe_008` 的后端和数据库，客户端仅发请求，不新增事实、迁移、并发写路径或跨客户访问。

## 自有文件

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/mobile-vue3/src/shared/platform/mobile-runtime-config.uts` | `19e772b027cd1e215a30db97bad10689e9187512351542a55b101b846d5fc4f8` |
| 前端配置示例 | `前端代码/mobile-vue3/.env.example` | `dabedc1adf06f1e897ab76c45bc90ab3f93447c4007014274582f3ade64c1d39` |
| 前端说明 | `前端代码/mobile-vue3/README.md` | `1f98d42570f5266ae9daf87a3d787b7fc53512df50190f16c95adfb7faca9aaf` |
| 自动化测试 | `tests/mobile-vue3/js/source-boundary.test.mjs` | `8827201e6638a39eefdcc5ccf0662ee3ab1686c162311812a78dee142906dcda` |

- 受控业务边界注释已更新于 `mobile-runtime-config.uts`，说明按客户构建及缺失配置时拒绝跨客户回退。后端源码、数据库迁移、工程脚本均无本次变更。
- 已跟踪三个修改文件的补丁 SHA-256：`76893c3245b5f1e93199d0943c151de23885d7f008211930a6ca95f58bc0c80e`；新配置示例的 SHA 如上。验收前如文件改变，须重新固定并复测。

## 本地验证

- 从上述 HEAD 导出 `mobile-vue3` 到临时构建目录，仅叠加本次修改的运行配置源文件；临时 `manifest.json` 的两个 AppID 字段设为 008，临时 `.env` 设为 008 HTTPS 域名。没有把工作区未验收页面带入。
- HBuilderX 5.26 `launch mp-weixin --compile true`：20 页编译成功；`launch web --compile true`：编译成功。小程序目录约 1.9 MB，属于运行模式产物，不等于正式发行包。
- 另试 `publish mp-weixin --upload false`：工具虽退出码为 0，但输出“appid 不存在，请在 manifest.json 中重新获取”，因此**发行包未生成**。这一路径需 DCloud 应用标识，不把它冒充为通过；若验收后上传，沿用既有的微信开发者工具上传已编译小程序包方式，并保留运行模式包限制。
- 生成物 `project.config.json` AppID 为目标 AppID，SHA-256 `e8d2952ad7dadfe727c174f52d9d54e11e30eacc7a05652e7fe4dc8d176fbc98`；`src/shared/platform/mobile-runtime-config.js` 仅含 008 域名，无瑞昊域名，SHA-256 `ca52a18b7b550ee4f2292b91aa8f6bca55bd69c1f5fb959c2c3f2e78bcfb408b`。
- 新增的定向 Node 测试通过；`git diff --check` 通过。整个 `source-boundary.test.mjs` 另有 2 项断言失败，分别涉及当前工作区的页面平台指令边界和既有 `platformConfig.json` WEB 目标列表，不将其冒充通过。
- 微信开发者工具 CLI 当前登录状态为 `true`；尚未验证该登录账号对目标 AppID 的上传权限，也尚未执行上传、预览或真机登录。

## 线上前置限制与回滚

- 008 的 `GET /api/mobile/merchant/bootstrap` 当前返回 `410010 站点升级中，请稍候访问`；同一路径在瑞昊返回移动接口鉴权协议错误。008 当前不能据此认定商家端可用，本任务未改站点开关。
- 目标 008 站点与独立 `mohe_008` 库已定位。小程序上传不改服务器文件和数据库；若微信平台已有旧体验版，上传新代码可能覆盖其体验版，须先确认版本和回滚方式。线上服务器已有历史备份目录，但本次未做新备份或回滚演练；不据此授权服务器变更。
- 产品经理已确认本文件记录的固定源码范围，并授权按规则本地提交后仅向目标 AppID 上传代码（不提交审核、不发布正式版）。另已授权只针对 008 排查站点维护开关；仅在确认它处于关闭状态后开启 008。提交后从该 commit 重新构建；上传成功与小程序可登录仍须分别验证。
