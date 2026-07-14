<template>
  <div class="form-submit">
    <div class="i-layout-page-header">
      <PageHeader class="product_tabs" hidden-breadcrumb>
        <div slot="title">
          <router-link :to="{ path: `${roterPre}/marketing/store_seckill/list` }">
            <div class="font-sm after-line">
              <span class="iconfont iconfanhui"></span>
              <span class="pl10">返回</span>
            </div>
          </router-link>
          <span v-text="$route.params.id && copy == 0 ? '编辑秒杀活动' : '添加秒杀活动'" class="mr20 ml16"></span>
        </div>
      </PageHeader>
    </div>
    <Card :bordered="false" dis-hover class="ivu-mt mb79 mh">
      <Tabs v-model="currentTab">
        <TabPane
            v-for="(item, index) in headTab"
            :key="index"
            :label="item.name"
            :name="item.type"
        ></TabPane>
      </Tabs>
      <Form
          class="formValidate mt20"
          ref="formValidate"
          :rules="ruleValidate"
          :model="formValidate"
          :label-width="labelWidth"
          :label-position="labelPosition"
          @submit.native.prevent
      >
        <Row :gutter="24" type="flex" v-show="currentTab === '1'">
          <Col span="24">
            <FormItem label="活动名称：" prop="name">
              <Input
                  v-model="formValidate.name"
                  placeholder="请输入活动名称"
                  class="w_input"
              />
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="活动日期：" prop="section_data">
              <div class="acea-row row-middle">
                <DatePicker :editable="false" type="datetimerange" format="yyyy-MM-dd HH:mm" placeholder="请选择活动日期"
                            @on-change="onchangeTime" class="w_input" :value="formValidate.section_data"
                            v-model="formValidate.section_data"></DatePicker>
              </div>
              <div class="tips">设置活动开启结束日期，用户可以在有效日期内参与秒杀</div>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="秒杀场次：" prop="time_id">
              <div class="acea-row row-middle">
                <Select v-model="formValidate.time_id" multiple class="w_input">
                  <Option v-for="item in timeList" :value="item.id" :key="item.id">{{ item.start_time }} - {{ item.end_time }}</Option>
                </Select>
              </div>
              <div class="tips">选择产品开始时间段，该时间段内用户可参与购买；其它时间段会显示活动未开始或已结束。如活动超过一天，则活动期内，每天都会定时开启</div>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="活动限购：" prop="num">
              <div class="acea-row row-middle">
                <InputNumber :min="1" placeholder="请输入数量限制" element-id="num" :precision="0"
                             v-model="formValidate.num" class="w_input" />
                <span class="ml10">个</span>
              </div>
              <div class="tips">活动有效期内每个用户可购买该商品总数限制。例如设置为4，表示本次活动有效期内，每个用户最多可购买总数4个</div>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="单次限购：" prop="once_num">
              <div class="acea-row row-middle">
                <InputNumber :min="1" placeholder="请输入单次购买数量限制" element-id="once_num" :precision="0"
                             v-model="formValidate.once_num" class="w_input" />
                <span class="ml10">个</span>
              </div>
              <div class="tips">用户参与秒杀时，一次购买最大数量限制。例如设置为2，表示参与秒杀时，用户一次购买数量最大可选择2个</div>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="活动氛围框：">
              <Row>
                <Col span="24">
                  <div v-if="formValidate.image" class="upload-list">
                    <div class="upload-item">
                      <img v-lazy="formValidate.image" />
                      <Button
                          shape="circle"
                          icon="ios-close"
                          @click="delImage"
                      ></Button>
                    </div>
                  </div>
                  <Button
                      v-else
                      class="upload-select"
                      type="dashed"
                      icon="ios-add"
                      @click="modalPicTap('dan', 'image', 1)"
                  ></Button>
                </Col>
              </Row>
            </FormItem>
          </Col>
          <Col span="24">
            <FormItem label="是否开启：" props="status" label-for="status">
              <i-switch v-model="formValidate.status" :true-value="1" :false-value="0" size="large">
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
            </FormItem>
          </Col>
        </Row>
        <Row :gutter="24" type="flex" v-show="currentTab === '2'">
          <Col span="24">
            <div class="acea-row row-between-wrapper">
              <div>
                <Button type="primary" @click="addGoods">添加商品</Button>
                <Button @click="batchSet" class="ml20" :disabled="!isAllChecked && !checkPidList.length">批量设置</Button>
                <Button @click="delAll" class="ml20" :disabled="!isAllChecked && !checkPidList.length">批量删除</Button>
              </div>
              <div class="goodsWord">
                <FormItem label="商品搜索：">
                  <Input class="w_input240" v-model="keyword" placeholder="请输入商品关键词" @on-change="searchWord"></Input>
                </FormItem>
              </div>
            </div>
          </Col>
          <Col span="24">
            <div class="vxeTable">
              <vxe-table
                  border="inner"
                  ref="xTree"
                  :column-config="{resizable: true}"
                  row-id="id"
                  :tree-config="{children: 'attrValue',reserve:true}"
                  @checkbox-all="checkboxAll"
                  @checkbox-change="checkboxItem"
                  :data="(searchTableData.length || keyword)?searchTableData:tableData">
                <vxe-column type="checkbox" title="多选" width="90" tree-node></vxe-column>
                <vxe-column field="info" title="商品信息" min-width="300">
                  <template v-slot="{ row }">
                    <div class="imgPic acea-row row-middle">
                      <viewer>
                        <div class="pictrue"><img v-lazy="row.image" /></div>
                      </viewer>
                      <div class="info">
                        <Tooltip max-width="200" placement="bottom" transfer>
                          <span class="line2">{{ row.store_name }}{{row.suk}}</span>
                          <p slot="content">{{ row.store_name }}{{row.suk}}</p>
                        </Tooltip>
                      </div>
                    </div>
                  </template>
                </vxe-column>
                <vxe-column field="price" title="秒杀价" min-width="150">
                  <template v-slot="{ row }">
                    <div v-if="row.parent == 1">——</div>
                    <vxe-input v-else v-model="row.price" min="0" placeholder="请输入秒杀价" type="float" digits="2" step="1"></vxe-input>
                  </template>
                </vxe-column>
                <vxe-column field="cost" title="成本价" min-width="80"></vxe-column>
                <vxe-column field="ot_price" title="划线价" min-width="80"></vxe-column>
                <vxe-column field="stock" title="库存" min-width="90"></vxe-column>
                <vxe-column field="quota" title="限量" min-width="150">
                  <template v-slot="{ row }">
                    <div v-if="row.parent == 1">——</div>
                    <vxe-input v-else v-model="row.quota" min="0" placeholder="请输入限量" type="integer"></vxe-input>
                  </template>
                </vxe-column>
				<vxe-column field="sort" title="排序" min-width="150">
				  <template v-slot="{ row }">
				    <vxe-input v-if="row.parent == 1" v-model="row.sort" min="0" placeholder="请输入排序" type="integer"></vxe-input>
					<div v-else>——</div>
				  </template>
				</vxe-column>
                <vxe-column field="status" title="是否开启" min-width="100">
                  <template v-slot="{ row }">
                    <i-switch
                        v-if="row.parent == 1"
                        v-model="row.status"
                        :true-value="1"
                        :false-value="0"
                        size="large"
                    >
                      <span slot="open">上架</span>
                      <span slot="close">下架</span>
                    </i-switch>
                    <i-switch
                        v-else
                        v-model="row.status"
                        :value="row.status"
                        :true-value="1"
                        :false-value="0"
                        size="large"
                    >
                      <span slot="open">上架</span>
                      <span slot="close">下架</span>
                    </i-switch>
                  </template>
                </vxe-column>
                <vxe-column field="date" title="操作" min-width="100" fixed="right">
                  <template v-slot="{ row }">
                    <a @click="del(row)" v-if="row.parent == 1">删除</a>
                  </template>
                </vxe-column>
              </vxe-table>
            </div>
          </Col>
        </Row>
        <Row :gutter="24" type="flex" v-show="currentTab === '3'">
          <Col span="24">
            <RadioGroup v-model="formValidate.applicable_type">
              <Radio :label="1">全部门店</Radio>
              <Radio :label="2">部分门店</Radio>
              <Radio :label="0">仅平台适用</Radio>
            </RadioGroup>
            <div class="tips">可选择活动在哪些门店使用，选择“仅平台适用“则不支持门店购买</div>
          </Col>
          <Col span="24" class="mt20" v-if="formValidate.applicable_type == 2">
            <Button type="primary" @click="addStore">添加门店</Button>
          </Col>
          <Col span="24">
            <div class="vxeTable" v-if="formValidate.applicable_type == 2">
              <vxe-table
                  border="inner"
                  ref="xTree"
                  row-id="id"
                  :data="storesList">
                <vxe-column field="id" title="ID" min-width="60"></vxe-column>
                <vxe-column field="info" title="门店图片" tree-node min-width="80">
                  <template v-slot="{ row }">
                    <div class="imgPic acea-row row-middle">
                      <viewer>
                        <div class="pictrue"><img v-lazy="row.image" /></div>
                      </viewer>
                    </div>
                  </template>
                </vxe-column>
                <vxe-column field="cate_name" title="门店分类" min-width="110"></vxe-column>
                <vxe-column field="name" title="门店名称" min-width="110"></vxe-column>
                <vxe-column field="phone" title="联系电话" min-width="110"></vxe-column>
                <vxe-column field="address" title="门店地址" min-width="150"></vxe-column>
                <vxe-column field="day_time" title="营业时间" min-width="140"></vxe-column>
                <vxe-column field="status_name" title="营业状态" min-width="80"></vxe-column>
                <vxe-column field="date" title="操作" min-width="100" fixed="right">
                  <template v-slot="{ row }">
                    <a @click="delte(row)">删除</a>
                  </template>
                </vxe-column>
              </vxe-table>
            </div>
          </Col>
        </Row>
      </Form>
    </Card>
    <Card :bordered="false" dis-hover class="fixed-card" :style="{left: `${!menuCollapse?'236px':isMobile?'0':'60px'}`}">
      <Form>
        <FormItem>
          <Button
              v-if="currentTab !== '1'"
              @click="upTab"
              style="margin-right:10px"
          >上一步</Button>
          <Button
              type="primary"
              class="submission"
              v-if="currentTab !== '3'"
              @click="downTab('formValidate')"
          >下一步</Button
          >
          <Button
              v-else
              type="primary"
              class="submission"
              @click="handleSubmit('formValidate')"
          >提交</Button
          >
        </FormItem>
      </Form>
    </Card>
    <Modal
        v-model="modalPic"
        width="960px"
        scrollable
        footer-hide
        closable
        title="上传活动氛围框"
        :mask-closable="false"
        :z-index="500"
    >
      <uploadPictures
          isChoice="单选"
          @getPic="getPic"
          :gridBtn="gridBtn"
          :gridPic="gridPic"
          v-if="modalPic"
      ></uploadPictures>
    </Modal>
    <Modal v-model="modals" title="商品列表" footerHide scrollable width="900" @on-cancel="cancel">
      <goods-list ref="goodslist" :ischeckbox="true" :isdiy="true" :chooseType="1"  @getProductId="getProductId" v-if="modals"></goods-list>
    </Modal>
    <Modal v-model="modalsSet" title="批量设置" @on-visible-change="batchVisibleChange">
      <Form
          ref="formBatch"
          :model="formBatch"
          :label-width="labelWidth"
          :label-position="labelPosition"
          @submit.native.prevent
      >
        <FormItem label="秒杀价：" prop="price">
          <vxe-input class="w_input315" v-model="formBatch.price" min="0" placeholder="请输入秒杀价" type="float" digits="2" step="1"></vxe-input>
        </FormItem>
        <FormItem label="限量：" prop="quota">
          <vxe-input class="w_input315" v-model="formBatch.quota" min="0" placeholder="请输入限量" type="integer"></vxe-input>
        </FormItem>
      </Form>
      <div slot="footer">
        <Button @click="cancelBatch">取消</Button>
        <Button type="primary" @click="okBatch">保存</Button>
      </div>
    </Modal>
    <Modal v-model="storeModals" title="门店列表" footerHide  scrollable width="900" @on-cancel="cancelStore">
      <store-list ref="storelist" @getStoreId="getStoreId" v-if="storeModals"></store-list>
    </Modal>
  </div>
</template>
<script>
import { mapState,mapMutations } from "vuex";
import { Debounce } from '@/utils';
import uploadPictures from "@/components/uploadPictures";
import goodsList from '@/components/goodsList';
import Setting from "@/setting";
import storeList from "@/components/storeList";
import { seckillInfoApi, seckillAddApi, seckillTimeListApi } from '@/api/marketing';
export default{
  name: "storeCreate",
  components: {
    uploadPictures,
    goodsList,
    storeList
  },
  data(){
    return{
      roterPre: Setting.roterPre,
      copy:0,
      keyword:'',
      storesList:[],
      storeModals: false,
      modalsSet: false,
      // checkUidList: [], //子级id集合（用于批量更改秒杀以及限量）
      checkPidList: [], //父级有关id集合 （需求禁止删除子级，用于删除整个商品）
      isAllChecked: false, //表头是否被选中
      modals: false,
      searchTableData:[],
      tableData: [],
      modalPic: false,
      currentTab: '1',
      headTab: [
        {
          name: "基础设置",
          type: '1',
        },
        {
          name: "添加商品",
          type: '2',
        },
        {
          name: "适用门店",
          type: '3',
        }
      ],
      timeList: [],
      id:0, //秒杀活动id；
      formValidate: {
        name:'',
        section_data: [],
        time_id: [],
        num: 1,
        once_num: 1,
        image: "",
        status: 1,
        seckill_ids:[],
        applicable_type:1
      },
      ruleValidate:{
        name: [
          { required: true, message: '请输入活动名称', trigger: 'blur' }
        ],
        section_data: [
          { required: true, type: 'array', message: '请选择活动日期', trigger: 'change' }
        ],
        time_id: [
          { required: true, type: 'array', message: '请选择秒杀场次', trigger: 'change' }
        ],
        num: [
          { required: true, type: 'number', message: '请输入购买数量限制', trigger: 'blur' }
        ],
        once_num: [
          { required: true, type: 'number', message: '请输入单次购买数量限制', trigger: 'blur' }
        ]
      },
      formBatch: {
        price:'',
        quota:'',
      },
      gridBtn: {
        xl: 4,
        lg: 8,
        md: 8,
        sm: 8,
        xs: 8,
      },
      gridPic: {
        xl: 6,
        lg: 8,
        md: 12,
        sm: 12,
        xs: 12,
      },
    }
  },
  computed: {
    ...mapState("admin/layout", ["isMobile","menuCollapse"]),
    labelWidth() {
      return this.isMobile ? undefined : 110;
    },
    labelPosition() {
      return this.isMobile ? "top" : "right";
    },
    labelBottom() {
      return this.isMobile ? undefined : 15;
    },
  },
  mounted(){
    this.setCopyrightShow({ value: false });
    this.seckillTimeList();
    if(this.$route.params.id){
      this.id = this.$route.params.id;
      this.copy = this.$route.params.copy;
      this.getInfo();
    }
  },
  destroyed () {
    this.setCopyrightShow({ value: true });
  },
  methods:{
    ...mapMutations('admin/layout', [
      'setCopyrightShow'
    ]),
    searchWord(){
      let list = []
      this.tableData.forEach(item=>{
        let obj = item.store_name.indexOf(this.keyword);
        if(obj !=-1){
          list.push(item)
        }
      })
      if(this.keyword){
        this.searchTableData = list;
      }else{
        this.searchTableData = []
      }
    },
    //秒杀详情
    getInfo(){
      seckillInfoApi(this.$route.params.id).then(res=>{
        res.data.info.seckill_ids = [];
        this.formValidate = res.data.info;
        this.storesList = res.data.info.stores || [];
        let list = res.data.info.productList || [];
        list.forEach(item=>{
          item.parent = 1
          item.isAllChecked = item.attrValue.some(value => {
            return value.status == 1
          });
        })
        this.tableData = list;
      }).catch(err=>{
        this.$Message.error(err.msg);
      })
    },
    //删除门店
    delte(row){
      this.storesList.forEach((item,index)=>{
        if(row.id == item.id){
          this.storesList.splice(index, 1)
        }
      })
    },
    //添加门店
    addStore(){
      this.storeModals = true;
    },
    //关闭门店弹窗
    cancelStore(){
      this.storeModals = false;
    },
    getStoreId (data) {
      this.storeModals = false;
      let list = this.storesList.concat(data);
      let uni = this.unique(list);
      this.storesList = uni;
    },
    batchVisibleChange(){
      this.formBatch.price = '';
      this.formBatch.quota = '';
    },
    onchangeIsShow(row){
      this.tableData.forEach(item=>{
        if(row.product_id == item.id){
          item.status = item.attrValue.some(value => {
            return value.status == 1
          });
        }
      })
    },
    okBatch(){
      if(this.formBatch.price=='' && this.formBatch.quota==''){
        return this.$Message.error("请输入秒杀价或限量");
      }
      if(this.isAllChecked && (this.tableData.length == this.searchTableData.length || !this.searchTableData.length)){
        this.tableData.forEach(item=>{
          item.attrValue.forEach(j=>{
            if(this.formBatch.price!=''){
              j.price = this.formBatch.price
            }
            if(this.formBatch.quota!=''){
              j.quota = this.formBatch.quota
            }
          })
        })
      }else{
        for(let i=0;i<this.tableData.length;i++){
          for(let j=0;j<this.checkPidList.length;j++){
            if(this.tableData[i].id == this.checkPidList[j]){
              this.tableData[i].attrValue.forEach(x=>{
                if(this.formBatch.price!=''){
                  x.price = this.formBatch.price
                }
                if(this.formBatch.quota!=''){
                  x.quota = this.formBatch.quota
                }
              })
            }
          }
        }
      }
      this.modalsSet = false;
    },
    cancelBatch(){
      this.modalsSet = false;
    },
    //批量设置
    batchSet(){
      this.modalsSet = true;
    },
    //批量删除
    delAll(){
      if(this.isAllChecked && (this.tableData.length == this.searchTableData.length || !this.searchTableData.length)){
        this.tableData = []
      }else {
        this.tableData = this.tableData.filter(item => !this.checkPidList.some(ele=>ele===item.id));
      }
      this.checkPidList = [];
      this.isAllChecked = false;
    },
    //删除
    del(row){
      if(this.searchTableData.length){
        this.searchTableData.forEach((i,index)=>{
          if(row.id == i.id){
            this.searchTableData.splice(index, 1)
          }
        })
        this.tableData.forEach((i,index)=>{
          if(row.id == i.id){
            return this.tableData.splice(index, 1)
          }
        })
      }else{
        this.tableData.forEach((i,index)=>{
          if(row.id == i.id){
            return this.tableData.splice(index, 1)
          }
        })
      }
      if(this.isAllChecked && !this.tableData.length){
        this.isAllChecked = false;
        this.checkPidList = []
      }else{
        let index = this.checkPidList.indexOf(row.id);
        this.checkPidList.splice(index, 1)
      }
    },
    checkboxAll(){
      this.isAllChecked = this.$refs.xTree.isAllCheckboxChecked();
      if(!this.isAllChecked){
        this.checkPidList = [];
      }
      console.log('fgfg',this.isAllChecked);
    },
    checkboxItem(e){
      let id = parseInt(e.rowid);
      if(e.row.product_id){
        // let index = this.checkUidList.indexOf(id);
        // if(index !== -1){
        //   this.checkUidList = this.checkUidList.filter((item)=> item !== id);
        // }else{
        //   this.checkUidList.push(id);
        // }
        let pIndex = this.checkPidList.indexOf(e.row.product_id);
        if(pIndex !== -1 && !e.checked){
          this.checkPidList = this.checkPidList.filter((item)=> item !== e.row.product_id);
        }
        if(pIndex === -1 && e.checked){
          this.checkPidList.push(e.row.product_id);
        }
      }else{
        let pIndex = this.checkPidList.indexOf(id);
        if(pIndex !== -1 && !e.checked){
          this.checkPidList = this.checkPidList.filter((item)=> item !== id);
        }
        if(pIndex === -1 && e.checked){
          this.checkPidList.push(id);
        }
      }
      this.isAllChecked = this.$refs.xTree.isAllCheckboxChecked();
    },
    //对象数组去重；
    unique(arr) {
      const res = new Map();
      return arr.filter((arr) => !res.has(arr.id) && res.set(arr.id, 1))
    },
    getProductId (data) {
      this.modals = false;
      let listChecked = JSON.parse(JSON.stringify(data));
      listChecked.forEach(item => {
        item.parent = 1;
        item.status = 1;
        item.attrValue.forEach(value => {
          value.cate_name = item.cate_name;
          value.store_label = item.store_label;
          value.status = 1;
        });
      })
      let list = this.tableData.concat(listChecked);
      let uni = this.unique(list);
      this.tableData = uni;
    },
    addGoods(){
      this.modals = true;
    },
    cancel () {
      this.modals = false;
    },
    modalPicTap() {
      this.modalPic = true;
    },
    getPic(pic) {
      this.modalPic = false;
      this.formValidate.image = pic.att_dir;
      this.$refs.formValidate.validateField('image');
    },
    delImage() {
      this.formValidate.image = "";
      this.$refs.formValidate.validateField('image');
    },
    seckillTimeList() {
      let that = this;
      seckillTimeListApi().then(res => {
        that.timeList = res.data
      }).catch(res => {
        that.$Message.error(res.msg);
      })
    },
    // 具体日期
    onchangeTime(e) {
      console.log(e)
      this.formValidate.section_data = e;
    },
    // 上一页：
    upTab() {
      this.currentTab = (Number(this.currentTab) - 1).toString();
    },
    // 下一页；
    downTab(name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
          if(!this.formValidate.section_data[0] !=''){
            return this.$Message.warning("请选择活动日期");
          }
          if(this.currentTab==2){
            if(!this.tableData.length){
              return this.$Message.warning("请添加商品");
            }
          }
          this.currentTab = (Number(this.currentTab) + 1).toString();
        }else{
          this.$Message.warning("请完善基础信息");
        }
      })
    },
    // 保存
	handleSubmit:Debounce(function(name){
		this.$refs[name].validate((valid) => {
		  if (valid) {
		    if (this.copy == 1){
		      this.formValidate.copy = 1
		    }else{
		      this.formValidate.copy = 0
		    }
		    if(!this.formValidate.section_data[0] !=''){
		      return this.$Message.warning("请选择活动日期");
		    }
		    if(!this.tableData.length){
		      this.$Message.warning("请添加商品");
		    }
				  let seckillIds = [];
		    this.tableData.forEach(item=>{
		      let hasAttrValue = item.attrValue.every(value => {
		        return value.status == 0
		      })
		      if (!hasAttrValue) {
		        seckillIds.push({
		          id:item.id,
		          attrValue:item.attrValue,
		          status:item.status,
				  sort:item.sort
		        });
		      }
		    })
		    this.formValidate.seckill_ids = seckillIds;
		    let storeId = []
		    this.storesList.forEach(item=>{
		      storeId.push(item.id)
		    })
		    if(this.formValidate.applicable_type==2 && !storeId.length){
		      return this.$Message.warning('请添加适用门店');
		    }
		    this.formValidate.applicable_store_id = storeId;
		    seckillAddApi(this.formValidate,this.copy==1?0:this.id).then(res=>{
		      this.$Message.success(res.msg);
		      setTimeout(() => {
		        this.$router.push({ path: this.roterPre + '/marketing/store_seckill/list' });
		      }, 500);
		    }).catch(err=>{
		      this.$Message.error(err.msg);
		    })
		  }else{
		    this.$Message.warning("请完善基础信息");
		  }
		})
	}),
    // 商品全部规格上架变化
    onchangeIsAllShow(row) {
    }
  }
}
</script>
<style scoped lang="stylus">
.w_input315{
  width: 315px;
}
.w_input240{
  width: 240px;
}
.goodsWord /deep/.ivu-form-item{
  margin-bottom 0!important
}
.imgPic{
  .info{
    width: 60%;
    margin-left: 10px;
  }
  .pictrue{
    height: 36px;
    margin: 7px 3px 0 3px;
    img{
      height: 100%;
      display: block;
    }
  }
}
.vxeTable{
  border-top:1px dotted #eee;
  margin-top 20px;
}
.w_input{
  width:460px;
}
.tips {
  display: inline-bolck;
  font-size: 12px;
  font-weight: 400;
  color: #999999;
  margin-top: 10px;
  line-height: initial;
}
.upload-select {
  width: 64px;
  height: 64px;
  font-size: 35px !important;
  background #f5f5f5;
  color #ccc;
}
.upload-list {
  display: inline-block;
  margin: 0 0 -10px 0;

  .upload-item {
    position: relative;
    display: inline-block;
    width: 64px;
    height: 64px;
    border: 1px dashed #DDDDDD;
    border-radius: 4px;
    margin: 0 15px 10px 0;
  }

  img {
    width: 64px;
    height: 64px;
    border-radius: 4px;
    vertical-align: middle;
  }

  .ivu-btn {
    position: absolute;
    top: 0;
    right: 0;
    width: 20px;
    height: 20px;
    margin: -10px -10px 0 0;
  }
}
.fixed-card {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 200px;
  z-index: 99;
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
</style>