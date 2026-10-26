# R45 双端视觉统一：开发与自测交付

日期：2026-09-27。基线 HEAD：ba7ebad8。状态：本地开发及已列自测通过，待产品经理确认验收；未提交、未部署、未上传体验版。

## 范围与分组

- 前端源码：`前端代码/shared/mohe-ai/browser-entry.mjs`；`前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue`；`前端代码/mobile-vue3/static/mohe-ai/addto.svg`、`guanbi5.svg`。
- 自动化测试：`tests/mohe-ai/browser-mobile-guidance-contract.mjs`。
- 后端源码、数据库迁移、工程脚本：无本轮改动。
- 生成物：HBuilderX 本地微信开发包，不纳入源码。生成的 project.config.json 恢复源码已配置的正式 appid 以便模拟器登录；未修改业务配置，未上传。
- 验收证据：本文件、同目录 `R45-mobile-style-2026-09-27.png`、`R45-desktop-style-2026-09-27.png`，根目录 design-qa.md 本轮追加记录。

## 完成内容

1. 手机标题为“魔核AI 1.0”，左对齐，与原生胶囊同排；下一行仅关闭、新增，采用已有阿里矢量字形。移除手机历史按钮及无调用抽屉代码，但保留会话存储、既有测试记录。
2. 双端采用浅色卡片、统一圆角边框和蓝色金额；手机按服务端分组展示两列卡片，窄屏单列；桌面保持可用宽度自适应。
3. 桌面输入区增加左侧加号控制 Execl 选项，右侧发送。保留 Execl 独立任务逻辑，没有增加模型调用或业务查询。
4. 修复手机旧会话 answer.presentation 嵌套结构未取出、导致有卡片数据却只展示长摘要的问题，兼容原平铺结构；相同终态失败信息不再重复展示。
5. 原有表格、排行、对比、详情类型未替换成固定经营概览；不改金额计算、查询范围、权限或数据口径。

## 自测事实

- browser-entry-contract.mjs：PASS。
- browser-mobile-guidance-contract.mjs：原 168 项 PASS；本轮嵌套结构、分组及无新增请求检查 PASS。
- git diff --check：PASS。
- HBuilderX 微信小程序编译：2026-09-26 23:50:34 成功；已有 span 选择器告警未在本轮扩散处理。
- 使用用户授权账号登录微信开发者工具的商家小程序，打开已有问答；截图确认标题与胶囊同排、无历史按钮、关闭/新增图标可见、卡片金额与原答案一致、底部加号/发送可见。
- 桌面本地 18081 重新编译后打开工作台，验证新标题、历史侧栏、表格结果、加号展开/收起 Execl。截图保存。
- 上述展示使用已有问答回放，不是新问答性能测试；截图中的 19/27 秒来自历史记录，不能视为本轮速度结果。

## 删除及注释

删除移动历史抽屉模板、专用状态/事件/样式及不再使用的分组标题辅助函数；保留存储和仍调用逻辑。此前本轮未提交 history.svg 随无引用入口移除。新增/更新注释位于两个前端主文件及测试文件，说明展示契约、胶囊安全区域和不触发新查询边界。

## 固定版本

以下 SHA-256 与基线共同标识交付，验收后如再改需补验：

| 文件 | SHA-256 |
| --- | --- |
| browser-entry.mjs | 3dd3be1966c59a3ab6996dd4f7b186ee05cbf8584a2746f16cac6cdf04e1ff8c |
| mohe-ai-entry.uvue | 47ad5fb44c5cd59870a6102a6e3bc898466f08c0d37fe5418835dec78b281b2a |
| browser-mobile-guidance-contract.mjs | 62b31bcd4f96b620609ed60613d6ba4859f533d89660f0da33b287fa2371157c |
| addto.svg | 0206c56d57cea986e7df8ba9758482b657a28a7f77cd288b13cea8dcb425fcbd |
| guanbi5.svg | 9e01a35270c5b76bf22ce1ca06f2dd590a2481d48251d4ec19219a7ac5414d74 |

三个已跟踪文件 git diff 补丁 SHA-256：cee450f4bce4487ada1c852e5a54d15ad3f48ec50fc45d7436c7644b04130a43。新增 SVG 以各自指纹覆盖。

## 限制

模拟器操作不冒充物理手机验收。真实手机键盘弹起、全部小屏尺寸及 Excel 文件下载端到端未在本轮重新验证。整体视觉 QA 尚未完成所有状态对照，详见 design-qa.md。工作区其他任务的改动均保留，不纳入本轮。无远程部署、迁移、备份操作；生产实例检查不适用。登录信息未写入记录。
