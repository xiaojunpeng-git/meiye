# 第 17 轮：平台进入门店，本地待验收记录

- 基线 HEAD：`df65e60a4d916f0f021ad00576314061eaeab1bf`。
- 固定文件指纹清单 SHA-256：`092c6eb3726158b05b7852024389f111d3933906ce60ce5857575ac5cd33fb73`；清单覆盖下列 10 个文件，具体各文件哈希以当次 `shasum -a 256` 输出为准。共享工作区同文件另有其他任务改动，后续提交或发布前须按本轮补丁单独隔离并重新固定候选。
- 各文件 SHA-256（按以下文件清单顺序）：`0d66a614b362cbd54cb08730d8a122ed6b07ed89292badc850054956e8d58eb4`、`98400c429a9058874c122e5c5f04815e7e2f5ef969205ba307330fb6a2c40cd5`、`03299464ba4c57c958a97888eab41ec49d2b88965ae497694aec83a2a815378c`、`116cd37ef85e4ff526e49b469abfa2861ac057d659843c18bf9e3f7558c4803e`、`8741bf7923ed351d41898be88a422ee1e548b6e14981a9354247f566ef40a305`、`47fff20eb94c723f39272c12808ceaf2eb8776563494dc8b576603c0cf6f70c6`、`db1ebf2460fa28dfb77dded07a49c07f5c88a1859255a663db3fac2e0e1961a6`、`55386d313e4f577f0b13b2635b8431e78856e85860264e4765502fe41c15bc37`、`492afa70fc192eb7aae9188cb93c9be7d3e1d1fc3288cc9fc98bb5ac348d01b8`、`17db4082544366ca30c5d60df3ce7359ea518cb58692f7e999088650fc7aa255`。
- 本轮前端源码：`前端代码/admin/src/layouts/basic-layout/index.vue`、`前端代码/admin/src/layouts/basic-layout/header-store-entry/index.vue`、`前端代码/cashier-v3/src/services/storeV3LoginApi.js`、`前端代码/cashier-v3/src/views/StoreLoginView.vue`。
- 本轮后端源码：`后端代码/app/services/cashier/v3/CashierV3StoreLoginServices.php`、`后端代码/app/services/cashier/v3/PlatformStoreEntryBridgeServices.php`、`后端代码/app/controller/admin/v1/store/PlatformStoreEntry.php`、`后端代码/app/controller/cashier/v3/PlatformStoreEntry.php`、`后端代码/route/admin.php`、`后端代码/route/cashier-v3.php`。
- 数据库迁移、工程脚本：无。本记录为验收证据；两端 `dist/` 为构建生成物，未作为源码或发布包提交。
- 本轮行为：平台顶部按钮打开门店端新页签；平台身份经 60 秒一次性票据传给门店端；未关联员工给出关联提示；有效员工沿用现有任职直登或组织选店逻辑。既有门店管理员代入入口没有参与本流程。
- 注释补充：平台按钮的同步开页签及消息边界、门店页签来源校验、跨端票据消费与实时权限重算分别在相应前后端文件中说明。
- 自测：6 个修改或新增的 PHP 文件 `php -l` 通过；平台端 `npm run build` 通过（有原文件既存混用缩进及体积警告）；门店端 `npm run build` 通过，预构建 70 项合同检查通过；`git diff --check` 通过。对本地 API 实测：未登录签票返回 410000；格式错误票据返回 400；不存在的合法格式票据返回 400，未获得门店会话。
- 仍待产品经理验收：使用已关联员工的平台账号实际点击入口，分别验证单店直登、多店选店及未关联员工提示。尚未 commit、push 或部署；远程实例基线与备份不适用。

## 同轮补充：移除门店列表旧入口

- 产品经理在门店管理列表逐行“进入门店”按钮上明确要求移除。本次仅删除该列表操作项、相邻分隔符和其已无引用的处理函数与导入；顶部新入口保留。
- 新增前端源码文件：`前端代码/admin/src/pages/store/storeList/index.vue`，SHA-256 为 `952c66ab8fdd0f747f9bce31a5299c7b715fa603c2349ccb86deb50e7bdf4551`。按前述 10 文件顺序追加该文件后的 11 文件清单指纹为 `f83026b2237cbff0b6776f3d3444ee975e2bba97015354fdc22b7396b07e87c3`。这是新的本地待验收版本，覆盖上面的旧 10 文件版本。
- 自测：门店列表 Vue 模板独立编译通过；文件内已无旧入口文本、调用或无用导入；`git diff --check` 通过。
