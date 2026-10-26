# R45 电脑输入框小调整

- 需求：电脑端删除加号，Execl靠左；手机不改。
- 基线HEAD：59bdfbd3。前端源码仅修改shared/mohe-ai/browser-entry.mjs，移除加号节点、点击事件、图标数据及无用样式；改为两列布局，Execl与输入文字同为22px左内边距。保留窄屏18px对齐，发送仍在右侧。
- 已更新关键约束注释；后端、数据库、工程脚本与小程序源码均未改。
- 测试：browser-entry-contract.mjs通过，新增无加号、选项直接可见及左对齐布局断言；移动168项及R45展示回归通过；git diff --check通过。
- 本地18081重新编译后浏览器核验：无加号，Execl在左下方与输入文字对齐，发送位于右侧。截图R45-desktop-no-plus-2026-09-27.png。
- 固定SHA-256：browser-entry.mjs为0be0561dae455891fa8ed02a44b6ce6fde26ec2351443fce1877018c5de9080d；browser-entry-contract.mjs为f4dbbf45b42923841a080f8f96049c868f21f438aee4eca1228da866f4110387。
- 状态：开发与本地自测完成，待产品经理确认。未commit、部署或push；线上保持上一版本。
