export const defaultObj = {
  is_presale_product: 0, //预售商品开关
  is_limit: 0, //是否限购开关
  limit_type: 1, //1单次限购，2长期限购
  limit_num: 1, //限购数量
  is_vip_product: 0, //付费会员专属开关
  is_support_refund: 0,
  card_num: 0,
  card_num_type: 0,
  system_form_type: 1,
  disk_info: "", //卡密简介
  presale_day: 1, //预售发货时间-结束
  presale_time: [],
  auto_on_time: "",
  auto_off_time: "",
  video_open: false, //视频按钮是否显示
  store_name: "",
  freight: 1, //运费设置
  postage: 0, //设置运费金额
  custom_form: [], //自定义留言
  cate_id: [],
  label_id: [],
  ensure_id: [],
  keyword: "",
  unit_name: "",
  specs_id: 0,
  store_info: "",
  bar_code: "",
  code: "",
  image: "",
  recommend_image: "",
  slider_image: [],
  description: "",
  ficti: 0,
  give_integral: 0,
  sort: 0,
  is_show: 1,
  is_hot: 0,
  is_benefit: 0,
  is_best: 0,
  is_new: 0,
  is_good: 0,
  is_postage: 0,
  is_sub: [],
  id: 0,
  spec_type: 0,
  video_link: "",
  temp_id: "",
  attr: {
    pic: "",
    price: 0,
	price_range_min: 0,
	price_range_max: 0,
    settle_price: 0,
    cost: 0,
    ot_price: 0,
    stock: 0,
    bar_code: '',
    code: "",
    weight: 0,
    volume: 0,
    stock_unit: '',
    sale_unit: '',
    unit_convert: 1,
    decimal_scale: 0,
    brokerage: 0,
    brokerage_two: 0,
    vip_price: 0,
    virtual_list: [],
    write_times: 0, //核销次数
    write_valid: 1, //核销时效
    days: 1,
	reservation_time_data: [], //预约时间段数组库存
  },
  attrs: [],
  items: [
    {
      pic: "",
      price: 0,
	  price_range_min: 0,
	  price_range_max: 0,
      cost: 0,
      ot_price: 0,
      stock: 0,
      bar_code: "",
      code: "",
    },
  ],
  coupons: [],
  couponName: [],
  selectRule: "",
  coupon_ids: [],
  command_word: "",
  // delivery_type: ["1"],
  delivery_type: [],
  store_delivery_type: [],  // 门店配送类型
  specs: [],
  recommend_list: [],
  brand_id: [],
  product_type: 0,
  store_label_id: [],
  off_show: 0,
  header: [],
  share_content: "", //分销文案
  presale_status: 1,
  applicable_type: 1,
  reservation_time_type:1 ,//预约时段类型1:自动划分2:自定义
  reservation_times: [], //[预约时间段开始，预约时间短结束]
  reservation_time_interval:30, //预约时段自动类型：时间间隔（分钟）
  customize_time_period: [[]], //自定义时间段
  // 预约设置模块参数
  reservation_type:1, //预约类型1：到店服务+上门服务，2：到店服务，3：上门服务
  reservation_timing_type:1, //预约时机1：购买时预约+先买后约，2：购买时预约，3：先买后约
  is_show_stock:1 ,//是否展示库存
  sale_time_type:1, //销售日期1：每天，2:每周，3：自定义时间
  sale_time_week:[], //销售日期每周设置
  sale_time_data:[], //销售日期自定义
  show_reservation_days_type:1, //显示可预约日期类型1：全部展示，2：自定义展示时间
  show_reservation_days:1, //显示多少天内可预约日期（天）
  is_advance:0, //是否需要提前预约0：无需提前1：可以
  advance_time:1, //提前多少小时预约（小时）
  is_cancel_reservation:0, //是否可以取消预约0：不允许1：可以
  cancel_reservation_time:1, //服务开始前多少小时允许取消（小时）
  project_service_duration: 0, //项目服务时长（分钟）
  addon_service_duration: 0, //增项服务时长（分钟）
  card_cover: 1, //卡片封面 1:图片，2:颜色
  card_cover_image: '', //卡片封面图片
  card_cover_color: '', //卡片封面颜色
  related: [], //卡项
  type: 0,
  supplier_id: 0,
  is_sync_stock: 1, //库存同步
  is_sync_show: 1, //状态同步
  is_inventory: 1, //是否参与库存管理（仅产品类型）
  allow_negative_stock: 1, //是否允许负库存
  salon_stock_enabled: 0, //是否可作为院装耗材（仅产品类型且参与库存）
};

export const GoodsTableHead = [
  {
    title: '图片',
    slot: 'pic',
    align: 'center',
    minWidth: '80px',
    labelClassName: 'column-required',
  },
  {
    title: '售价',
    slot: 'price',
    align: 'center',
    minWidth: '120px',
    labelClassName: 'column-required',
  },
  {
    title: '成本价',
    slot: 'cost',
    align: 'center',
    minWidth: '120px',
  },
  {
    title: '划线价',
    slot: 'ot_price',
    align: 'center',
    minWidth: '120px',
  },
  {
    title: '库存',
    slot: 'stock',
    align: 'center',
    minWidth: '180px',
    labelClassName: 'column-required',
  },
  {
    title: '商品编号',
    slot: 'code',
    align: 'center',
    minWidth: '120px',
  },
  {
    title: '商品条形码',
    slot: 'bar_code',
    align: 'center',
    minWidth: '120px',
  },
  {
    title: '重量（KG）',
    slot: 'weight',
    align: 'center',
    minWidth: '100px',
  },
  {
    title: '体积(m³)',
    slot: 'volume',
    align: 'center',
    minWidth: '100px',
  },
  {
    title: '院装耗材单位',
    slot: 'stock_unit',
    align: 'center',
    minWidth: '110px',
  },
  {
    title: '换算数',
    slot: 'unit_convert',
    align: 'center',
    minWidth: '120px',
  },
  {
    title: '默认选中规格',
    slot: 'selected_spec',
    fixed: 'right',
    align: 'center',
    minWidth: '100px',
  },
  {
    title: '操作',
    slot: 'action',
    fixed: 'right',
    align: 'center',
    minWidth: '120px',
  },
];
//   虚拟商品
export const VirtualTableHead = [
  {
    title: '图片',
    slot: 'pic',
    align: 'center',
    minWidth: 80,
  },
  {
    title: '售价',
    slot: 'price',
    align: 'center',
    minWidth: 120,
  },
  {
    title: '成本价',
    slot: 'cost',
    align: 'center',
    minWidth: 120,
  },
  {
    title: '划线价',
    slot: 'ot_price',
    align: 'center',
    minWidth: 120,
  },
  {
    title: '库存',
    slot: 'stock',
    align: 'center',
    minWidth: 180,
  },
  {
    title: '商品条形码',
    slot: 'bar_code',
    align: 'center',
    minWidth: '120px',
  },
  {
    title: '产品编号',
    slot: 'code',
    align: 'center',
    minWidth: 120,
  },
  {
    title: '默认选中规格',
    slot: 'selected_spec',
    fixed: 'right',
    align: 'center',
    minWidth: 90,
  },
  {
    title: '操作',
    slot: 'action',
    fixed: 'right',
    align: 'center',
    minWidth: 120,
  },
];

//   虚拟商品
export const VirtualTableHead2 = [
  {
    title: '图片',
    slot: 'pic',
    align: 'center',
    minWidth: 80,
  },
  {
    title: '售价',
    slot: 'price',
    align: 'center',
    minWidth: 120,
  },
  {
    title: '成本价',
    slot: 'cost',
    align: 'center',
    minWidth: 120,
  },
  {
    title: '划线价',
    slot: 'ot_price',
    align: 'center',
    minWidth: 120,
  },
  {
    title: '库存',
    slot: 'stock',
    align: 'center',
    minWidth: 120,
  },
  {
    title: '商品条形码',
    slot: 'bar_code',
    align: 'center',
    minWidth: '120px',
  },
  {
    title: '产品编号',
    slot: 'code',
    align: 'center',
    minWidth: 120,
  },
  {
    title: '添加卡密/网盘',
    slot: 'fictitious',
    align: 'center',
    minWidth: 120,
  },
  {
    title: '默认选中规格',
    slot: 'selected_spec',
    fixed: 'right',
    align: 'center',
    minWidth: 90,
  },
  {
    title: '操作',
    slot: 'action',
    fixed: 'right',
    align: 'center',
    minWidth: 120,
  },
];

export const columns2 = [
  {
    title: "图片",
    slot: "pic",
    align: "center",
    minWidth: 80,
  },
  {
    title: "售价",
    slot: "price",
    align: "center",
    minWidth: 95,
  },
  {
    title: "成本价",
    slot: "cost",
    align: "center",
    minWidth: 95,
  },
  {
    title: "划线价",
    slot: "ot_price",
    align: "center",
    minWidth: 95,
  },
];

export const StoreTableHead = [
  {
    title: "ID",
    key: "id",
    width: 60,
  },
  {
    title: "门店图片",
    slot: "image",
    minWidth: 80,
    className: 'store-image-column',
  },
  {
    title: "门店分类",
    key: "cate_name",
    minWidth: 80,
  },
  {
    title: "门店名称",
    key: "name",
    minWidth: 80,
  },
  {
    title: "联系电话",
    key: "phone",
    minWidth: 90,
  },
  {
    title: "门店地址",
    key: "address",
    ellipsis: true,
    minWidth: 150,
  },
  {
    title: "营业时间",
    key: "day_time",
    minWidth: 120,
  },
  {
    title: "营业状态",
    key: "status_name",
    minWidth: 80,
  },
  {
    title: "操作",
    slot: "action",
    width: 100,
  }
];
// 预约商品
export const ReservationTableHead = [
  {
    title: '图片',
    slot: 'pic',
    align: 'center',
    minWidth: '80px',
  },
  {
    title: '售价',
    slot: 'price',
    align: 'center',
    minWidth: '120px',
  },
  {
    title: '成本价',
    slot: 'cost',
    align: 'center',
    minWidth: '120px',
  },
  {
    title: '划线价',
    slot: 'ot_price',
    align: 'center',
    minWidth: '120px',
  },
  {
    title: '预约数量',
    slot: 'stock',
    align: 'center',
    minWidth: '120px',
  },
  {
    title: '默认选中规格',
    slot: 'selected_spec',
    fixed: 'right',
    align: 'center',
    minWidth: '100px',
  },
  {
    title: '操作',
    slot: 'action',
    fixed: 'right',
    align: 'center',
    minWidth: '120px',
  },
];
