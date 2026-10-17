# R11A 商家端登录按钮顺序与文案：本地自测

## 固定版本

- 基线 HEAD：`4d2ba6379f9e431e266b130913106900d1e3eb35`。
- 闭环文件：`前端代码/mobile-vue3/src/app/pages/bootstrap/index.uvue`、`tests/mobile-auth/php/wechat-phone-login-contract.php`。
- 两文件补丁 SHA-256：`1d315ad96fbe8c99c04cb49765d4a9df1ebee491ee5f23db6c5a5b5c305b5d6c`。
- 当前状态：开发与自测完成，待产品经理确认固定版本验收；未提交、部署、push 或上传小程序。

## 结果与防回退

- 小程序登录页直接显示账号密码输入、“账号登录”按钮，微信授权手机号按钮位于其下方。拒绝微信授权或服务端未通过时，仍留在该表单并显示失败原因；可立即改用账号密码或再次微信授权。
- 移除不再需要的 `passwordLoginVisible`、`choosePasswordLogin`、入口切换控件及样式。仅调整登录页面；微信一次性凭证、后端手机号匹配、员工手机端授权、会话和数据权限逻辑均沿用已验收实现。
- 本次未改变权威员工数据、权限或业务事实，无数据库迁移、后端源码或工程脚本改动。已上线 R9D 服务项目双指标排行与 R9B 手机 AI 入口仍包含在基线构建中。

## 自测证据

- `php tests/mobile-auth/php/wechat-phone-login-contract.php`：PASS。覆盖登录入口并存、账号按钮在微信按钮之前、拒绝/失败时账号表单持续可用，以及既有手机号身份边界。
- `git diff --check`：PASS；全路径引用扫描显示旧切换状态和样式已从登录页删除。
- 从上述 HEAD 的一次性 `git archive` 构建源，仅放入本闭环登录页，HBuilderX 5.26 编译微信小程序成功（20 页）。生成登录页 WXML 的输入与按钮顺序为账号、密码、账号登录、`open-type="getPhoneNumber"`；生成 JS 含“账号登录”“微信授权手机号登录”和拒绝授权后的账号提示，旧按钮文案不存在。
- 微信开发者工具实际打开隔离候选包，在 iPhone 12/13 (Pro) 模拟器中目视确认两个按钮顺序、文案及完整可见性。该操作未提交手机号授权码、未创建真实登录会话。
- 生成登录页 WXML SHA-256：`0ff6f1399c199c8558fe3accb274ea268ac7d3e5dfa3030d63b3fbba01771551`；JS SHA-256：`ab29201e3edd238be03557ee80c9f53f207018a96906cce9e07910309e2556d5`。生成物位于 `/private/tmp/r11-login-layout-4d2ba637-20261017/`，不作为源码或提交对象。

## 文件与注释

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/mobile-vue3/src/app/pages/bootstrap/index.uvue` | `43ad4d69e6129d1c23724fd43ba31d9728bc56be549d41a821c7d2d33fc2f69c` |
| 自动化测试 | `tests/mobile-auth/php/wechat-phone-login-contract.php` | `2e9da36552b396950d1e6a95978146c6c9ad286f1b11674b440d783a24ca451c` |

业务注释已在登录页同步更新，说明账号表单常驻是为了在微信拒绝或身份未通过时保留安全回退，以及微信按钮仍只使用一次性授权码。验收证据为本文；生成物不纳入源码文件清单。
