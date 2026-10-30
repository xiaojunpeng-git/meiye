# 第97轮问卷与调查任务：本地固定候选 v2

- 源码正本：`美容源码/`；基线 HEAD `6e959c83249d1d9358a24b071649f619f63a78b3`。本轮只做本地开发，线上实例版本、备份与回滚点不适用。
- 固定候选业务文件清单按下表及前一候选剩余文件的 SHA-256 顺序拼接后，整体指纹为 `635b50b9ca013c0fc8aba56a31cc0c8e432056a181eb88af29d5c04cf8b1c912`。
- 前一固定候选 `fixed-candidate-2026-10-30.md` 已被本候选替代，尚未取得产品经理验收。前一候选的会员归属门店与项目/产品奖励逻辑保留。
- 本次追加：问卷管理移除重复的“赠送设置”；仅调查任务草稿可“赠送设置”，发布时冻结该任务奖励。旧问卷模板记录不删除，后续任务不再继承；已发布任务继续读取自身原奖励快照。
- 调查任务列表新增“正常/停止”单选筛选，默认正常；正常含草稿和进行中，停止含已停止。后端统一计算命中总数与当前页，切换筛选回首页。
- 操作区分两行，按钮增加间距和边框样式。复制生成独立草稿，保留问卷、筛选条件及可迁移奖励配置，不复用答卷、目标名单或二维码；旧固定门店奖励须转换为会员归属门店口径，无法唯一转换则拒绝复制。已过期截止日不复制。
- 删除按钮对进行中任务禁用。后端只允许删除草稿及已停止、无答卷、无奖励、无已完成目标记录的任务；事务中删除任务自身、未完成目标名单、弹窗记录和任务奖励配置，已有答卷/奖励一律保留并拒绝删除。用户已确认此历史保护范围。
- 无数据库迁移、无历史数据改写。平台 `dist` 为生成物，不计入源码。

## 文件指纹（SHA-256）

| 分组 | 文件 | 指纹 |
| --- | --- | --- |
| 前端 | `前端代码/admin/src/pages/user/survey/index.vue` | `aef17554041b5c4e07aa1b793aa27b881f77e0da17ee9668c344c75685096c8e` |
| 前端 | `前端代码/admin/src/api/memberSurvey.js` | `0dd901149083122f121ccf9968c0fc161d0cd430aacfe5584306ca77e63fffa8` |
| 前端 | `前端代码/admin/src/components/memberSurveyReward/index.vue` | `ebce7dcdc4e156c5600a9911776b4f2d7ba049a1a1318fd23a1ab0eaeb8ee911` |
| 后端 | `后端代码/app/services/user/MemberSurveyServices.php` | `ddf36b86211f30ea54c9a2a4411c8342a5710ad8ad3df51abdc62f4d048335c6` |
| 后端 | `后端代码/app/services/user/MemberSurveyGiftServices.php` | `3e6ecd4338820ed1347ae6fde5e2d5d71842f0d693ad2a24a326f4ad0094fcb7` |
| 后端 | `后端代码/app/services/user/MemberSurveyRewardConfigurationServices.php` | `fcac338a292da6de85ddf9b8176c396cbbb5b0af13f7b3cbe74f644551843b5b` |
| 后端 | `后端代码/app/controller/admin/v1/user/MemberSurvey.php` | `81b51d4c17ffaea1cb32d79105e2c8889cd32d09b6745276fc7fc13445f9ac8e` |
| 后端路由 | `后端代码/route/admin.php` | `97fd76c21960e651eeb7ab66ff290b69e2ef23f29f1e1def6eed5805f49b7212` |
| 迁移正本注释 | `后端代码/database/upgrades/2026-10-29-会员调查/01-schema.sql` | `b59fa459876df37fb2d840852404dd999fc17dfc7610a63033b2e7aa50177bbe` |
| 测试 | `tests/member-survey/php/local-business.php` | `720aedfd454141e49d12147f5f128196bd5a96a019584f0bea943b049cb0d101` |
| 测试 | `tests/member-survey/js/browser.cjs` | `a4ee1fd9dfdf36062c08997ccde6dc04b905479e73f5324dbd74381aa78ba5e8` |

前一候选其余文件指纹见原记录。`后端代码/route/admin.php` 已有其他任务的暂存和未暂存差异；提交本轮时只能隔离相应路由增删，不得整文件暂存。共享工作区其他改动均不属于本轮。

## 自测与限制

- PHP 7.4 隔离真实数据库检查 28 项通过：总部权限、复制草稿和奖励配置、旧问卷模板不继承、正常/停止计数、进行中禁止删除、无记录草稿/停止任务删除、有答卷停止任务拒删、公开二维码和答卷基本回归。测试账户仅可读取 schema-only 来源库并写入随机临时库；临时库及账户清理后均为零。
- Vue 2 真实组件浏览器检查 27 项通过，含默认正常、两行操作、进行中禁删、切换停止回首页，以及上一候选的扫码、会员端选择门店与奖励交互。证据见同目录 `browser.json` 等。
- 平台构建通过；PHP 语法检查和 `git diff --check` 通过。完整旧业务测试仍有既有断言及缺失 `scripts/member-survey/install.php`，未记为完整回归通过。
- 新增/更新注释位于任务列表/复制/删除服务、控制器、奖励配置冻结、隔离测试及迁移说明。业务代码、测试、证据分组列示如上；无新增迁移语句或工程发布脚本。

当前状态：本地开发及针对性自测完成，待产品经理对本固定候选确认验收；未提交、未部署、未 push。
