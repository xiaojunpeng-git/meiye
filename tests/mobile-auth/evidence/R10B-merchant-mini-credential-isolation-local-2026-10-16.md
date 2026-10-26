# R10B 商家小程序独立微信凭据：本地固定候选

## 背景与边界

- R10A 已验收并提交 `87e94057b91305254ece3b03744b3bcdfdafb4c5`；本轮按用户授权先后部署到瑞昊、push 至 Gitee 独立分支、部署到渼约008。两站后端六文件指纹、Swoole 状态和非微信平台失败路径通过；未上传新的小程序包。
- 瑞昊商家小程序和实例 `routine_appId` 均为 `wx29d9b63c92555701`。008 已上传商家小程序 AppID 为 `wx14666197911d052b`，但实例 `routine_appId` 为 `wx85d4bfd0b4b6aafb`，且配置库没有独立商家 AppID。因此 R10A 在 008 的微信手机号成功路径尚不可用；现有账号密码登录不受影响。
- 用户确认可取得 008 商家小程序的 AppSecret 并通过安全配置页录入。未在聊天、源码、测试或证据中写入任何真实 AppSecret。

## 固定源码

- 基线 HEAD：`87e94057b91305254ece3b03744b3bcdfdafb4c5`。
- 下列 8 文件合成补丁 SHA-256：`46a664fc8b3ec03d5209eb44bafa051a197e2d7b856053078536feb562f6a820`。

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/admin/src/pages/app/routine/download/index.vue` | `854f74cdaaf8396cfcd8c98730da8022a7a6f100fc4e1780788a03cee2cfe23f` |
| 后端源码 | `后端代码/app/controller/admin/v1/system/config/SystemConfig.php` | `eda9661d276ddfeacb51c9db46879ca86534f6ad84d4b07ea541761f7e93026b` |
| 后端源码 | `后端代码/app/services/system/config/SystemConfigServices.php` | `2998d8f02d1b9fd3947fcd5a1f952c7058ea257b2b3d6271f603da27a399158e` |
| 后端源码 | `后端代码/app/services/mobile/merchant/MobileMerchantWechatPhoneServices.php` | `6cebff2a8d5a2836e76b5f28d3ea39f19e9cb32327f40bd4c38540e6dcdd7963` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-16-商家小程序微信手机号配置/00-升级清单.md` | `285976aa0d49ac55dea6f149549f5b61ddfd16496a5845941d8721ff11c65c14` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-16-商家小程序微信手机号配置/01-正式升级.sql` | `c2860d7c449078233c035c4c4e194700ffb8fc93f9a8fb690f04d6cfe4a4aa28` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-16-商家小程序微信手机号配置/02-升级后验证.sql` | `1c40d390aaf32832b6fd555c7ac3d01de3c5f8e2f49dec3696e9141b2f3fac90` |
| 自动化测试 | `tests/mobile-auth/php/merchant-mini-config-contract.php` | `a840907d7e98a3a6f79bb7005b6977aac2011135580a17847addb759ac494e95` |

工程脚本、生成物：无。验收证据：本文，不纳入业务源码补丁。

## 改动与自测

- 独立商家 AppID/Secret 成对配置时只用于商家微信手机号兑换；若均未设置，瑞昊等同 AppID 实例维持 R10A 路径；只设置一项则拒绝兑换，不串用会员小程序的密钥。微信返回水印仍与实际兑换 AppID 核对。
- 现有“设置 → 小程序”增加商家端独立配置输入。商家 AppSecret 不回显，留空保留现值；改 AppID 时必须同时提交新 Secret。配置列表、单值接口及通用配置表单也不返回此密钥，单项编辑入口拒绝绕过成对校验。注释已补充在以上修改的 PHP 与 Vue 文件。
- 迁移只向各实例 `eb_system_config` 幂等新增两个空行；不覆盖已有 `routine_*` 配置，不跨客户复制凭据。
- `php tests/mobile-auth/php/merchant-mini-config-contract.php`、R10A 原合同测试通过；PHP 7.4 远程解释器仅通过 stdin 对 4 个候选 PHP 文件语法检查，没有写入线上文件；Vue 2 SFC 模板编译和脚本解析通过；`git diff --check` 通过。
- 使用一次性、无客户数据的本地 MySQL 5.7 容器运行迁移两次：两个新键各仅 1 行、与既有键同配置页，原 AppID/Secret 未改变；容器已清理。

## 待验收与上线前提

- R10B 尚未取得产品经理对本固定版本的验收，未 commit、push、迁移或部署。
- 生产数据库迁移和部署需要对点名实例单独授权与独立备份；008 管理员随后在安全配置页输入 `wx14666197911d052b` 的真实 AppSecret，不能使用 `wx85...` 的密钥。
- 真实微信一次性手机号授权成功登录仍需在对应商家小程序中验证；本地及线上安全失败路径不能替代该验证。R10B 不包含小程序上传。
