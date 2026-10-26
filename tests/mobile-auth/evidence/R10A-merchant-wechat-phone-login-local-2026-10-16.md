# R10A 商家端微信手机号登录与账号密码回退：本地自测

## 固定版本

- 基线 HEAD：`4e1b25ebcf4862480bbf143c60da32061cf5e2c9`。
- R10A 补丁 SHA-256：`03c70272cdfa2553bbb14ea6b82be512532dc1bda87a2515a1d21df76117545b`。该指纹只包含下列源码与测试的 R10A 差异；`api-mobile.php` 中另一任务尚未提交的目标方案路由不纳入补丁，R10A 路由差异指纹为 `2cc1a87b44a7784fb74d4c6b89934d2c21cb75d9b7de2e5e23ed4b217e2a05ca`。
- 当前状态：开发与自测完成，待产品经理确认验收；未 commit、部署、push 或上传体验版。

## 业务与技术结果

- 微信小程序首次进入商家端登录时展示微信手机号授权入口，同时提供“使用账号密码登录”。拒绝授权，或服务端验证后无法登录（包括手机号无唯一有效员工匹配、员工停用或未开通手机端）时，同页切换为账号密码登录视图；该视图仍保留微信授权按钮以便重试。现有账号密码登录路径继续使用原接口。
- 客户端只上传微信返回的一次性 `phoneCode`，服务端以当前客户实例的小程序配置向微信换取手机号；只接受中国大陆格式手机号，匹配未删除且唯一、启用的员工记录，并复用现有手机端授权、岗位权限、组织上下文、会话租约和数据范围校验。手机号或凭证不写入新增审计载荷。匹配失败不猜选员工，不创建商家会话。
- 权威身份为当前实例员工档案 `employee.phone`；权限仍取服务端既有 `employee_mobile_auth` 等数据。无数据库迁移、无新权限授予。
- 业务代码注释已覆盖一次性凭证交换、身份唯一性、会话复核、客户端根状态安装及回退边界。

## 自测

- `php tests/mobile-auth/php/wechat-phone-login-contract.php`：通过，覆盖微信响应、AppID 水印、境外号码、无匹配、重复和停用员工，以及拒绝／失败回退和两个入口并存。
- 新增／修改 PHP 文件宿主 `php -l`：通过；`mohe-app` PHP 7.4 对微信手机号服务与商家会话服务语法检查：通过。合同 JSON 格式和限定文件 `git diff --check`：通过。
- HBuilderX 5.26 从当前工作区编译微信小程序成功，控制台显示 `ready in 7818ms`；生成 `src/app/pages/bootstrap/index.wxml` 含 `open-type="getPhoneNumber"`、账号密码条件视图，生成 JS 含拒绝和失败时的 `choosePasswordLogin`。另以 Vue SFC 编译器解析并编译该页面模板：通过。
- 本地接口对空授权码和非微信平台请求分别拒绝，未触发手机号兑换或商家会话创建。

## 验证边界

- 未使用真实微信一次性手机号凭证，故微信授权成功后的端到端登录仍需在小程序开发者工具或体验版中用授权手机号验证。此次编译使用当前工作区，其中存在其他任务的未提交改动；生成物只用于语法和视图契约检查，不作为发布包。
- 现有 `tests/mobile-vue3/js/contracts.test.mjs` 有五项因旧的 `src/merchant/contracts/mobile-merchant-v1.contract.json` 路径已迁移而失败；与本次登录路径无关，未将其当作 R10A 通过项。
- 工作区存在大量其他任务改动。将来获得验收后，仅按下列 R10A 文件逐项暂存；`api-mobile.php` 只暂存两处 R10A 路由差异。

## 文件清单与 SHA-256

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/mobile-vue3/src/app/pages/bootstrap/index.uvue` | `fbc145e5384d96987cca35097e7c55e27186400283efd532fe0544f6813afbaf` |
| 前端源码 | `前端代码/mobile-vue3/src/shared/api/mobile-login-client.uts` | `90b8cfc1f5f5d4884829c36a5e3821e888a47570adbbe3e38cbe4129fc34836a` |
| 前端契约 | `前端代码/mobile-vue3/src/shared/contracts/mobile-merchant-v1.contract.json` | `c3cc591078e0c384b7f8484afa8efc67c777125bfd1a41bd7b6dddd00d3880df` |
| 后端源码 | `后端代码/app/controller/mobile/merchant/MerchantSession.php` | `db41a149e381d1b43169a6557faeaf3b71c32734b01ca7e530f8b03123d0df08` |
| 后端源码 | `后端代码/app/http/middleware/mobile/MobileMerchantWechatPhoneMiddleware.php` | `9b85c0c1729df108321c436653e4d02eefd992d9cf870e139f45ef245cce3db6` |
| 后端源码 | `后端代码/app/services/mobile/merchant/MobileMerchantSessionServices.php` | `873827ac62b17376840dd9b5d486e06b50860aeb5a304be380f43eebbd29f2c2` |
| 后端源码 | `后端代码/app/services/mobile/merchant/MobileMerchantWechatPhoneServices.php` | `274031718581a1abb14b036724b85c96f9c68a93f6a63d679f99a0fe0bd4f7c3` |
| 后端源码 | `后端代码/app/services/mobile/protocol/MobileRequestMetadata.php` | `b1067d676e39e67714336a7cabeb7db9975c43a5420e8dcd3ed040c69` |
| 后端源码 | `后端代码/route/api-mobile.php` | `3c7a53203286ccf32f69ea5126524199e425cae9d8e9cced7ec8425b0ba5b24a`；整文件含其他任务未提交差异，R10A 路由差异指纹见上 |
| 自动化测试 | `tests/mobile-auth/php/wechat-phone-login-contract.php` | `6e59b2cc9b49961f5436426a099cab6ec35453c33bb54e3dcd13eb10902fd96c` |

数据库迁移、工程脚本：无。生成物：仅工作区忽略的 `unpackage/dist/dev/mp-weixin`，不提交。验收证据：本文，不混入业务源码。
