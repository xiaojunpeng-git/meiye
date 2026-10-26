# 第 4 轮：魔核 AI 回答评价与问题采集本地固定版本

- 基线 HEAD：`75101a3d6b9d5f48fdf82d0214cef0b00b2ea11e`
- 结果：本地开发与自测完成，待产品经理确认验收；尚未 commit、部署或 push。
- 本地数据：仅在 Docker `mohe-mysql` 的 `ruihao` 开发库执行本轮新增表；未连接远程实例。
- 真人路径：平台 AI 成功问答 → 有用 → 采集列表与详情 → 改为没用 → 仍为同一条记录；另一次无法直接回答的问题 → 没用 → 详情保留失败状态和页面原文。补验在 `18081/admin/setting/shop/base` 普通页面刷新后，窄屏点击顶部菜单按钮，侧栏出现“魔核 AI 回答评价与问题采集”，点击后进入采集页；这条路径无需先访问魔核 AI 配置页。
- 自动验证：平台生产构建通过（6 组既有 ESLint 警告）；HBuilderX 5.26 本地 `mp-weixin` 编译成功，生成组件含有用／没用及反馈请求（生成 JS SHA-256：`39f93291ae729d1159276dda8ff17d3817141ac10e57679affb43bef3e8c2fb3`）；网关集成 183 项、状态存储 54 项、菜单策略 22 项及反馈存储、路由、浏览器问答回归通过。补验 `platform-empty-menu-login.mjs`、`r6-management-ui.cjs`（54 项）和 `admin-router-contract.mjs` 均通过。
- 验证限制：商家端全量 `npm run check` 有 22 项既有失败，主要是仓库缺失未改动的契约文件及旧静态断言；小程序仅完成本地编译，尚未进行真机业务验收或生成正式安装包。
- 注释更新：`AiGatewayServices.php`、`AiRunStore.php`、`AiFeedbackStore.php`、`AiRuntimeFactory.php`、`browser-entry.mjs`、`device-session.mjs`、`mohe-ai-entry.uvue`、`menus.js`、`menu.js`，以及本次入口修复涉及的 `moheAiManagementMenu.js`、`platformLogin.js`、`main.js`、`basic-layout/index.vue` 的职责和身份边界。

## 闭环文件 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `tests/mohe-ai/admin-router-contract.mjs` | `11bafdafd85847bc44a0b2feb60cf34e35482129a6df063a0395f81066216b19` |
| `tests/mohe-ai/browser-entry-contract.mjs` | `74ce86bdf0cd29a93464604fdfcc6cabe25e0656ba57fcd2a5d0cba96f3110a6` |
| `tests/mohe-ai/feedback-store-contract.php` | `58f0443d7c4c390511a2ef8767c85739520e2464ccf3ac03f1d651f9ca167225` |
| `tests/mohe-ai/management-menu.php` | `bab4c3abc48c617c9e4ddbefa87b598b3a76272dddeb5d826828ee68e187e14d` |
| `tests/mohe-ai/r6-management-ui.cjs` | `fa6f0f88ee73b6a47fdfa65354adb5dfa43c47c308bca788ee03cb7b55fe5f20` |
| `tests/mohe-ai/state-store-contract.php` | `2aab9d960816704cc17aaf1f36e0cb6b8be4732bee6cf64d8e358ad71e4feea1` |
| `tests/organization-store-v3/js/platform-empty-menu-login.mjs` | `bca63e6ad774ef62378c399e212263ca4a1cd1a19bf84a13ad22dad34a87d8b1` |
| `前端代码/admin/src/layouts/basic-layout/index.vue` | `d1ccd657e230b8ac4d0d85977cc2120341cb41c995cdd3895662646ac626f189` |
| `前端代码/admin/src/libs/moheAiManagementMenu.js` | `d41a4144797fec3b1d98ce63f5de63489fb016d5e12d4c63183922c7f024f960` |
| `前端代码/admin/src/libs/platformLogin.js` | `b9b002a1137cda744a1d84f08d7f16f77e9319465807769d365086d3e55511b5` |
| `前端代码/admin/src/main.js` | `eb63418892c66f008bb4f1638e02b565ed64f1e3833475d1e7dd519edd3a945b` |
| `前端代码/admin/src/pages/setting/moheAi/feedback.vue` | `6b5a0cb229ce6fce59f1f44df4d647e48d11e4e16105fbef93f5343bd3c4c026` |
| `前端代码/admin/src/router/index.js` | `7c9d6a93dd27802406e4ef801727fe25facfb990279a363209665efd19f37c55` |
| `前端代码/admin/src/router/modules/setting.js` | `db53a3c9c0a5fd78492e3fafb4299fde04307b4e4a4f9683b970870cbe8d80ee` |
| `前端代码/admin/src/store/modules/admin/modules/menu.js` | `27da2e581398ddca81d18404704b2f539acf1088fde5f21e24ecec5f088ba990` |
| `前端代码/admin/src/store/modules/admin/modules/menus.js` | `1a323b1838e500218075d6f05e1a77ace6eae917961f6b012332cfd7d8c8dbb9` |
| `前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue` | `088182176bfc37eda1fbaa06df4957c36d9c3e2331970892b30769c07da39895` |
| `前端代码/shared/mohe-ai/browser-entry.mjs` | `3e87613216ee82991f96d22ad84790fdf49af1490db8ae110f0ecfde1fba5611` |
| `前端代码/shared/mohe-ai/device-session.mjs` | `fb36827fbbc73d744fbe84e838853aa1684c27ac76e51c35ded9eec0825b3845` |
| `后端代码/app/controller/ai/AiHttpActions.php` | `726769d3079b14f8046235b872c07edd7b0c44b5464399f71024c08ae0be7af5` |
| `后端代码/app/services/ai/AiGatewayServices.php` | `d8cef61d89fc56079e3dfe6884571df2c355d2ade7647a74e0ac0545fad37874` |
| `后端代码/app/services/ai/execution/AiRunStore.php` | `fc91c3e982605e51e21fe9bc25ba0a7547b953f9ca83d7d0cf1a4951214ca485` |
| `后端代码/app/services/ai/execution/AiRuntimeFactory.php` | `e7424a3733a9057bf7a147eefe63ef83403fcced54e19f2c0fedd92ff8252132` |
| `后端代码/app/services/ai/feedback/AiFeedbackStore.php` | `dee677a4dd113d3a4f3a81eea6897bad1b7fc2734ff4284010cf3bff01b84c7d` |
| `后端代码/app/services/ai/management/AiManagementMenuPolicy.php` | `d4d507d133b4b9017d3d4ee06df7fc5ea14ac1713c6bbef81cd14b06b69506b7` |
| `后端代码/database/upgrades/2026-10-12-魔核AI回答评价/00-升级清单.md` | `591978a675642d8cde932891c9a27bdf029f330856b21ab7cd7c810f2d1dca7b` |
| `后端代码/database/upgrades/2026-10-12-魔核AI回答评价/01-正式升级.sql` | `612934355eb3c3365c308afabbee6294c1e160edfb41fd3df6ab9a14de0ea518` |
| `后端代码/route/a-ai.php` | `e74b77882b67f35be3a9ff5fdcf6335b4bdf413a19e23385ea8bfe203bc559cc` |

- 文件清单指纹 SHA-256（按路径排序，以 `路径<TAB>文件 SHA-256<LF>` 拼接）：`089d1e88520fc035c926ee6cd3004419f7eebf2c701fa20d2f2efbd03503c152`

## 分组

- 前端源码：平台采集页与导航、普通页面登录态菜单刷新、窄屏侧栏入口、网页 AI、商家端 AI。
- 后端源码：评价 API、所有权核验、实例内存储、菜单投影。
- 数据库迁移：`2026-10-12-魔核AI回答评价/`。
- 自动化测试：反馈存储、状态存储、菜单、路由和浏览器契约。
- 工程脚本：无。
- 生成物：平台 `dist/`，仅本地构建产物，不作为源码交付。
- 验收证据：本文件与本地采集页面。
