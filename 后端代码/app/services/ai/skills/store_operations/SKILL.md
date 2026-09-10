# 门店运营 Skill

这个 Skill 面向门店经营者和拥有相应报表权限的管理者。它帮助用户理解经营结果、定位分析对象、补齐评价标准与期间，并把问题编译成已经登记的统一查询能力。

它不把现有报表页或固定问法当成能力边界。门店、人员、岗位、项目、产品、分类、合作方、顾客和库存使用，只有在统一数据底层已经登记对象关系、指标、筛选合同和当前账号权限时，才能成为本次问题的可执行条件。

<!-- MOHE_SKILL_CONTRACT_BEGIN
{
  "schema_version": "mohe-runtime-skill-v1",
  "skill_code": "skill_store_operations",
  "version": 12,
  "label": "门店运营",
  "goal": "基于当前授权范围和统一数据底层，理解门店经营者的问题，逐步确认分析对象、评价标准、期间和筛选条件，并只执行已登记、可验证的经营查询。",
  "domains": [
    {"code":"operating_result","label":"整体经营情况","objects":["门店","授权范围"],"questions":["收款、退款、实际业绩、消耗、销售、服务完成和活客情况"]},
    {"code":"store_organization_performance","label":"门店与组织表现","objects":["门店","组织"],"questions":["哪家门店表现好、哪些门店发生变化、授权范围内的排行和对比"]},
    {"code":"personnel_position_performance","label":"人员与岗位表现","objects":["人员","岗位"],"questions":["哪个技师表现好、谁销售贡献大、哪个岗位服务表现好"]},
    {"code":"project_performance","label":"项目经营表现","objects":["项目"],"questions":["哪个项目卖得好、完成得多、消耗高、期间发生变化"]},
    {"code":"product_category_performance","label":"产品与商品分类表现","objects":["产品","商品分类"],"questions":["哪个产品卖得好、哪些品类贡献高、客户自定义分类表现"]},
    {"code":"member_operations","label":"会员经营情况","objects":["会员","会员群体"],"questions":["哪些会员消费高、经常来、近期未到店、单会员近期表现"]},
    {"code":"partner_product_performance","label":"合作品项经营","objects":["合作方","合作产品","合作项目"],"questions":["哪家合作方的品项卖得好、合作项目完成情况、合作品项在各门店表现"]},
    {"code":"product_usage_inventory","label":"产品使用与库存","objects":["产品","仓库","门店"],"questions":["卖出、领用、实际耗用和库存情况"]}
  ],
  "required_facts": ["registered_metric","complete_period","current_business_scope","complete_filters","object_grain_contract"],
  "ambiguities": ["analysis_object","evaluation_metric","period","comparison_period","scope","business_filter","member_behavior_type","usage_fact_type"],
  "completion": "仅当分析对象、评价指标、期间和全部筛选条件均有权威证据，并由当前账号可执行的统一查询合同覆盖时完成。",
  "counterexamples": ["按报表页或固定问法限定能力","未登记指标或对象关系","将售卖、领用和实际耗用混为同一事实","把合作方当成获客渠道或客户来源","省略人员、分类、合作方或会员条件","将排行或变化直接解释为经营原因"],
  "semantic_projection": {
    "objects": [
      {"code":"store","label":"门店","aliases":["门店","店铺","店"],"model_kind":"store","contract_ref":"metric_read_view_store_v1","contract_label":"门店范围与统一查询合同"},
      {"code":"person","label":"人员","aliases":["人员","员工","技师","美容师","手艺人","销售人"],"model_kind":"person","contract_ref":"metric_read_view_person_v1","contract_label":"人员对象、当前任职与逐人指标合同"},
      {"code":"position","label":"岗位","aliases":["岗位","职位","职务","岗"],"model_kind":"position","contract_ref":"metric_read_view_person_v1","contract_label":"岗位对象、当前任职与逐人指标合同"},
      {"code":"project","label":"项目","aliases":["项目","品项","服务项目"],"model_kind":"project","contract_ref":"metric_dimension_project_v1","contract_label":"项目维度、当前报表范围、统一查询与证据合同"},
      {"code":"product","label":"产品","aliases":["产品","商品","货品"],"model_kind":"product","contract_ref":"object_product_v1","contract_label":"产品对象解析、当前权限、筛选、统一查询与证据合同"},
      {"code":"category","label":"商品分类","aliases":["商品分类","品类","分类"],"model_kind":"category","contract_ref":"object_category_v1","contract_label":"商品分类对象、当前配置快照与统一查询合同"},
      {"code":"partner","label":"合作方","aliases":["合作方","合作品牌","合作品项"],"model_kind":"partner","contract_ref":"object_partner_v1","contract_label":"合作方分类维度、当前权限与统一查询合同"},
      {"code":"member","label":"会员","aliases":["会员","顾客","客户"],"model_kind":"member","contract_ref":"metric_read_view_member_v1","contract_label":"会员付款能力排行与统一查询合同"},
      {"code":"inventory","label":"库存与耗用","aliases":["库存","耗用","领用","使用量"],"model_kind":"inventory","contract_ref":"object_inventory_v1","contract_label":"库存与耗用对象、门店权限、统一查询与证据合同"}
    ],
    "actions": [
      {"code":"revenue","label":"收款","meaning":"成功记账收款形成的经营结果"},
      {"code":"refund","label":"退款","meaning":"成功现金退款形成的经营结果"},
      {"code":"actual","label":"实际业绩","meaning":"指标字典登记的实际业绩结果"},
      {"code":"consume","label":"消耗","meaning":"项目实际核销或服务完成形成的消耗结果"},
      {"code":"sales","label":"销售","meaning":"产品、项目或品项完成销售形成的金额或数量结果"},
      {"code":"service","label":"服务","meaning":"服务项目实际完成形成的数量或人员表现"},
      {"code":"active_customer","label":"活客","meaning":"指标字典登记的活跃顾客结果"},
      {"code":"payment","label":"付款能力","meaning":"会员在统计期内的实际付款规模"},
      {"code":"visit","label":"到店表现","meaning":"会员到店天数、来访频率或服务次数等行为结果"},
      {"code":"usage","label":"领用与耗用","meaning":"产品被领用或实际耗用形成的数量结果"},
      {"code":"stock","label":"库存","meaning":"当前或指定统计时点的库存结果"}
    ],
    "capability_groups": [
      {"code":"operating_result","meaning":"理解门店整体经营结果","object_codes":["store"],"action_codes":["revenue","refund","actual","consume","sales","service","active_customer"],"slot_codes":[]},
      {"code":"store_organization_performance","meaning":"理解授权范围内门店与组织表现","object_codes":["store"],"action_codes":["revenue","refund","actual","consume","sales","service","active_customer"],"slot_codes":[]},
      {"code":"personnel_position_performance","meaning":"理解人员与岗位表现","object_codes":["person","position"],"action_codes":["sales","service"],"slot_codes":["evaluation_metric"]},
      {"code":"project_performance","meaning":"理解项目的销售、服务与消耗表现","object_codes":["project"],"action_codes":["sales","service","consume"],"slot_codes":["evaluation_metric"]},
      {"code":"product_category_performance","meaning":"理解产品与客户自定义商品分类表现","object_codes":["product","category"],"action_codes":["sales","service","consume"],"slot_codes":["evaluation_metric"]},
      {"code":"member_operations","meaning":"理解会员付款能力与到店服务表现","object_codes":["member"],"action_codes":["payment","revenue","visit"],"slot_codes":["member_behavior"]},
      {"code":"partner_product_performance","meaning":"理解外部合作方品项的经营表现，不解释为渠道或来源","object_codes":["partner"],"action_codes":["sales","service","consume"],"slot_codes":["evaluation_metric"]},
      {"code":"inventory_operations","meaning":"理解产品售卖、领用、实际耗用与库存结果","object_codes":["inventory"],"action_codes":["sales","usage","stock"],"slot_codes":["usage_fact_type"]}
    ],
    "slots": [
      {"code":"evaluation_metric","label":"您想按哪种结果判断？","required":true,"kind":"select","options":[{"code":"sales_amount","label":"销售额"},{"code":"sales_quantity","label":"销售数量"},{"code":"completed_service_count","label":"完成服务数量"},{"code":"consume_amount","label":"消耗金额"}]},
      {"code":"member_behavior","label":"您想了解会员的哪种表现？","required":true,"kind":"select","options":[{"code":"consumption_strength","label":"消费能力"},{"code":"visit_frequency","label":"到店频次"},{"code":"service_frequency","label":"服务次数"}]},
      {"code":"usage_fact_type","label":"您想查看哪一种库存结果？","required":true,"kind":"select","options":[{"code":"sold","label":"卖出"},{"code":"issued","label":"领用"},{"code":"consumed","label":"实际耗用"},{"code":"stock","label":"库存"}]},
      {"code":"period","label":"统计期间","required":true,"kind":"date_range","options_source":"server_authorized_periods"},
      {"code":"scope","label":"分析范围","required":false,"kind":"multi_object","options_source":"server_authorized_catalog"},
      {"code":"comparison_period","label":"对比期间","required":false,"kind":"date_range","options_source":"server_authorized_periods"}
    ],
    "gateway": {"candidate_source":"server_authorized_contract","rules":[{"case":"known_object","action":"ALLOW"},{"case":"slot_candidate","action":"ALLOW"},{"case":"unknown_term","action":"RESOLVE_OR_STOP"},{"case":"contract_missing","action":"GUIDE_OR_EXPLAIN"}]}
  },
  "extension_rule": "教培、标准财务等模块完成后，通过新增统一数据底层的指标、对象关系和读取合同接入；本 Skill 不保存模块公式、表名、SQL、DAO 或客户数据。"
}
MOHE_SKILL_CONTRACT_END -->

## 运行规则

1. 先识别经营目的，再识别分析对象和评价标准。用户问“哪个做得好”时，先确定对象和评价依据；不能把“好”默认等同于某个业绩指标。用户问“经常来”时，也要确认是到店天数、服务次数或消费次数。排序、前后数量、对比和趋势是自然语言表达出的结果形态，不是独立业务场景，也不决定指标口径。
2. 当前账号的报表权限决定可见对象。前端选择、人员名称、会员、门店、日期、分类和合作方只能缩小查询范围。
3. 单轮不能准确识别时，逐步提供少量单选项。优先使用已授权的真实岗位、人员、门店或其他对象；最多使用当前运行配置允许的引导轮数。
4. 用户追问沿用已确认对象、指标和筛选条件，只变更用户明确改变的部分，并重新校验当前权限与能力。
5. 只执行编译后的统一查询、证据校验、确定性回答和已验证导出节点。模型不计算数字、不创建指标、不拼写查询实现。
6. 没有合法能力时说明当前缺少的对象关系或指标，不删除条件，也不替换为全量、默认对象或近似指标。
7. 通用用户意图理解 Skill 负责从完整的去标识化自然语言中提取分析对象、经营动作、评价指标需求和结果要求；不得在 PHP、前端或本 Skill 中维护问法触发词、前后数量语法或结果形态词表。门店运营 Skill 只提供业务含义和可用能力边界。
8. 网关按 `semantic_projection.gateway.rules` 处理对象、待补槽位、未知词与合同缺失；可执行性以对象的 `contract_ref` 对应的运行时注册合同和当前账号权限为准，`contract_label` 仅用于向用户说明限制。

## 当前接入状态

当前可执行范围由实例内已登记且已就绪的 V3 指标与对象合同决定：

- 门店范围：已登记的现金、退款、实际、消耗、销售额、余额扣款、充值本金、完成服务项目数量和活客，可按各自登记的汇总、趋势、对比或门店排行形态查询；金额、数量和人数必须保留各自单位。
- 人员范围：只有已取得相应人员数据权限的销售人业绩和劳动业绩，才允许按人员、岗位或手艺人／销售人资格查询或排行。
- 会员付款能力：已接入当前报表门店范围内、按会员汇总的现金业绩排行；“消费能力／付款能力／支付能力”均表示会员实际付款规模，不改写为到店或服务表现。会员到店频次、服务次数、画像或单会员明细仍须各自登记合同。
- 项目：已接入“完成服务项目数量”的项目维度排行。用户问项目表现时，AI 只从当前已登记的项目对象维度与指标候选中选择；销售额、消耗金额等没有项目维度合同前不会伪造成可查。
- 产品、分类、合作方、库存和耗用对象：底层已有的维度读取能力不等于 AI 已接入。必须另行登记对象解析、当前权限、筛选、查询和证据合同后，才会进入候选范围；未完成前准确说明限制，不以相近指标或报表字段代替。

## 场景引导

- “这个月哪个项目卖得最好”：其中“卖得”明确要求销售表现。只有项目销售额或销售数量已登记项目维度合同后才可执行；当前不会把它改成完成服务数量。“完成服务最多的项目”才使用已登记的完成服务项目数量排行。
- “消费能力最强的会员有哪些”：按会员实际付款规模（现金业绩）排行；未给出期间时询问起止日期，复数排行未指定数量时沿用前 5 项默认值，明确数量必须保留。已确定评价标准时不重复询问“付款还是到店服务”。
- “经常来的会员有哪些”：先确认按到店天数、服务次数或消费次数判断；不把活客汇总人数拆成会员排行。
- “哪个合作方做得好”：合作方只指商品分类中启用合作方配置的外部合作品项；先确认销售、服务或消耗评价标准，不把它理解成渠道或客户来源。
- “产品用了多少”：先确认售卖、领用、实际耗用或库存，四类事实各自独立。
