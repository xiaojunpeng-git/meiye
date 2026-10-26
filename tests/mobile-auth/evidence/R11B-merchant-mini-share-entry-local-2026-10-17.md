# R11B 商家小程序分享入口本地验收候选

- 固定基线：`2ed1714a1b1f9495f3f2b2c3aeb4146990ad8daf`。本轮仅修改登录页与经营首页的微信分享生命周期。工作区另有未验收的 `pages.json`、`manifest.json` 等改动；通过 `git archive HEAD` 加本轮两个文件的一次性目录 `/private/tmp/r11b-mini-share-20261017` 编译，未带入它们。
- 原因：R11A 上传的瑞昊与渼约 008 两包中，登录页和经营首页都没有 `onShareAppMessage`、`onShareTimeline`，微信右上角菜单显示“当前页面不可转发／不可分享”，与产品经理提供的两张真机截图一致。
- 本轮行为：两个页面在微信小程序中可转发给朋友、分享到朋友圈；朋友转发卡片固定跳转 `/src/app/pages/bootstrap/index`，分享标题为“商家端登录”。不分享经营数值、客户身份、账号输入、登录态或页面查询参数。朋友圈卡片不传查询参数；应用启动入口仍按既有逻辑进入商家登录页。
- 源码 SHA-256：`src/app/pages/bootstrap/index.uvue` 为 `352ce3019582f5e0479643c61384c22e70d0c3d35aa856525415774b47f2cd84`；`src/merchant/pages/home/index.uvue` 为 `7b8581e2da3905bd1beb082585fa8fde9ba30bc76803b4d2c2bc792a62f5f917`。相对基线的二文件补丁 SHA-256：`178cf8c6a36ba3cfb69eb532034b8791ae054e0c4a7f69192f5ea5706cfc821e`。
- 自测：`git diff --check` 通过；商家微信手机号登录合同测试通过；HBuilderX 5.26 小程序 20 页编译成功。生成的登录页与经营首页 JS 均含 `onShareAppMessage` 和 `onShareTimeline`，前者的 `path` 固定为登录入口。
- 实际操作：微信开发者工具打开本地编译包，在登录页点击右上角菜单，菜单显示“发送给朋友”“分享到朋友圈”；未实际发送给任何人。经营首页因无测试账号未在模拟器内进入，已检查生成代码注册了相同生命周期。
- 本次仅本地开发和自测；未取得此候选的产品经理验收，未 commit、push、上传两套小程序或修改服务器。
