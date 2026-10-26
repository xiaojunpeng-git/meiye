<template>
  <!-- 商品-商品标签 -->
  <div>
    <Row class="ivu-mt box-wrapper">
      <Col span="3" class="left-wrapper">
        <!-- 竖向导航菜单 -->
        <Menu width="auto">
          <MenuGroup>
            <MenuItem
                :name="item.id"
                class="menu-item"
                :class="index === current ? 'showOn' : ''"
                v-for="(item, index) in labelSort"
                :key="index"
                @click.native="bindMenuItem(item, index)"
            >
              {{ item.name }}
              <div class="icon-box" v-if="index != 0">
                <Icon type="ios-more" size="24" @click.stop="showMenu(item)"/>
              </div>
              <div
                  class="right-menu ivu-poptip-inner"
                  v-if="index != 0 && item.status"
              >
                <div class="ivu-poptip-body" @click="labelEdit(item)">
                  <div class="ivu-poptip-body-content">
                    <div class="ivu-poptip-body-content-inner">编辑</div>
                  </div>
                </div>
                <div
                    class="ivu-poptip-body"
                    @click="deleteSort(item, '删除分类', index)"
                >
                  <div class="ivu-poptip-body-content">
                    <div class="ivu-poptip-body-content-inner">删除</div>
                  </div>
                </div>
              </div>
            </MenuItem>
          </MenuGroup>
        </Menu>
      </Col>
      <Col span="21" ref="rightBox">
        <Card :bordered="false" dis-hover>
          <!-- 相关操作 -->
         <div class="flex">
           <Button type="primary" @click="add">添加标签</Button>
           <Button type="success" @click="addSort" class="ml-14">添加分组</Button>
           <Button class="ml-14" :disabled="!ids.length" @click="labelDel">批量删除</Button>
         </div>
          <!-- 用户标签表格 -->
          <Table
              :columns="columns1"
              :data="labelLists"
              ref="table"
              class="mt25"
              :loading="loading"
              highlight-row
              no-data-text="暂无数据"
              no-filtered-data-text="暂无筛选结果"
              @on-selection-change="onSelectTab"
          >
            <template slot-scope="{row, index}" slot="label_name">
              <div v-if="row.style_type == 1" class="words_tag"
                   :style="{ backgroundColor: row.bg_color, color: row.color,border: row.border_color ? '1px solid ' + row.border_color : 'none'  }">
                <span>{{ row.label_name }}</span>
              </div>
              <img :src="row.icon" class="tag_img" v-else>
            </template>
            <template slot-scope="{ row, index }" slot="is_show">
              <i-switch
                  v-model="row.is_show"
                  :true-value="1"
                  :false-value="0"
                  size="large"
                  @on-change="showChange(row)"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
            </template>
            <template slot-scope="{ row, index }" slot="status">
              <i-switch
                  v-model="row.status"
                  :true-value="1"
                  :false-value="0"
                  size="large"
                  @on-change="statusChange(row)"
              >
                <span slot="open">开启</span>
                <span slot="close">关闭</span>
              </i-switch>
            </template>
            <template slot-scope="{ row, index }" slot="action">
              <a @click="edit(row)">修改</a>
              <Divider type="vertical"/>
              <a @click="delLabel(row, '删除分组', index)">删除</a>
            </template>
          </Table>
          <div class="acea-row row-right page">
            <Page
                :total="total"
                show-elevator
                show-total
                @on-change="pageChange"
                :page-size="labelFrom.limit"
            />
          </div>
        </Card>
      </Col>
    </Row>
    <Modal
	   v-model="modals"
	   @on-cancel='cancel'
       :closable="true"
       :title="form.id ? '编辑标签' : '添加标签'"
       :mask-closable="false"
       :z-index="10"
       width="560">
      <div>
        <Form size="small" ref="form" :rules="rules" :model="form" :label-width="100">
          <FormItem label="标签名称：" prop="label_name">
            <Input v-model="form.label_name" placeholder="请填写标签名称" class="w-420"></Input>
          </FormItem>
          <FormItem label="分组选择：" prop="label_cate">
            <Select
                v-model="form.label_cate"
                clearable
                class="w-420"
            >
              <Option
                  v-for="item in labelSort.slice(1)"
                  :value="item.id"
                  :key="item.id"
              >{{ item.name }}</Option
              >
            </Select>
          </FormItem>
          <FormItem label="移动端开展示：">
            <i-switch v-model="form.is_show" :true-value="1" :false-value="0" size="large">
              <span slot="open">开启</span>
              <span slot="close">关闭</span>
            </i-switch>
          </FormItem>
          <FormItem label="效果设置：">
            <RadioGroup
                v-model="form.style_type"
                :true-value="1"
                :false-value="2"
            >
              <Radio :label="1">自定义</Radio>
              <Radio :label="2">图片</Radio>
            </RadioGroup>
          </FormItem>
          <FormItem label="字体颜色：" v-if="form.style_type == 1">
            <ColorPicker v-model="form.color" alpha ></ColorPicker>
            <p class="desc">若未设置颜色，则为默认色</p>
          </FormItem>
          <FormItem label="背景颜色：" v-if="form.style_type == 1">
            <ColorPicker v-model="form.bg_color" alpha ></ColorPicker>
            <p class="desc">若未设置颜色，则为默认色</p>
          </FormItem>
          <FormItem label="边框颜色：" v-if="form.style_type == 1">
            <ColorPicker v-model="form.border_color" alpha ></ColorPicker>
            <p class="desc">若未设置颜色，则无边框</p>
          </FormItem>
          <FormItem label="上传图标：" v-if="form.style_type == 2">
            <div v-if="form.icon" class="upload-list">
              <div class="upload-item">
                <img :src="form.icon" />
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
            <p class="desc">建议尺寸：80px*30px，若未上传则为空白</p>
          </FormItem>
          <FormItem label="排序：">
            <InputNumber
                v-model="form.sort"
                :min="0"
                :max="999"
                class="selWidth"
            ></InputNumber>
          </FormItem>
          <FormItem label="是否开启：">
            <i-switch v-model="form.status" :true-value="1" :false-value="0" size="large">
              <span slot="open">开启</span>
              <span slot="close">关闭</span>
            </i-switch>
          </FormItem>
        </Form>
      </div>
	  <div slot="footer">
	    <Button @click="cancel">取消</Button>
	    <Button type="primary" @click="addWordsConfirm">保存</Button>
	  </div>
    </Modal>
    <Modal
        v-model="modalPic"
        width="960px"
        scrollable
        footer-hide
        closable
        title="上传图标"
        :mask-closable="false"
        :z-index="500"
    >
      <uploadPictures
          isChoice="单选"
          @getPic="getPic"
          v-if="modalPic"
      ></uploadPictures>
    </Modal>
  </div>
</template>

<script>
import {mapState} from "vuex";
import {
  productLabelCate,
  productLabelListApi,
  productLabelEdit,
  labelCateCreate,
  productLabel,
  productLabelShowApi,
  productLabelStatusApi,
  productLabelDeleteApi
} from "@/api/product"
import uploadPictures from "@/components/uploadPictures";
export default {
  name: "product_label",
  data() {
    return {
      grid: {
        xl: 7,
        lg: 7,
        md: 12,
        sm: 24,
        xs: 24,
      },
      loading: false,
      columns1: [
        {
          type: 'selection',
          width: 100,
          align: 'center'
        },
        {
          title: "标签",
          slot: "label_name",
          width: 250,
        },
        {
          title: '是否开启',
          slot: 'status',
          minWidth: 140,
          align: 'center'
        },
        {
          title: '移动端展示',
          slot: 'is_show',
          minWidth: 140,
          align: 'center'
        },
        {
          title: '排序',
          key: 'sort',
          minWidth: 100,
          align: 'center'
        },
        {
          title: '创建时间',
          key: 'add_time',
          minWidth: 150,
          align: 'center'
        },
        {
          title: "操作",
          slot: "action",
          fixed: "right",
          minWidth: 120,
        },
      ],
      labelFrom: {
        page: 1,
        limit: 15,
        label_cate: "",
      },
      labelLists: [],
      total: 0,
      theme3: "light",
      labelSort: [],
      sortName: "",
      current: 0,

      modals:false,
      form: {
        id: 0,
        label_cate: '',
        label_name: '',
        style_type: 1, //样式类型 1自定义 2图片
        color: '#e93323',
        bg_color: '#fff',
        border_color:'#e93323',
        sort: 0,
        is_show: 1,
        icon: '',
        status: 1,
      },
      checkSelect: [],
      modalPic: false,
      rules: {
        label_name: [
          { required: true, message: '请输入热词名称', trigger: 'blur' },
          { min: 2, max: 6, message: '长度在 2 到 6 个字符', trigger: 'blur' },
        ],
        label_cate:[
          { required: true, message: '请选择分组'}
        ]
      },
      ids:''
    };
  },
  components:{
    uploadPictures
  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 75;
    },
    labelPosition() {
      return this.isMobile ? "top" : "right";
    },
  },
  created() {
    this.getproductLabelAll();
    this.getList();
  },
  methods: {
    // 添加
    add() {
      this.modals = true;
    },
    modalPicTap() {
      this.modalPic = true;
    },
    getPic(pic) {
      this.modalPic = false;
      this.form.icon = pic.att_dir;
    },
    delImage(){
      this.form.icon = '';
    },
    addWordsConfirm(){
      this.$refs.form.validate((valid) => {
        if (valid){
          productLabel(this.form).then(res=>{
            this.$Message.success(res.msg);
            this.modals = false;
            this.cancel();
            this.labelFrom.page = 1;
            this.getList();
          }).catch((res) => {
            this.$Message.error(res.msg);
          });
        }
      })
    },
    cancel(){
	  this.$refs['form'].resetFields();
      this.form = {
        id: 0,
        label_cate: '',
        label_name: '',
        style_type: 1, //样式类型 1自定义 2图片
        color: '#e93323',
        bg_color: '#ffffff',
        border_color:'#e93323',
        sort: 0,
        is_show: 1,
        icon: '',
        status: 1,
      };
	  this.modals = false;
    },
    // 分组列表
    getList() {
      this.loading = true;
      productLabelListApi(this.labelFrom)
          .then(async (res) => {
            let data = res.data;
            this.labelLists = data.list;
            this.total = data.count;
            this.loading = false;
          })
          .catch((res) => {
            this.loading = false;
            this.$Message.error(res.msg);
          });
    },
    pageChange(index) {
      this.labelFrom.page = index;
      this.getList();
    },
    // 全选
    onSelectTab(selection) {
      if(selection.length){
        let data = [];
        selection.forEach((item) => {
          data.push(item.id);
        });
        this.ids = data.join(",");
      }else{
        this.ids = '';
      }
    },
    labelDel(){
      let data = {
        ids: this.ids,
        all: 0,
        where: this.labelFrom.label_cate
      };
      this.$Modal.confirm({
        title: '提示',
        content: '<p>确定要删除吗？</p><p>删除后将无法恢复，请谨慎操作！</p>',
        onOk: () => {
          productLabelDeleteApi(data).then(res=>{
            this.$Message.success(res.msg);
            this.labelFrom.page = 1;
            this.getList()
          }).catch((res) => {
            this.$Message.error(res.msg);
          });
        }
      });
    },
    showChange(row){
      productLabelShowApi({id:row.id,is_show: row.is_show}).then(res=>{
        this.$Message.success(res.msg);
      }).catch((res) => {
        this.$Message.error(res.msg);
      });
    },
    statusChange(row){
      productLabelStatusApi({id:row.id,status:row.status}).then(res=>{
        this.$Message.success(res.msg);
      }).catch((res) => {
        this.$Message.error(res.msg);
      });
    },
    // 修改
    edit(row) {
      let formObj = {
        id: row.id,
        label_cate: row.label_cate,
        label_name: row.label_name,
        style_type: row.style_type, //样式类型 1自定义 2图片
        color: row.color,
        bg_color: row.bg_color,
        border_color: row.border_color,
        sort: row.sort,
        is_show: row.is_show,
        icon: row.icon,
        status: row.status,
      };
      this.form = formObj;
      this.modals = true;
    },
    // 删除
    delLabel(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `product/label/${row.id}`,
        method: "DELETE",
        ids: "",
      };
      this.$modalSure(delfromData).then((res) => {
        this.$Message.success(res.msg);
        this.labelLists.splice(num, 1);
        this.getList();
      })
      .catch((res) => {
        this.$Message.error(res.msg);
      });
    },
    // 标签分类
    getproductLabelAll(key) {
      productLabelCate().then((res) => {
        let obj = {
          name: "全部",
          id: "",
        };
        res.data.list.unshift(obj);
        res.data.list.map((el) => {
          this.$set(el, 'status', false);
        });
        this.labelSort = res.data.list;
      });
    },
    // 显示标签小菜单
    showMenu(item) {
      this.labelSort.forEach((el) => {
        if (el.id == item.id) {
          el.status = item.status ? false : true;
        } else {
          el.status = false;
        }
      });
    },
    //编辑标签
    labelEdit(item) {
      this.$modalForm(productLabelEdit(item.id)).then(() => this.getproductLabelAll());
    },
    // 添加分类
    addSort() {
      this.$modalForm(labelCateCreate()).then(() => this.getproductLabelAll());
    },
    deleteSort(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `product/label_cate/${row.id}`,
        method: "DELETE",
        ids: "",
      };
      this.$modalSure(delfromData)
          .then((res) => {
            this.$Message.success(res.msg);
            this.labelSort.splice(num, 1);
            this.labelSort = [];
            this.getproductLabelAll();
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
    },
    bindMenuItem(name, index) {
      this.current = index;
      this.labelSort.forEach((el) => {
        el.status = false;
      });
      this.labelFrom.label_cate = name.id;
      this.getList();
    },
    synLabel() {

    }
  },
};
</script>

<style lang="stylus" scoped>
.ivu-menu-light.ivu-menu-vertical .ivu-menu-item-active:not(.ivu-menu-submenu) {
  background: #eff6fe !important;
  z-index:1 !important;
}
/deep/.ivu-menu{
  position unset!important
}
/deep/ .ivu-menu-vertical .ivu-menu-item-group-title {
  display: none;
}

/deep/ .ivu-menu-vertical.ivu-menu-light:after {
  display: none;
}

.left-wrapper {
  // height: 904px;
  background: #fff;
  border-right: 1px solid #dcdee2;
}

/deep/ .ivu-menu {
  z-index: 0 !important;
}

/deep/ .ivu-table-wrapper {
  min-height: 535px;
}
.menu-item {
  position: relative;
  display: flex;
  justify-content: space-between;

  .icon-box {
    z-index: 3;
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    display: none;
  }

  &:hover .icon-box {
    display: block;
  }

  .right-menu {
    z-index: 10;
    position: absolute;
    right: -106px;
    top: -11px;
    width: auto;
    min-width: 121px;
  }
}
.input-add {
  width: 250px;
  margin-right:14px;
}
.line2{
  max-height 36px
}
.tabBox_img {
  width: 36px;
  height: 36px;

  img {
    width: 100%;
    height: 100%;
  }
}
.w-420{
  width: 420px;
}
.words_tag {
  background-color: #f4f4f4;
  display: inline-block;
  padding: 0 10px;
  font-size: 12px;
  color: #4f4f4f;
  border-radius: 4px;
  box-sizing: border-box;
  white-space: nowrap;
  height: 28px;
  line-height: 28px;
}
.tag_img{
  display: block;
  height: 28px;
  object-fit: cover;
  border-radius: 4px;
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
</style>