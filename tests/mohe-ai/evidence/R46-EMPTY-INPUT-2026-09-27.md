# R46 双端输入框取消默认提示

- 用户最新要求：双端输入框默认提示去掉，输入框放空。删除浏览器placeholder赋值、手机placeholder绑定及已无引用的questionHint函数。保留无障碍名称“输入问题”，不显示为提示、不修改用户输入或发送行为。
- 前端源码：shared/mohe-ai/browser-entry.mjs、mobile-vue3/src/shared/components/mohe-ai-entry.uvue；同步更新边界注释。自动化测试：browser-mobile-guidance-contract.mjs更新双端无提示断言。后端、迁移、工程脚本无修改。
- 移动控制器168项、R45及双端无提示断言通过；git diff --check通过。全路径扫描src/shared后questionHint零引用。HBuilderX微信编译成功，保留既有span选择器警告；只恢复派生project.config上传身份及压缩配置，不改源manifest，不上传体验版。
- 基线HEAD b006fea9e4ea2f10a85124c4dd3d8df03f522892。
- 文件SHA256：browser-entry.mjs `d650dfa624a1f7e15d3d98857a1144a68a0332b7d5dd0ffa69a7bf1fecfe14da`；mohe-ai-entry.uvue `7a61b5380caaf969613ce61b63228228fe08b0a54847443a56c55cf969f72588`；测试 `95eb03442afcc18aecc79e921e9178f965911e023f9e459ce0c518a379ca25ec`。
- 交付时未提交，待产品经理确认；产品经理随后明确要求commit，确认本固定版本。提交前核对三个源码/测试指纹一致，相关回归再次通过。本次仅本地提交，未部署、未push、未上传新体验版。
- 已重启本地18081开发编译服务并核对新脚本；浏览器实际打开工作台，输入框placeholder=null、value为空串，截图R46-empty-input-2026-09-27.png。测试页已保留；小程序仅编译验证，不冒充真机验证。
