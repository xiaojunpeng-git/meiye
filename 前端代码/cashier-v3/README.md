# 门店端 V3（收银系统 V3）

本目录是门店端 V3 的独立 Vue 3 前端模块。它以收银核心为起点，并通过“管理中心”逐步承接原门店后台功能。旧 `前端代码/cashier/` 与旧 `前端代码/store/` 都继续作为兼容实现，本模块开发和验收期间不覆盖它们。

门店端产品与旧功能迁移边界遵守：[XJPMD/15-门店端统一入口与旧功能迁移规则.md](../../../XJPMD/15-门店端统一入口与旧功能迁移规则.md)。当前一级菜单为：收银、核销、房间、预约、会员、挂单、订单中心、管理中心。

## 本地运行

```bash
npm install
npm run dev
```

默认开发地址为 `http://127.0.0.1:18084/`。

用于核对页面布局的本地演示数据仅在开发环境启用：

```text
http://127.0.0.1:18084/view_cashier_v3/?preview=1#/cashier
```

它不会进入正式构建，不连接真实业务数据，也不能作为业务验收数据。

## 构建约定

```bash
npm run build
```

构建产物位于 `dist/`。正式切换前，发布包再将 `dist/index.html` 映射为独立静态入口、将 `dist/assets/` 映射到独立资源目录；不得提前覆盖旧 `cashier.html` 或 `view_cashier/`。

正式入口切换另行安排；推荐的独立发布契约是：

- `dist/index.html` → `后端代码/public/cashier-v3.html`
- `dist/assets/` → `后端代码/public/view_cashier_v3/`
- 后端新增仅服务 V3 的 `/cashier-v3` 入口，页面地址为 `/cashier-v3/#/cashier`；本期保留该技术入口名称，产品展示名称为“门店端”。

不得修改现有 `/cashier`、`cashier.html`、`view_cashier/` 或旧收银台的路由兜底。

## 前后端接入边界

- 页面、交互和组件仅由 Codex 修改。
- Cursor 只能在 Codex 提供的 API 适配层接入真实接口，并保留页面结构与交互。
- 真实数据、动作请求和状态刷新只能接入 [src/services/cashierV3Bridge.js](src/services/cashierV3Bridge.js)；不得在任一 Vue 页面中直接发请求、重算金额、余额、优惠、库存或业绩。
- 后端页面初始化可注入 `window.__CASHIER_V3_BOOTSTRAP__`，动作适配器可注入 `window.__CASHIER_V3_ADAPTER__.request(action, payload)`。每份完整根 `state` 必须携带 `stateContextId`（当前账号、强制门店和浏览器工作台会话）及从 `1` 开始严格单调的十进制 `stateRevision`。该序号只保护同一工作台的完整页面投影，不承担业务并发；业务写入必须依赖大于 `0` 的资源版本、幂等和事务锁。纯查询不伪造业务命令；只有替换完整根投影的查询才签发新序号。前端会在整包替换前把缺失、非法、更旧、相同版本或**普通动作响应的不同上下文**都作为非成功响应整体忽略；与它绑定的 overlay／navigation 同时丢弃，调用页面不能继续按原始成功结果打开。只有账号、强制门店或浏览器工作台会话已经明确切换后的 bootstrap，才可通过 `replaceCashierV3StateForContextSwitch` 接纳新 `stateContextId`；该入口会先清空旧根状态与本地弹层现场，再接收新上下文从 `1` 开始的投影。静默房态刷新也不能用残缺整包清空收银、服务确认或核销状态。`conflict` 只保留可读提示和只读 `latestState`，不会应用整包或执行其 overlay／navigation。
- 每个工作台状态必须含根级 `workspace = { id, revision, status, serverTime }`。写动作会附加：

  ```js
  command = {
    action: '...',
    idempotencyKey: 'CMD-...',
    // 兼容主对象；不得成为唯一并发校验对象。
    context: { kind: 'cashier_workspace', id: 'CW...', expectedVersion: 12 },
    // 所有会同时修改、占用或依赖最新版本的对象。
    contexts: [
      { kind: 'cashier_workspace', id: 'CW...', expectedVersion: 12 },
      { kind: 'service_order', id: 'FW...', expectedVersion: 3 }
    ]
  }
  ```

  后端必须校验 `contexts` 全部对象并以幂等键保证幂等；版本冲突只能返回最新摘要与可读提示，不能静默覆盖另一台设备的修改。
- `pendingHangCount` 接受数字或数字字符串，负数和无效值按 0 处理；接入后，挂单、提单、结账、作废等动作都必须由后端返回更新后的数量。
- 管理中心由 `featurePermissions['cashier.v3.management_center']` 控制一级入口。后端通过 `managementCenter.entries` 返回当前账号／当前门店有权进入的入口；点击只提交 `entryId`，由后端再次鉴权并返回 `navigation`，前端不拼接旧 URL、也不嵌入旧 Vue 2 页面。
- 已确认完成 V3 重做的“经营看板”可由管理中心返回 `navigation.routeName = 'cashier-v3-business-dashboard'` 进入 `#/management-center/business-dashboard`；它不是第九个一级菜单。门店端固定当前登录门店并展示员工排行；平台端必须使用独立的 Vue 3 容器，但与门店端共用同一事实指标契约，不能复用当前门店壳或旧 Vue 2 看板。
- 门店端根状态必须返回 `currentStore = { id, name }`。统一查询中的门店字段在门店端固定为当前门店：前端只显示当前门店、不提供跨店选择；后端仍必须把当前账号／当前门店的数据权限强制注入所有列表、汇总、导出与下钻，不能信任前端筛选值。
- 统一查询的人员、门店、组织选择器统一通过 `query-query-entities` 只读动作加载：`{ entityType: 'person | store | organization', keyword, page, pageSize, selectorContext }`。结果放在 `queryEntitySelector[entityType] = { records, total, page, pageSize, isLoading }`。选择器仅帮助填写筛选条件，最终列表查询必须由后端按权限重新校验。
- 同一“选择人员”控件可支持单选或多选；预约编辑传入 `selectorContext.scope = 'reservation_craftsmen'` 时，后端只能返回当前门店、在职、未删除、手艺人资格为是且当前预约时段可候选的人员。不得用旧 `is_reservable`、旧排班或前端过滤代替；保存预约时仍须在事务中复核人员／房间冲突。
- 预约、销售订单、会员、房间详情以及核销确认都只能展示后端已返回的快照。对应展示状态建议使用 `reservation.detail`、`orderCenter.salesOrderDetail`、`memberCenter.detail`、`room.detail`、`writeoff.confirmation`；前端不重算金额、时长、房态、权益或业绩。

### 详情与浮层响应

后端动作可在标准响应中返回 `overlay`，由门店端打开相应的已交付 Vue 3 组件。推荐值如下：

```js
{
  overlay: {
    name: 'reservation-editor | reservation-detail | sales-order-detail | member-detail | room-detail | room-assignment | writeoff-confirmation | service-completion',
    reservationId: '...', // 仅相关场景传入
    orderId: '...',       // 仅相关场景传入
    memberId: '...',      // 仅相关场景传入
    roomId: '...'         // 仅相关场景传入
  }
}
```

`overlay` 只负责打开页面；详情数据仍通过同一响应的 `state` 返回并受后端数据权限过滤。前端不得用本地列表残缺字段补造敏感详情或可执行权限。

### 全局“确认本次服务”契约

服务结束、服务中结账、预约和房间入口都打开同一份全局服务确认覆盖层。它只消费根级 `serviceCompletion` 快照；**不会**回退使用当前收银购物车、其他预约详情或房间卡片来猜项目，避免错单、重复核销和错结账。

```js
serviceCompletion: {
  source: 'cashier | reservation | room | writeoff',
  // 与准备动作输入及 overlay.preparationRequestId 完全一致；用于忽略晚到旧响应。
  preparationRequestId: 'SERVICE_PREPARE-...',
  // 仅刷新／重新登录恢复原处理中请求时为 true。
  resumeOnLoad: false,
  // 由后端签发；前端仅原样回传，服务端仍需在事务中再次校验和锁定。
  commandContexts: [{ kind: 'service_order', id: 'FW202607270001', expectedVersion: 8 }],
  serviceOrder: {
    id: 'FW202607270001', revision: 8, serviceNo: 'FW202607270001',
    status: '服务中', roomName: '普通房 02',
    completion: {
      status: 'editing | processing | pending_confirmation | result_unknown | failed | succeeded',
      requestNo: '...',
      confirmationReady: true,
      snapshotToken: 'server-signed-or-opaque-token',
      // 缺少四项中任一项时，前端只显示“待后端确认”，禁止正式确认。
      preview: {
        completedProjectCount: 1,
        writeoffAmount: 100,
        consumptionPerformanceAmount: 100,
        laborPerformanceAmount: 100
      },
      // 仅在 succeeded 时由后端明确返回；前端不作默认推断。
      nextAction: 'finish-service-completion | continue-service-checkout'
    }
  },
  // 与上述服务单同一份完整项目快照，而非当前购物车的猜测结果。
  lines: [{
    id: 'service-line-id', isServiceProject: true, name: '水光护理', quantity: 1,
    serviceSource: '卡内项目', serviceRole: '主项目',
    completionStatus: 'completed', actualCompletedQuantity: 1,
    entitlementSource: { id: 'card-project-id', label: '年度护理卡 · K202607270001' },
    unservedReason: null, unservedReasonNote: null,
    actualCraftsmen: [{ id: 'staff-1', name: '张三', isPrimary: true }],
    writeoffAmount: 100, consumptionPerformanceAmount: 100, laborPerformanceAmount: 100
  }]
}
```

- 每次准备确认都提交稳定 `preparationRequestId`，后端必须将同一值同时回传到根状态与 overlay。适配边界会在替换根状态前校验全局最后一次服务确认意图；缺失、不一致、同一服务单旧请求或 A 服务单被 B 服务单取代后的晚到响应均会被丢弃，不能关闭或覆盖当前确认层。
- 每次准备确认、项目完成数量／未服务原因修改、实际手艺人顺序修改，都必须由后端返回新的完整 `serviceCompletion` 快照与预览。全部服务项目行、逐行三项金额和汇总四项预览完整后，后端才能返回 `confirmationReady = true` 与绑定当前版本的 `snapshotToken`；浏览器只提交用户输入，不计算核销金额、消耗业绩或劳动业绩。
- `confirm-service-completion` 与 `retry-service-completion` 仅使用后端幂等键和完整多对象并发校验。`processing`、`pending_confirmation`、`result_unknown` 只能 `query-service-completion-result` 查询原请求，不能重提；刷新／重新登录恢复原请求时才设置根级 `resumeOnLoad = true`，普通动作响应不得设置。
- 成功后的 `finish-service-completion` 仅关闭／返回已完成的纯卡内服务；`continue-service-checkout` 只交接同一 `serviceOrderId` 到 `prepare-checkout`。后端必须返回匹配的收银工作台和最新服务单版本，前端不会用另一张购物车代替。
- 服务确认成功不能成为“支付成功自动核销”的替代链路。卡内／预约项目在确认事务内完成必要核销；本次新买且尚未支付项目只锁定服务确认快照，最终服务记录与业绩仍等支付成功后按该快照处理。
- 核销工作台存在 `activeServiceSession` 时只能进入该全局服务确认，不能直接 `prepare-writeoff`／`submit-writeoff`；独立核销仍保留原流程。

### 房间安排契约

预约计划房间和服务实际房间共用全局 `RoomAssignmentOverlay`，但必须由后端明确返回 `assignmentScope`。页面只展示后端候选与原因，不在浏览器计算房态、冲突或权限。

- `reservation_plan`：只调整预约计划房间及对应时段预留；服务开始前不形成当前房态占用。
- `active_service`：调整已经开始服务的实际房间占用。

```js
room: {
  assignment: {
    roomAssignmentPreparationId: 'ROOM_ACTION-...',
    preparationRequestId: 'ROOM_ACTION-...',
    assignmentScope: 'reservation_plan | active_service',
    mode: 'assign | change | remove',
    reservation: { id: 'YY202607270001', revision: 4, reservationNo: 'YY202607270001' },
    serviceOrder: { id: 'FW202607270001', revision: 8, serviceNo: 'FW202607270001' },
    // 只表示本 assignmentScope 的原房：预约为原计划房，服务为原实际房；不得跨范围回退读取。
    currentRoom: { id: 'room-02', revision: 6, name: '普通房 02' },
    commandContexts: [
      { kind: 'reservation', id: 'YY202607270001', expectedVersion: 4 },
      { kind: 'service_order', id: 'FW202607270001', expectedVersion: 8 },
      { kind: 'room', id: 'room-02', expectedVersion: 6 }
    ],
    candidates: [{
      id: 'room-03', name: '普通房 03', categoryName: '普通房间',
      selectable: true, disabledReason: '', conflictSummary: '', nextReservation: null
    }]
  }
}
```

`prepare-room-assignment` 的每次独立打开必须产生新的 `roomAssignmentPreparationId`／`preparationRequestId`；只有同一次网络重试可复用本次值。请求意图、`room.assignment` 与 overlay 必须同时返回并严格一致的 `assignmentScope`、预约／服务对象、请求号和 `mode`（`assign | change | remove`）；缺失或任一错配时，适配边界会在写入根状态前拒绝。已打开的浮层同时绑定对象、请求号和模式，收到同一对象的另一轮准备快照也会关闭并要求重新打开。`save-service-room-assignment` 只使用本轮已校验准备快照的范围、模式、预约／服务版本、原房和 `commandContexts`，不会相信子组件改写：预约范围只更新计划关系，不提前占用当前房态；服务范围才锁定新房并释放旧房。换房／移出时必须有原房 ID、版本和匹配 `room` context，缺失即拒绝。明确选择“待分配房间”或移出房间时 `targetRoomId = null`，不会自动开始／结束服务、核销或结账。

### 待分配房间列表契约

房态页顶部入口使用 `room.pendingAssignmentCount`，弹层读取 `room.unassignedList = { records, total, page, pageSize, refreshedAt, staleMessage }`。每条记录必须由后端返回来源、状态文字、会员、时间段、主项目与项目总数、计划／主要手艺人、预约号、本次服务单号，以及 `actions.view`、`actions.assignRoom` 的 `visible`、`enabled`、`code`、`mode`、`disabledReason`。记录和分配动作还必须返回一致的 `assignmentScope`，且 `assignRoom.mode = 'assign'`：预约为 `reservation_plan`，当前服务为 `active_service`；前端不会按中文状态猜测或自行补出范围／模式。

列表每页 20 条，只提供“查看”和“分配房间”；没有结束服务、核销、结账、作废、批量或内联选房。点击分配继续复用 `prepare-room-assignment`，成功后的数量、列表与房态必须由后端完整根状态一并刷新，前端不会自行删除记录或减角标。未分配房间仍可按原业务流程开始服务、核销和结账。

### 经营看板 V3 契约

`businessDashboard` 是纯展示状态，所有十张卡、趋势点、排行、明细与导出必须读取 V3 事实、统一指标字典和后端数据权限；不得读取或混算旧 `homeStatics`、`staff_yeji`、`store_order_writeoff.writeoff_price`。

```js
businessDashboard: {
  // 门店端为 store；平台端独立 V3 容器传 platform。
  mode: 'store | platform',
  scope: {
    dateRange: { start: '2026-07-27', end: '2026-07-27' },
    organization: null, // 仅平台端可选，仍受后端权限收敛
    store: null,        // 门店端固定当前门店，不由前端选择
    forcedRangeLabel: '当前登录门店：瑞昊一店'
  },
  cards: [{ metricCode: 'cash_performance', name: '现金业绩', value: 1200, unit: '元', description: '...' }],
  selectedMetricCode: 'cash_performance',
  trend: { metricCode: 'cash_performance', points: [{ label: '07-27', value: 1200 }], isLoading: false },
  ranking: {
    dimension: 'staff | store', sortBy: 'cash_performance', sortOrder: 'desc',
    sortOptions: [{ value: 'cash_performance', label: '现金业绩' }],
    columns: [{ key: 'name', label: '员工名称' }], records: [], isLoading: false
  },
  metricVersion: 'v3.0', dataAsOf: '2026-07-27 10:35:00',
  aggregationCaughtUp: true, coverageStart: '2026-07-27'
}
```

卡片顺序固定为：销售人业绩、现金业绩、实际业绩、消耗业绩、退款金额、储值金额、余额扣款、散客数量、新客数量、预约客数；不得出现“新建档数”。默认趋势与排行均为现金业绩降序。前端动作 `query-business-dashboard-summary`、`query-business-dashboard-trend`、`query-business-dashboard-ranking`、`open-business-dashboard-detail`、`export-business-dashboard` 仅请求后端重算或下钻；后端必须返回相同的 `metricVersion`、`dataAsOf`、`aggregationCaughtUp`、`coverageStart` 和强制权限范围。

### 结账收款明细与恢复契约

结账浮层只展示后端试算的 `cashier.checkout`。每笔收款必须返回稳定 ID、金额、状态、可编辑／可删除标记、外部流水号和收款备注；前端通过 `update-payment-line`、`remove-payment-line` 提交修改，不自行调整合计或判断金额是否可支付。收款状态固定为未开始、处理中、成功、失败、结果确认中；存在成功或结果确认中的明细时，后端必须将成功明细锁定，并返回继续补收／退款处理的允许动作。

```js
cashier: {
  checkout: {
    status: 'editing | processing | pending | pending_confirmation | result_unknown | failed | succeeded',
    // 仅在后端确认应恢复普通编辑态时为 true；处理中、结果确认中、失败态会自动恢复。
    resumeOnLoad: false,
    payment: {
      availableBalance: 500, balanceAvailable: true,
      balanceVerification: { required: false, status: 'not_verified | verified', label: '验证会员身份' },
      selectedLines: [{
        id: 'payment-id', name: '微信支付', amount: 100, status: '未开始',
        canEdit: true, canRemove: true, externalTransactionNo: '', remark: ''
      }],
      summary: { receivableAmount: 100, selectedAmount: 100, remainingAmount: 0, validationMessage: '' }
    },
    finalChanges: [], finalChangesConfirmationRequired: false,
    cardUpgradeDeductionAmount: 0, projectUpgradeDeductionAmount: 0
  }
}
```

余额身份验证默认关闭；本期只保留界面和后端动作锚点，不做真实验证测试。外部流水号仅作备注记录，最长 50 字符，不按收款方式推断或校验外部渠道。关闭、恢复、部分成功、退款处理、资源预占和支付重试的最终判断全部在后端结账请求状态机中完成。

预约、房间、核销和服务确认后的“去结账”统一使用同一交接：后端返回与目标 `serviceOrderId` 匹配的 `state.cashier.serviceOrder`、完整 `state.cashier.checkout`、`checkout.resumeOnLoad = true` 及 `navigation.routeName = 'cashier-v3-cashier'`。收银工作台在导航后按该状态自动打开结账层；仅返回路由、仅返回服务单号或使用当前另一张购物车都属于契约错误。

## 首批展示状态契约

以下是页面当前消费的展示字段。它们只是界面状态；订单、资产、支付和统计的权威数据仍以 V3 业务主表、事务内事件／Outbox 和统一事实层为准。

```js
window.__CASHIER_V3_BOOTSTRAP__ = {
  storeName: '瑞昊一店',
  currentStore: { id: 'store-id', name: '瑞昊一店' },
  operator: { name: '肖君鹏', roleName: '收银员' },
  pendingHangCount: 2,
  workspace: { id: 'CW202607270001', revision: 12, status: 'editing', serverTime: '2026-07-27T01:20:00+08:00' },
  cashier: {
    member: {
      id: '10086', name: '肖君鹏', phone: '13800000000',
      accountBalance: 2000, principalBalance: 1500, giftBalance: 500,
      cardBenefitAmount: 3600, remainingTimes: 28, activeCardCount: 3,
      totalAvailableAmount: 5600
    },
    catalog: {
      types: ['项目', '产品', '次卡', '时间卡', '定制卡'],
      categories: ['全部', '面部'],
      items: [{ id: 'product-id', name: '水光护理', kind: '项目', category: '面部', price: 300 }]
    },
    cart: {
      lines: [{
        id: 'line-id', name: '水光护理', kind: '项目', quantity: 1,
        finalAmount: 300, originalAmount: 300,
        craftsmenSummary: '肖君鹏(点)', salespersonSummary: '李四(售前)',
        couponSummary: '未选择', debtSummary: '未设置',
        serviceSource: '本次新买', serviceRole: '主项目'
      }],
      summary: { selectedCount: 1, originalAmount: 300, discountAmount: 0, receivableAmount: 300, hasOrderNote: false }
    },
    serviceOrder: { status: '待确认' },
    supplement: null
  },
  queryEntitySelector: {
    person: { records: [], total: 0, page: 1, pageSize: 20, isLoading: false },
    store: { records: [], total: 0, page: 1, pageSize: 20, isLoading: false },
    organization: { records: [], total: 0, page: 1, pageSize: 20, isLoading: false }
  },
  reservation: { records: [], detail: null },
  writeoff: { sources: [], confirmation: null },
  memberCenter: { records: [], detail: null },
  room: { categories: [], detail: null },
  orderCenter: { salesOrders: [], salesOrderDetail: null }
}
```

动作名称由页面通过 `requestCashierV3Action` 发出，例如 `choose-catalog-item`、`open-member-selector`、`open-line-assignment`、`open-hang-order`、`prepare-checkout`。Cursor 接入后必须在每次动作完成后返回后端重新校验、重新试算后的状态，不能由前端自行增减购物车金额或权益。`submit-checkout` 会固定复用同一个幂等键直到服务端给出最终结果，避免网络重试变成第二次结账。

`query-query-entities`、`query-*`、`open-*-detail` 等只读动作不得写入业务事实、命令回执或经营数据；正式保存个人查询方案、预约、服务、核销和结账时才使用适用的幂等／并发命令契约。
