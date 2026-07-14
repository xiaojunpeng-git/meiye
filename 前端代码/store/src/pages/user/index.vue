<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
      <div class="padding-add">
      <Form
        ref="userFrom"
        :model="userFrom"
        :label-width="labelWidth"
        :label-position="labelPosition"
        class="user-filter-form"
        @submit.native.prevent
      >
        <Row :gutter="16">
          <Col :xs="24" :sm="12" :md="8" :lg="8">
            <FormItem label="用户搜索：" label-for="nickname">
              <Input
                v-model="userFrom.nickname"
                placeholder="请输入"
                element-id="nickname"
                clearable
                class="input-add"
              >
                <Select v-model="field_key" slot="prepend" style="width: 80px">
                  <Option value="all">全部</Option>
                  <Option value="uid">UID</Option>
                  <Option value="phone">手机号</Option>
                  <Option value="nickname">用户昵称</Option>
                </Select>
              </Input>
            </FormItem>
          </Col>
          <Col :xs="24" :sm="12" :md="8" :lg="8">
            <FormItem label="会员等级：">
              <Select v-model="userFrom.level" placeholder="请选择" clearable class="input-add">
                <Option :value="item.id" v-for="item in levelList" :key="item.id">{{ item.name }}</Option>
              </Select>
            </FormItem>
          </Col>
          <Col :xs="24" :sm="12" :md="8" :lg="8">
            <FormItem label="付费会员：">
              <Select v-model="userFrom.isMember" placeholder="请选择" clearable class="input-add">
                <Option value="1">是</Option>
                <Option value="0">否</Option>
              </Select>
            </FormItem>
          </Col>
        </Row>
        <template v-if="collapse">
          <Row :gutter="16">
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="用户标签：">
                <div class="labelInput acea-row row-between-wrapper input-add" @click="openLabelList">
                  <div>
                    <div v-if="dataLabel.length">
                      <Tag closable v-for="(item, index) in dataLabel" :key="index" @on-close="closeLabel(item)">{{ item.label_name }}</Tag>
                    </div>
                    <span class="span" v-else>请选择</span>
                  </div>
                  <div class="iconfont iconxiayi"></div>
                </div>
              </FormItem>
            </Col>
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="用户分组：">
                <Select v-model="userFrom.group_id" placeholder="请选择" clearable class="input-add">
                  <Option :value="item.id" v-for="item in groupList" :key="item.id">{{ item.group_name }}</Option>
                </Select>
              </FormItem>
            </Col>
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="用户身份：">
                <Select v-model="userFrom.is_promoter" placeholder="请选择" clearable class="input-add">
                  <Option value="1">推广员</Option>
                  <Option value="0">普通用户</Option>
                </Select>
              </FormItem>
            </Col>
          </Row>
          <Row :gutter="16">
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="性别：">
                <Select v-model="userFrom.sex" placeholder="请选择" clearable class="input-add">
                  <Option value="1">男</Option>
                  <Option value="2">女</Option>
                  <Option value="0">未知</Option>
                </Select>
              </FormItem>
            </Col>
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="当前积分：">
                <div class="num-range">
                  <InputNumber class="num-range-input" placeholder="开始" :max="9999999999" :min="0" :precision="0" v-model="integralStart" />
                  <span class="num-range-sep">~</span>
                  <InputNumber class="num-range-input" placeholder="结尾" :max="9999999999" :min="0" :precision="0" v-model="integralEnd" />
                </div>
              </FormItem>
            </Col>
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="账户余额：">
                <div class="num-range">
                  <InputNumber class="num-range-input" placeholder="开始" :max="9999999999" :min="0" :precision="0" v-model="nowMoneyPeiceStart" />
                  <span class="num-range-sep">~</span>
                  <InputNumber class="num-range-input" placeholder="结尾" :max="9999999999" :min="0" :precision="0" v-model="nowMoneyPeiceEnd" />
                </div>
              </FormItem>
            </Col>
          </Row>
          <Row :gutter="16">
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="消费现金金额：">
                <div class="num-range">
                  <InputNumber class="num-range-input" placeholder="开始" :min="0" :precision="0" v-model="cashConsumePriceStart" />
                  <span class="num-range-sep">~</span>
                  <InputNumber class="num-range-input" placeholder="结尾" :min="0" :precision="0" v-model="cashConsumePriceEnd" />
                </div>
              </FormItem>
            </Col>
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="销售日期：">
                <DatePicker
                  :editable="false"
                  @on-change="onSaleDateTime"
                  :value="saleDateTimeVal"
                  format="yyyy/MM/dd HH:mm:ss"
                  type="datetimerange"
                  placeholder="仅影响消费现金金额、消费次数"
                  class="input-add"
                />
              </FormItem>
            </Col>
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="消费次数：">
                <div class="num-range">
                  <InputNumber class="num-range-input" placeholder="开始" :max="9999999999" :min="0" :precision="0" v-model="payCountStart" />
                  <span class="num-range-sep">~</span>
                  <InputNumber class="num-range-input" placeholder="结尾" :max="9999999999" :min="0" :precision="0" v-model="payCountEnd" />
                </div>
              </FormItem>
            </Col>
          </Row>
          <Row :gutter="16">
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="到店次数：">
                <div class="num-range">
                  <InputNumber class="num-range-input" placeholder="开始" :min="0" :precision="0" v-model="writeoffCountStart" />
                  <span class="num-range-sep">~</span>
                  <InputNumber class="num-range-input" placeholder="结尾" :min="0" :precision="0" v-model="writeoffCountEnd" />
                </div>
              </FormItem>
            </Col>
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="最近有消费：">
                <div class="combo-filter">
                  <Select v-model="recentConsumeType" placeholder="请选择" clearable class="combo-type">
                    <Option value="days">几天内</Option>
                    <Option value="range">时间段</Option>
                  </Select>
                  <InputNumber v-if="recentConsumeType === 'days'" class="combo-value" placeholder="天数" :min="1" :precision="0" v-model="recentConsumeDays" />
                  <DatePicker v-if="recentConsumeType === 'range'" :editable="false" @on-change="onRecentConsumeTime" :value="recentConsumeTimeVal" format="yyyy/MM/dd" type="daterange" placeholder="消费时间" class="combo-value input-add" />
                </div>
              </FormItem>
            </Col>
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="最近无消费：">
                <div class="combo-filter">
                  <Select v-model="noConsumeType" placeholder="请选择" clearable class="combo-type">
                    <Option value="days">几天内</Option>
                    <Option value="range">时间段</Option>
                  </Select>
                  <InputNumber v-if="noConsumeType === 'days'" class="combo-value" placeholder="天数" :min="1" :precision="0" v-model="noConsumeDays" />
                  <DatePicker v-if="noConsumeType === 'range'" :editable="false" @on-change="onNoConsumeTime" :value="noConsumeTimeVal" format="yyyy/MM/dd" type="daterange" placeholder="时间范围" class="combo-value input-add" />
                </div>
              </FormItem>
            </Col>
          </Row>
          <Row :gutter="16">
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="最近有到店：">
                <div class="combo-filter">
                  <Select v-model="recentWriteoffType" placeholder="请选择" clearable class="combo-type">
                    <Option value="days">几天内</Option>
                    <Option value="range">时间段</Option>
                  </Select>
                  <InputNumber v-if="recentWriteoffType === 'days'" class="combo-value" placeholder="天数" :min="1" :precision="0" v-model="recentWriteoffDays" />
                  <DatePicker v-if="recentWriteoffType === 'range'" :editable="false" @on-change="onRecentWriteoffTime" :value="recentWriteoffTimeVal" format="yyyy/MM/dd" type="daterange" placeholder="到店时间" class="combo-value input-add" />
                </div>
              </FormItem>
            </Col>
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="最近无到店：">
                <div class="combo-filter">
                  <Select v-model="noWriteoffType" placeholder="请选择" clearable class="combo-type">
                    <Option value="days">几天内</Option>
                    <Option value="range">时间段</Option>
                  </Select>
                  <InputNumber v-if="noWriteoffType === 'days'" class="combo-value" placeholder="天数" :min="1" :precision="0" v-model="noWriteoffDays" />
                  <DatePicker v-if="noWriteoffType === 'range'" :editable="false" @on-change="onNoWriteoffTime" :value="noWriteoffTimeVal" format="yyyy/MM/dd" type="daterange" placeholder="时间范围" class="combo-value input-add" />
                </div>
              </FormItem>
            </Col>
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="访问时间：">
                <DatePicker
                  :editable="false"
                  @on-change="onchangeTime"
                  :value="timeVal"
                  format="yyyy/MM/dd"
                  type="daterange"
                  placeholder="自定义时间"
                  class="input-add"
                />
              </FormItem>
            </Col>
          </Row>
          <Row :gutter="16">
            <Col :xs="24" :sm="12" :md="8" :lg="8">
              <FormItem label="访问情况：">
                <Select v-model="userFrom.user_time_type" placeholder="请选择" clearable class="input-add">
                  <Option value="visitno">时间段未访问</Option>
                  <Option value="visit">时间段访问过</Option>
                  <Option value="add_time">首次访问</Option>
                </Select>
              </FormItem>
            </Col>
          </Row>
        </template>
        <Row>
          <Col span="24" class="user-filter-actions">
            <Button type="primary" class="mr15 search" @click="userSearchs">搜索</Button>
            <Button class="ResetSearch search mr15" @click="reset('userFrom')">重置</Button>
            <a class="ivu-ml-8" @click="collapse = !collapse">
              {{ collapse ? '收起' : '展开' }}
              <Icon :type="collapse ? 'ios-arrow-up' : 'ios-arrow-down'" />
            </a>
          </Col>
        </Row>
      </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt listbox">
      <div class="new_tab">
        <Tabs v-model="headeType" @on-click="onClickTab">
          <TabPane :label="item.name" :name="item.type" v-for="item in headeNum" :key="item.type" />
        </Tabs>
      </div>
      <Row type="flex" justify="space-between">
        <Col span="24">
          <Button class="mr20" :disabled="datanew.length <= 0" @click="setLabel">批量设置标签</Button>
          <Button type="primary" class="mr20" @click="exports">导出</Button>
          <Tooltip content="本页至少选中一项">
            <Button
              v-auth="['store-user-coupon']"
              type="primary"
              class="mr20"
              :disabled="!datanew.length"
              @click="onSend"
            >发送优惠券</Button>
          </Tooltip>
        </Col>
        <Col span="24">
          <Table
            :columns="columns"
            :data="dataList"
            ref="selection"
            @on-select-all="selectall"
            @on-select-all-cancel="selectall"
            @on-sort-change="sortChanged"
            @on-selection-change="select"
            :loading="loading"
            highlight-row
            no-userFrom-text="暂无数据"
            no-filtered-userFrom-text="暂无筛选结果"
            class="ivu-mt"
          >
            <template slot-scope="{ row }" slot="avatars">
              <viewer>
                <div class="tabBox_img">
                  <img v-lazy="row.avatar" />
                </div>
              </viewer>
            </template>
            <template slot-scope="{ row }" slot="nickname">
              <div class="acea-row">
                <Icon type="md-male" v-show="row.sex === '男'" color="#2db7f5" size="15" class="mr5" />
                <Icon type="md-female" v-show="row.sex === '女'" color="#ed4014" size="15" class="mr5" />
                <div>
                  {{ row.nickname
                  }}<span style="color: #ed4014" v-if="row.delete_time != null"> (已注销)</span>
                </div>
              </div>
            </template>
            <template slot-scope="{ row }" slot="action">
              <a @click="detail(row)">详情</a>
              <Divider type="vertical" v-if="row.delete_time == null" />
              <a v-if="row.delete_time == null" @click="openLabel(row)">设置标签</a>
            </template>
          </Table>
          <div class="acea-row row-right page">
            <Page
              :total="total"
              :current="userFrom.page"
              show-elevator
              show-total
              @on-change="pageChange"
              :page-size="userFrom.limit"
              class="box"
            />
          </div>
        </Col>
      </Row>
    </Card>
    <Recharges ref="recharges"></Recharges>
    <Paying ref="payings"></Paying>
    <Setusers ref="setusers"></Setusers>
    <user-details ref="userDetails" :group-list="groupList" @cardHolderOpen="cardHolderOpen"></user-details>
    <Modal v-model="labelShow" scrollable title="选择用户标签" :closable="true" width="540" :footer-hide="true">
      <userLabel :uid="labelActive.uid" @close="labelClose"></userLabel>
    </Modal>
    <Modal v-model="labelListShow" scrollable title="选择用户标签" :closable="true" width="540" :footer-hide="true">
      <userLabelList ref="labelList" @activeData="activeData" @close="labelListClose"></userLabelList>
    </Modal>
    <Modal v-model="cardHolderShow" scrollable title="卡项详情" closable footer-hide width="900">
      <cardHolder :cardHolder="cardHolderData"></cardHolder>
    </Modal>
    <sendCoupons ref="sends" :where="userFrom" :userIds="dataid.join(',')"></sendCoupons>
  </div>
</template>

<script>
import sendCoupons from '@/components/sendCoupons/index.vue';
import Setusers from './components/setuser';
import Recharges from './components/recharge';
import Paying from './components/paying';
import userLabel from './components/userLabel';
import userLabelList from '@/components/userLabelList';
import userDetails from './components/userDetails2';
import cardHolder from './components/cardHolder';
import { mapState } from 'vuex';
import { userListApi, userSetLabelApi, levelListApi, userGroupApi, exportUserDataApi } from '@/api/user';
import exportExcel from '@/utils/newToExcel.js';
import { formatDate } from '@/utils/validate';

export default {
  name: 'user',
  components: {
    sendCoupons,
    userDetails,
    cardHolder,
    userLabel,
    userLabelList,
    Recharges,
    Setusers,
    Paying,
  },
  data() {
    return {
      total: 0,
      loading: false,
      collapse: false,
      headeType: '-1',
      headeNum: [
        { type: '-1', name: '全部' },
        { type: 'wechat', name: '微信公众号' },
        { type: 'routine', name: '微信小程序' },
        { type: 'h5', name: 'H5' },
        { type: 'pc', name: 'PC' },
        { type: 'app', name: 'APP' },
        { type: 'cashier', name: '收银台录入' },
        { type: 'import', name: '外部导入' },
      ],
      field_key: 'all',
      dataLabel: [],
      labelListShow: false,
      levelList: [],
      groupList: [],
      timeVal: [],
      columns: [
        { type: 'selection', width: 60, align: 'center' },
        { title: 'ID', key: 'uid', width: 60 },
        { title: '头像', slot: 'avatars', minWidth: 60 },
        { title: '昵称', slot: 'nickname', minWidth: 150 },
        { title: '用户等级', key: 'level', minWidth: 90 },
        { title: '手机号', key: 'phone', minWidth: 100 },
        { title: '用户类型', key: 'user_type', minWidth: 100 },
        { title: '余额', key: 'now_money', sortable: 'custom', minWidth: 100 },
        { title: '关联店员', key: 'staff_name', minWidth: 100 },
        { title: '本金', key: 'ben_money', minWidth: 100 },
        { title: '赠金', key: 'give_money', minWidth: 100 },
        { title: '消费现金金额', key: 'cash_consume_amount', minWidth: 120 },
        { title: '消费次数', key: 'cash_consume_count', minWidth: 90 },
        { title: '到店次数', key: 'writeoff_count', minWidth: 90 },
        { title: '操作', slot: 'action', fixed: 'right', minWidth: 150, align: 'center' },
      ],
      dataList: [],
      datanew: [],
      dataid: [],
      userFrom: {
        nickname: '',
        label_id: '',
        user_type: '',
        sex: '',
        is_promoter: '',
        isMember: '',
        pay_count: '',
        user_time_type: '',
        user_time: '',
        page: 1,
        limit: 15,
        level: '',
        group_id: '',
        field_key: '',
      },
      labelShow: false,
      labelActive: { uid: 0 },
      cardHolderShow: false,
      cardHolderData: {},
      integralStart: null,
      integralEnd: null,
      nowMoneyPeiceStart: null,
      nowMoneyPeiceEnd: null,
      cashConsumePriceStart: null,
      cashConsumePriceEnd: null,
      recentConsumeType: '',
      recentConsumeDays: null,
      recentConsumeTimeVal: [],
      noConsumeType: '',
      noConsumeDays: null,
      noConsumeTimeVal: [],
      writeoffCountStart: null,
      writeoffCountEnd: null,
      recentWriteoffType: '',
      recentWriteoffDays: null,
      recentWriteoffTimeVal: [],
      noWriteoffType: '',
      noWriteoffDays: null,
      noWriteoffTimeVal: [],
      payCountStart: null,
      payCountEnd: null,
      saleDateTimeVal: [],
    };
  },
  computed: {
    ...mapState('store/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 100;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  mounted() {
    this.userGroup();
    this.levelLists();
    this.getList();
  },
  methods: {
    userGroup() {
      userGroupApi({ page: 1, limit: '' }).then((res) => {
        this.groupList = res.data.list || [];
      });
    },
    levelLists() {
      levelListApi({ page: 1, limit: '', title: '', is_show: 1 }).then((res) => {
        this.levelList = res.data.list || [];
      });
    },
    closeLabel(label) {
      const index = this.dataLabel.indexOf(this.dataLabel.filter((d) => d.id === label.id)[0]);
      this.dataLabel.splice(index, 1);
    },
    activeData(dataLabel) {
      this.labelListShow = false;
      this.dataLabel = dataLabel;
    },
    openLabelList() {
      this.labelListShow = true;
      this.$nextTick(() => {
        this.$refs.labelList.userLabel(JSON.parse(JSON.stringify(this.dataLabel)));
      });
    },
    labelListClose() {
      this.labelListShow = false;
    },
    onClickTab(type) {
      this.userFrom.page = 1;
      this.userFrom.user_type = type === -1 || type === '-1' ? '' : type;
      this.getList();
    },
    onchangeTime(e) {
      this.timeVal = e || [];
      this.userFrom.user_time = this.timeVal[0] ? this.timeVal.join('-') : '';
    },
    buildQueryParams() {
      const activeIds = this.dataLabel.map((item) => item.id);
      return {
        ...this.userFrom,
        field_key: this.field_key === 'all' ? '' : this.field_key,
        label_id: activeIds.join(',') || '',
        sex: this.userFrom.sex || '',
        is_promoter: this.userFrom.is_promoter || '',
        isMember: this.userFrom.isMember || '',
        user_time_type: this.userFrom.user_time_type || '',
        user_type: this.userFrom.user_type || '',
        integral: `${this.integralStart === null ? '' : this.integralStart}-${this.integralEnd === null ? '' : this.integralEnd}`,
        now_money_peice: `${this.nowMoneyPeiceStart === null ? '' : this.nowMoneyPeiceStart}-${this.nowMoneyPeiceEnd === null ? '' : this.nowMoneyPeiceEnd}`,
        cash_consume_price: `${this.cashConsumePriceStart === null ? '' : this.cashConsumePriceStart}-${this.cashConsumePriceEnd === null ? '' : this.cashConsumePriceEnd}`,
        recent_consume_type: this.recentConsumeType || '',
        recent_consume_days: this.recentConsumeDays || 0,
        recent_consume_time: this.recentConsumeTimeVal.length ? this.recentConsumeTimeVal.join('-') : '',
        no_consume_type: this.noConsumeType || '',
        no_consume_days: this.noConsumeDays || 0,
        no_consume_time: this.noConsumeTimeVal.length ? this.noConsumeTimeVal.join('-') : '',
        writeoff_count: `${this.writeoffCountStart === null ? '' : this.writeoffCountStart}-${this.writeoffCountEnd === null ? '' : this.writeoffCountEnd}`,
        recent_writeoff_type: this.recentWriteoffType || '',
        recent_writeoff_days: this.recentWriteoffDays || 0,
        recent_writeoff_time: this.recentWriteoffTimeVal.length ? this.recentWriteoffTimeVal.join('-') : '',
        no_writeoff_type: this.noWriteoffType || '',
        no_writeoff_days: this.noWriteoffDays || 0,
        no_writeoff_time: this.noWriteoffTimeVal.length ? this.noWriteoffTimeVal.join('-') : '',
        pay_count: `${this.payCountStart === null ? '' : this.payCountStart}-${this.payCountEnd === null ? '' : this.payCountEnd}`,
        sale_date_time: this.formatSaleDateTimeRange(this.saleDateTimeVal),
      };
    },
    validateFilters() {
      if (this.userFrom.user_time_type && !this.timeVal.length) {
        return '请选择访问时间';
      }
      if (this.timeVal.length && !this.userFrom.user_time_type) {
        return '请选择访问情况';
      }
      if (this.recentConsumeType === 'days' && !this.recentConsumeDays) return '请填写最近有消费的天数';
      if (this.recentConsumeType === 'range' && !this.recentConsumeTimeVal.length) return '请选择最近有消费的时间范围';
      if (this.noConsumeType === 'days' && !this.noConsumeDays) return '请填写最近无消费的天数';
      if (this.noConsumeType === 'range' && !this.noConsumeTimeVal.length) return '请选择最近无消费的时间范围';
      if (this.recentWriteoffType === 'days' && !this.recentWriteoffDays) return '请填写最近有到店的天数';
      if (this.recentWriteoffType === 'range' && !this.recentWriteoffTimeVal.length) return '请选择最近有到店的时间范围';
      if (this.noWriteoffType === 'days' && !this.noWriteoffDays) return '请填写最近无到店的天数';
      if (this.noWriteoffType === 'range' && !this.noWriteoffTimeVal.length) return '请选择最近无到店的时间范围';
      return '';
    },
    getList() {
      this.loading = true;
      userListApi(this.buildQueryParams())
        .then((res) => {
          this.loading = false;
          this.total = res.data.count;
          this.dataList = res.data.list;
        })
        .catch((err) => {
          this.loading = false;
          this.$Message.error(err.msg);
        });
    },
    userSearchs() {
      const msg = this.validateFilters();
      if (msg) return this.$Message.error(msg);
      this.userFrom.page = 1;
      this.getList();
    },
    onRecentConsumeTime(e) { this.recentConsumeTimeVal = e || []; },
    onNoConsumeTime(e) { this.noConsumeTimeVal = e || []; },
    onRecentWriteoffTime(e) { this.recentWriteoffTimeVal = e || []; },
    onNoWriteoffTime(e) { this.noWriteoffTimeVal = e || []; },
    onSaleDateTime(e) { this.saleDateTimeVal = e || []; },
    formatSaleDateTimeRange(val) {
      if (!val || !val.length) return '';
      const fmt = 'yyyy/MM/dd HH:mm:ss';
      const formatOne = (d, isEnd = false) => {
        if (!d) return '';
        let text = '';
        if (typeof d === 'string') {
          text = d.trim();
        } else if (d instanceof Date && !isNaN(d.getTime())) {
          text = formatDate(d, fmt);
        } else {
          text = String(d).trim();
        }
        if (isEnd && /00:00:00$/.test(text)) {
          text = text.replace(/00:00:00$/, '23:59:59');
        }
        return text;
      };
      const start = formatOne(val[0]);
      const end = formatOne(val[1], true);
      return start && end ? `${start}~${end}` : '';
    },
    async exports() {
      let [th, filekey, data, fileName] = [[], [], [], ''];
      const excelData = this.buildQueryParams();
      excelData.page = 1;
      excelData.limit = 500;
      if (this.dataid.length) {
        excelData.ids = this.dataid.join(',');
      }
      for (let i = 0; i < excelData.page + 1; i++) {
        const lebData = await this.getExcelData(excelData);
        if (!fileName) fileName = lebData.filename;
        if (!filekey.length) filekey = lebData.filekey;
        if (!th.length) th = lebData.header;
        if (lebData.export.length) {
          data = data.concat(lebData.export);
          excelData.page++;
        } else {
          exportExcel(th, filekey, fileName, data);
          return;
        }
      }
    },
    getExcelData(excelData) {
      return new Promise((resolve) => {
        exportUserDataApi(excelData).then((res) => resolve(res.data));
      });
    },
    reset() {
      this.headeType = '-1';
      this.userFrom = {
        nickname: '',
        label_id: '',
        user_type: '',
        sex: '',
        is_promoter: '',
        isMember: '',
        pay_count: '',
        user_time_type: '',
        user_time: '',
        page: 1,
        limit: 15,
        level: '',
        group_id: '',
        field_key: '',
      };
      this.field_key = 'all';
      this.dataLabel = [];
      this.timeVal = [];
      this.integralStart = null;
      this.integralEnd = null;
      this.nowMoneyPeiceStart = null;
      this.nowMoneyPeiceEnd = null;
      this.cashConsumePriceStart = null;
      this.cashConsumePriceEnd = null;
      this.recentConsumeType = '';
      this.recentConsumeDays = null;
      this.recentConsumeTimeVal = [];
      this.noConsumeType = '';
      this.noConsumeDays = null;
      this.noConsumeTimeVal = [];
      this.writeoffCountStart = null;
      this.writeoffCountEnd = null;
      this.recentWriteoffType = '';
      this.recentWriteoffDays = null;
      this.recentWriteoffTimeVal = [];
      this.noWriteoffType = '';
      this.noWriteoffDays = null;
      this.noWriteoffTimeVal = [];
      this.payCountStart = null;
      this.payCountEnd = null;
      this.saleDateTimeVal = [];
      this.getList();
    },
    select(e) {
      this.datanew = e;
      this.dataid = e.map((item) => item.uid);
    },
    selectall(e) {
      if (e.length === 0) {
        this.dataid = [];
      } else {
        this.datanew = e;
        this.dataid = e.map((item) => item.uid);
      }
    },
    setLabel() {
      if (this.datanew.length === 0) {
        this.$Message.warning('请选择要设置标签的用户');
      } else {
        const uids = { all: 0, uids: this.dataid };
        this.$modalForm(userSetLabelApi(uids)).then(() => this.getList());
      }
    },
    detail(row) {
      this.$refs.userDetails.modals = true;
      this.$refs.userDetails.getDetails(row.uid);
      this.$refs.userDetails.changeType('card_holder');
    },
    openLabel(row) {
      this.labelShow = true;
      this.labelActive.uid = row.uid;
    },
    labelClose() {
      this.labelShow = false;
      this.labelActive.uid = 0;
      this.getList();
    },
    pageChange(page) {
      this.userFrom.page = page;
      this.getList();
    },
    sortChanged(e) {
      this.userFrom[e.key] = e.order;
      this.getList();
    },
    cardHolderOpen(row) {
      this.cardHolderData = {};
      this.$nextTick(() => {
        this.cardHolderData = row;
        this.cardHolderShow = true;
      });
    },
    onSend() {
      this.$refs.sends.modals = true;
      this.$refs.sends.getList();
    },
  },
};
</script>

<style scoped lang="stylus">
/deep/.ivu-form-label-left .ivu-form-item-label {
  text-align: right;
}

/deep/.ivu-card-body {
  padding-bottom: 0px;
}

/deep/.ivu-select-selected-value {
  font-size: 12px !important;
}

.padding-add {
  padding: 20px 20px 0;
}

.user-filter-form {
  /deep/ .ivu-form-item {
    margin-bottom: 16px;
  }
  /deep/ .input-add {
    width: 100%;
    max-width: none;
  }
  /deep/ .labelInput {
    width: 100%;
    max-width: none;
  }
  /deep/ .ivu-date-picker {
    width: 100%;
  }
  .num-range {
    display: flex;
    align-items: center;
    width: 100%;
    .num-range-input {
      flex: 1;
      width: 0;
      min-width: 0;
    }
    .num-range-sep {
      flex-shrink: 0;
      padding: 0 6px;
      color: #999;
    }
  }
  .combo-filter {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    .combo-type {
      width: 88px;
      flex-shrink: 0;
    }
    .combo-value {
      flex: 1;
      min-width: 0;
    }
    /deep/ .combo-value.ivu-input-number {
      width: 100%;
    }
  }
  .user-filter-actions {
    text-align: right;
    padding-bottom: 16px;
  }
}

.mart {
  margin-top: 10px;
}

.listbox {
  >>>.ivu-divider-horizontal {
    margin: 0 !important;
  }
}

.userFrom {
  >>> .ivu-form-item-content {
    margin-left: 0px !important;
  }
}

.input-add {
  max-width: 250px;
}

.tabBox_img {
  width: 36px;
  height: 36px;
  border-radius: 4px;
  cursor: pointer;

  img {
    width: 100%;
    height: 100%;
  }
}

.search {
  width: 86px;
  height: 32px;
}

.labelInput {
  max-width: 250px;
  border: 1px solid #dcdee2;
  padding: 0 5px;
  border-radius: 5px;
  min-height: 30px;
  cursor: pointer;

  .span {
    color: #c5c8ce;
  }

  .iconxiayi {
    font-size: 12px;
  }
}

.w-118 {
  width: 118px;
}

.mr8 {
  margin-right: 8px;
}

.mr14 {
  margin-right: 14px;
}

.box {
  padding-bottom: 20px;
}

.new_tab {
  >>>.ivu-tabs-nav .ivu-tabs-tab {
    padding: 4px 16px 20px !important;
    font-weight: 500;
  }
}

.dateMedia {
  /deep/.ivu-form-item-content {
    max-width: 250px;
  }
}
</style>
