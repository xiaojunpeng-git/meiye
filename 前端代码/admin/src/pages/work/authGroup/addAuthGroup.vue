<template>
<!-- 新建自动拉群 -->
  <div>
    <div class="i-layout-page-header">
      <PageHeader class="product_tabs" hidden-breadcrumb>
        <div slot="title">
          <div class="float-l">
            <router-link :to="{ path: `${roterPre}/work/auth_group` }">
              <div class="font-sm after-line">
                <span class="iconfont iconfanhui"></span>
                <span class="pl10">返回</span>
              </div>
            </router-link>
          </div>
          <span class="mr20 ml16">新建自动拉群</span>
        </div>
      </PageHeader>
    </div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Form :model="formItem" :label-width="110" :label-colon="true" :rules="ruleValidate">
        <FormItem label="二维码名称" prop="name">
          <Input
            v-model="formItem.name"
            placeholder="请输入二维码名称"
            class="input-add"
          ></Input>
        </FormItem>
        <FormItem label="选择群聊" required>
          <Button @click="addGroup()">添加群聊</Button>
          <div class="mt10">
            <Tag closable @on-close="handleDel" :name="item.name" size="medium" v-for="(item,index) in formItem.chat_id" :key="index">{{item.name}}</Tag>
          </div>
        </FormItem>
        <FormItem label="自动建群">
          <i-switch v-model="formItem.auth_group_chat" size="large" :true-value="1" :false-value="0">
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </i-switch>
          <div class="desc">
            开启时，选择群聊群人数达到上限后，将以原群主身份自动创建新群聊
          </div>
        </FormItem>
        <FormItem label="设置群名称" v-if="formItem.auth_group_chat">
          <Input
            v-model="formItem.group_name"
            placeholder="请输入群名称"
           class="input-add"
          />
        </FormItem>
        <FormItem label="群序号"  v-if="formItem.auth_group_chat">
          <InputNumber
            v-model="formItem.group_num"
            controls-outside
            :min="0"
            :max="999"
          ></InputNumber>
          <div class="desc">
            群序列号将在自动创建群时自动增加，比如设置的群名为：西安客户群，设置的序列表为：5，那么自动建群的名称为：西安客户群5，西安客户群6，西安客户群7，西安客户群8。
          </div>
        </FormItem>
        <FormItem label="客户标签">
            <div class="acea-row row-between-wrapper label-content" @click="openLabel">
                <div class="label-inner">
                    <div v-if="dataLabel.length">
                        <Tag closable v-for="item in dataLabel" :key="item.tag_id" @on-close="closeLabel(item)">{{ item.label_name }}</Tag>
                    </div>
                    <span v-else class="placeholder">请选择</span>
                </div>
                <div class="iconfont iconxiayi"></div>
            </div>
        </FormItem>
      </Form>
    </Card>
    <Card :bordered="false" dis-hover class="fixed-card" :style="{left: `${!menuCollapse?'236px':isMobile?'0':'60px'}`}">
      <div class="acea-row row-center-wrapper">
        <Button type="primary" @click="submit()" class="step_btn">保存</Button>
      </div>
    </Card>
    <Modal
      v-model="modalPic"
      width="960px"
      scrollable
      footer-hide
      closable
      title="logo"
      :mask-closable="false"
      :z-index="9"
    >
      <uploadPictures
        :isChoice="isChoice"
        @getPic="getPic"
        :gridBtn="gridBtn"
        :gridPic="gridPic"
        v-if="modalPic"
      ></uploadPictures>
    </Modal>
    <Modal
      v-model="modalRoutine"
      title="添加小程序消息"
      @on-ok="routineConfirm"
      :z-index="1"
    >
      <Form :model="formItem" :label-width="110" :label-colon="true">
        <FormItem label="小程序功能页">
          <div class="picBox" @click="modalPicTap('routine')">
            <div class="pictrue" v-if="rontineObj.miniprogram.pic_url">
              <img v-lazy="rontineObj.miniprogram.pic_url" />
            </div>
            <div class="upLoad" v-else>
              <div class="iconfont">+</div>
            </div>
          </div>
        </FormItem>
        <FormItem label="小程序消息标题">
          <Input v-model="rontineObj.miniprogram.title"></Input>
        </FormItem>
        <FormItem label="小程序Appid">
          <Input v-model="rontineObj.miniprogram.appid"></Input>
        </FormItem>
        <FormItem label="小程序功能页">
          <Input v-model="rontineObj.miniprogram.page"></Input>
        </FormItem>
      </Form>
    </Modal>
     <Modal
      v-model="groupStatus"
      title="客户群列表"
      @on-ok="groupConfirm"
      width="900"
      >
      <Table :columns="groupColumn" :data="groupData.list" 
      :loading="userLoading"
      @on-select-all="selectAll"
      @on-select-all-cancel="selectAll"
      @on-selection-change="handleSelectRow">
         <template slot-scope="{ row }" slot="avatar">
          <viewer class="acea-row row-center">
            <div class="tabBox_img">
              <img v-lazy="row.client.avatar" />
            </div>
          </viewer>
        </template>
        <template slot-scope="{ row }" slot="ownerInfo">
          <span>{{row.ownerInfo.name}}</span>
        </template>
        <template slot-scope="{ row }" slot="admin_user_list">
          <span v-for="(item,index) in row.admin_user_list" :key="index">{{item.name}}</span>
        </template>
        <template slot-scope="{ row }" slot="notice">
            <Tooltip max-width="200" placement="bottom">
              <span class="line2">{{ row.notice }}</span>
              <p slot="content">{{ row.notice }}</p>
            </Tooltip>
        </template>
        <template slot-scope="{ row }" slot="name">
          <span>{{row.client.name}}</span>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="groupData.count"
          :current="groupForm.page"
          show-elevator
          show-total
          @on-change="groupChange"
          :page-size="groupForm.limit"
        />
      </div>
    </Modal>
    <department
      ref="department"
      :active-department="activeDepartment"
      :is-site="isSite"
      :userList="userList"
      :only-department="onlyDepartment"
      @changeMastart="changeMastart"
    />
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
        <userLabel ref="userLabel" @activeData="activeData" @close="labelClose"></userLabel>
    </Modal>
  </div>
</template>
<script>
import { mapState } from "vuex";
import uploadPictures from "@/components/uploadPictures";
import department from "@/components/department/index.vue";
import userLabel from "@/components/labelList";
import {workGroupChat,workLabel,groupChatAuthSave,getGroupChatInfo,UpdateGroupChat} from "@/api/work"
import Setting from "@/setting";
export default {
  data() {
    return {
      roterPre: Setting.roterPre,
      formItem: {
        name: "", //二维码名称
        chat_id:[], //群聊chat_id
        group_name: "", //群名称
        group_num: 0, //群序列号
        //owner:[], //群主userid
        //admin_user:[], //群管理员userid
        label: [], //客户标签
        auth_group_chat: 0, //自动建群0=关闭，1=开启
      },
      labelList:[],
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
      groupColumn:[
        {
          type: "selection",
          width: 60,
          align: "center",
        },
        {
          title: "群名称",
          key: "name",
          minWidth: 80,
          align: 'center'
        },
        {
          title: "群主",
          slot: "ownerInfo",
          minWidth: 100,
          align: 'center'
        },
        {
          title: "群公告",
          slot: "notice",
          minWidth: 100,
          align: 'center'
        },
        {
          title: "管理员",
          slot: "admin_user_list",
          minWidth: 80,
          align: 'center'
        },
        {
          title: "创建时间",
          key: "group_create_time",
          minWidth: 110,
          align: 'center'
        },
        {
          title: "群人数",
          key: "member_num",
          minWidth: 80,
          align: 'center'
        },
        {
          title: "退群人数",
          key: "retreat_group_num",
          minWidth: 80,
          align: 'center'
        },
      ],
      groupData:[],
      groupForm:{
        page:1,
        limit:15
      },
      rontineObj: {
        msgtype: "miniprogram",
        miniprogram: {
          pic_url: "",
          pic_media_id: "",
          title: "",
          appid: "",
          page: "",
        },
      },
      imageObj: {
        msgtype: "image",
        image: {
          media_id: "",
          pic_url: "",
        },
      },
      groupStatus:false,
      userLoading:false,
      picTit: "",
      modalPic: false,
      modalRoutine: false,
      isChoice: "单选",
      activeDepartment: {},
      isSite: true,
      onlyDepartment: false,
      openType: "",
      userList: [],
      selectGroup:[],
      ruleValidate:{
        name: [
            { required: true, message: '二维码名称不能为空', trigger: 'blur' }
        ],
      },
      labelShow: false,
      dataLabel: []
    };
  },
  components: { uploadPictures, department, userLabel },
  computed: {
    ...mapState("admin/layout", ["isMobile","menuCollapse"]),
    labelWidth() {
      return this.isMobile ? undefined : 80;
    },
    labelPosition() {
      return this.isMobile ? "top" : "left";
    },
  },
  watch: {
    dataLabel(val) {
        this.formItem.label = val.map(item => item.tag_id);
    }
  },
  mounted() {
    if (this.$route.params.id !== "0" && this.$route.params.id) {
      this.getInfo();
    }
    this.getWorkGroupChat();
    // this.getWorkLabel();
  },
  methods: {
    getWorkGroupChat(){
      this.userLoading = true;
      workGroupChat(this.groupForm).then(res=>{
        this.groupData = res.data;
        this.userLoading = false;
      }).catch(err=>{
        this.$Message.error(err.msg)
        this.userLoading = false;
      })
    },
    addGroup(){
      this.groupStatus = true;
    },
    selectAll(row) {
      if (row.length) {
        this.selectGroup = row; 
      }
    },
    handleSelectRow(row){
      this.selectGroup = row; 
    },
    groupConfirm(){
      this.formItem.chat_id = this.selectGroup.map(item=>{
        return {
          chat_id:item.chat_id,
          name:item.name
        }
      })
    },
    groupChange(index){
      this.groupForm.page =index;
      this.getWorkGroupChat();
    },
    modalPicTap(picTit) {
      this.modalPic = true;
      this.picTit = picTit;
    },
    addRoutine() {
      this.rontineObj.miniprogram.pic_url = "";
      this.rontineObj.miniprogram.title = "";
      this.rontineObj.miniprogram.appid = "";
      this.rontineObj.miniprogram.page = "";
      this.modalRoutine = true;
    },
    addUser(type,index) {
        this.$refs.department.memberStatus = true;
        switch (type) {
          case 'one':
            // this.userList = this.formItem.userids;
            this.$refs.department.openType = 'one';
            break;
          case 'two':
            // this.userList = this.formItem.reserve_userid;
            this.$refs.department.openType = 'two';
            break;
          default:
              break;
        }
    },
    //确认人员
    changeMastart(arr,type){
        if(type == 'one'){
         if(arr.length && arr.length > 1){
           this.$Message.warning("群主只能选择一个");
           let newArr = arr.slice(0,1);
           this.formItem.owner = newArr.map(item=>{
              return {
                userid:item.userid,
                name:item.name
              }
            })
         }else{
            this.formItem.owner = arr.map(item=>{
              return {
                userid:item.userid,
                name:item.name
              }
            })
         }
        }else if(type == 'two'){
          this.formItem.admin_user = arr.map(item=>{
              return {
                userid:item.userid,
                name:item.name
              }
          })
        }
    },
    //tag标签删除成员
    handleDel(e, name) {
      let i = this.formItem.chat_id.findIndex((item) => item.name == name);
      this.formItem.chat_id.splice(i,1);
    },
    //欢迎语tag删除
    wordsDel(name){
      let index = this.formItem.welcome_words.attachments.indexOf(name);
      this.formItem.welcome_words.attachments.splice(index,1);
    },
    // 选中图片
    getPic(pc) {
      switch (this.picTit) {
        case "image":
          this.imageObj.image.pic_url = pc.att_dir;
          this.formItem.welcome_words.attachments.push(this.imageObj);
          break;
        case "routine":
          this.rontineObj.miniprogram.pic_url = pc.att_dir;
          break;
      }
      this.modalPic = false;
    },
    insertName() {
      this.formItem.welcome_words.text.content = "##客户名称##";
    },
    routineConfirm() {
      const routine = this.deepClone(this.rontineObj);
      this.formItem.welcome_words.attachments.push(routine);
    },
    routineCancel() {},
    //获取客户标签
    getWorkLabel() {
      workLabel().then((res) => {
        this.mapTree(res.data)
      });
    },
     mapTree(org) {
        for (let i = 0; i < org.length; i++) {
            for (let j = 0; j < this.formItem.label.length; j++) {
                if (this.formItem.label[j] === org[i].value) {
                    this.dataLabel.push({
                        label_name: org[i].label,
                        id: org[i].id,
                        tag_id: org[i].value
                    });
                }
            }
            Array.isArray(org[i].children) && this.mapTree(org[i].children);
        }
    },
    getInfo(){
      getGroupChatInfo(this.$route.params.id).then(res=>{
        this.formItem = res.data;
        this.formItem.chat_id = this.formItem.chatList;
        this.getWorkLabel();
      })
    },
    submit(){
      if(!this.formItem.chat_id.length) return this.$Message.error("请添加群聊")
      const formData = this.deepClone(this.formItem);
      formData.chat_id = formData.chat_id.map(item=>{
        return item.chat_id
      })
      if(this.$route.params.id){
        delete formData.chatList;
        delete formData.labelList;
        UpdateGroupChat(this.$route.params.id,formData).then(res=>{
          this.$Message.success("修改自动拉群成功");
          this.$router.push(this.roterPre + '/work/auth_group')
        })
      }else{
        groupChatAuthSave(formData).then(res=>{
          this.$Message.success("保存成功");
          this.$router.push(this.roterPre + '/work/auth_group')
        }).catch(err=>{
          this.$Message.error(err.msg)
        })
      }
    },
    //深克隆
    deepClone(obj) {
        let newObj = Array.isArray(obj) ? [] : {}
        if (obj && typeof obj === "object") {
            for (let key in obj) {
                if (obj.hasOwnProperty(key)) {
                    newObj[key] = (obj && typeof obj[key] === 'object') ? this.deepClone(obj[key]) : obj[key];
                }
            }
        } 
        return newObj
    },
    openLabel() {
        this.labelShow = true;
        this.$refs.userLabel.userLabel(JSON.parse(JSON.stringify(this.dataLabel)));
    },
    activeData(dataLabel){
        this.labelShow = false;
        this.dataLabel = dataLabel;
    },
    // 标签弹窗关闭
    labelClose() {
        this.labelShow = false;
    },
    closeLabel(label){
        let index = this.dataLabel.indexOf(this.dataLabel.filter(d=>d.id == label.id)[0]);
        this.dataLabel.splice(index,1);
    },
  },
};
</script>
<style scoped lang="stylus">
.input-add {
 width: 460px;
}
.desc {
  color: #999;
  font-size: 12px;
  line-height: 17px;
  padding-top: 6px;
}

.poptip_content {
  width: 138px;
  height: 94px;
  box-sizing: border-box;
  padding: 17px;
  display: flex;
  justify-content: space-between;
  cursor: pointer;
}

/deep/.ivu-input-number-input {
  text-align: center;
}

.add_img {
  width: 40px;
  height: 40px;
  background: rgba(24, 144, 255, 0.1);
  border-radius: 2px;
}

.add_routine {
  width: 40px;
  height: 40px;
  background: rgba(27, 190, 107, 0.1);
  border-radius: 2px;
}

.icontupian4 {
  color: #1890FF;
  font-size: 20px;
}

.iconxiaochengxu {
  color: #1BBE6B;
  font-size: 20px;
}

.tip_tit {
  display: block;
  font-size: 12px;
  text-align: center;
  padding-top: 6px;
}

.add_table {
  width: 500px;
  border-left: 1px dashed rgba(0, 0, 0, 0.15);
  border-right: 1px dashed rgba(0, 0, 0, 0.15);
}

.mt-18 {
  margin-top: 18px;
}
.tag_icon{
  font-size:14px;
  display:inline-block;
  margin-right:8px;
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
}
.picBox
    display: inline-block;
    cursor: pointer;
    .upLoad
        width: 58px;
        height: 58px;
        line-height: 58px;
        border: 1px dotted rgba(0, 0, 0, 0.1);
        border-radius: 4px;
        background: rgba(0, 0, 0, 0.02);
    .pictrue
        width: 60px;
        height: 60px;
        border: 1px dotted rgba(0, 0, 0, 0.1);
        margin-right: 10px;
        img
            width: 100%;
            height: 100%;
    .iconfont
        color: #CCCCCC;
        font-size 26px;
        text-align center
.label-content
    width 250px
    padding 4px 0 4px 7px
    border 1px solid #dcdee2
    border-radius 4px
    cursor pointer

    .label-inner
        flex 1

    .placeholder
        display block
        height 22px
        font-size 12px
        line-height 22px
        color #c5c8ce

    .iconfont
        width 24px
        height 22px
        text-align center
        font-size 10px
        line-height 22px
        color #808695

.label-content:hover
    border-color #57a3f3
</style>