<template>
  <div>
    <Modal v-model="modals" scrollable closable title="选择链接" :mask-closable="false"  width="860" @on-cancel="cancel">
      <div class="table_box">
        <div class="left_box" v-if="fromType != 'diyPage'">
          <Tree :data="categoryData" @on-select-change="handleCheckChange"></Tree>
        </div>
        <div class="right_box" v-if="currenType=='link'">
          <div v-if="basicsList.length">
            <div class="cont">基础链接</div>
            <div class="Box">
              <div class="cont_box" :class="currenId==item.id?'on':''" v-for="(item,index) in basicsList" :key="index" @click="getUrl(item)">{{item.name}}</div>
            </div>
          </div>
          <div v-if="isStaffListSelected" class="staff-link-filter">
            <div class="cont">人员列表筛选</div>
            <Form :label-width="104">
              <FormItem label="岗位（可多选）">
                <Select
                  v-model="staffPositionIds"
                  multiple
                  clearable
                  filterable
                  :loading="staffPositionsLoading"
                  placeholder="不选择时展示全部岗位"
                  @on-change="updateStaffListUrl"
                >
                  <Option v-for="item in staffPositionOptions" :key="item.id" :value="item.id">{{ item.name }}</Option>
                </Select>
              </FormItem>
            </Form>
            <div class="staff-link-filter__hint">链接会跳转到人员列表；不选岗位时展示当前门店全部服务老师。</div>
          </div>
          <div v-if="userList.length">
            <div class="cont">个人中心</div>
            <div class="Box">
              <div class="cont_box" :class="currenId==item.id?'on':''" v-for="(item,index) in userList" :key="index" @click="getUrl(item)">{{item.name}}</div>
            </div>
          </div>
          <div v-if="distributionList.length">
            <div class="cont">分销</div>
            <div class="Box">
              <div class="cont_box" :class="currenId==item.id?'on':''" v-for="(item,index) in distributionList" :key="index" @click="getUrl(item)">{{item.name}}</div>
            </div>
          </div>
        </div>
        <div class="right_box" v-if="currenType=='marketing_link'">
          <div v-if="coupon.length">
            <div class="cont">优惠券</div>
            <div class="Box">
              <div class="cont_box" :class="currenId==item.id?'on':''" v-for="(item,index) in coupon" :key="index" @click="getUrl(item)">{{item.name}}</div>
            </div>
          </div>
          <div v-if="basicsList.length">
            <div class="cont">秒杀</div>
            <div class="Box">
              <div class="cont_box" :class="currenId==item.id?'on':''" v-for="(item,index) in basicsList" :key="index" @click="getUrl(item)">{{item.name}}</div>
            </div>
          </div>
          <div v-if="distributionList.length">
            <div class="cont">砍价</div>
            <div class="Box">
              <div class="cont_box" :class="currenId==item.id?'on':''" v-for="(item,index) in distributionList" :key="index" @click="getUrl(item)">{{item.name}}</div>
            </div>
          </div>
          <div v-if="userList.length">
            <div class="cont">拼团</div>
            <div class="Box">
              <div class="cont_box" :class="currenId==item.id?'on':''" v-for="(item,index) in userList" :key="index" @click="getUrl(item)">{{item.name}}</div>
            </div>
          </div>
          <div v-if="presale.length">
            <div class="cont">预售</div>
            <div class="Box">
              <div class="cont_box" :class="currenId==item.id?'on':''" v-for="(item,index) in presale" :key="index" @click="getUrl(item)">{{item.name}}</div>
            </div>
          </div>
          <div v-if="integral.length">
            <div class="cont">积分</div>
            <div class="Box">
              <div class="cont_box" :class="currenId==item.id?'on':''" v-for="(item,index) in integral" :key="index" @click="getUrl(item)">{{item.name}}</div>
            </div>
          </div>
          <div v-if="luckDraw.length">
            <div class="cont">抽奖</div>
            <div class="Box">
              <div class="cont_box" :class="currenId==item.id?'on':''" v-for="(item,index) in luckDraw" :key="index" @click="getUrl(item)">{{item.name}}</div>
            </div>
          </div>
        </div>
        <div class="right_box" :class="fromType == 'diyPage'?'diy':''" v-if="currenType=='special' || currenType=='store_home' || currenType=='product_category' || currenType=='product' || currenType=='seckill' || currenType == 'bargain' || currenType == 'combination' || currenType == 'news' || currenType == 'integral' || currenType == 'lottery'">
          <Form
              ref="formValidate"
              :model="formValidate"
              class="tabform"
              v-if="currenType=='product' || currenType=='store_home'"
          >
            <Row type="flex" :gutter="24" v-if="currenType=='product'">
              <Col>
                <FormItem label="" label-for="pid">
                  <Select
                      v-model="formValidate.cate_id"
                      style="width: 230px"
                      clearable
                      @on-change="userSearchs"
                  >
                    <Option v-for="item in treeSelect" :value="item.id" :key="item.id"
                    >{{ item.html + item.cate_name }}
                    </Option>
                  </Select>
                </FormItem>
              </Col>
              <Col>
                <FormItem label="" label-for="store_name">
                  <Input
                      search
                      enter-button
                      placeholder="请输入商品名称,关键字,编号"
                      v-model="formValidate.store_name"
                      class="input-add"
                      @on-search="userSearchs"
                  />
                </FormItem>
              </Col>
            </Row>
			<Row type="flex" :gutter="24" v-if="currenType=='store_home'">
			  <Col>
			    <FormItem label="" label-for="keywords">
			      <Input
			          search
			          enter-button
			          placeholder="点击搜索门店"
			          v-model="formValidate.keywords"
			          class="input-add"
			          @on-search="userSearchs"
			      />
			    </FormItem>
			  </Col>
			</Row>
          </Form>
          <Table
              row-key="id"
              :load-data="handleLoadData"
              ref="table"
              no-data-text="暂无数据"
              no-filtered-data-text="暂无筛选结果"
              :columns="(currenType=='special'||currenType=='lottery')?columns:currenType=='product_category'?columns7:(currenType=='bargain'||currenType=='combination'||currenType=='integral')?bargain:currenType=='news'?news:currenType=='store_home'?storeHome:columns8"
              :data="tableList"
              :loading="loading"
              :max-height="(currenType=='special'||currenType=='lottery')?'410':( currenType=='seckill' || currenType == 'bargain' || currenType == 'combination' || currenType == 'news' || currenType == 'integral')?'400':currenType=='product'?'356':''"
          >
            <template slot-scope="{ row, index }" slot="pic" v-if="row.hasOwnProperty('pic')">
              <viewer>
                <div class="tabBox_img">
                  <img v-lazy="row.pic"/>
                </div>
              </viewer>
            </template>
            <template slot-scope="{ row, index }" slot="image" v-if="row.hasOwnProperty('image')">
              <viewer>
                <div class="tabBox_img">
                  <img v-lazy="row.image" />
                </div>
              </viewer>
            </template>
            <template slot-scope="{ row, index }" slot="image_input" v-if="row.hasOwnProperty('image_input')">
              <viewer>
                <div class="tabBox_img">
                  <img v-lazy="row.image_input[0]" />
                </div>
              </viewer>
            </template>
			<template slot-scope="{ row, index }" slot="storeInfo" v-if="row.hasOwnProperty('image')">
			  <div class="acea-row row-middle">
				<viewer>
				  <div class="tabBox_img">
				    <img v-lazy="row.image" />
				  </div>
				</viewer>
				<div class="ml10 width10">{{row.name}}</div>
			  </div>
			</template>
          </Table>
          <div class="acea-row row-right page" v-if="currenType=='special'||currenType=='store_home'||currenType=='product'||currenType=='seckill'||currenType == 'bargain'||currenType == 'combination'||currenType == 'news'||currenType == 'integral'||currenType=='lottery'">
            <Page
                :current="formValidate.page"
                :total="total"
                show-elevator
                show-total
                @on-change="pageChange"
                :page-size="formValidate.limit"
            />
          </div>
        </div>
        <div class="right_box" v-if="currenType=='custom'">
          <div style="width: 340px;margin: 150px 100px 0 120px">
            <Form ref="customdate" :model="customdate" :rules="ruleValidate" :label-width="100">
              <FormItem label="APPID：" prop="appid">
                <Input v-model="customdate.appid" placeholder="请输入正确APPID"></Input>
              </FormItem>
              <FormItem label="小程序路径：" prop="mpUrl">
                <Input v-model="customdate.mpUrl" placeholder="请输入正确小程序路径"></Input>
              </FormItem>
            </Form>
          </div>
        </div>
      </div>
      <div slot="footer">
        <Button @click="cancel">取消</Button>
        <Button type="primary" @click="handleSubmit('customdate')" v-if="currenType=='custom'">确定</Button>
        <Button type="primary" @click="ok" v-else>确定</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import { pageCategory, pageLink, saveLink } from '@/api/diy';
import { treeListApi, changeListApi } from '@/api/product';
import { seckillProductList, combinationListApi, bargainListApi, integralProductListApi } from '@/api/marketing';
import { cmsListApi } from '@/api/cms';
import { positionList } from '@/api/position';

const STAFF_LIST_LINK_ID = 'staff_list';
const STAFF_LIST_PATH = '/pages/activity/therapist_list/index';

export default {
  name: 'linkaddress',
  props: {
    linkType: {
      type: Number,
      default: 0
    },
    fromType: {
      type: String,
      default: ''
    },
    isCateTree: {
      type: Boolean,
      default: true
    }
  },
  data() {
    return {
      modals: false,
      categoryData: [],
      currenType: 'link',
      columns: [
        {
          title: 'ID',
          key: 'id',
          width: 60
        },
        {
          title: '页面名称',
          key: 'name',
          width: 150
        },
        {
          title: '页面链接',
          key: 'url'
        }
      ],
      columns7: [
        {
          title: 'ID',
          key: 'id',
          width: 60
        },
        {
          title: '分类名称',
          key: 'cate_name',
          tree: true
        },
        {
          title: '分类图标',
          slot: 'pic'
        }
      ],
      columns8: [
        {
          title: 'ID',
          key: 'id',
          width: 60
        },
        {
          title: '商品图片',
          slot: 'image',
          width: 90
        },
        {
          title: '商品名称',
          key: 'store_name'
        }
      ],
	  storeHome: [
        {
		  title: 'ID',
		  key: 'id',
		  width: 60
        },
        {
		  title: '门店信息',
		  slot: 'storeInfo',
		  width: 160
        },
        {
		  title: '门店分类',
		  key: 'cate_name',
		  width: 90
        },
        {
		  title: '联系电话',
		  key: 'phone',
		  width: 100
        },
        {
		  title: '门店地址',
		  key: 'detailed_address'
        }
	  ],
      bargain: [
        {
          title: 'ID',
          key: 'id',
          width: 60
        },
        {
          title: '商品图片',
          slot: 'image',
          width: 90
        },
        {
          title: '商品名称',
          key: 'title'
        }
      ],
      news: [
        {
          title: 'ID',
          key: 'id',
          width: 60
        },
        {
          title: '文章图片',
          slot: 'image_input',
          width: 90
        },
        {
          title: '文章名称',
          key: 'title'
        }
      ],
      formValidate: {
        page: 1,
        limit: 15,
        cate_id: '',
        store_name: '',
        keywords: ''
      },
      total: 0,
      basicsList: [],
      userList: [],
      distributionList: [],
      coupon: [],
      luckDraw: [],
      integral: [],
      presale: [],
      staffPositionIds: [],
      staffPositionOptions: [],
      staffPositionsLoading: false,
      currenId: '',
      currenUrl: '',
	  currenName: '',
      loading: false,
      tableList: [],
      presentId: 0,
      categoryId: '', // 左侧分类id
      treeSelect: [],
      customdate: {
        appid: '',
        mpUrl: ''
      },
      customNum: 1,
      ruleValidate: {
        name: [
          { required: true, message: '请输入链接名称', trigger: 'blur' }
        ],
        url: [
          { required: true, message: '请输入跳转路径', trigger: 'blur' }
        ],
        appid: [
          { required: true, message: '请输入正确APPID', trigger: 'blur' }
        ],
        mpUrl: [
          { required: true, message: '请输入正确小程序路径', trigger: 'blur' }
        ]
      },
      pid: 0,
      currentItem: {}
    };
  },
  computed: {
    isStaffListSelected() {
      return this.currenType === 'link' && this.currenId === STAFF_LIST_LINK_ID;
    },
  },
  watch: {
    isCateTree: {
      handler(val) {
        for (const value of this.columns7) {
          if (value.key == 'cate_name') {
            value.tree = val;
            break;
          }
        }
      },
      immediate: true
    }
  },
  created() {
    this.getSort();
    this.goodsCategory();
    const radio = {
      width: 60,
      align: 'center',
      render: (h, params) => {
        const id = params.row.id;
        let flag = false;
        if (this.presentId === id) {
          flag = true;
        } else {
          flag = false;
        }
        const self = this;
        return h('div', [
          h('Radio', {
            props: {
              value: flag
            },
            on: {
              'on-change': () => {
                self.presentId = id;
                this.currenUrl = params.row.url;
              }
            }
          })
        ]);
      }
    };
    this.columns.unshift(radio);
    this.columns7.unshift(radio);
    this.columns8.unshift(radio);
    this.bargain.unshift(radio);
    this.news.unshift(radio);
    this.storeHome.unshift(radio);
  },
  methods: {
    handleLoadData(item, callback) {
      item._loading = true;
      pageLink(this.categoryId, { pid: item.id }).then(res => {
        item._loading = false;
        res.data.list.forEach((e) => {
          e.url = `/pages/goods/goods_list/index?sid=${e.id}&title=${e.cate_name}`;
        });
        callback(res.data.list);
      });
    },
    // 删除
    delLink(row, tit, num) {
      const delfromData = {
        title: tit,
        num: num,
        url: `diy/del_link/${row.id}`,
        method: 'DELETE',
        ids: ''
      };
      this.$modalSure(delfromData).then((res) => {
        this.$Message.success(res.msg);
        this.tableList.splice(num, 1);
        if (!this.tableList.length) {
          this.customNum = 2;
        }
      }).catch(res => {
        this.$Message.error(res.msg);
      });
    },
    customLink() {
      this.customNum = 2;
    },
    customList() {
      this.customNum = 1;
    },
    getCustomList() {
      pageLink(this.categoryId).then(res => {
        if (!res.data.list.length) {
          this.customNum = 2;
        }
        this.tableList = res.data.list;
      }).catch(err => {
        this.$Message.error(err.msg);
      });
    },
    handleSubmit(name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
          const url = this.customdate.mpUrl + '@APPID=' + this.customdate.appid;
          this.$emit('linkUrl', url, this.currenName);
          this.modals = false;
          this.reset();
        } else {
          this.$Message.error('请填写信息');
        }
      });
    },
    pageChange(index) {
      this.formValidate.page = index;
      if (this.currenType == 'special' || this.currenType == 'store_home' || this.currenType == 'lottery') {
        this.handleCheckChange('', this.currentItem);
      } else {
        this.getList();
      }
    },
    // 商品分类；
    goodsCategory() {
      treeListApi(1)
        .then((res) => {
          this.treeSelect = res.data;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 表格搜索
    userSearchs() {
      this.formValidate.page = 1;
	  if (this.currenType == 'product') {
		  this.getList();
	  } else {
		  this.handleCheckChange('', this.currentItem);
	  }
    },
    reset() {
      this.currenUrl = '';
      this.presentId = 0;
      this.currenId = '';
      this.staffPositionIds = [];
      this.customdate.appid = '';
      this.customdate.mpUrl = '';
    },
    getUrl(item) {
      this.currenId = item.id;
      this.currenName = item.name;
      if (item.id === STAFF_LIST_LINK_ID) {
        this.updateStaffListUrl(this.staffPositionIds);
        this.loadStaffPositions();
        return;
      }
      this.currenUrl = item.url;
    },
    loadStaffPositions() {
      if (this.staffPositionsLoading || this.staffPositionOptions.length) return;
      this.staffPositionsLoading = true;
      positionList({ page: 1, limit: 100 }).then((res) => {
        const data = res.data || {};
        this.staffPositionOptions = (Array.isArray(data.list) ? data.list : [])
          .filter((item) => Number(item.status) === 1)
          .map((item) => ({ id: Number(item.id), name: item.name || `岗位${item.id}` }))
          .filter((item) => item.id > 0);
      }).catch((err) => {
        this.$Message.error((err && err.msg) || '岗位列表加载失败');
      }).finally(() => {
        this.staffPositionsLoading = false;
      });
    },
    updateStaffListUrl(ids) {
      const selected = (Array.isArray(ids) ? ids : [])
        .map(Number)
        .filter((id, index, values) => id > 0 && values.indexOf(id) === index);
      this.staffPositionIds = selected;
      const query = selected.length ? `?position_ids=${encodeURIComponent(selected.join(','))}` : '';
      this.currenUrl = `${STAFF_LIST_PATH}${query}`;
    },
    getSort() {
      pageCategory().then(res => {
        res.data[0].children[0].selected = true;
        this.categoryData = res.data;
        this.handleCheckChange('', res.data[0].children[0]);
      }).catch(err => {
        this.$Message.error(err.msg);
      });
    },
    getList() {
      this.loading = true;
      if (this.currenType == 'product') {
        changeListApi(this.formValidate)
          .then(async(res) => {
            const data = res.data;
            data.list.forEach((e) => {
              e.url = `/pages/goods_details/index?id=${e.id}`;
            });
            this.tableList = data.list;
            this.total = res.data.count;
            this.loading = false;
          })
          .catch((res) => {
            this.loading = false;
            this.$Message.error(res.msg);
          });
      } else if (this.currenType == 'seckill') {
        seckillProductList(this.formValidate)
          .then(async(res) => {
            const data = res.data;
            data.list.forEach((e) => {
              e.url = `/pages/activity/goods_details/index?id=${e.id}&status=1&type=1`;
            });
            this.tableList = data.list;
            this.total = res.data.count;
            this.loading = false;
          })
          .catch((res) => {
            this.loading = false;
            this.$Message.error(res.msg);
          });
      } else if (this.currenType == 'bargain') {
        bargainListApi(this.formValidate)
          .then(async(res) => {
            const data = res.data;
            data.list.forEach((e) => {
              e.url = `/pages/activity/goods_bargain_details/index?id=${e.id}`;
            });
            this.tableList = data.list;
            this.total = res.data.count;
            this.loading = false;
          })
          .catch((res) => {
            this.loading = false;
            this.$Message.error(res.msg);
          });
      } else if (this.currenType == 'combination') {
        combinationListApi(this.formValidate)
          .then(async(res) => {
            const data = res.data;
            data.list.forEach((e) => {
              e.url = `/pages/activity/goods_details/index?id=${e.id}&type=3`;
            });
            this.tableList = data.list;
            this.total = res.data.count;
            this.loading = false;
          })
          .catch((res) => {
            this.loading = false;
            this.$Message.error(res.msg);
          });
      } else if (this.currenType == 'news') {
        cmsListApi(this.formValidate).then(async res => {
          const data = res.data;
          data.list.forEach((e) => {
            e.url = `/pages/extension/news_details/index?id=${e.id}`;
          });
          this.tableList = data.list;
          this.total = data.count;
          this.loading = false;
        }).catch(res => {
          this.loading = false;
          this.$Message.error(res.msg);
        });
      } else if (this.currenType == 'integral') {
        integralProductListApi(this.formValidate)
          .then(async(res) => {
            const data = res.data;
            data.list.forEach((e) => {
              e.url = `/pages/activity/goods_details/index?id=${e.id}&type=4`;
            });
            this.tableList = data.list;
            this.total = res.data.count;
            this.loading = false;
          })
          .catch((res) => {
            this.loading = false;
            this.$Message.error(res.msg);
          });
      }
    },
    handleCheckChange(data, event) {
      if (data && data.length) {
        this.formValidate.page = 1;
        this.currenName = data[0].name;
      }
      this.currentItem = event;
      this.reset();
      let id = '';
      if (event.pid) {
        id = event.id;
        this.categoryId = event.id;
      } else {
        return false;
      }
      this.loading = true;
      this.currenType = event.type;
      if (this.currenType == 'product' || this.currenType == 'seckill' || this.currenType == 'bargain' || this.currenType == 'combination' || this.currenType == 'news' || this.currenType == 'integral') {
        this.getList();
      } else if (this.currenType == 'custom') {
        this.getCustomList();
      } else {
        const form = { pid: this.pid };
        if (this.currenType == 'special' || this.currenType == 'store_home' || this.currenType == 'lottery') {
          form.page = this.formValidate.page;
          form.limit = this.formValidate.limit;
        }
        if (this.currenType == 'store_home') {
		  form.keywords = this.formValidate.keywords;
        }
        pageLink(id, form).then(res => {
          this.loading = false;
          const data = res.data.list;
          if (this.currenType == 'marketing_link' || this.currenType == 'link') {
            const basicsList = [];
            const distributionList = [];
            const userList = [];
            const integral = [];
            const luckDraw = [];
            const coupon = [];
            const presale = [];
            data.forEach((e) => {
              if (e.type == 1) {
                basicsList.push(e);
              } else if (e.type == 2) {
                distributionList.push(e);
              } else if (e.type == 3) {
                userList.push(e);
              } else if (e.type == 4) {
                integral.push(e);
              } else if (e.type == 5) {
                luckDraw.push(e);
              } else if (e.type == 6) {
                presale.push(e);
              } else {
                coupon.push(e);
              }
            });
            if (this.currenType === 'link' && !basicsList.some((item) => item.id === STAFF_LIST_LINK_ID)) {
              basicsList.push({
                id: STAFF_LIST_LINK_ID,
                name: '人员列表',
                url: STAFF_LIST_PATH
              });
            }
            this.basicsList = basicsList;
            this.distributionList = distributionList;
            this.userList = userList;
            this.coupon = coupon;
            this.luckDraw = luckDraw;
            this.integral = integral;
            this.presale = presale;
          } else if (this.currenType == 'special') {
            const list = [];
            res.data.list.forEach((e) => {
              if (this.linkType) {
                e.url = `/pages/annex/special/index?id=${e.id}&name=${e.name}`;
              } else {
                e.url = `/pages/annex/special/index?id=${e.id}`;
              }
              if (e.is_diy) {
                list.push(e);
              }
            });
            this.total = res.data.count;
            this.tableList = list;
          } else if (this.currenType == 'product_category') {
            data.forEach((e) => {
              if (e.hasOwnProperty('children')) {
                e.children.forEach((j) => {
                  j.url = `/pages/goods/goods_list/index?sid=${j.id}&title=${j.cate_name}`;
                });
              }
              e.url = `/pages/goods/goods_list/index?cid=${e.id}&title=${e.cate_name}`;
            });
            this.tableList = data;
          } else if (this.currenType == 'store_home') {
            const list = [];
            res.data.list.forEach((e) => {
			  e.url = `/pages/store/home/index?id=${e.id}`;
			  list.push(e);
            });
            this.total = res.data.count;
            this.tableList = list;
		  } else if (this.currenType == 'lottery') {
            const list = [];
            res.data.list.forEach((e) => {
              e.url = `/pages/goods/lottery/grids/index?type=1&id=${e.id}`;
              list.push(e);
            });
            this.total = res.data.count;
            this.tableList = list;
          }
        }).catch(err => {
          this.loading = false;
          this.$Message.error(err.msg);
        });
      }
    },
    ok() {
      if (this.currenUrl == '') {
        return this.$Message.warning('请选择链接');
      } else {
        this.$emit('linkUrl', this.currenUrl, this.currenName);
        this.modals = false;
        this.reset();
      }
    },
    cancel() {
      this.modals = false;
	  this.$refs['customdate'].resetFields();
      this.reset();
    }
  }
};
</script>

<style scoped lang="stylus">
/deep/.ivu-tree-title-selected, /deep/.ivu-tree-title-selected:hover,/deep/.ivu-tree-title:hover{
  background-color: unset;
  color: #1890FF;
}
/deep/.ivu-table-cell-tree{
  border:0;
  font-size 15px;
  background-color unset;
}
/deep/.ivu-table-cell-tree .ivu-icon-ios-add:before{
  content: "\F11F";
}
/deep/.ivu-table-cell-tree .ivu-icon-ios-remove:before{
  content: "\F116";
}
.radioGroup{
  /deep/.ivu-radio-wrapper{
    margin-right 30px;
  }
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
/*定义滑块 内阴影+圆角*/
::-webkit-scrollbar-thumb{
  -webkit-box-shadow: inset 0 0 6px #ddd;
}
::-webkit-scrollbar {
  width: 4px!important; /*对垂直流动条有效*/
}
.on{
  background-color #2d8cf0!important;
  color #fff!important;
}
.staff-link-filter {
  margin: 16px 0;
  padding: 12px 16px 2px;
  border: 1px solid #e8eaec;
  border-radius: 4px;
  background: #fafcff;
}
.staff-link-filter .cont {
  margin-bottom: 12px;
}
.staff-link-filter__hint {
  margin: -8px 0 12px 104px;
  color: #999;
  font-size: 12px;
  line-height: 18px;
}
.menu-item{
  position: relative;
  display flex
  justify-content space-between
  word-break break-all
  .icon-box{
    z-index 3
    position absolute
    right 20px
    top 50%
    transform translateY(-50%)
    display none
  }
  &:hover .icon-box{
    display block
  }
  .right-menu{
    z-index 10
    position absolute
    right: -106px
    top: -11px
    width auto
    min-width: 121px
  }
}

.table_box{
  margin-top: 14px;
  display flex
  position relative
  .left_box{
    width: 171px;
    height: 470px
    border-right: 1px solid #EEEEEE;
    overflow-x hidden;
    overflow-y auto;
    .left_cont{
      margin-bottom 12px
      cursor pointer
    }
  }
  .right_box{
    margin-left 23px
    font-size: 13px;
    font-family: PingFang SC;
    width 645px;
    height: 470px;
    overflow-x hidden;
    overflow-y auto;
    &.diy {
      width 780px;
    }
    .cont{
      font-weight: 500;
      color: #000000
      font-weight bold
    }
    .Box{
      margin-top 19px
      display flex
      flex-wrap wrap
      .cont_box{
        font-weight: 400;
        color: rgba(0, 0, 0, 0.85);
        background: #FAFAFA;
        border-radius: 3px;
        text-align center
        padding 7px 30px
        margin-right 10px
        margin-bottom 18px
        cursor pointer
        &:hover{
          background-color #eee
          color #333;
        }
      }
      .item{
        position relative;
        .iconfont{
          display none;
        }
        &:hover{
          .iconfont{
            display block;
          }
        }
      }
      .iconfont{
        position absolute
        right 9px;
        top:-8px;
        font-size 18px;
        color #333;
      }
    }

  }
  .Button{
    position absolute
    bottom 15px
    right 15px
    font-family: PingFangSC-Regular;
    text-align center
    .cancel{
      width: 70px;
      height: 32px;
      background: #FFFFFF;
      border: 1px solid rgba(0, 0, 0, 0.14901960784313725);
      border-radius: 2px;
      font-size: 14px;
      color: #000000
      line-height 32px
      float left
      margin-right 10px
      cursor pointer
    }
    .ok{
      width: 70px;
      height: 32px;
      background: #1890FF;
      border-radius: 2px;
      font-size: 14px;
      color: #FFFFFF;
      line-height 32px
      float left
      cursor pointer
    }
  }
}
</style>
