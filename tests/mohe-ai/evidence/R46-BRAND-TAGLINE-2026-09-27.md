# R46 双端品牌标题小字

- 用户要求魔核AI标题下加小字“够智能、够准确、够便捷”。电脑端左侧品牌下方12px灰字；手机端在原标题行下独立居中12px，不改变胶囊避让、退出触控区及空白输入框。仅展示文案，不进入模型上下文。
- 前端源码：shared/mohe-ai/browser-entry.mjs、mobile-vue3/src/shared/components/mohe-ai-entry.uvue，均补充边界注释。自动化测试：browser-mobile-guidance-contract.mjs增加双端标语断言。后端、迁移、脚本无修改。
- 移动控制器168项及R45/标语/空输入断言通过，diff检查通过。HBuilderX微信编译成功，产物包含标语，保留既有span警告；派生project.config恢复原AppID和压缩设置，源manifest未改。
- 本地admin开发服务首次重启退出139，重新启动后编译成功。实际新标签打开工作台，标题下小字可见，输入框仍空白；截图R46-brand-tagline-2026-09-27.png。
- 小程序模拟器停在登录界面，操作中收到用户切换应用提示后未继续操作；本次手机仅编译和契约验证，不冒充模拟器/真机视觉验收。
- 基线22afd836a68cacfe3f80e9119bb118b44b6b3385。SHA256：browser-entry.mjs `d02c73f35498edab5d3e2109a61f8e507d43dc8f20c8e2ef8f05557d8e3838ba`；mohe-ai-entry.uvue `c0a483ba3e14fb563236517093b42937c75147a7316eea4de731053045fe09e7`；测试 `b6c0953b06721584da4c390b7dbae95a22764f846f739398caa45365b0a7b6d2`。
- 产品经理已在收到上述固定版本交付后明确要求“commit,部署瑞昊,push”，本范围验收确认并授权发布。发布结果另记；未授权本次上传体验版。历史证据和其他任务改动保留。
