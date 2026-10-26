<template>
  <div>
    <div class="i-layout-page-header">
      <PageHeader class="product_tabs" hidden-breadcrumb>
        <div slot="title">
          <a @click="$router.go(-1)">
            <div class="font-sm after-line">
              <span class="iconfont iconfanhui"></span>
              <span class="pl10">返回</span>
            </div>
          </a>
          <span
            v-text="$route.query.id ? '编辑渠道码' : '添加渠道码'"
            class="ml16"
          ></span>
        </div>
      </PageHeader>
    </div>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Form :model="formData" :label-width="150" :rules="ruleValidate">
        <FormItem label="二维码名称：" required>
          <Input v-model="formData.name" placeholder="请输入二维码名称"></Input>
        </FormItem>
        <FormItem label="二维码分组：" required>
          <Select v-model="formData.cate_id" style="width: 320px">
            <Option
              :value="item.id"
              v-for="(item, index) in labelSort"
              :key="index"
              >{{ item.cate_name }}</Option
            >
          </Select>
        </FormItem>
        <FormItem label="用户标签：" required>
          <div style="display: flex">
            <div
              class="labelInput acea-row row-between-wrapper"
              @click="openLabel"
            >
              <div style="width: 90%">
                <div v-if="dataLabel.length">
                  <Tag
                    closable
                    v-for="(item, index) in dataLabel"
                    @on-close="closeLabel(item)"
                    :key="index"
                    >{{ item.label_name }}</Tag
                  >
                </div>
                <span class="span" v-else>选择用户关联标签</span>
              </div>
              <div class="ivu-icon ivu-icon-ios-arrow-down"></div>
            </div>
            <span class="addfont" @click="addLabel">新增标签</span>
          </div>
        </FormItem>
        <FormItem label="关联推广员：" required>
          <div class="picBox" @click="customer">
            <div class="pictrue" v-if="formData.avatar">
              <img v-lazy="formData.avatar" />
            </div>
            <div class="upLoad acea-row row-center-wrapper" v-else>
              <Icon type="ios-camera-outline" size="26" />
            </div>
          </div>
          <div class="trip">
            扫码注册的新用户,将自动成为此推广员的下级,与分销推广功能一致
          </div>
        </FormItem>
        <FormItem label="有效期：">
          <RadioGroup v-model="isReceiveTime">
            <Radio :label="0">永久</Radio>
            <Radio :label="1">有效期</Radio>
          </RadioGroup>
          <span v-show="isReceiveTime">
            <InputNumber
              :min="1"
              :max="10000"
              :precision="0"
              v-model="formData.time"
              placeholder="请输入天数"
              style="width: 100px"
            ></InputNumber>
            天
          </span>
          <div class="trip">
            临时码过期后不能再扫码,永久二维码最大创建数量为10万个
          </div>
        </FormItem>
        <FormItem label="回复内容：" required>
          <Row>
            <Col span="17" style="border: 1px solid #f2f2f2">
              <Row>
                <Col span="6">
                  <Menu
                    theme="light"
                    :active-name="formData.type"
                    width="auto"
                    @on-select="selectMenu"
                  >
                    <MenuItem name="text">文字内容</MenuItem>
                    <MenuItem name="voice">声音消息</MenuItem>
                    <MenuItem name="image">图片消息</MenuItem>
                    <MenuItem name="news">图文消息</MenuItem>
                  </Menu>
                </Col>
                <Col span="18" style="padding: 10px">
                  <FormItem
                    label="消息内容："
                    prop="content"
                    v-if="formData.type === 'text' || formData.type === 'url'"
                  >
                    <textarea
                      v-model="formData.content.content"
                      :placeholder="
                        formData.type === 'text'
                          ? '请填写消息内容'
                          : '请填写网址链接'
                      "
                    ></textarea>
                  </FormItem>
                  <FormItem label="选取图文：" v-if="formData.type === 'news'">
                    <Button type="info" @click="modals = true"
                      >选择图文消息</Button
                    >
                    <div class="news-box" v-if="formData.content.list.title">
                      <img
                        class="news_pic"
                        :src="formData.content.list.image_input[0]"
                      />
                      <span>{{ formData.content.list.title }}</span>
                    </div>
                  </FormItem>
                  <FormItem
                    :label="
                      formData.type === 'image' ? '选择图片：' : '语音地址：'
                    "
                    prop="src"
                    v-if="
                      formData.type === 'image' || formData.type === 'voice'
                    "
                  >
                    <div
                      v-show="formData.content.src && formData.type === 'image'"
                      class="pictrue"
                      draggable="true"
                    >
                      <img
                        v-lazy="formData.content.src"
                        width="58"
                        height="58"
                      />
                      <Button
                        shape="circle"
                        icon="md-close"
                        @click.native="handleRemove"
                        class="btndel"
                      ></Button>
                    </div>
                    <div
                      v-show="
                        !formData.content.src && formData.type === 'image'
                      "
                      class="upLoad acea-row"
                      @click="modalPicTap('dan')"
                    >
                      <Icon type="ios-camera-outline" size="26" />
                    </div>
                    <div v-show="formData.type === 'voice'" class="acea-row">
                      <Input
                        readonly="readonly"
                        placeholder="请填入链接地址"
                        style="width: 75%"
                        class="mr15"
                        v-model="formData.content.src"
                      />
                      <Upload
                        :show-upload-list="false"
                        :action="fileUrl"
                        :on-success="handleSuccess"
                        :format="formatVoice"
                        :max-size="2048"
                        :headers="header"
                        :on-format-error="handleFormatError"
                        :on-exceeded-size="handleMaxSize"
                        class="mr20"
                        style="margin-top: 1px"
                      >
                        <Button type="primary">上传</Button>
                      </Upload>
                    </div>
                    <span v-show="formData.type === 'voice'"
                      >文件最大2Mb，支持mp3/wma/wav/amr格式,播放长度不超过60s</span
                    >
                  </FormItem>
                </Col>
              </Row>
            </Col>
          </Row>
        </FormItem>
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
          <Button type="primary" @click="save" :disabled="disabled"
            >立即提交</Button
          >
        </FormItem>
      </Form>
    </Card>
    <Modal
      v-model="customerShow"
      scrollable
      title="请选择商城用户"
      width="820"
    >
      <customerInfo
        v-if="customerShow"
        :isPromoter="1"
        @imageObject="imageObject"
      ></customerInfo>
    </Modal>
    <!--图文消息 -->
    <Modal
      v-model="modals"
      scrollable
      title="发送消息"
      width="1200"
      height="800"
      footer-hide
      class="modelBox"
    >
      <news-category
        v-if="modals"
        @getCentList="getCentList"
        :scrollerHeight="scrollerHeight"
        :contentTop="contentTop"
        :contentWidth="contentWidth"
        :maxCols="maxCols"
      ></news-category>
    </Modal>
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
        :gridBtn="gridBtn"
        :gridPic="gridPic"
        v-if="modalPic"
      ></uploadPictures>
    </Modal>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import customerInfo from '@/components/customerInfo';
import userLabel from '@/components/labelList';
import goodsList from '@/components/goodsList/index';
import newsCategory from '@/components/newsCategory/index';
import uploadPictures from '@/components/uploadPictures';
import { labelListApi } from '@/api/product';
import { userLabelAddApi } from '@/api/user';
import {
  wechatQrcodeCateList,
  wechatQrcodeInfo,
  wechatQrcodeSave,
} from '@/api/marketing';
import Setting from '@/setting';
import util from '@/libs/util';

export default {
  name: 'marketing_channelCreate',
  components: {
    goodsList,
    newsCategory,
    customerInfo,
    userLabel,
    uploadPictures,
  },
  data() {
    return {
      roterPre: Setting.roterPre,
      customerShow: false,
      labelShow: false,
      disabled: false,
      modals: false,
      maxCols: 4,
      labelSelect: [],
      scrollerHeight: '600',
      contentTop: '130',
      contentWidth: '98%',
      formatImg: ['jpg', 'jpeg', 'png', 'bmp', 'gif'],
      formatVoice: ['mp3', 'wma', 'wav', 'amr'],
      header: {},
      fileUrl: Setting.apiBaseURL + '/file/upload/1?type=1',
      formData: {
        name: '',
        type: 'text',
        time: 0,
        label_id: [],
        image: '',
        cate_id: '',
        content: {
          content: '',
          src: '',
          list: {},
        },
      },
      labelSort: [],
      isReceiveTime: 0,
      modals: false,
      ruleValidate: {
        name: [
          {
            required: true,
            message: '请填写二维码名称',
            trigger: 'blur',
          },
        ],
        cate_id: [
          {
            required: true,
            message: '请选择二维码分组',
            trigger: 'change',
          },
        ],
      },
      id: 0,
      dataLabel: [],
      loading: false,
      modalPic: false,
      isChoice: '',
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
    };
  },
  computed: mapState('admin/layout', ['isMobile', 'menuCollapse']),
  created() {
    this.getUserLabelAll();
    this.userLabel();
    this.getToken();
    if (this.$route.query.id) {
      this.id = this.$route.query.id;
      this.getDetail();
    }
    this.formData.cate_id = this.$route.query.cateId;
  },
  methods: {
    activeData(dataLabel) {
      this.labelShow = false;
      this.dataLabel = dataLabel;
    },
    // 标签弹窗关闭
    labelClose() {
      this.labelShow = false;
    },
    openLabel(row) {
      this.labelShow = true;
      this.$refs.userLabel.userLabel(
        JSON.parse(JSON.stringify(this.dataLabel))
      );
    },
    closeLabel(label) {
      let index = this.dataLabel.indexOf(
        this.dataLabel.filter((d) => d.id == label.id)[0]
      );
      this.dataLabel.splice(index, 1);
    },
    getDetail() {
      wechatQrcodeInfo(this.id).then((res) => {
        this.formData = res.data;
        if (res.data.label_id.length) {
          this.dataLabel = res.data.label_id;
        }
        if (res.data.time) {
          this.isReceiveTime = 1;
        }
      });
    },
    customer() {
      this.customerShow = true;
    },
    addLabel() {
      this.$modalForm(userLabelAddApi(0)).then(() => this.userLabel());
    },
    // 用户标签
    userLabel() {
      labelListApi()
        .then((res) => {
          this.labelSelect = res.data.list;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    imageObject(e) {
      this.customerShow = false;
      this.formData.uid = e.uid;
      this.formData.avatar = e.image;
    },
    getCentList(val) {
      this.formData.content.list = val.new[0];
      this.modals = false;
    },
    // 上传成功
    handleSuccess(res, file) {
      if (res.status === 200) {
        this.formData.content.src = res.data.src;
        this.$Message.success(res.msg);
      } else {
        this.$Message.error(res.msg);
      }
    },
    handleFormatError(file) {
      if (this.formData.type === 'image') {
        this.$Message.warning('请上传bmp/png/jpeg/jpg/gif格式的图片');
      } else {
        this.$Message.warning('请上传mp3/wma/wav/amr格式的语音');
      }
    },
    handleMaxSize(file) {
      this.$Message.warning('请上传文件2M以内的文件');
    },
    // 上传头部token
    getToken() {
      this.header['Authori-zation'] = 'Bearer ' + util.cookies.get('token');
    },
    selectMenu(name) {
      this.formData.type = name;
      this.formData.content.list = [];
      this.formData.content.content = '';
      this.formData.content.src = '';
    },
    // 获取分类
    getUserLabelAll() {
      wechatQrcodeCateList().then((res) => {
        let data = res.data.data;
        this.labelSort = data;
      });
    },
    // 创建
    save() {
      if (!this.formData.name) {
        return this.$Message.error('请输入二维码名称');
      }
      if (!this.formData.cate_id) {
        return this.$Message.error('请选择分组');
      }
      if (!this.dataLabel.length) {
        return this.$Message.error('请选择用户标签');
      } else {
        let ids = [];
        this.dataLabel.map((i) => {
          ids.push(i.id);
        });
        this.formData.label_id = ids;
      }
      if (!this.formData.uid) {
        return this.$Message.error('请选择推广员');
      }
      if (this.isReceiveTime) {
        if (this.formData.time < 1) {
          return this.$Message.error('使用有效期限不能小于1天');
        }
      } else {
        this.formData.time = 0;
      }
      if (this.formData.type === 'text' || this.formData.type === 'url') {
        if (!this.formData.content.content.trim()) {
          return this.$Message.error('请输入内容');
        }
      }
      if (this.formData.type === 'voice' || this.formData.type === 'image') {
        if (!this.formData.content.src.trim()) {
          return this.$Message.error('请先上传消息');
        }
      }
      if (this.formData.type === 'news') {
        if (!this.formData.content.list.title.trim()) {
          return this.$Message.error('请选择图文消息');
        }
      }
      this.disabled = false;
      this.loading = true;
      wechatQrcodeSave(this.id, this.formData)
        .then((res) => {
          this.disabled = true;
          this.$Message.success(res.msg);
          setTimeout(() => {
            this.$router.push({
              path: `${Setting.roterPre}/marketing/channel_code`,
            });
          }, 1000);
        })
        .catch((err) => {
          this.disabled = false;
          this.$Message.error(err.msg);
        });
    },
    // 点击商品图
    modalPicTap(tit, picTit, index) {
      this.modalPic = true;
      this.isChoice = tit === 'dan' ? '单选' : '多选';
      this.picTit = picTit;
      this.tableIndex = index;
    },
    // 获取单张图片信息
    getPic(pc) {
      this.formData.content.src = pc.att_dir;
      this.modalPic = false;
    },
    handleRemove() {
      this.formData.content.src = '';
    },
  },
};
</script>

<style scoped lang="stylus">
.info {
  color: #888;
  font-size: 12px;
}

.ivu-input-wrapper {
  width: 320px;
}

.ivu-radio-wrapper {
  margin-right: 30px;
  font-size: 14px !important;
}

.ivu-radio-wrapper >>> .ivu-radio {
  margin-right: 10px;
}

.ivu-input-number {
  width: 160px;
}

.ivu-date-picker {
  width: 320px;
}

.ivu-icon-ios-camera-outline {
  width: 58px;
  height: 58px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  border-radius: 4px;
  background-color: rgba(0, 0, 0, 0.02);
  line-height: 58px;
  cursor: pointer;
  vertical-align: middle;
}

.upload-list {
  width: 58px;
  height: 58px;
  border: 1px dotted rgba(0, 0, 0, 0.1);
  border-radius: 4px;
  margin-right: 15px;
  display: inline-block;
  position: relative;
  cursor: pointer;
  vertical-align: middle;
}

.upload-list img {
  display: block;
  width: 100%;
  height: 100%;
}

.ivu-icon-ios-close-circle {
  position: absolute;
  top: 0;
  right: 0;
  transform: translate(50%, -50%);
}

.modelBox /deep/ .ivu-modal-body {
  padding: 0 16px 16px 16px !important;
}

.header-save {
  display: flex;
  justify-content: space-between;
}

.trip {
  color: #888;
}

.submit {
  margin: 30px 0 30px 50px;
}

.fl_header {
  padding-bottom: 10px;
}

textarea {
  width 50%
  padding: 0 5px;
  border-radius: 3px;
  border-color: #c5c8ce;
  outline-color: #2d8cf0;
}

/deep/ textarea::-webkit-input-placeholder {
  -webkit-text-fill-color: #ccc;
}

.pictrue {
  position: relative;
  display: inline-block;
  width: 58px;
  height: 58px;

  .btndel {
    position: absolute;
    z-index: 1;
    width: 20px !important;
    height: 20px !important;
    left: 48px;
    top: -4px;
  }
}

.picBox {
  display: inline-block;
  cursor: pointer;

  .upLoad {
    width: 58px;
    height: 58px;
    line-height: 58px;
    border: 1px dotted rgba(0, 0, 0, 0.1);
    border-radius: 4px;
    background: rgba(0, 0, 0, 0.02);
  }

  .pictrue {
    width: 60px;
    height: 60px;
    border: 1px dotted rgba(0, 0, 0, 0.1);
    margin-right: 10px;

    img {
      width: 100%;
      height: 100%;
    }
  }

  .iconfont {
    color: #898989;
  }
}

.addfont {
  display: inline-block;
  font-size: 13px;
  font-weight: 400;
  color: #1890FF;
  margin-left: 14px;
  cursor: pointer;
  margin-left: 10px;
}

.iconxiayi {
  font-size: 14px;
}

.ivu-page-header-title {
  padding-bottom: 0;
}

.news-box {
  width: 200px;
  background-color: #f2f2f2;
  padding: 10px;
  border-radius: 10px;
  margin-left: 65px;
  margin-top: 20px;

  .news_pic {
    width: 100%;
    height: 150px;
  }
}

.labelInput {
  border: 1px solid #dcdee2;
  width: 320px;
  padding: 0 8px;
  border-radius: 5px;
  min-height: 30px;
  cursor: pointer;

  .span {
    color: #c5c8ce;
  }

  .ivu-icon-ios-arrow-down {
    font-size: 14px;
    color: #808695;
  }
}

.fixed-card {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 200px;
  z-index: 9;
  box-shadow: 0 -1px 2px rgb(240, 240, 240);

  /deep/ .ivu-card-body {
    padding: 15px 16px 14px;
  }

  /deep/ .ivu-form-item-content {
    text-align: center;
  }

  .ivu-btn {
    height: 36px;
    padding: 0 20px;
  }
}

.ivu-form-item .ivu-menu {
  z-index: 9;
}
</style>
