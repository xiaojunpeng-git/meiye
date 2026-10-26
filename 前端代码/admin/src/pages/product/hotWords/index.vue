<template>
  <!-- 商品-保障服务 -->
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
      <div class="new_card_pd">
        <!-- 筛选条件 -->
        <Form
            ref="hotWordsForm"
            :model="hotWordsForm"
            :label-width="110"
            label-position="right"
            @submit.native.prevent
        >
          <Row :gutter="24" type="flex" justify="end">
            <Col span="24">
              <FormItem label="热词搜索：">
                <Input
                    v-model="hotWordsForm.name"
                    placeholder="请输入热词ID、热词"
                    class="input-add"
                ></Input>
                <Button class="ml-14" type="primary" @click="hotSearchs">查询</Button>
              </FormItem>
            </Col>
          </Row>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <!-- 相关操作 -->
      <Row type="flex">
        <Col v-bind="grid">
          <Button type="primary" @click="add">添加热词</Button>
        </Col>
      </Row>
      <!-- 保障服务表格 -->
      <Table
          :columns="columns1"
          :data="list"
          ref="table"
          class="mt25"
          :loading="loading"
          highlight-row
          no-userFrom-text="暂无数据"
          no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{row, index}" slot="name">
          <div class="words_tag" :style="{ backgroundColor: row.bg_color, color: row.color,border: row.border_color ? '1px solid ' + row.border_color : 'none'  }">
            <img v-if="row.icon" :src="row.icon" alt="" />
            <span>{{ row.name }}</span>
          </div>
        </template>
        <template slot-scope="{ row, index }" slot="is_show">
          <i-switch
              v-model="row.is_show"
              :true-value="1"
              :false-value="0"
              size="large"
              @on-change="showBnt(row)"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </i-switch>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <a @click="edit(row.id)">编辑</a>
          <Divider type="vertical" />
          <a @click="del(row, '删除热词', index)">删除</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
            :total="total"
            show-elevator
            show-total
            @on-change="pageChange"
            :page-size="hotWordsForm.limit"
        />
      </div>
    </Card>
    <Modal v-model="modals"
           @on-cancel="cancel"
           closable
           :title="form.id?'编辑热词':'添加热词'"
           :mask-closable="false"
           :z-index="10"
           width="560">
      <div>
        <Form size="small" ref="form" :rules="rules" :model="form" :label-width="100">
          <FormItem label="热词名称：" prop="name">
            <Input v-model="form.name" placeholder="请填写热词名称" class="w-420"></Input>
          </FormItem>
          <FormItem label="高级设置：">
            <i-switch v-model="form.is_search" :true-value="1" :false-value="0" size="large">
              <span slot="open">开启</span>
              <span slot="close">关闭</span>
            </i-switch>
          </FormItem>
          <FormItem label="字体颜色：" v-if="form.is_search">
            <ColorPicker v-model="form.color" alpha ></ColorPicker>
            <p class="desc">若未设置颜色，则为默认色</p>
          </FormItem>
          <FormItem label="背景颜色：" v-if="form.is_search">
            <ColorPicker v-model="form.bg_color" alpha ></ColorPicker>
            <p class="desc">若未设置颜色，则为默认色</p>
          </FormItem>
          <FormItem label="边框颜色：" v-if="form.is_search">
            <ColorPicker v-model="form.border_color" alpha ></ColorPicker>
            <p class="desc">若未设置颜色，则无边框</p>
          </FormItem>
          <FormItem label="上传图标：" v-if="form.is_search">
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
            <p class="desc">建议尺寸：32px*32px，若未上传则只展示文字</p>
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
            <i-switch v-model="form.is_show" :true-value="1" :false-value="0" size="large">
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
import { mapState } from "vuex";
import {
  hotWordsAddApi,
  hotWordsListApi,
  wordsSetShow,
  hotWordsInfoApi
} from "@/api/product";
import uploadPictures from "@/components/uploadPictures";
export default {
  name: "hotWord",
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
          title: "ID",
          key: "id",
          width: 80,
        },
        {
          title: "热词",
          slot: "name",
          minWidth: 100,
        },
        {
          title: "是否显示",
          slot: "is_show",
          minWidth: 100,
        },
        {
          title: "排序",
          key: "sort",
          minWidth: 100,
        },
        {
          title: "创建时间",
          key: "add_time",
          minWidth: 130,
        },
        {
          title: "操作",
          slot: "action",
          fixed: "right",
          minWidth: 120,
        },
      ],
      hotWordsForm: {
        page: 1,
        limit: 15,
        name: "",
      },
      list: [],
      total: 0,
      modals:false,
      form: {
        name: '',
        color: '#666',
        bg_color: '#f5f5f5',
        border_color:'transparent',
        sort: 0,
        is_show: 1,
        icon: '',
        is_search: 0,
      },
      checkSelect: [],
      modalPic: false,
      rules: {
        name: [
          { required: true, message: '请输入热词名称', trigger: 'blur' },
          { min: 2, max: 6, message: '长度在 2 到 6 个字符', trigger: 'blur' },
        ]
      },
      word_id:0
    };
  },
  components:{
    uploadPictures
  },
  computed: {
    ...mapState("admin/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 96;
    },
    labelPosition() {
      return this.isMobile ? "top" : "right";
    },
  },
  created() {
    this.getList();
  },
  methods: {
    hotSearchs() {
      this.hotWordsForm.page = 1;
      this.list = [];
      this.getList();
    },
    showBnt(row) {
      let data = {
        id: row.id,
        is_show: row.is_show,
      };
      wordsSetShow(data).then((res) => {
        this.$Message.success(res.msg);
      })
      .catch((err) => {
        this.$Message.error(err.msg);
      });
    },
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
          hotWordsAddApi(this.word_id,this.form).then(res=>{
            this.$Message.success(res.msg);
            this.modals = false;
            this.cancel();
            this.hotWordsForm.page = 1;
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
        name: '',
        color: '#666',
        bg_color: '#f5f5f5',
		border_color:'transparent',
        sort: 0,
        is_show: 1,
        icon: '',
        is_search: 0,
      };
	  this.word_id = 0;
      this.modals = false;
    },
    // 单位列表
    getList() {
      this.loading = true;
      hotWordsListApi(this.hotWordsForm)
          .then((res) => {
            let data = res.data;
            this.list = data.list;
            this.total = data.count;
            this.loading = false;
          })
          .catch((err) => {
            this.loading = false;
            this.$Message.error(err.msg);
          });
    },
    pageChange(index) {
      this.hotWordsForm.page = index;
      this.getList();
    },
    //修改
    edit(id) {
      this.word_id = id;
      hotWordsInfoApi(id).then(res=>{
        this.form = res.data;
        this.modals = true;
      })
    },
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `product/words/${row.id}`,
        method: "DELETE",
        ids: "",
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.hotWordsForm.page == 1;
          this.getList();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
  },
};
</script>

<style scoped lang="stylus">
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
  border-radius: 16px;
  box-sizing: border-box;
  white-space: nowrap;
  height: 28px;
  line-height: 28px;
  img {
    width: 12px;
    height: 12px;
    vertical-align: middle;
    display: inline-block;
    margin-right: 4px;
  }
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