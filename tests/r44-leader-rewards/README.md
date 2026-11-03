# 第44轮团长成团奖励回归

本组测试覆盖团长奖励业务适配器、复用赠送目录及后台配置组件。奖励资格来自拼团成团事实，项目和券仍通过统一赠送领域发放或撤销，不生成销售、支付或人员业绩。

## 本地真实业务回归

先由本轮升级流程执行 `2026-11-02-r44-leader-rewards/upgrade.sql`。测试只允许本地 `ruihao` 数据库且奖励回执表为空；所有模拟会员、活动、团、权益和回执均位于外层事务，结束回滚，不请求真实支付或发送通知。

在源码正本根目录运行：

```sh
docker run --rm --entrypoint php --network mohe-net --volumes-from mohe-app -v "$PWD/tests:/tests:ro" mohe-app:local /tests/r44-leader-rewards/local-business.php
```

覆盖保存刷新、开团冻结、真实成团、虚拟补齐不发、重复回调幂等、失败重试、普通团员退款、团长全退、漏回调补撤销、部分奖励已用、预约占用、取消、队列公平性、原团长退款接任、数量校验、配置门店防伪和匿名访问拒绝。接任用例调用现有 `StorePinkServices::setRefundPink`，再由新团长真实成团，验证原/新回执不会双发。共享事实表计数证明奖励不写销售、支付或人员业绩。

2026-11-03 本地执行通过，共 38 个业务断言，夹具已回滚。

## 组件与语法回归

```sh
node tests/r44-leader-rewards/ui-contract.cjs
```

覆盖调查奖励原读写接口、每轮赠送及实物选品不回退；拼团草稿隔离、项目/券限制、共享目录分页，以及三个 Vue 文件的模板和脚本编译。共 15 项通过，另覆盖真实 getInfo 方法在空错误、Error 和业务错误下均显示有效提示并结束加载。相关 8 个 PHP 文件 `php -l` 通过。

上述合同和真实领域回归不代替浏览器页面验收、外部支付渠道验收或产品经理最终验收；未做 commit、push 或远程部署。

## 修改文件归属

- 前端：`admin/src/components/memberSurveyReward/index.vue`、`admin/src/pages/marketing/storeCombination/create.vue`、`admin/src/pages/marketing/storeCombination/combinaList.vue`。
- 后端：`CombinationLeaderRewardServices.php`、`MemberRewardCatalogServices.php`、`MemberSurveyGiftServices.php`、`StoreCombinationServices.php`、后台 `StoreCombination.php`、命令 `CombinationLeaderRewards.php`；`StorePinkServices.php` 只新增接任事务回调，本轮其他成团和开团回调由主任务集成。
- 迁移：`后端代码/database/upgrades/2026-11-02-r44-leader-rewards/upgrade.sql`。
- 测试：当前目录中的业务回归及组件合同；`baseline.json` 为修改前相关源码指纹。
- 注释：上述业务文件同步说明开团冻结、真实成团、身份、权益撤销、队列重试及调查兼容边界；没有复制另一套赠送实现。

## 正常页面保存与精确清理

本地原活动 3 对应商品 8354 缺少规格记录，原 getInfo 会失败；没有修改两行既有业务数据。通过 `ui-fixture.php` 创建显式 R44 虚构、关闭活动 476，配置项目 SKU 609834 与券 97 各 1 份、有效 30 天。主任务在浏览器开启奖励、提交成功并重新打开核对；getInfo 与数据库均回读一致。随后按所有权清单精确删除活动、商品、规格、券、奖励配置及该 SKU 的 Redis 库存键；订单、拼团、奖励与领券计数均为 0。原活动 3 / 商品 8354 联合 SHA-256 前后保持 `c48799afc4b8c81638de49494565e8596ce45c632b2ff9e473c34fa28cfdcdde`。

回读和清理证据位于 `evidence/ui-readback.json`、`ui-db-proof.json`、`ui-cleanup.json` 与 `ui-fixture-cleanup-verified.json`。完整负责范围、共享文件边界、源码/迁移/测试指纹和会员 H5/小程序构建证据见 `evidence/leader-share-final-manifest.json`；会员分享与登录链 47 项方法执行、43 项真实创建事务用例另有记录。
