<template>
  <div>
    <Card :bordered="false" dis-hover>
      <Form ref="formValidate" :model="formData" :label-width="90">
        <div class="relative mb-22 title">基础设置</div>
        <FormItem label="同城配送：">
          <RadioGroup
            v-model="formData.city_delivery_type"
            @on-change="onCityDeliveryTypeChange"
          >
            <Radio :label="0">商家自配</Radio>
            <Radio :label="2">UU跑腿</Radio>
            <Radio :label="1">达达快送</Radio>
          </RadioGroup>
          <div class="text--w111-999">
            设置同城配送的配送渠道，用户下单后，自动转配送单至对应渠道，选择UU、达达后，使用第三方计算的配送费用。请务必准确设置商品重量、门店地址等信息。
          </div>
        </FormItem>
        <FormItem label="商品类型：" v-show="formData.city_delivery_type">
          <Select
            v-model="formData.business"
            placeholder="全部"
            v-width="280"
          >
            <Option
              :value="item.key"
              v-for="item in businessList"
              :key="item.key"
              >{{ item.label }}</Option
            >
          </Select>
        </FormItem>
        <div class="relative mb-22 title">配送范围</div>
        <FormItem label="划分依据：">
          <RadioGroup
            v-model="formData.range_type"
            @on-change="onRangeTypeChange"
          >
            <Radio :label="1">按服务半径</Radio>
            <Radio :label="2">按行政区域</Radio>
            <Radio :label="3">电子围栏</Radio>
          </RadioGroup>
          <div class="text--w111-999">
            收货地址在配送范围之外的买家将不可下单
          </div>
        </FormItem>
        <FormItem v-show="formData.range_type == 1">
          <div class="map-wrapper">
            <Input
              v-model="formData.radius"
              v-width="280"
              class="mb-20"
              number
              autocomplete
              @on-change="onRadiusChange"
            >
              <span slot="append">公里</span>
            </Input>
            <div class="map-wrap">
              <div id="mapContainer1" class="z-1"></div>
            </div>
          </div>
        </FormItem>
        <FormItem v-show="formData.range_type == 2">
          <lazyCascader
            v-model="formData.region"
            :props="props"
            :filterable="false"
            collapse-tags
            clearable
          ></lazyCascader>
          <div class="text--w111-999">
            如启用第三方配送，请不要选择默认配送地址以外的城市区域
          </div>
        </FormItem>
        <FormItem v-show="formData.range_type == 3">
          <div class="map-wrapper" :class="{ 'full-screen': isFullscreen }">
            <div class="map-wrap h-full">
              <Row type="flex" class="h-full">
                <Col span="16">
                  <div
                    class="acea-row row-column h-full"
                    @mouseleave="handleMouseLeave"
                  >
                    <div style="flex: 1; min-height: 0">
                      <div id="mapContainer" class="z-1"></div>
                      <div class="map-control">
                        <AutoComplete
                          v-model="autoCompleteValue"
                          @on-search="onAutoCompleteSearch"
                          @on-select="onAutoCompleteSelect"
                          placeholder="请输入要搜索的地址"
                          v-width="320"
                        >
                          <Option
                            v-for="item in autoCompleteData"
                            :value="item.id"
                            :key="item.id"
                            >{{ item.title }}</Option
                          >
                        </AutoComplete>
                        <div class="flex-y-center mt-10">
                          <RadioGroup
                            v-model="editorMode"
                            type="button"
                            button-style="solid"
                            @on-change="onEditorModeChange"
                          >
                            <Radio label="DRAW">添加</Radio>
                            <Radio label="INTERACT">编辑</Radio>
                          </RadioGroup>
                          <div
                            v-show="editorMode === 'DRAW'"
                            id="toolControl"
                            class="ml-10"
                          >
                            <div
                              :class="[
                                'toolItem',
                                tool.type,
                                { active: activeOverlayId === tool.id },
                              ]"
                              :id="tool.id"
                              :title="tool.title"
                              v-for="tool of toolList"
                              :key="tool.id"
                              @click="handleMapToolClick(tool)"
                            ></div>
                          </div>
                        </div>
                      </div>
                      <div
                        class="geometry-tooltip"
                        :style="geometryTooltipStyle"
                        v-if="geometryTooltipData.visible"
                      >
                        {{ geometryTooltipData.label }}
                      </div>
                    </div>
                    <div>
                      <div>
                        添加区域：点击右侧添加配送区域按钮或者鼠标左键点击及移动即可绘制图形。
                      </div>
                      <div>
                        结束区域绘制：多边形结束绘制需要双击鼠标左键，圆形、矩形、椭圆单击即可结束绘制。
                      </div>
                      <div>
                        编辑区域：点击编辑按钮，然后点击需要编辑的区域即可修改区域边界，按
                        Delete 键删除区域。
                      </div>
                      <div>
                        其他：椭圆图形绘制时先绘制宽度再绘制高度，点击地图右上角按钮可以切换到全屏。
                      </div>
                    </div>
                  </div>
                </Col>
                <Col span="8">
                  <div class="acea-row row-column h-full pl-20">
                    <div class="flex-1">
                      <div
                        class="acea-row row-middle mb-10"
                        v-for="(item, index) in fence"
                        :key="item.id"
                      >
                        <ColorPicker
                          v-model="fence[index].color"
                          @on-change="handleColorChange(item)"
                        />
                        <Input
                          v-model="fence[index].title"
                          v-width="150"
                          maxlength="10"
                          show-word-limit
                          class="ml-10"
                          @on-focus="handleFenceTitleFocus(item)"
                        />
                        <Button
                          v-show="fence.length > 1"
                          type="text"
                          class="ml-10"
                          @click="handleDelFence(index)"
                          >删除</Button
                        >
                      </div>
                    </div>
                    <div>
                      <Button type="primary" class="w-full" @click="addRegion"
                        >添加配送区域</Button
                      >
                    </div>
                  </div>
                </Col>
              </Row>
            </div>
          </div>
        </FormItem>
        <div class="relative mb-22 title">配送规则</div>
        <FormItem label="起送价：">
          <Input v-model="formData.min_delivery_amount" v-width="280">
            <span slot="append">元</span>
          </Input>
          <div class="text--w111-999">
            订单中的商品在优惠前的总金额（不包含配送费）低于起送价时，买家将无法下单
          </div>
        </FormItem>
        <FormItem label="包邮规则：">
          <Input v-model="formData.free_shipping_amount" v-width="280">
            <span slot="append">元</span>
          </Input>
          <div class="text--w111-999">
            订单优惠后总金额（不包含配送费）满多少元，商家进行包邮
          </div>
        </FormItem>
        <!-- 配送费设置 -->
        <div class="" v-show="!formData.city_delivery_type">
          <div class="relative mb-22 title">配送费设置</div>
          <FormItem label="基础运费：">
            <Input v-model="formData.base_shipping_fee" v-width="280">
              <span slot="append">元</span>
            </Input>
            <div class="text--w111-999">
              商家未设置叠加溢价规则时，则所有订单统一运费标准
            </div>
          </FormItem>
          <FormItem label="叠加溢价：">
            <Switch
              v-model="formData.is_premium_stack_enabled"
              :true-value="1"
              :false-value="0"
              size="large"
            >
              <span slot="open">开启</span>
              <span slot="close">关闭</span>
            </Switch>
            <div class="text--w111-999">
              多个叠加溢价规则互相叠加。例如：距离溢价设置5-8公里内，每增加2公里运费增加1元，3-5千克内，每增加1千克运费增加1元。则7公里4千克的订单，计算运费时基于基础运费之外，再额外增加溢价1+1=2元。
            </div>
            <div
              v-if="formData.is_premium_stack_enabled"
              class="pt-22 pr-22 pb-22 pl-22 bg-w111-F5F7FA overflow-hidden"
            >
              <!-- 距离阶梯价 -->
              <div class="pt-22 pr-22 pl-22 bg-w111-FFFFFF overflow-hidden">
                <div class="acea-row row-middle mb-22">
                  距离阶梯价
                  <Tooltip placement="right">
                    <Icon
                      type="ios-help-circle"
                      size="14"
                      color="#B6BABE"
                      class="ml-2"
                    />
                    <div slot="content" class="fs-12">
                      最多设置7个层级。增加距离不足阶梯公里数的，向上按足量计算。
                    </div>
                  </Tooltip>
                </div>
                <div class="acea-row row-middle mb-22">
                  <Input
                    v-model="
                      formData.distance_premium_config.level_first.lt_distance
                    "
                    v-width="150"
                    @on-blur="onLtDistanceBlur"
                  >
                    <span slot="append">公里</span>
                  </Input>
                  <div class="pr-10 pl-10">内，按基础运费计算</div>
                  <Button
                    type="text"
                    v-if="!formData.distance_premium_config.level_stairs.length"
                    @click="addDistanceLadderPrice"
                    >+新增</Button
                  >
                </div>
                <template
                  v-if="formData.distance_premium_config.level_stairs.length"
                >
                  <div
                    class="acea-row row-middle mb-22"
                    v-for="(item, index) in formData.distance_premium_config
                      .level_stairs"
                    :key="index"
                  >
                    <Input
                      v-model="
                        formData.distance_premium_config.level_stairs[index]
                          .end_distance
                      "
                      v-width="183"
                      class="input-group"
                      @on-blur="onEndDistanceBlur(index)"
                    >
                      <span slot="prepend">
                        <Input
                          v-model="
                            formData.distance_premium_config.level_stairs[index]
                              .start_distance
                          "
                          v-width="80"
                          disabled
                        >
                          <span slot="append">-</span>
                        </Input>
                      </span>
                      <span slot="append">公里</span>
                    </Input>
                    <div class="pr-10 pl-10">内，每增加</div>
                    <Input
                      v-model="
                        formData.distance_premium_config.level_stairs[index]
                          .add_distance
                      "
                      v-width="150"
                    >
                      <span slot="append">公里</span>
                    </Input>
                    <div class="pr-10 pl-10">运费增加</div>
                    <Input
                      v-model="
                        formData.distance_premium_config.level_stairs[index]
                          .add_amount
                      "
                      v-width="150"
                    >
                      <span slot="append">元</span>
                    </Input>
                    <Button
                      type="text"
                      @click="deleteDistanceLadderPrice(index)"
                    >
                      <Icon type="ios-remove-circle-outline" size="16" />
                    </Button>
                    <Divider type="vertical" />
                    <Button
                      type="text"
                      v-if="
                        index ==
                        formData.distance_premium_config.level_stairs.length - 1
                      "
                      @click="addDistanceLadderPrice"
                      >+新增</Button
                    >
                  </div>
                </template>
                <div class="acea-row row-middle mb-22">
                  <Input
                    v-model="
                      formData.distance_premium_config.level_last.gt_distance
                    "
                    v-width="150"
                    disabled
                  >
                    <span slot="append">公里</span>
                  </Input>
                  <div class="pr-10 pl-10">外，每增加</div>
                  <Input
                    v-model="
                      formData.distance_premium_config.level_last.add_distance
                    "
                    v-width="150"
                  >
                    <span slot="append">公里</span>
                  </Input>
                  <div class="pr-10 pl-10">运费增加</div>
                  <Input
                    v-model="
                      formData.distance_premium_config.level_last.add_amount
                    "
                    v-width="150"
                  >
                    <span slot="append">元</span>
                  </Input>
                </div>
              </div>
              <!-- 重量阶梯价 -->
              <div
                class="pt-22 pr-22 pl-22 mt-22 bg-w111-FFFFFF overflow-hidden"
              >
                <div class="acea-row row-middle mb-22">
                  重量阶梯价
                  <Tooltip placement="right">
                    <Icon
                      type="ios-help-circle"
                      size="14"
                      color="#B6BABE"
                      class="ml-2"
                    />
                    <div slot="content" class="fs-12">
                      设置重量后，请务必检查商品重量是否正确填写
                    </div>
                  </Tooltip>
                </div>
                <div class="acea-row row-middle mb-22">
                  <Input
                    v-model="
                      formData.weight_premium_config.level_first.lt_weight
                    "
                    v-width="150"
                    @on-blur="onLtWeightBlur"
                  >
                    <span slot="append">千克</span>
                  </Input>
                  <div class="pr-10 pl-10">内，按基础运费计算</div>
                  <Button
                    type="text"
                    v-if="!formData.weight_premium_config.level_stairs.length"
                    @click="addWeightLadderPrice"
                    >+新增</Button
                  >
                </div>
                <template
                  v-if="formData.weight_premium_config.level_stairs.length"
                >
                  <div
                    class="acea-row row-middle mb-22"
                    v-for="(item, index) in formData.weight_premium_config
                      .level_stairs"
                    :key="index"
                  >
                    <Input
                      v-model="
                        formData.weight_premium_config.level_stairs[index]
                          .end_weight
                      "
                      v-width="183"
                      class="input-group"
                      @on-blur="onEndWeightBlur(index)"
                    >
                      <span slot="prepend">
                        <Input
                          v-model="
                            formData.weight_premium_config.level_stairs[index]
                              .start_weight
                          "
                          v-width="80"
                          disabled
                        >
                          <span slot="append">-</span>
                        </Input>
                      </span>
                      <span slot="append">千克</span>
                    </Input>
                    <div class="pr-10 pl-10">内，每增加</div>
                    <Input
                      v-model="
                        formData.weight_premium_config.level_stairs[index]
                          .add_weight
                      "
                      v-width="150"
                    >
                      <span slot="append">千克</span>
                    </Input>
                    <div class="pr-10 pl-10">运费增加</div>
                    <Input
                      v-model="
                        formData.weight_premium_config.level_stairs[index]
                          .add_amount
                      "
                      v-width="150"
                    >
                      <span slot="append">元</span>
                    </Input>
                    <Button type="text" @click="deleteWeightLadderPrice(index)">
                      <Icon type="ios-remove-circle-outline" size="16" />
                    </Button>
                    <Divider type="vertical" />
                    <Button
                      type="text"
                      v-if="
                        index ==
                        formData.weight_premium_config.level_stairs.length - 1
                      "
                      @click="addWeightLadderPrice"
                      >+新增</Button
                    >
                  </div>
                </template>
                <div class="acea-row row-middle mb-22">
                  <Input
                    v-model="
                      formData.weight_premium_config.level_last.gt_weight
                    "
                    v-width="150"
                    disabled
                  >
                    <span slot="append">千克</span>
                  </Input>
                  <div class="pr-10 pl-10">外，每增加</div>
                  <Input
                    v-model="
                      formData.weight_premium_config.level_last.add_weight
                    "
                    v-width="150"
                  >
                    <span slot="append">千克</span>
                  </Input>
                  <div class="pr-10 pl-10">运费增加</div>
                  <Input
                    v-model="
                      formData.weight_premium_config.level_last.add_amount
                    "
                    v-width="150"
                  >
                    <span slot="append">元</span>
                  </Input>
                </div>
              </div>
            </div>
          </FormItem>
        </div>
        <!-- 配送时间 -->
        <div class="relative mb-22 title">配送时间</div>
        <FormItem label="送达时间：">
          <RadioGroup v-model="formData.delivery_time_type">
            <Radio :label="1">买家可选定时送达</Radio>
            <Radio :label="2">统一尽快送达</Radio>
          </RadioGroup>
          <div v-show="formData.delivery_time_type == 1" class="text--w111-999">
            选中买家可选定时送达，买家下单选择同城配送时，需要选择送达时间，商家按约定时间送达。
          </div>
        </FormItem>
        <FormItem
          v-if="formData.delivery_time_type == 1"
          label="用户端可选定天数："
          :label-width="140"
        >
          <Input v-model="formData.selectable_days" v-width="280"></Input>
          <div class="text--w111-999">
            用户可预约未来几天的时间送达，填写1代表用户只能预约今天送达。
          </div>
        </FormItem>
        <FormItem
          v-if="formData.delivery_time_type == 2"
          label="尽快送达用户端文案："
          :label-width="140"
        >
          <Input
            v-model="formData.delivery_prompt"
            maxlength="10"
            show-word-limit
            v-width="280"
          >
          </Input>
        </FormItem>
      </Form>
    </Card>
    <div class="h-100"></div>
    <Card
      :bordered="false"
      dis-hover
      class="fixed-card"
      :style="{ left: `${!menuCollapse ? '220px' : isMobile ? '0' : '80px'}` }"
    >
      <Form>
        <FormItem>
          <Button
            type="primary"
            class="submission"
            @click="handleSubmit('formValidate')"
            >保存</Button
          >
        </FormItem>
      </Form>
    </Card>
  </div>
</template>

<script>
import { mapState, mapMutations } from 'vuex';
import lazyCascader from '@/components/lazyCascader';
import {
  keyApi,
  cityData,
  storeGetInfoApi,
  deliveryDetailApi,
  deliveryConfigUpdateApi,
  getBusiness,
} from '@/api/setting';

const cacheAddress = {};
let map1;
let circle1;
let map;
let editor;
let marker;
// let activeType = 'polygon'; // 激活的图形编辑类型
const colorList = [
  '#4073fa',
  '#0fc6c2',
  '#f56464',
  '#ff7d00',
  '#3491fa',
  '#9fdb1d',
  '#f7ba1e',
  '#b27feb',
];

export default {
  components: {
    lazyCascader,
  },
  data() {
    const prefix = 'id_' + this.getRandomId();

    const polygonToolId = prefix + '_polygon';
    const circleToolId = prefix + '_circle';
    const rectangleToolId = prefix + '_rectangle';
    const ellipseToolId = prefix + '_ellipse';
    return {
      polygonToolId,
      circleToolId,
      rectangleToolId,
      ellipseToolId,
      toolList: [
        {
          id: polygonToolId,
          type: 'polygon',
          title: '多边形',
        },
        {
          id: circleToolId,
          type: 'circle',
          title: '圆形',
        },
        {
          id: rectangleToolId,
          type: 'rectangle',
          title: '矩形',
        },
        {
          id: ellipseToolId,
          type: 'ellipse',
          title: '椭圆',
        },
      ],
      storeInfo: {},
      mapKey: '',
      activeType: 'polygon',
      formData: {
        city_delivery_type: 0, // 同城配送 0：商家自配 1：达达快送 2：UU跑腿
        business: 0, //同城配送商品类型
        range_type: 1, // 划分依据 1：按服务半径 2：按行政区域 3：电子围栏
        radius: 1, // 服务半径（公里）
        region: [], // 行政区域
        fence: [], // 电子围栏配置
        min_delivery_amount: '', // 起送价
        base_shipping_fee: '', // 基础运费
        free_shipping_amount: '', // 包邮规则
        is_premium_stack_enabled: 0, // 是否开启溢价叠加 0：关闭 1：开启
        distance_premium_config: {
          // 距离阶梯价
          level_first: {
            lt_distance: '',
          },
          level_stairs: [
            // {
            //   start_distance: null,
            //   end_distance: null,
            //   add_distance: '',
            //   add_amount: '',
            // },
          ],
          level_last: {
            gt_distance: '',
            add_distance: '',
            add_amount: '',
          },
        },
        weight_premium_config: {
          // 重量阶梯价
          level_first: {
            lt_weight: '',
          },
          level_stairs: [
            // {
            //   start_weight: null,
            //   end_weight: null,
            //   add_weight: '',
            //   add_amount: '',
            // },
          ],
          level_last: {
            gt_weight: '',
            add_weight: '',
            add_amount: '',
          },
        },
        delivery_time_type: 1, // 送达时间
        selectable_days: 7, // 用户端可选定天数
        delivery_prompt: '', // 尽快送达用户端文案
      },
      props: {
        children: 'children',
        label: 'label',
        value: 'value',
        multiple: true,
        lazy: true,
        lazyLoad: this.lazyLoad,
        checkStrictly: true,
      },
      editorMode: 'DRAW',
      fence: [],
      color1: '',
      autoCompleteValue: '',
      autoCompleteData: [],
      geometryTooltipData: {
        visible: false,
        label: '',
        x: 0,
        y: 0,
      },
      activeOverlayId: prefix + '_polygon',
      isFullscreen: false,
      businessList: [],
    };
  },
  computed: {
    ...mapState('store/layout', ['isMobile', 'menuCollapse']),
    labelWidth() {
      return this.isMobile ? undefined : 164;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
    geometryTooltipStyle() {
      return {
        top: this.geometryTooltipData.y + 'px',
        left: this.geometryTooltipData.x + 'px',
      };
    },
    currentToolType() {
      if (this.activeOverlayId === this.polygonToolId) {
        return 'polygon';
      } else if (this.activeOverlayId === this.circleToolId) {
        return 'circle';
      } else if (this.activeOverlayId === this.rectangleToolId) {
        return 'rectangle';
      } else if (this.activeOverlayId === this.ellipseToolId) {
        return 'ellipse';
      }
    },
  },
  watch: {

  },
  mounted() {
    this.$nextTick(() => {
      window.initMap = this.geocoderAddress;
      this.getStoreInfo();
      this.getDeliveryDetail();
    });
  },
  beforeDestroy() {
    map1 && map1.destroy();
    map && map.destroy();
    map1 = null;
    map = null;
  },
  methods: {
    handleToggleFullscreen() {
      // 切换全屏
      this.isFullscreen = !this.isFullscreen;
    },
    getRandomId() {
      // 生成随机 ID
      return Math.random().toString(36).substring(2, 10);
    },
    handleMouseLeave() {
      // 鼠标离开地图时，隐藏 tooltip
      this.geometryTooltipData.visible = false;
    },
    // 获取配送设置
    getDeliveryDetail() {
      deliveryDetailApi()
        .then((res) => {
          const { config, storeInfo } = res.data;
          const deliveryDetail = { ...config, ...storeInfo };
          for (const key in deliveryDetail) {
            if (!Object.hasOwn(this.formData, key)) continue;

            const value = deliveryDetail[key];

            if (
              key === 'distance_premium_config' ||
              key === 'weight_premium_config'
            ) {
              if (Array.isArray(value)) {
              } else {
                this.formData[key] = value;
              }
            } else {
              this.formData[key] = value;
            }
          }
          this.onCityDeliveryTypeChange(this.formData.city_delivery_type);
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 获取门店信息
    getStoreInfo() {
      storeGetInfoApi()
        .then((res) => {
          this.storeInfo = res.data;
          this.getKey();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 地址解析（地址转坐标）
    geocoderAddress() {
      this.$jsonp('https://apis.map.qq.com/ws/geocoder/v1', {
        address: `${this.storeInfo.address}${this.storeInfo.detailed_address}`,
        key: this.mapKey,
        output: 'jsonp',
      })
        .then((res) => {
          this.location = res.result.location;
          this.initMap(res.result.location);
        })
        .catch((err) => {
          this.$Message.error('获取城市编码失败');
        });
    },
    lazyLoad(node, resolve) {
      if (cacheAddress[node]) {
        cacheAddress[node]().then((res) => {
          resolve([...res.data]);
        });
      } else {
        const p = cityData({ pid: node });
        cacheAddress[node] = () => p;
        p.then((res) => {
          res.data.forEach((item) => {
            item.leaf = !item.hasOwnProperty('children');
          });
          cacheAddress[node] = () =>
            new Promise((resolve1) => {
              setTimeout(() => resolve1(res), 300);
            });
          resolve(res.data);
        }).catch((res) => {
          this.$message.error(res.message);
        });
      }
    },
    // 获取地图key
    getKey() {
      keyApi()
        .then((res) => {
          this.mapKey = res.data.tengxun_map_key;
          var script = document.createElement('script');
          script.type = 'text/javascript';
          script.src = `https://map.qq.com/api/gljs?libraries=tools&v=1.exp&key=${this.mapKey}&callback=initMap`;
          document.body.appendChild(script);
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    initMap(location) {
      const center = new TMap.LatLng(location.lat, location.lng);
      if (this.formData.range_type === 1 && !map1) {
        // 初始化地图
        map1 = new TMap.Map(document.getElementById('mapContainer1'), {
          zoom: 15, // 设置地图缩放级别
          center, // 设置地图中心点坐标
        });
        // 移除比例尺控件
        map1.removeControl(TMap.constants.DEFAULT_CONTROL_ID.SCALE);
        // 移除旋转控件
        map1.removeControl(TMap.constants.DEFAULT_CONTROL_ID.ROTATION);
        // 获取缩放控件实例
        let control = map1.getControl(TMap.constants.DEFAULT_CONTROL_ID.ZOOM);
        // 设置控件位置
        control.setPosition(TMap.constants.CONTROL_POSITION.BOTTOM_RIGHT);
        // 创建标注，用来显示地图中心点
        let marker1 = new TMap.MultiMarker({
          map: map1,
          geometries: [{ position: center }],
        });
        // 创建圆形覆盖物
        circle1 = new TMap.MultiCircle({
          map: map1,
          geometries: [
            {
              center, // 圆形中心点坐标
              radius: this.formData.radius * 1000, // 半径（单位：米）
            },
          ],
        });
      }

      if (this.formData.range_type === 3 && !map) {
        // 初始化地图
        map = new TMap.Map(document.getElementById('mapContainer'), {
          zoom: 15, // 设置地图缩放级别
          center, // 设置地图中心点坐标
        });
        // 移除比例尺控件
        map.removeControl(TMap.constants.DEFAULT_CONTROL_ID.SCALE);
        // 移除旋转控件
        map.removeControl(TMap.constants.DEFAULT_CONTROL_ID.ROTATION);
        // 获取缩放控件实例
        let control = map.getControl(TMap.constants.DEFAULT_CONTROL_ID.ZOOM);
        // 设置控件位置
        control.setPosition(TMap.constants.CONTROL_POSITION.BOTTOM_RIGHT);
        // 创建标注，用来显示地图中心点
        marker = new TMap.MultiMarker({
          map,
          geometries: [{ position: center }],
        });
        // 监听地图的鼠标移动事件，更新 tooltip 的坐标
        map.on('mousemove', this.updateTooltipPosition);
        this.initEditor();
      }
    },
    updateTooltipPosition(event) {
      // 更新 tooltip 的坐标
      this.geometryTooltipData.x = event.point.x;
      this.geometryTooltipData.y = event.point.y;
    },
    generateOverlay(map) {
      // 生成 overlay 配置

      const TMap = window.TMap;
      const overlayConfig = [
        {
          type: 'polygon', // overlay 类型
          id: this.polygonToolId, // overlay 的 id
          factory: TMap.MultiPolygon, // overlay 的工厂函数
        },
        {
          type: 'circle',
          id: this.circleToolId,
          factory: TMap.MultiCircle,
        },
        {
          type: 'rectangle',
          id: this.rectangleToolId,
          factory: TMap.MultiRectangle,
        },
        {
          type: 'ellipse',
          id: this.ellipseToolId,
          factory: TMap.MultiEllipse,
        },
      ];

      // 将电子围栏数据按 overlay类型分组
      const fenceGroupByType = this.fence.reduce((acc, item) => {
        acc[item.type] = acc[item.type] || [];
        acc[item.type].push(item);
        return acc;
      }, {});

      // 根据 overlay 类型生成所有的对应的颜色实例对象
      // 例如 { "#000000": 颜色实例 }
      const getStyle = (type) => {
        const fenceList = fenceGroupByType[type];
        let styles = {};
        colorList.forEach((color) => {
          styles[color] = this.generateStyle(type, color);
        });
        if (fenceList && fenceList.length) {
          fenceGroupByType[type].reduce((acc, item) => {
            acc[item.color] =
              acc[item.color] || this.generateStyle(type, item.color);
            return acc;
          }, styles);
        }

        return {
          highlight: this.generateStyle(type, '#ffff00'), // 高亮样式
          default: this.generateStyle(type, colorList[0]), // 默认样式
          ...styles, // fenceData 中对应的颜色实例对象
        };
      };

      // 根据 overlay 类型，将 fenceData 转为 geometry 数据
      const getGeometries = (type) => {
        const fenceList = fenceGroupByType[type];
        if (!fenceList || fenceList.length === 0) return [];
        const data = fenceList.map((item) => this.convertToGeometry(item));
        return data;
      };

      // 生成 overlayList 配置
      return overlayConfig.map((item) => {
        return {
          overlay: new item.factory({
            map,
            styles: getStyle(item.type),
            geometries: getGeometries(item.type),
          }),
          id: item.id,
          selectedStyleId: 'highlight',
        };
      });
    },
    updateTooltipContent(event) {
      // 更新 tooltip 的内容
      let label = '';
      if (event.geometry) {
        const fenceData = this.fence.find(
          (item) => item.id === event.geometry.id
        );
        if (fenceData) {
          label = fenceData.title;
        }
      }
      this.geometryTooltipData.visible = !!event.geometry;
      this.geometryTooltipData.label = label;
    },
    initEditor() {
      const TMap = window.TMap;
      const overlayList = this.generateOverlay(map);
      // 初始化几何图形及编辑器
      // let polygon = new TMap.MultiPolygon({
      //   map,
      // styles: {
      //   highlight: new TMap.PolygonStyle({
      //     color: 'rgba(255, 255, 0, 0.6)',
      //   }),
      // },
      // geometries: [
      //   {
      //     id: 'polygon1',
      //     paths: simplePath,
      //   },
      // ],
      // });
      // let circle = new TMap.MultiCircle({
      //   map,
      // });
      // let rectangle = new TMap.MultiRectangle({
      //   map,
      // });
      // let ellipse = new TMap.MultiEllipse({
      //   map,
      // });
      editor = new TMap.tools.GeometryEditor({
        map, // 编辑器绑定的地图对象
        overlayList,
        // overlayList: [
        //   {
        //     overlay: polygon,
        //     id: 'polygon',
        //     selectedStyleId: 'highlight',
        //   },
        //   {
        //     overlay: circle,
        //     id: 'circle',
        //     selectedStyleId: 'highlight',
        //   },
        //   {
        //     overlay: rectangle,
        //     id: 'rectangle',
        //     selectedStyleId: 'highlight',
        //   },
        //   {
        //     overlay: ellipse,
        //     id: 'ellipse',
        //     selectedStyleId: 'highlight',
        //   },
        // ],
        actionMode: TMap.tools.constants.EDITOR_ACTION.DRAW, // 编辑器的工作模式
        activeOverlayId: this.activeOverlayId, // 激活图层
        selectable: true, // 开启选择
        snappable: true, // 开启吸附
        selectedStyleId: 'highlight',
      });

      overlayList.forEach((overlayItem) => {
        // 监听 overlay 的 hover 事件，更新 tooltip 的内容
        overlayItem.overlay.on('hover', this.updateTooltipContent);
      });
      // 监听绘制结束事件，获取绘制几何图形
      editor.on('draw_complete', (geometry) => {
        // console.log(geometry);
        // console.log(editor.getActiveOverlay());
        // 判断当前处于编辑状态的图层id是否是overlayList中id为rectangle（矩形）图层
        // 判断当前处于编辑状态的图层id是否是overlayList中id为rectangle（矩形）图层
        // var id = geometry.id;
        // if (editor.getActiveOverlay().id === 'rectangle') {
        //   // 获取矩形顶点坐标
        //   var geo = rectangle.geometries.filter(function (item) {
        //     return item.id === id;
        //   });
        //   console.log('绘制的矩形定位的坐标：', geo[0].paths);
        // }
        // 根据当前选取类型解析成标准的电子围栏数据
        const fenceData = this.parseGeometry(geometry, this.currentToolType);

        // 保存当前 fenceData
        this.saveRegion(fenceData);

        // 更新当前绘制的电子围栏数据的颜色
        const nextFenceData = this.fence[this.fence.length - 1];
        this.handleColorChange(nextFenceData);
      });

      editor.on('delete_complete', (geometryList) => {
        // map 控件中删除 geometry 时，同步删除 fenceData
        const idMap = geometryList.reduce((acc, item) => {
          acc[item.id] = 1;
          return acc;
        }, {});
        this.fence = this.fence.filter((item) => !idMap[item.id]);
      });

      editor.on('adjust_complete', (geometry) => {
        // map 控件中调整 geometry 时，同步调整 fenceData
        const fenceData = this.fence.find((item) => item.id === geometry.id);
        if (!fenceData) return;
        const nextFenceData = this.parseGeometry(geometry, fenceData.type);
        Object.assign(fenceData, nextFenceData);
      });
    },
    generateStyle(overlayType, color) {
      // 根据 overlay 类型和颜色生成对应的颜色实例
      // 传入的颜色设置为边框颜色，填充颜色使用传入颜色的 16% 透明度

      let factory;
      if (overlayType === 'polygon') {
        factory = TMap.PolygonStyle;
      } else if (overlayType === 'circle') {
        factory = TMap.CircleStyle;
      } else if (overlayType === 'rectangle') {
        factory = TMap.RectangleStyle;
      } else if (overlayType === 'ellipse') {
        factory = TMap.EllipseStyle;
      }
      if (!factory) return;
      return new factory({
        color: this.hexToRgba(color),
        borderColor: color,
      });
    },
    hexToRgba(hex, alpha = 0.16) {
      // 将十六进制颜色转换为 rgba 颜色，支持设置透明度

      // 去掉开头的 #
      hex = hex.replace(/^#/, '');

      // 处理三位简写情况 #abc -> #aabbcc
      if (hex.length === 3) {
        hex = hex
          .split('')
          .map((c) => c + c)
          .join('');
      }

      const r = parseInt(hex.slice(0, 2), 16);
      const g = parseInt(hex.slice(2, 4), 16);
      const b = parseInt(hex.slice(4, 6), 16);

      return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    },
    handleColorChange(fenceData) {
      // 根据选择的颜色生成新样式
      const overlayItem = editor
        .getOverlayList()
        .find((item) => item.id.endsWith(fenceData.type));
      if (!overlayItem) return;
      const overlay = overlayItem.overlay;
      const prevStyles = overlay.getStyles();

      if (!prevStyles[fenceData.color]) {
        // 根据选择的新颜色不存在，则生成新的颜色配置
        // 并更新到 overlay 中
        const newStyle = this.generateStyle(fenceData.type, fenceData.color);
        if (!newStyle) return;

        prevStyles[fenceData.color] = newStyle;
        overlay.setStyles(prevStyles);
      }

      // 将 fenceData 转换为地图需要的 geometry 数据
      // 并更新 overlay 中对应的 geometry
      const nextGeometry = this.convertToGeometry(fenceData);
      overlay.updateGeometries([nextGeometry]);
    },
    generateColorByGeometryLength() {
      // 根据电子围栏数量生成颜色
      const length = this.fence.length;
      return colorList[length % colorList.length];
    },
    convertToGeometry(fenceData) {
      // 将标准电子围栏数据转换为地图需要的 geometry 数据
      const { type } = fenceData;
      const geometry = {
        id: fenceData.id,
        styleId: fenceData.color,
      };

      if (type === 'polygon') {
        geometry.paths = fenceData.data.paths.map(
          (path) => new TMap.LatLng(path.lat, path.lng)
        );
      } else if (type === 'circle') {
        geometry.center = new TMap.LatLng(
          fenceData.data.center.lat,
          fenceData.data.center.lng
        );
        geometry.radius = Number(fenceData.data.radius);
      } else if (type === 'rectangle') {
        geometry.center = new TMap.LatLng(
          fenceData.data.center.lat,
          fenceData.data.center.lng
        );
        geometry.width = fenceData.data.width;
        geometry.height = fenceData.data.height;
      } else if (type === 'ellipse') {
        geometry.center = new TMap.LatLng(
          fenceData.data.center.lat,
          fenceData.data.center.lng
        );
        geometry.majorRadius = Number(fenceData.data.majorRadius);
        geometry.minorRadius = Number(fenceData.data.minorRadius);
      }

      return geometry;
    },
    parseGeometry(geometry, type) {
      // 将地图几何体数据转换为标准电子围栏数据
      if (type === 'polygon') {
        return {
          id: geometry.id,
          type: 'polygon',
          data: {
            paths: geometry.paths,
          },
        };
      } else if (type === 'circle') {
        return {
          id: geometry.id,
          type: 'circle',
          data: {
            radius: geometry.radius,
            center: geometry.center,
          },
        };
      } else if (type === 'rectangle') {
        return {
          id: geometry.id,
          type: 'rectangle',
          data: {
            width: geometry.width,
            height: geometry.height,
            center: geometry.center,
          },
        };
      } else if (type === 'ellipse') {
        return {
          id: geometry.id,
          type: 'ellipse',
          data: {
            center: geometry.center,
            majorRadius: geometry.majorRadius,
            minorRadius: geometry.minorRadius,
          },
        };
      }
    },
    saveRegion(fenceData) {
      // 保存电子围栏区域
      const baseFenceData = {
        title: `区域${this.fence.length + 1}`,
        color: fenceData.color || this.generateColorByGeometryLength(),
      };

      this.fence.push({
        ...baseFenceData,
        ...fenceData,
      });
    },
    // 添加配送区域
    addRegion() {
      // 获取处于编辑状态的图层
      const activeOverlay = editor.getActiveOverlay();
      if (!activeOverlay) {
        return;
      }
      // 获取处于编辑状态的几何图层
      const overlay = activeOverlay.overlay;
      // console.log(overlay);
      // console.log(activeOverlay);
      // 获取当前激活的 overlay 类型
      const currentOverlayType = overlay._layerType.toLowerCase();
      // 获取地图中心
      const center = overlay.getMap().getCenter();

      const POSITION_OFFSET = 0.01;

      // 默认电子围栏数据
      let defaultFenceData = {
        color: this.generateColorByGeometryLength(),
        type: currentOverlayType, // 当前激活的 overlay 类型
        data: {
          paths: [
            // 多边形数据
            {
              lat: center.lat + POSITION_OFFSET, // 纬度 -> top
              lng: center.lng - POSITION_OFFSET, // 经度 -> right
            },
            {
              lat: center.lat + POSITION_OFFSET,
              lng: center.lng + POSITION_OFFSET,
            },
            {
              lat: center.lat - POSITION_OFFSET,
              lng: center.lng + POSITION_OFFSET,
            },
            {
              lat: center.lat - POSITION_OFFSET,
              lng: center.lng - POSITION_OFFSET,
            },
          ],

          center: {
            // 圆形数据、矩形数据、椭圆数据的中心点
            lat: center.lat,
            lng: center.lng,
          },

          radius: 500, // 圆形半径数据

          width: 500, // 矩形宽度数据
          height: 500, // 矩形高度数据

          majorRadius: 300, // 椭圆长轴半径数据
          minorRadius: 400, // 椭圆短轴半径数据
        },
      };

      // 将默认电子围栏数据转换为地图需要的 geometry 数据，函数中会自动根据类型生成对应的 geometry 数据
      const geometryHalf = this.convertToGeometry(defaultFenceData);

      overlay.add([geometryHalf]);
      const geometries = overlay.getGeometries();
      const fenceData = this.parseGeometry(
        geometries[geometries.length - 1],
        currentOverlayType
      );
      this.saveRegion(fenceData);
    },
    // 切换激活图层
    handleMapToolClick(tool) {
      // 点击地图工具栏事件
      // 更新 editor 选中的 overlay 类型
      const toolType = tool.type;
      if (!toolType) return;
      this.activeOverlayId = tool.id;
      editor.setActiveOverlay(this.activeOverlayId);
    },
    // 服务半径改变
    onRadiusChange(event) {
      const value = Number(event.target.value);
      if (!isNaN(value) && value > 0) {
        circle1.setGeometries([
          {
            center: circle1.getMap().getCenter(), // 圆形中心点坐标
            radius: value * 1000, // 半径（单位：米）
          },
        ]);
      }
    },
    handleQueryAddressPosition(address) {
      // 获取地址的经纬度
      return this.$jsonp('https://apis.map.qq.com/ws/geocoder/v1', {
        address: `${address}`,
        key: this.mapKey,
        output: 'jsonp',
      });
    },
    async onAutoCompleteSearch(value) {
      const options = {
        key: this.mapKey,
        keyword: value,
        output: 'jsonp',
      };
      if (map) {
        const center = map.getCenter();
        options.location = `${center.lat},${center.lng}`;
      }
      const { data, message } = await this.$jsonp(
        'https://apis.map.qq.com/ws/place/v1/suggestion',
        options
      );

      // this.autoCompleteData = data.map((item) => item.title);
      this.autoCompleteData = data;
    },
    onAutoCompleteSelect(value) {
      this.autoCompleteValue = '';
      this.$nextTick(() => {
        const selected = this.autoCompleteData.find(
          (item) => item.id === value
        );
        this.autoCompleteValue = selected.title;
        const { lng, lat } = selected.location;
        this.setMapCenter(lat, lng);
      });
    },
    setMapCenter(lat, lng) {
      const centerPoint = new TMap.LatLng(lat, lng);

      // 更新地图中心点
      map.setCenter(centerPoint);

      // 更新标注
      marker.setGeometries([
        {
          position: centerPoint,
        },
      ]);
    },
    // 新增距离阶梯价
    addDistanceLadderPrice() {
      if (this.formData.distance_premium_config.level_stairs.length == 7) {
        return this.$Message.warning('阶梯层数最多为7');
      }
      const distance_premium_config = this.formData.distance_premium_config;
      if (distance_premium_config.level_stairs.length) {
        let level_stairs_item = distance_premium_config.level_stairs[distance_premium_config.level_stairs.length - 1];
        // 范围内公里数
        // if (!level_stairs_item.end_distance) {
        //   return this.$Message.warning('请输入范围内公里数');
        // }
        // if (isNaN(Number(level_stairs_item.end_distance))) {
        //   return this.$Message.warning('请输入范围内公里数');
        // }
        // if (Number(level_stairs_item.end_distance) <= 0) {
        //   return this.$Message.warning('请输入范围内公里数');
        // }

        // 增加公里数
        // if (!level_stairs_item.add_distance) {
        //   return this.$Message.warning('请输入增加公里数');
        // }
        // if (isNaN(Number(level_stairs_item.add_distance))) {
        //   return this.$Message.warning('请输入增加公里数');
        // }
        // if (Number(level_stairs_item.add_distance) <= 0) {
        //   return this.$Message.warning('请正确输入增加公里数');
        // }
        // if (level_stairs_item.add_distance.includes('.')) {
        //   return this.$Message.warning('增加公里数需要是正整数');
        // }

        // 运费
        // if (!level_stairs_item.add_amount) {
        //   return this.$Message.warning('请输入运费');
        // }
        // if (isNaN(Number(level_stairs_item.add_amount))) {
        //   return this.$Message.warning('请输入运费');
        // }
        // if (Number(level_stairs_item.add_amount) <= 0) {
        //   return this.$Message.warning('请正确输入运费');
        // }
        // let add_amount = level_stairs_item.add_amount.split('.');
        // if (add_amount[1].length > 2) {
        //   return this.$Message.warning('运费只支持最多两位小数');
        // }

        this.formData.distance_premium_config.level_stairs.push({
          start_distance: level_stairs_item.end_distance,
          end_distance: null,
          add_distance: '',
          add_amount: '',
        });
      } else {
        // let lt_distance = distance_premium_config.level_first.lt_distance;
        // if (!lt_distance) {
        //   return this.$Message.warning('请输入按基础运费计算的公里数');
        // }
        // lt_distance = Number(lt_distance);
        // if (isNaN(lt_distance)) {
        //   return this.$Message.warning('请输入按基础运费计算的公里数');
        // }
        // if (lt_distance <= 0) {
        //   return this.$Message.warning('请输入按基础运费计算的公里数');
        // }
        this.formData.distance_premium_config.level_stairs.push({
          start_distance: distance_premium_config.level_first.lt_distance,
          end_distance: null,
          add_distance: '',
          add_amount: '',
        });
      }
    },
    // 删除距离阶梯价
    deleteDistanceLadderPrice(index) {
      this.$Modal.confirm({
        title: '提示',
        content: '此操作将删除该层级, 是否继续？',
        onOk: () => {
          this.formData.distance_premium_config.level_stairs.splice(index, 1);
          for (let i = 1; i < this.formData.distance_premium_config.level_stairs.length; i++) {
            this.formData.distance_premium_config.level_stairs[i].start_distance = this.formData.distance_premium_config.level_stairs[i - 1].end_distance;
          }
          if (this.formData.distance_premium_config.level_stairs.length) {
            this.formData.distance_premium_config.level_last.gt_distance = this.formData.distance_premium_config.level_stairs[this.formData.distance_premium_config.level_stairs.length - 1].end_distance;
          } else {
            this.formData.distance_premium_config.level_last.gt_distance = this.formData.distance_premium_config.level_first.lt_distance;
          }
        },
      });
    },
    // 新增重量阶梯价
    addWeightLadderPrice() {
      if (this.formData.weight_premium_config.level_stairs.length == 7) {
        return this.$Message.warning('阶梯层数最多为7');
      }
      if (this.formData.weight_premium_config.level_stairs.length) {
        this.formData.weight_premium_config.level_stairs.push({
          start_weight: this.formData.weight_premium_config.level_stairs[this.formData.weight_premium_config.level_stairs.length - 1].end_weight,
          end_weight: null,
          add_weight: '',
          add_amount: '',
        });
      } else {
        this.formData.weight_premium_config.level_stairs.push({
          start_weight: this.formData.weight_premium_config.level_first.lt_weight,
          end_weight: null,
          add_weight: '',
          add_amount: '',
        });
      }
    },
    // 删除重量阶梯价
    deleteWeightLadderPrice(index) {
      this.$Modal.confirm({
        title: '提示',
        content: '此操作将删除该层级, 是否继续？',
        onOk: () => {
          this.formData.weight_premium_config.level_stairs.splice(index, 1);
          for (let i = 1; i < this.formData.weight_premium_config.level_stairs.length; i++) {
            this.formData.weight_premium_config.level_stairs[i].start_weight = this.formData.weight_premium_config.level_stairs[i - 1].end_weight;
          }
          if (this.formData.weight_premium_config.level_stairs.length) {
            this.formData.weight_premium_config.level_last.gt_weight = this.formData.weight_premium_config.level_stairs[this.formData.weight_premium_config.level_stairs.length - 1].end_weight;
          } else {
            this.formData.weight_premium_config.level_last.gt_weight = this.formData.weight_premium_config.level_first.lt_weight;
          }
        },
      });
    },
    handleSubmit(name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
          // 划分依据
          if (this.formData.range_type == 1) {
            this.formData.region = [];
            this.formData.fence = [];
          } else if (this.formData.range_type == 2) {
            this.formData.radius = 1;
            this.formData.fence = [];
          } else if (this.formData.range_type == 3) {
            this.formData.radius = 1;
            this.formData.region = [];
          }
          // 开启叠加溢价
          if (!this.formData.city_delivery_type && this.formData.is_premium_stack_enabled) {
            // 距离阶梯价
            let lt_distance = this.formData.distance_premium_config.level_first.lt_distance;
            if (!lt_distance) {
              return this.$Message.warning('公里数需为正整数');
            }
            if (isNaN(Number(lt_distance))) {
              return this.$Message.warning('公里数需为正整数');
            }
            if (Number(lt_distance) <= 0) {
              return this.$Message.warning('公里数需为正整数');
            }

            for (const level_stairs_item of this.formData.distance_premium_config.level_stairs) {
              // 范围内公里数
              if (!level_stairs_item.end_distance) {
                return this.$Message.warning('公里数需为正整数');
              }
              if (isNaN(Number(level_stairs_item.end_distance))) {
                return this.$Message.warning('公里数需为正整数');
              }
              if (Number(level_stairs_item.end_distance) <= 0) {
                return this.$Message.warning('公里数需为正整数');
              }
              // 增加公里数
              if (!level_stairs_item.add_distance) {
                return this.$Message.warning('公里数需为正整数');
              }
              if (isNaN(Number(level_stairs_item.add_distance))) {
                return this.$Message.warning('公里数需为正整数');
              }
              if (Number(level_stairs_item.add_distance) <= 0) {
                return this.$Message.warning('公里数需为正整数');
              }
              if (level_stairs_item.add_distance.includes('.')) {
                return this.$Message.warning('公里数需为正整数');
              }
              // 运费
              if (!level_stairs_item.add_amount) {
                return this.$Message.warning('运费需为最多两位小数的正数');
              }
              if (isNaN(Number(level_stairs_item.add_amount))) {
                return this.$Message.warning('运费需为最多两位小数的正数');
              }
              if (Number(level_stairs_item.add_amount) <= 0) {
                return this.$Message.warning('运费需为最多两位小数的正数');
              }
              let add_amount = level_stairs_item.add_amount.split('.');
              if (add_amount[1] && add_amount[1].length > 2) {
                return this.$Message.warning('运费需为最多两位小数的正数');
              }
            }

            let level_last = this.formData.distance_premium_config.level_last;
            if (!level_last.add_distance) {
              return this.$Message.warning('公里数需为正整数');
            }
            if (isNaN(Number(level_last.add_distance))) {
              return this.$Message.warning('公里数需为正整数');
            }
            if (Number(level_last.add_distance) <= 0) {
              return this.$Message.warning('公里数需为正整数');
            }
            if (!level_last.add_amount) {
              return this.$Message.warning('运费需为最多两位小数的正数');
            }
            if (isNaN(Number(level_last.add_amount))) {
              return this.$Message.warning('运费需为最多两位小数的正数');
            }
            if (Number(level_last.add_amount) <= 0) {
              return this.$Message.warning('运费需为最多两位小数的正数');
            }
            let add_amount = level_last.add_amount.split('.');
            if (add_amount[1] && add_amount[1].length > 2) {
              return this.$Message.warning('运费需为最多两位小数的正数');
            }

            // 重量阶梯价
            let lt_weight = this.formData.weight_premium_config.level_first.lt_weight;
            if (!lt_weight) {
              return this.$Message.warning('千克数需为正整数');
            }
            if (isNaN(Number(lt_weight))) {
              return this.$Message.warning('千克数需为正整数');
            }
            if (Number(lt_weight) <= 0) {
              return this.$Message.warning('千克数需为正整数');
            }

            for (const level_stairs_item of this.formData.weight_premium_config.level_stairs) {
              // 范围内千克数
              if (!level_stairs_item.end_weight) {
                return this.$Message.warning('千克数需为正整数');
              }
              if (isNaN(Number(level_stairs_item.end_weight))) {
                return this.$Message.warning('千克数需为正整数');
              }
              if (Number(level_stairs_item.end_weight) <= 0) {
                return this.$Message.warning('千克数需为正整数');
              }
              // 增加千克数
              if (!level_stairs_item.add_weight) {
                return this.$Message.warning('千克数需为正整数');
              }
              if (isNaN(Number(level_stairs_item.add_weight))) {
                return this.$Message.warning('千克数需为正整数');
              }
              if (Number(level_stairs_item.add_weight) <= 0) {
                return this.$Message.warning('千克数需为正整数');
              }
              if (level_stairs_item.add_weight.includes('.')) {
                return this.$Message.warning('千克数需为正整数');
              }
              // 运费
              if (!level_stairs_item.add_amount) {
                return this.$Message.warning('运费需为最多两位小数的正数');
              }
              if (isNaN(Number(level_stairs_item.add_amount))) {
                return this.$Message.warning('运费需为最多两位小数的正数');
              }
              if (Number(level_stairs_item.add_amount) <= 0) {
                return this.$Message.warning('运费需为最多两位小数的正数');
              }
              let add_amount = level_stairs_item.add_amount.split('.');
              if (add_amount[1] && add_amount[1].length > 2) {
                return this.$Message.warning('运费需为最多两位小数的正数');
              }
            }

            let weight_level_last = this.formData.weight_premium_config.level_last;
            if (!weight_level_last.add_weight) {
              return this.$Message.warning('千克数需为正整数');
            }
            if (isNaN(Number(weight_level_last.add_weight))) {
              return this.$Message.warning('千克数需为正整数');
            }
            if (Number(weight_level_last.add_weight) <= 0) {
              return this.$Message.warning('千克数需为正整数');
            }
            if (!weight_level_last.add_amount) {
              return this.$Message.warning('运费需为最多两位小数的正数');
            }
            if (isNaN(Number(weight_level_last.add_amount))) {
              return this.$Message.warning('运费需为最多两位小数的正数');
            }
            if (Number(weight_level_last.add_amount) <= 0) {
              return this.$Message.warning('运费需为最多两位小数的正数');
            }
            let weight_add_amount = weight_level_last.add_amount.split('.');
            if (weight_add_amount[1] && weight_add_amount[1].length > 2) {
              return this.$Message.warning('运费需为最多两位小数的正数');
            }
          }
          deliveryConfigUpdateApi(this.formData)
            .then((res) => {
              this.$Message.success(res.msg);
            })
            .catch((err) => {
              this.$Message.error(err.msg);
            });
        } else {
          this.$Message.error('请完善信息');
        }
      });
    },
    // 添加/编辑
    onEditorModeChange(value) {
      if (!editor) return;
      const { DRAW, INTERACT } = TMap.tools.constants.EDITOR_ACTION;
      editor.setActionMode(value === 'DRAW' ? DRAW : INTERACT);
      editor.setActiveOverlay(this.activeOverlayId);
    },
    // 划分依据
    onRangeTypeChange() {
      this.$nextTick(() => {
        this.initMap(this.location);
      });
    },
    handleFenceTitleFocus(fenceData) {
      if (!this.map) return;

      const location =
        fenceData.type === 'polygon'
          ? fenceData.data.paths[0]
          : fenceData.data.center;

      this.map.panTo(new TMap.LatLng(location.lat, location.lng));
    },
    // 删除电子围栏区域
    handleDelFence(index) {
      const fenceData = this.fence[index];
      this.fence.splice(index, 1);
      if (!editor) return;
      const overlayItem = editor
        .getOverlayList()
        .find((item) => item.id.endsWith(fenceData.type));
      const overlay = overlayItem.overlay;
      overlay.remove([fenceData.id]);
    },
    getBusinessList() {
      getBusiness(this.formData.city_delivery_type)
        .then((res) => {
          this.businessList = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    onCityDeliveryTypeChange(value) {
      if (value) {
        this.getBusinessList();
      }
    },
    onLtDistanceBlur() {
      if (this.formData.distance_premium_config.level_stairs.length) {
        this.formData.distance_premium_config.level_stairs[0].start_distance = this.formData.distance_premium_config.level_first.lt_distance;
      } else {
        this.formData.distance_premium_config.level_last.gt_distance = this.formData.distance_premium_config.level_first.lt_distance;
      }
    },
    onEndDistanceBlur(index) {
      if (index === this.formData.distance_premium_config.level_stairs.length - 1) {
        this.formData.distance_premium_config.level_last.gt_distance = this.formData.distance_premium_config.level_stairs[index].end_distance;
      } else {
        this.formData.distance_premium_config.level_stairs[index + 1].start_distance = this.formData.distance_premium_config.level_stairs[index].end_distance;
      }
    },
    onLtWeightBlur() {
      if (this.formData.weight_premium_config.level_stairs.length) {
        this.formData.weight_premium_config.level_stairs[0].start_weight = this.formData.weight_premium_config.level_first.lt_weight;
      } else {
        this.formData.weight_premium_config.level_last.gt_weight = this.formData.weight_premium_config.level_first.lt_weight;
      }
    },
    onEndWeightBlur(index) {
      if (index === this.formData.weight_premium_config.level_stairs.length - 1) {
        this.formData.weight_premium_config.level_last.gt_weight = this.formData.weight_premium_config.level_stairs[index].end_weight;
      } else {
        this.formData.weight_premium_config.level_stairs[index + 1].start_weight = this.formData.weight_premium_config.level_stairs[index].end_weight;
      }
    },
  },
};
</script>

<style lang="less" scoped>
.fixed-card {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 200px;
  z-index: 1001;
  box-shadow: 0 -1px 2px rgb(240, 240, 240);

  /deep/ .ivu-card-body {
    padding: 15px 16px 14px;
  }

  .ivu-form-item {
    margin-bottom: 0;
  }

  /deep/ .ivu-form-item-content {
    text-align: center;
  }

  .ivu-btn {
    height: 36px;
    padding: 0 20px;
  }
}
/deep/.ivu-tooltip-inner {
  max-width: none;
}
/deep/.input-group {
  > .ivu-input {
    border-left-color: transparent;
    &:hover {
      border-left-color: #57a3f3;
    }
    &:focus {
      border-left-color: #57a3f3;
    }
  }
  .ivu-input-group-prepend {
    padding: 0;
    border: 0;
    .ivu-input {
      border-right-color: transparent;
      border-top-left-radius: 4px;
      border-bottom-left-radius: 4px;
      &:hover {
        border-right-color: #57a3f3;
      }
      &:focus {
        border-right-color: #57a3f3;
      }
    }
    .ivu-input-group-append {
      border-right: 0;
      border-radius: 0;
      background-color: #ffffff;
    }
  }
}
.ivu-divider,
.ivu-divider-vertical {
  margin: 0;
}
.ivu-btn-text {
  border-color: transparent !important;
  color: #2681ff;
  &:focus {
    box-shadow: none;
  }
}
.title {
  &::before {
    content: '';
    display: inline-block;
    width: 2px;
    height: 14px;
    margin-right: 5px;
    background-color: #2a7efb;
    vertical-align: middle;
  }
}
.map-wrapper {
  width: 1000px;
  padding: 20px;
  background-color: #f5f7fa;
  &.full-screen {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    left: 0;
    width: 100%;
    inset: 0;
    z-index: 120;

    .color-box {
      height: 90vh;
    }

    .map-next {
      height: 84vh;
    }
  }
}
.map-wrap {
  padding: 20px;
  border-radius: 5px;
  background-color: #ffffff;
}
#mapContainer {
  width: 100%;
  height: 450px;
}
.map-control {
  position: absolute;
  top: 10px;
  left: 10px;
  right: 10px;
  // margin: auto;
  // width: 252px;
  z-index: 1001;
}

.toolItem {
  width: 32px;
  height: 32px;
  float: left;
  margin: 1px;
  padding: 4px;
  border-radius: 3px;
  background-size: 24px 24px;
  background-position: 4px 4px;
  background-repeat: no-repeat;
  box-shadow: 0 1px 2px 0 #e4e7ef;
  background-color: #ffffff;
  border: 1px solid #ffffff;
}

.toolItem:hover {
  border-color: #789cff;
}

.active {
  border-color: #d5dff2;
  background-color: #d5dff2;
}

.marker {
  background-image: url('https://mapapi.qq.com/web/lbs/javascriptGL/demo/img/marker_editor.png');
}

.polyline {
  background-image: url('https://mapapi.qq.com/web/lbs/javascriptGL/demo/img/polyline.png');
}

.polygon {
  background-image: url('https://mapapi.qq.com/web/lbs/javascriptGL/demo/img/polygon.png');
}

.circle {
  background-image: url('https://mapapi.qq.com/web/lbs/javascriptGL/demo/img/circle.png');
}

.rectangle {
  background-image: url('https://mapapi.qq.com/web/lbs/javascriptGL/demo/img/rectangle.png');
}

.ellipse {
  background-image: url('https://mapapi.qq.com/web/lbs/javascriptGL/demo/img/ellipse.png');
}
.geometry-tooltip {
  position: absolute;
  top: 0;
  left: 0;
  background-color: rgba(0, 0, 0, 0.8);
  padding: 10px;
  border-radius: 5px;
  color: #fff;
  line-height: 1.5;
  transform: translate3d(-113%, -50%, 0);

  &::before {
    content: '';
    position: absolute;
    right: -10px;
    top: 13px;
    z-index: 1002;
    border-top: 7px solid transparent;
    border-left: 10px solid rgba(0, 0, 0, 0.8);
    border-bottom: 7px solid transparent;
  }
}
.fullscreen-btn {
  position: absolute;
  top: 10px;
  right: 10px;
  z-index: 1001;
  background-color: rgba(0, 0, 0, 0.8);
  width: 30px;
  height: 30px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;

  .iconfont-h5 {
    font-size: 20px;
    color: #fff;
  }
}
</style>