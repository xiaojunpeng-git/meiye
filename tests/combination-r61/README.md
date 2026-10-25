# 第61轮拼团开发与本地自测记录

状态：开发与本地自测完成后交产品经理确认验收；未提交、未推送、未部署，未操作线上数据库或真实资金。

## 本轮业务范围

- 产品、卡项、项目参与拼团；支付后先等待成团，成团才激活未消费权益，项目不自动预约或核销。
- 平台已登录上下文内提供整笔原路退款，不新增独立退款按钮权限；已使用、发货、转让、升级等不满足条件的订单拒绝退款。
- 富友先受理再签名查询终态；冻结期间不能消费，明确失败可重试，未知状态只查询原退款单。渠道成功后本地关闭失败可恢复，不重复申请退款。
- 详情展示富友订单号、支付交易号、退款流水号、退款追踪号和商户退款单号；余额退款不伪装富友编号。
- 仅进行中非团长可踢出，整笔原路退款成功才退出；立即成团只补齐虚拟名额，不生成虚拟支付、订单或权益。
- 每团导出与用户样例相同的16列，保留已退款/踢出真实成员、排除虚拟名额。列表与导出共用成员事实查询。

## 本地验证

测试入口 `local-acceptance.php` 只允许 Docker mysql/ruihao；主套件自建会员/商品/订单、调用真实业务服务，事务回滚，富友 transport 使用签名模拟。UI固定样本单独提交到本地，均以 R61- 标识，见 ui-fixtures.json / race-fixtures*.json，不含导入用户模板中的业务记录。

- 主套件结果见 evidence/local-acceptance.log，必须存在 R61 ACCEPTANCE COMPLETE 才算通过。
- 并发退款仅一个执行者发起 apply+query，另一执行者复用同一退款号；并发成团只产生2个虚拟名额、1条审计、1个卡持有权益。最初复测发现死锁，增加限定死锁重试后通过；最终结果见 refund-a/b.log、complete-a/b.log、concurrency-check.json。
- 页面亲测卡成团、踢出团员、余额原路退款、再次补齐成团、退款编号回显及按团下载。实际下载的 拼团记录-160.xlsx 共16列、2个真实成员，含已退款成员；结果见 export-file-check.json。
- PHP语法检查、Vue SFC模板/脚本解析、热更新编译和限定本轮文件的差异空白检查通过。原有 CRLF 文件按 cr-at-eol 检查，未批量改换行。

## 已有功能与边界

复用原订单终态退款、余额账本、库存回退和卡权益发放服务，补齐原活动库存/额度恢复，保留非拼团原逻辑。共享工作区含其他任务既有变更，不在本轮提交范围。固定清单只标识本轮触及文件，不能将整文件diff视为全属本轮。

真实富友支付/退款尚未联调，按用户安排留待渼约008上线测试；当前不能声称渠道实测成功。老富友订单缺少原商户证明时不猜测商户发起退款；老已完成团若原截止时间已被历史代码覆盖，导出留空，不编造时间。前端只做整数金额展示，导出保持原金额精度。

## 固定基线

HEAD：`4b4bf53280c952b1368a633458374ea1da4ebb7b`；当前唯一源码正本为美容源码/。远程实例与部署指纹：不适用。固定文件SHA-256见 evidence/fixed-version.json；开始时指纹见 evidence/baseline.json。未创建平行源码、worktree或发布包。

## 分组文件与注释

本轮业务约束注释随下列源码更新（退款终态/幂等、原订单权益、虚拟名额、导出粒度、金额展示等），未新增工程脚本。自动化脚本属于测试目录。

### 前端源码

- `前端代码/admin/src/api/marketing.js`
- `前端代码/admin/src/components/goodsList/index.vue`
- `前端代码/admin/src/pages/marketing/storeCombination/combinaList.vue`
- `前端代码/admin/src/pages/marketing/storeCombination/create.vue`
- `前端代码/admin/src/utils/formatBusinessMoney.js`

### 后端源码

- `后端代码/app/controller/admin/v1/marketing/combination/StoreCombination.php`
- `后端代码/app/jobs/activity/pink/CombinationRecoveryJob.php`
- `后端代码/app/jobs/order/OrderPayHandelJob.php`
- `后端代码/app/listener/order/Pay.php`
- `后端代码/app/services/activity/combination/CombinationFulfillmentServices.php`
- `后端代码/app/services/activity/combination/CombinationGroupManagementServices.php`
- `后端代码/app/services/activity/combination/CombinationRecordServices.php`
- `后端代码/app/services/activity/combination/CombinationRefundServices.php`
- `后端代码/app/services/activity/combination/StoreCombinationServices.php`
- `后端代码/app/services/activity/combination/StorePinkServices.php`
- `后端代码/app/services/order/StoreOrderCreateServices.php`
- `后端代码/app/services/order/StoreOrderDeliveryServices.php`
- `后端代码/app/services/order/StoreOrderRefundDomainServices.php`
- `后端代码/app/services/order/StoreOrderRefundServices.php`
- `后端代码/app/services/order/StoreReservationOrderServices.php`
- `后端代码/app/services/order/store/WriteOffOrderServices.php`
- `后端代码/app/services/pay/FuyouCombinationRefundGateway.php`
- `后端代码/mohe/services/wechat/HwcPayService.php`
- `后端代码/route/admin.php`

### 数据库迁移

- `后端代码/database/upgrades/2026-10-25-拼团权益退款/01-schema.sql`

### 自动化测试

- `tests/combination-r61/local-acceptance.php`

### 生成物与证据

- evidence/ 下为本地测试日志、指纹、截图与固定样本ID，不是可编辑业务源码。
- `/Users/xiaojunpeng/Downloads/拼团记录-160.xlsx` 为实际页面下载的本地自建数据导出文件。
- 无生产构建包、无线上迁移记录。新增SQL包含5张专用表，已在本地应用；将来发布须另行获得迁移授权。
