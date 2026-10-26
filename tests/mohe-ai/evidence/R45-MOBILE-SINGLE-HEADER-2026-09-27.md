# R45 手机顶部单行导航

- 范围：手机移除X与新增整行，标题魔核AI 1.0屏幕居中，左侧返回形退出按钮；电脑及底部输入区不改。
- 前端：mobile-vue3/src/shared/components/mohe-ai-entry.uvue。对称留白避让原生胶囊，退出沿用closePanel，未改变会话与任务状态。补充导航和底部加号边界注释。
- 删除：全源码引用扫描后移除不再使用的static/mohe-ai/addto.svg、guanbi5.svg及操作行专用样式；保留仍用于底部加号的线条样式和初始化会话函数。SVG可从Git恢复。
- 测试：browser-mobile-guidance-contract.mjs新增导航断言；原168项及R45测试PASS，diff检查PASS。HBuilderX于00:34:12完成微信编译，已有span选择器警告保持原状。
- 模拟器：使用已授权账号登录，打开AI，确认居中标题与原生胶囊同排，无X/+行；点击左侧退出回到商家主页。使用已有问答回放，没有新建业务查询。截图R45-mobile-single-header-2026-09-27.png；不冒充物理手机测试。
- 前端SHA-256：e352502acd711b16fb68e96d5c5a89b1ba9ad56e82d3472dc9c15c794931273e。测试SHA-256：614f72948abe0f0e90f19667e64fa5ca0564076c4f2dfabf76ed458dfac98e93。基线为51494919。
- 后端、数据库迁移、工程脚本：无。生成物仅本地开发包；生成的project.config.json恢复既有appid供预览，不提交。证据为本文件与截图。
- 开发与本地自测完成，待产品经理确认。未commit、部署、push或上传体验版。
