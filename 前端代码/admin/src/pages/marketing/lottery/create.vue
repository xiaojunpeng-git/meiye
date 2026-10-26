<template>
  <!-- 营销-抽奖配置 -->
  <div class="form-submit">
    <div class="i-layout-page-header">
      <PageHeader class="product_tabs" hidden-breadcrumb>
        <div slot="title">
          <router-link :to="{ path: `${roterPre}/marketing/lottery/index` }">
            <div class="font-sm after-line">
              <span class="iconfont iconfanhui"></span>
              <span class="pl10">返回</span>
            </div>
          </router-link>
          <span v-text="id ? '编辑抽奖' : '添加抽奖'" class="mr20 ml16"></span>
        </div>
      </PageHeader>
    </div>
    <Card :bordered="false" dis-hover class="ivu-mt mb79">
      <Form
        class="form"
        ref="formValidate"
        :rules="ruleValidate"
        :model="formValidate"
        :label-width="labelWidth"
        :label-position="labelPosition"
        @submit.native.prevent
      >
        <FormItem label="活动类型：">
          <RadioGroup v-model="formValidate.factor">
            <Radio :label="1" :disabled="!!id">积分抽奖</Radio>
            <Radio :label="3" :disabled="!!id">订单支付</Radio>
            <Radio :label="4" :disabled="!!id">订单评价</Radio>
            <Radio :label="5" :disabled="!!id">关注公众号</Radio>
          </RadioGroup>
        </FormItem>
        <!-- 积分抽奖 -->
        <FormItem label="活动名称：" prop="name" label-for="name">
          <Input
            v-width="320"
            placeholder="请输入活动名称"
            element-id="name"
            maxlength="50"
            show-word-limit
            v-model="formValidate.name"
          />
        </FormItem>
        <FormItem label="活动时间：" prop="period">
          <DatePicker
            v-width="320"
            :editable="false"
            type="datetimerange"
            format="yyyy-MM-dd HH:mm"
            placeholder="请选择活动时间"
            @on-change="onchangeTime"
            v-model="formValidate.period"
          ></DatePicker>
        </FormItem>
        <FormItem
          label="抽奖类型："
          prop="type"
          label-for="type"
          v-if="formValidate.factor == 1"
        >
          <RadioGroup element-id="type" v-model="formValidate.type">
            <Radio :label="1">九宫格</Radio>
            <Radio :label="2">大转盘</Radio>
          </RadioGroup>
        </FormItem>
        <FormItem
          label="参与用户："
          prop="attends_user"
          label-for="attends_user"
        >
          <RadioGroup
            element-id="attends_user"
            v-model="formValidate.attends_user"
          >
            <Radio :label="1">全部用户</Radio>
            <Radio :label="2">部分用户</Radio>
          </RadioGroup>
        </FormItem>
        <FormItem
          label=""
          prop="user_level"
          v-if="formValidate.attends_user == 2"
        >
          <Select
            multiple
            v-model="formValidate.user_level"
            v-width="320"
            placeholder="请选择用户等级"
          >
            <Option
              v-for="item in userLevelListApi"
              :value="String(item.id)"
              :key="item.id"
              >{{ item.name }}</Option
            >
          </Select>
        </FormItem>
        <FormItem label="" prop="is_svip" v-if="formValidate.attends_user == 2">
          <Select
            v-model="formValidate.is_svip"
            v-width="320"
            clearable
            placeholder="请选择是否是付费会员"
          >
            <Option
              v-for="item in templateList"
              :value="item.id"
              :key="item.id"
              >{{ item.name }}</Option
            >
          </Select>
        </FormItem>
        <FormItem
          label=""
          prop="user_label"
          v-if="formValidate.attends_user == 2"
        >
          <div
            class="labelInput acea-row row-between-wrapper"
            @click="openLabel"
            v-width="320"
          >
            <div class="flex-1" v-if="dataLabel.length">
              <Tag
                closable
                v-for="(item, index) in dataLabel"
                :key="index"
                @on-close="closeLabel(item)"
                >{{ item.label_name }}</Tag
              >
            </div>
            <span class="span" v-else>请选择用户关联标签</span>
            <div class="iconfont iconxiayi"></div>
          </div>
          <div class="ml100 grey">
            三个条件都设置后,必须这些条件都满足的用户才能参加抽奖
          </div>
        </FormItem>
        <FormItem
          label="每人抽奖总次数："
          prop="total_lottery_num"
          label-for="total_lottery_num"
          v-if="formValidate.factor == 1"
        >
          <div class="input-number-wrapper">
            <InputNumber
              v-model="formValidate.total_lottery_num"
              :min="1"
              element-id="total_lottery_num"
              v-width="192"
            ></InputNumber>
            <div class="suffix">次</div>
          </div>
        </FormItem>
        <FormItem
          label="每人每天抽奖次数："
          prop="lottery_num"
          label-for="lottery_num2"
          v-if="formValidate.factor == 1"
        >
          <div class="input-number-wrapper">
            <InputNumber
              v-model="formValidate.lottery_num"
              :min="1"
              element-id="lottery_num2"
              v-width="192"
            ></InputNumber>
            <div class="suffix">次</div>
          </div>
        </FormItem>
        <FormItem
          label="单次抽奖消耗积分："
          prop="factor_num"
          label-for="factor_num"
          v-if="formValidate.factor == 1"
        >
          <InputNumber
            placeholder=""
            element-id="factor_num"
            :min="1"
            :precision="0"
            v-model="formValidate.factor_num"
            v-width="192"
          />
        </FormItem>
        <!-- 订单支付 -->
        <FormItem
          label="抽奖次数："
          prop="factor_num"
          label-for="factor_num"
          v-if="formValidate.factor == 3 || formValidate.factor == 4"
        >
          <div class="input-number-wrapper">
            <InputNumber
              placeholder=""
              element-id="factor_num"
              :min="1"
              :precision="0"
              v-model="formValidate.factor_num"
              v-width="192"
            />
            <div class="suffix">次</div>
          </div>
        </FormItem>
        <!-- 订单评价 -->
        <!-- 关注公众号 -->
        <FormItem
          label="抽奖次数："
          prop="lottery_num_term"
          label-for="status"
          v-if="formValidate.factor == 5"
        >
          <RadioGroup
            element-id="lottery_num_term"
            v-model="formValidate.lottery_num_term"
          >
            <Radio :label="1">每天N次</Radio>
            <Radio :label="2">每人N次</Radio>
          </RadioGroup>
        </FormItem>
        <FormItem
          label="邀请新用户最多可获得抽奖："
          prop="lottery_num"
          label-for="lottery_num"
          v-if="formValidate.factor == 5"
        >
          <div class="input-number-wrapper">
            <InputNumber
              placeholder=""
              element-id="lottery_num"
              :min="1"
              :precision="0"
              v-model="formValidate.lottery_num"
              v-width="192"
            />
            <div class="suffix">次</div>
          </div>
        </FormItem>
        <FormItem
          label="邀请一位新用户关注公众号可获得抽奖："
          prop="spread_num"
          label-for="spread_num"
          v-if="formValidate.factor == 5"
        >
          <div class="input-number-wrapper">
            <InputNumber
              placeholder=""
              element-id="spread_num"
              :min="1"
              :precision="0"
              v-model="formValidate.spread_num"
              v-width="192"
            />
            <div class="suffix">次</div>
          </div>
        </FormItem>
        <FormItem label="奖品选择：">
          <Table
            :data="specsData"
            :columns="columns"
            border
            draggable
            @on-drag-drop="onDragDrop"
          >
            <template slot-scope="{ row, index }" slot="image">
              <div
                class="acea-row row-middle row-center-wrapper"
                @click="modalPicTap('dan', 'goods', index)"
              >
                <div class="pictrue pictrueTab" v-if="row.image">
                  <img v-lazy="row.image" />
                </div>
                <div
                  class="upLoad pictrueTab acea-row row-center-wrapper"
                  v-else
                >
                  <Icon type="ios-camera-outline" size="21" class="iconfonts" />
                </div>
              </div>
            </template>
            <template slot-scope="{ row, index }" slot="total">
              <InputNumber
                v-model="row.total"
                :max="99999"
                :min="0"
                :precision="0"
                class="priceBox"
                @on-change="
                  (data) => {
                    changeTotal(data, index);
                  }
                "
              ></InputNumber>
            </template>
            <template slot-scope="{ row, index }" slot="chance">
              <InputNumber
                v-model="specsData[index].chance"
                :max="100"
                :min="0"
                :precision="0"
                class="priceBox"
              ></InputNumber>
            </template>
            <template slot-scope="{ row, index }" slot="type">
              <div>{{ row.type | typeName }}</div>
            </template>
            <template slot-scope="{ row, index }" slot="setting">
              <Button
                class="submission"
                type="text"
                style="
                  background-color: transparent;
                  box-shadow: none;
                  color: #1890ff;
                "
                @click="editGoods(index)"
                >编辑</Button
              >
            </template>
          </Table>
          <Button
            v-if="specsData.length < 8"
            type="primary"
            class="submission mr15 mt20"
            @click="addGoods"
            >添加商品</Button
          >
          <div class="pl60 grey">
            奖品必须设置为8个，列表中拖拽可调整奖品在九宫中的位置
            <Poptip
              placement="bottom"
              trigger="hover"
              width="380"
              transfer
              padding="8px"
            >
              <a>查看位置示例图</a>
              <div class="api" slot="content">
                <img
                  src="../../../assets/images/lotteryTest.png"
                  alt=""
                  style="
                    display: block;
                    width: 100%;
                    height: 100%;
                    object-fit: contain;
                  "
                />
              </div>
            </Poptip>
          </div>
        </FormItem>
        <FormItem
          v-if="formValidate.factor == 1 || formValidate.factor == 5"
          prop="image"
        >
          <div class="custom-label" slot="label">
            <div>
              <div>活动背景图</div>
            </div>
            <div>：</div>
          </div>
          <div class="acea-row">
            <div class="pictrue" v-if="formValidate.image">
              <img v-lazy="formValidate.image" />
              <Button
                shape="circle"
                icon="md-close"
                @click.native="handleRemove()"
                class="btndel"
              ></Button>
            </div>
            <div
              v-else
              class="upLoad acea-row row-center-wrapper"
              @click="modalPicTap('dan', 'danFrom')"
            >
              <Icon type="ios-camera-outline" size="26" class="iconfonts" />
            </div>
          </div>
          <div
            style="
              margin-top: 14px;
              font-size: 12px;
              line-height: 12px;
              color: #999999;
            "
          >
            建议尺寸：750*1386
            <Poptip
              placement="bottom"
              trigger="hover"
              width="256"
              transfer
              padding="8px"
            >
              <a>查看示例</a>
              <div class="exampleImg" slot="content">
                <img :src="`${baseURL}/statics/system/luck.png`" alt="" />
              </div>
            </Poptip>
          </div>
        </FormItem>
        <FormItem
          v-if="formValidate.factor == 1 || formValidate.factor == 5"
          label="中奖名单："
          prop="is_all_record"
          label-for="is_all_record"
        >
          <Switch
            v-model="formValidate.is_all_record"
            :true-value="1"
            :false-value="0"
            element-id="is_all_record"
            size="large"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </Switch>
        </FormItem>
        <FormItem
          v-if="formValidate.factor == 1 || formValidate.factor == 5"
          label="个人中奖记录："
          prop="is_personal_record"
          label-for="is_personal_record"
        >
          <Switch
            v-model="formValidate.is_personal_record"
            :true-value="1"
            :false-value="0"
            element-id="is_personal_record"
            size="large"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </Switch>
        </FormItem>
        <FormItem
          v-if="formValidate.factor == 1 || formValidate.factor == 5"
          label="活动规则："
          prop="is_content"
          label-for="is_content"
        >
          <Switch
            v-model="formValidate.is_content"
            :true-value="1"
            :false-value="0"
            element-id="is_content"
            size="large"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </Switch>
        </FormItem>
        <FormItem
          label=""
          prop="content"
          v-if="
            (formValidate.factor == 1 || formValidate.factor == 5) &&
            formValidate.is_content == 1
          "
        >
          <WangEditor
            style="width: 90%"
            :content="formValidate.content"
            @editorContent="getEditorContent"
          ></WangEditor>
        </FormItem>
        <FormItem label="活动状态：" prop="status" label-for="status">
          <Switch
            v-model="formValidate.status"
            :true-value="1"
            :false-value="0"
            element-id="status"
            size="large"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </Switch>
        </FormItem>
        <Spin size="large" fix v-if="spinShow"></Spin>
      </Form>
    </Card>
    <Card
      :bordered="false"
      dis-hover
      class="fixed-card"
      :style="{ left: `${!menuCollapse ? '236px' : isMobile ? '0' : '60px'}` }"
    >
      <Form>
        <FormItem>
          <Button
            type="primary"
            class="submission"
            :loading="submitOpen"
            @click="next('formValidate')"
          >
            <div v-if="!submitOpen">提交</div>
            <div v-else>提交中</div>
          </Button>
        </FormItem>
      </Form>
    </Card>
    <!-- 用户标签 -->
    <Modal
      v-model="labelShow"
      scrollable
      title="选择用户标签"
      :closable="true"
      width="540"
      :footer-hide="true"
      :mask-closable="false"
    >
      <userLabel
        ref="userLabel"
        @activeData="activeData"
        @close="labelClose"
      ></userLabel>
    </Modal>
    <!-- 上传图片-->
    <Modal
      v-model="modalPic"
      width="960px"
      scrollable
      footer-hide
      closable
      title="上传商品图"
      :mask-closable="false"
      :z-index="1"
    >
      <uploadPictures
        :isChoice="isChoice"
        @getPic="getPic"
        v-if="modalPic"
      ></uploadPictures>
    </Modal>
    <!-- 编辑奖品-->
    <Modal
      v-model="addGoodsModel"
      width="60%"
      scrollable
      footer-hide
      closable
      :title="title"
      :mask-closable="false"
      :z-index="100"
    >
      <addGoods
        v-if="addGoodsModel"
        @addGoodsData="addGoodsData"
        :editData="editData"
      ></addGoods>
    </Modal>
  </div>
</template>

<script>
import Setting from '@/setting';
import { mapMutations, mapState } from 'vuex';
import goodsList from '@/components/goodsList/index';
import uploadPictures from '@/components/uploadPictures';
import userLabel from '@/components/labelList';
import addGoods from './addGoods';
import {
  lotteryDetailApi,
  lotteryCreateApi,
  lotteryEditApi,
  lotteryFactorInfo,
} from '@/api/lottery'; //详情 创建 编辑
import { lotteryFrom } from './formRule/lotteryFrom';
import { labelListApi } from '@/api/product';
import { levelListApi } from '@/api/user';
import WangEditor from '@/components/wangEditor/index.vue';
import { formatDate } from '@/utils/validate';
import { formatRichText } from '@/utils/editorImg';

export default {
  name: 'lotteryCreate',
  components: { goodsList, uploadPictures, WangEditor, addGoods, userLabel },
  data() {
    Object.keys(lotteryFrom).forEach((key) => {
      if (key === 'total_lottery_num') {
        lotteryFrom[key][0]['validator'] = (rule, value, callback) => {
          if (value < this.formValidate.lottery_num) {
            return callback(new Error('总次数不能小于每天抽奖次数'));
          }
          callback();
        };
      }
    });
    return {
      roterPre: Setting.roterPre,
      baseURL: Setting.apiBaseURL.replace(/adminapi/, ''),
      dataLabel: [],
      labelShow: false,
      headTab: [
        {
          name: '积分抽奖',
          type: '1',
        },
        {
          name: '订单支付',
          type: '3',
        },
        {
          name: '订单评价',
          type: '4',
        },
        {
          name: '关注公众号',
          type: '5',
        },
      ],
      title: '添加商品',
      loading: false,
      userLabelList: [], //用户标签列表
      userLevelListApi: [], //用户等级列表
      submitOpen: false,
      spinShow: false,
      addGoodsModel: false,
      editData: {},
      isChoice: '单选',
      modalPic: false,
      modal_loading: false,
      images: [],
      templateList: [
        { id: 0, name: '非付费会员' },
        { id: 1, name: '付费会员' },
      ],
      columns: [
        {
          title: '序号',
          type: 'index',
          width: 60,
          align: 'center',
        },
        {
          title: '图片',
          slot: 'image',
          align: 'center',
          minWidth: 120,
        },
        {
          title: '名称',
          align: 'center',
          minWidth: 80,
          key: 'name',
        },
        {
          title: '奖品',
          slot: 'type',
          align: 'center',
          minWidth: 80,
        },
        {
          title: '提示语',
          key: 'prompt',
          align: 'center',
          minWidth: 80,
        },
        {
          title: '数量',
          slot: 'total',
          align: 'center',
          minWidth: 80,
        },
        {
          slot: 'chance',
          align: 'center',
          minWidth: 80,
          renderHeader: (h, params) => {
            const num = this.chanceSum;
            return h(
              'div',
              `概率(${num < 0 ? '超出' : '剩余'}${Math.abs(num)}%)`
            );
          },
        },
        {
          title: '操作',
          slot: 'setting',
          align: 'center',
          minWidth: 80,
        },
      ],
      specsData: [],
      formValidate: {
        images: [],
        name: '', //活动名称
        desc: '', //活动描述
        image: '', //活动背景图
        factor: 1, //抽奖类型：1:积分 2:余额 3：下单支付成功 4:订单评价',5:关注
        factor_num: 1, //获取一次抽奖的条件数量
        attends_user: 1, //参与用户1：所有  2：部分
        user_level: 0, //参与用户等级
        user_label: [], //参与用户标签
        is_svip: '-1', //参与用户是否付费会员
        prize_num: 0, //奖品数量
        period: [], //活动时间
        prize: [], //奖品数组
        lottery_num_term: 1, //抽奖次数限制：1：每天2：每人
        lottery_num: 1, //抽奖次数
        spread_num: 1, //关注推广获取抽奖次数
        is_all_record: 0, //中奖纪录展示
        is_personal_record: 0, //个人中奖纪录展示
        is_content: 0, //活动规格是否展示
        content: '', //富文本内容
        status: 0, //状态
        total_lottery_num: 1, //每人抽奖总次数
        type: 1, //抽奖类型 1：九宫格 2：大转盘
      },
      ruleValidate: lotteryFrom,
      currentid: '',
      picTit: '',
      tableIndex: 0,
      copy: 0,
      editIndex: null,
      id: '',
      isData: 0,
      content: '',
    };
  },
  filters: {
    typeName(type) {
      if (type == 1) {
        return '未中奖';
      } else if (type == 2) {
        return '积分';
      } else if (type == 3) {
        return '余额';
      } else if (type == 4) {
        return '红包';
      } else if (type == 5) {
        return '优惠券';
      } else if (type == 6) {
        return '商品';
      }
    },
  },
  computed: {
    ...mapState('admin/layout', ['isMobile', 'menuCollapse']),
    labelWidth() {
      return this.isMobile ? undefined : 135;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
    chanceSum() {
      const sum = this.specsData.reduce((total, item) => {
        return total + item.chance;
      }, 0);
      return 100 - sum;
    },
  },
  watch: {
    chanceSum(num) {
      if (num < 0) {
        this.$Message.destroy();
        this.$Message.warning(
          `概率总和不能大于100%,当前已超出${Math.abs(num)}%`
        );
      }
    },
  },
  mounted() {
    this.id = Number(this.$route.params.id) || 0;
    if (this.id) {
      this.getInfo();
    }
    this.prizeList();
    this.labelListApi();
    this.levelListApi();
    this.setCopyrightShow({ value: false });
  },
  destroyed() {
    this.setCopyrightShow({ value: true });
  },
  methods: {
    ...mapMutations('admin/layout', ['setCopyrightShow']),
    prizeList() {
      let list = [];
      for (var i = 0; i < 8; i++) {
        let data = {
          type: 1, //类型 1：未中奖2：积分  3:余额  4：红包 5:优惠券 6：站内商品
          name: '', //活动名称
          num: 0, //奖品数量
          image: '', //奖品图片
          chance: 0, //中奖概率
          total: 0, //奖品数量
          prompt: '', //提示语
        };
        list.push(data);
      }
      this.specsData = list;
    },
    getEditorContent(data) {
      this.content = data;
    },
    closeLabel(label) {
      let index = this.dataLabel.indexOf(
        this.dataLabel.filter((d) => d.id == label.id)[0]
      );
      this.dataLabel.splice(index, 1);
    },
    activeData(dataLabel) {
      this.labelShow = false;
      this.dataLabel = dataLabel;
    },
    openLabel(row) {
      this.labelShow = true;
      this.$refs.userLabel.userLabel(
        JSON.parse(JSON.stringify(this.dataLabel))
      );
    },
    // 标签弹窗关闭
    labelClose() {
      this.labelShow = false;
    },
    //用户标签列表
    labelListApi() {
      labelListApi().then((res) => {
        this.userLabelList = res.data.list;
      });
    },
    //用户等级列表
    levelListApi() {
      levelListApi().then((res) => {
        this.userLevelListApi = res.data.list;
      });
    },
    // 具体日期
    onchangeTime(e) {
      this.$nextTick(() => {
        this.$set(this.formValidate, 'period', e);
      });
    },
    // 详情
    getInfo() {
      this.spinShow = true;
      lotteryDetailApi(this.id).then((res) => {
        let factor = this.formValidate.factor;
        this.spinShow = false;
        this.isData = res.data.id || 0;
        if (!this.isData) {
          this.prizeList();
          this.formValidate = {
            factor: factor,
            images: [],
            name: '', //活动名称
            desc: '', //活动描述
            image: '', //活动背景图
            factor_num: 1, //获取一次抽奖的条件数量
            attends_user: 1, //参与用户1：所有  2：部分
            user_level: 0, //参与用户等级
            user_label: [], //参与用户标签
            is_svip: '-1', //参与用户是否付费会员
            prize_num: 0, //奖品数量
            period: [], //活动时间
            prize: [], //奖品数组
            lottery_num_term: 1, //抽奖次数限制：1：每天2：每人
            lottery_num: 1, //抽奖次数
            spread_num: 1, //关注推广获取抽奖次数
            is_all_record: 0, //中奖纪录展示
            is_personal_record: 0, //个人中奖纪录展示
            is_content: 0, //活动规格是否展示
            content: '', //富文本内容
            status: 0, //状态
          };
          return;
        }
        this.formValidate = res.data;
        this.formValidate.factor = res.data.factor;
        this.formValidate.user_level = res.data.user_level || [];
        this.formValidate.user_label = res.data.user_label || [];
        this.dataLabel = res.data.user_label || [];
        this.formValidate.is_svip = res.data.is_svip;
        this.content = res.data.is_content ? res.data.content : '';
        this.formValidate.period = [
          this.formatDate(res.data.start_time) || '',
          this.formatDate(res.data.end_time) || '',
        ];
        this.specsData = res.data.prize;
      });
    },
    // 下一步
    next(name) {
      if (this.submitOpen) {
        return;
      }
      this.submitOpen = true;
      this.formValidate.prize = this.specsData;
      if (this.formValidate.is_content) {
        this.formValidate.content = formatRichText(this.content);
      }
      this.$refs[name].validate((valid) => {
        if (valid) {
          // 活动时间
          if (this.formValidate.period[0] == '') {
            this.submitOpen = false;
            return this.$Message.error('请选择活动时间');
          }
          // 规则选择
          const invalid = this.specsData.some(
            (item) => !item.name || !item.image
          );
          if (invalid) {
            this.submitOpen = false;
            return this.$Message.error('请完善规则选择');
          }
          // 用户标签
          let activeIds = [];
          this.dataLabel.forEach((item) => {
            activeIds.push(item.id);
          });
          this.formValidate.user_label = activeIds;
          if (this.formValidate.factor == 1) {
            this.formValidate.lottery_num_term = 1;
            this.formValidate.spread_num = 1;
          } else if (
            this.formValidate.factor == 3 ||
            this.formValidate.factor == 4
          ) {
            this.formValidate.type = 1;
            this.formValidate.total_lottery_num = 1;
            this.formValidate.lottery_num = 1;
            this.formValidate.lottery_num_term = 1;
            this.formValidate.lottery_num = 1;
            this.formValidate.spread_num = 1;
            this.formValidate.image = '';
            this.formValidate.is_all_record = 0;
            this.formValidate.is_personal_record = 0;
            this.formValidate.is_content = 0;
            this.formValidate.content = '';
          } else if (this.formValidate.factor == 5) {
            this.formValidate.type = 1;
            this.formValidate.total_lottery_num = 1;
            this.formValidate.factor_num = 1;
          }
          if (this.id) {
            lotteryEditApi(this.isData, this.formValidate)
              .then(async (res) => {
                this.$Message.success(res.msg);
                setTimeout(() => {
                  this.submitOpen = false;
                  this.$router.push({
                    path: `${this.roterPre}/marketing/lottery/index`,
                  });
                }, 500);
              })
              .catch((res) => {
                this.submitOpen = false;
                this.$Message.error(res.msg);
              });
          } else {
            lotteryCreateApi(this.formValidate)
              .then(async (res) => {
                this.$Message.success(res.msg);
                setTimeout(() => {
                  this.submitOpen = false;
                  this.$router.push({
                    path: `${this.roterPre}/marketing/lottery/index`,
                  });
                }, 500);
              })
              .catch((res) => {
                this.submitOpen = false;
                this.$Message.error(res.msg);
              });
          }
        } else {
          this.submitOpen = false;
          this.$Message.error('请完善信息');
        }
      });
    },
    // 上一步
    step() {
      this.current--;
    },
    // 内容
    getContent(val) {
      this.formValidate.content = val;
    },
    // 规则
    getRole(val) {
      this.formValidate.rule = val;
    },
    // 点击商品图
    modalPicTap(tit, picTit, index) {
      this.modalPic = true;
      this.isChoice = tit === 'dan' ? '单选' : '多选';
      this.picTit = picTit || '';
      this.tableIndex = index;
    },
    // 获取单张图片信息
    getPic(pc) {
      switch (this.picTit) {
        case 'danFrom':
          this.formValidate.image = pc.att_dir;
          break;
        default:
          this.specsData[this.tableIndex].image = pc.att_dir;
      }
      this.modalPic = false;
    },
    handleRemove() {
      this.formValidate.image = '';
    },
    // 添加自定义弹窗
    addCustomDialog(editorId) {
      window.UE.registerUI(
        'test-dialog',
        function (editor, uiName) {
          // 创建 dialog
          let dialog = new window.UE.ui.Dialog({
            // 指定弹出层中页面的路径，这里只能支持页面，路径参考常见问题 2
            iframeUrl: '/admin/widget.images/index.html?fodder=dialog',
            // 需要指定当前的编辑器实例
            editor: editor,
            // 指定 dialog 的名字
            name: uiName,
            // dialog 的标题
            title: '上传图片',
            // 指定 dialog 的外围样式
            cssRules: 'width:1200px;height:500px;padding:20px;',
          });
          this.dialog = dialog;
          var btn = new window.UE.ui.Button({
            name: 'dialog-button',
            title: '上传图片',
            cssRules: `background-image: url(../../../assets/images/icons.png);background-position: -726px -77px;`,
            onclick: function () {
              // 渲染dialog
              dialog.render();
              dialog.open();
            },
          });
          return btn;
        },
        37
      );
    },
    //新增商品
    addGoods() {
      this.addGoodsModel = true;
      this.title = '添加商品';
      this.editData = {};
    },
    //编辑商品
    editGoods(index) {
      this.addGoodsModel = true;
      this.title = '编辑奖品';
      this.editData = this.specsData[index];
      this.editIndex = index;
    },
    //删除商品
    deleteGoods(index) {
      this.specsData.splice(index, 1);
    },
    //获取数组中某个字段之和
    sumArr(arr, name) {
      let arrData = [];
      for (let i = 0; i < arr.length; i++) {
        arrData.push(arr[i][name]);
      }
      return eval(arrData.join('+'));
    },
    // 编辑奖品提交的数据
    addGoodsData(data) {
      this.editIndex != null
        ? this.$set(this.specsData, [this.editIndex], data)
        : this.specsData.length < 8
        ? this.specsData.push(data)
        : this.$Message.warning('最多添加8个奖品');
      this.addGoodsModel = false;
      this.editIndex = null;
    },
    changeTotal(data, index) {
      this.$set(this.specsData[index], 'total', data);
    },
    //修改排序
    onDragDrop(a, b) {
      this.specsData.splice(
        b,
        1,
        ...this.specsData.splice(a, 1, this.specsData[b])
      );
    },
    //时间格式转换
    formatDate(time) {
      if (time) {
        let date = new Date(time * 1000);
        return formatDate(date, 'yyyy-MM-dd hh:mm');
      } else {
        return '';
      }
    },
  },
};
</script>

<style scoped lang="stylus">
.labelInput {
  border: 1px solid #dcdee2;
  width: 50%;
  padding: 0 5px;
  border-radius: 5px;
  min-height: 30px;
  cursor: pointer;

  .span {
    color: #c5c8ce;
  }

  .iconxiayi {
    font-size: 12px;
    padding-right: 5px;
  }
}

.custom-label {
  display: inline-flex;
  line-height: 1.5;
}

.grey {
  color: #999;
}

.maxW /deep/.ivu-select-dropdown {
  max-width: 600px;
}

.ivu-table-wrapper {
  border-left: 1px solid #dcdee2;
  border-top: 1px solid #dcdee2;
}

.tabBox_img {
  width: 50px;
  height: 50px;
}

.tabBox_img img {
  width: 100%;
  height: 100%;
}

.priceBox {
  width: 100%;
}

.form {
  .picBox {
    display: inline-block;
    cursor: pointer;
  }

  .pictrue {
    width: 60px;
    height: 60px;
    border: 1px dotted rgba(0, 0, 0, 0.1);
    margin-right: 15px;
    display: inline-block;
    position: relative;
    cursor: pointer;

    img {
      width: 100%;
      height: 100%;
    }

    .btndel {
      position: absolute;
      z-index: 9;
      width: 20px !important;
      height: 20px !important;
      left: 46px;
      top: -4px;
    }
  }

  .upLoad {
    width: 58px;
    height: 58px;
    line-height: 58px;
    border: 1px dotted rgba(0, 0, 0, 0.1);
    border-radius: 4px;
    background: rgba(0, 0, 0, 0.02);
    cursor: pointer;
  }
}

.form-submit {
  /deep/.ivu-card {
    border-radius: 0;
  }

  margin-bottom: 79px;

  .fixed-card {
    position: fixed;
    right: 0;
    bottom: 0;
    left: 200px;
    z-index: 10;
    box-shadow: 0 -1px 2px rgb(240, 240, 240);

    /deep/ .ivu-card-body {
      padding: 15px 16px 14px;
    }

    .ivu-form-item {
      margin-bottom: 0;
    }

    /deep/ .ivu-form-item-content {
      margin-right: 124px;
      text-align: center;
    }

    .ivu-btn {
      height: 36px;
      padding: 0 20px;
    }
  }
}

.new_tab {
  >>>.ivu-tabs-nav .ivu-tabs-tab {
    padding: 4px 16px 20px !important;
    font-weight: 500;
  }
}

.input-number-wrapper {
  position: relative;
  display: inline-block;

  .ivu-input-number {
    width: 100%;
  }

  .suffix {
    position: absolute;
    top: 0;
    right: -22px;
    color: #999;
  }
}
</style>
