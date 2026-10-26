# R47 手机输入框增高修复（真机结果待验证）

- 范围仅魔核AI手机输入框；电脑端、业务查询、数据库、接口、脚本无修改。产品经理收到固定版本及真机验证限制后，明确要求“commit,部署瑞昊,push,上传体验版”；按该指令提交并交付体验版本，不将发布批准记作真机测试已通过。
- 前端源码：mobile-vue3/src/shared/components/mohe-ai-entry.uvue。原生小程序声明fixed，关闭auto-height竞争路径，以真实linechange行数驱动显式高度，28px行高加10px内边距，38至144px限幅；上限后由原生textarea内部滚动。不按字符数估计，不读取可能尚未更新的v-model来判定行数。
- 展开布局保持54px水平内边距，避免展开变宽导致行数减少、反复收起。删除回一行时回缩；清空、提交立即复位。按钮位置及输入内容不变。补充职责、事件顺序、样式约束注释，删除旧auto-height依赖及旧行数判定逻辑，没有平行兼容实现。
- 自动化测试：tests/mohe-ai/browser-mobile-guidance-contract.mjs执行真实控制器。175项PASS，覆盖行数事件先于v-model、2/3/20行、回一行、异常行数、清空与提交复位，原问答回归通过。git diff --check通过。
- HBuilderX5.26微信编译10:26:07成功，产物textarea绑定显式高度及原生fixed；保留既有span选择器警告。只恢复派生project.config发布元数据，不改源manifest或生成业务代码。不涉及远程实例、迁移、服务重启。
- 固定源码SHA256 a0a87b5578a5d275d89599625a17f38a38cbab31a5fa3a0782b381d94460d7ce；测试SHA256 e1d858b6e04ec327b90351b5939f71bed738fd60e66cdaea6afaed41dda242b1。
- 未连接实体手机，不能宣称真机问题已验收。待验证：iOS/Android中文连续输入、粘贴长段、手动换行、超过上限后光标及内部滚动、逐字删除回一行、清空和发送复位、键盘开合时按钮可用。用户反馈的原句必须包含在真机测试中。
