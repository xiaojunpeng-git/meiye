# R52 手机端输入框“+”菜单：本地自测

- 基线 HEAD：`b356b81cbe5490c565a7acbfad4bd0b5c98fe561`。
- 前端源码：`前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue`（SHA-256 `2f7723d3c44bad3adf5ca71cd0ec37fa28e481219db52969d1ff149bb345dccd`）。
- 自动化测试：`tests/mohe-ai/browser-mobile-guidance-contract.mjs`（SHA-256 `665e583d7ca470186b295f83230cb003b0a86dc059e7fee3e0cca9e06b2eb785`）。两文件补丁 SHA-256：`ed87e0b6cbcf54d7135d3751309f3f7e657af11b7f9e55246585882dcc0109c4`。
- 后端源码、数据库迁移、工程脚本：无。生成物在忽略的 `unpackage/dist/dev/mp-weixin/`，不是源码。未改动工作区其他任务的脏文件。

实现边界：手机端加号展开输入框上方单列菜单，依次为“生成Execl、 新增对话、清空对话、历史对话”。Excel 仍是下次提问时的导出选项，不会在点菜单时单独查询。清空前用原生确认框确认，只删除当前本机会话；新增对话保留旧记录。历史列表只读当前身份 24 小时内的本机会话，查询进行中或入站结果未确认时不能切换/清空。保留完成任务的本地保护记录，防止旧页面迟到回调污染新会话。

自测：

1. `browser-mobile-guidance-contract.mjs` 通过，包含 R52 单列布局、四项菜单、Excel 开关无额外查询、忙时禁切换、清空取消/确认、其他历史保留。
2. `mobile-login-wait-contract.mjs` 10 个场景通过；`git diff --check` 通过。
3. HBuilderX 5.26 `launch mp-weixin` 编译成功；生成的 WXML 保留四个按钮，WXSS 的 `.ai-composer-options__actions` 为 `flex-direction:column`。
4. 全量 `npm run check` 未通过：当前工作区其他移动模块的契约/源码缺失或断言不符（48 项中 26 通过、22 失败），本轮未修改这些文件。旧版 `mobile-ai-entry-transport-contract.php` 仍要求已移除的顶部历史抽屉，也不适用于当前已验收的单行标题布局。

尚未完成：微信开发者工具模拟器启动报错“AppID 不存在”（code 10）；生成的 `project.config.json` 为 `touristappid`，而当前已有脏改动的 `manifest.json` 的 `mp-weixin.appid` 是 `null`。为不夹带其他任务配置，本轮未修改 AppID，未声称模拟器或实体手机点按验收通过。待配置归属任务修正后，需检查加号展开、单列可读、四项操作、历史切换及清空确认。产品经理尚未对本固定版本确认验收；未 commit、部署、push 或上传体验版。
