<template>
  <Modal
    :value="visible"
    :z-index="zIndex"
    title="上传图片"
    width="960"
    class-name="upload-img-modal"
    @on-visible-change="visibleChange"
  >
    <Spin v-if="spinShow" size="large" fix></Spin>
    <Form :model="formData" :label-width="100">
      <FormItem label="上传方式：">
        <RadioGroup v-model="formData.type" @on-change="typeChange">
          <Radio :label="0">本地上传</Radio>
          <Radio :label="1">网络图片</Radio>
          <Radio :label="2">扫码上传</Radio>
        </RadioGroup>
      </FormItem>
      <FormItem label="上传至分组：">
        <Cascader
          v-model="formData.region"
          :data="cascaderData"
          :load-data="loadData"
          style="width: 426px"
          change-on-select
          @on-change="regionChange"
        ></Cascader>
      </FormItem>
      <FormItem v-if="!formData.type" label="上传图片：">
        <div class="picture-list" v-viewer>
          <div
            v-for="(item, index) in previewImgList"
            :key="item.url"
            class="item"
          >
            <img
              class="image"
              :src="item.url"
              @dragstart="dragstart(index)"
              @dragenter="dragenter(index, $event)"
            />
            <Icon
              type="ios-close-circle"
              size="16"
              color="#999999"
              @click="handleRemove(index)"
            ></Icon>
          </div>
          <label>
            <input type="file" name="" accept="image/png, image/jpeg, image/gif" multiple hidden @change="beforeUpload">
            <Icon type="ios-add" size="62" color="#CCCCCC"></Icon>
          </label>
        </div>
        <div class="tips">
          建议上传图片最大宽度750px，不超过{{
            maxSize / 1024 / 1024
          }}MB；仅支持jpeg、png、gif格式，可拖拽调整上传顺序
        </div>
      </FormItem>
      <FormItem v-if="formData.type === 1" label="网络图片：">
        <div class="input-wrapper">
          <Input
            v-model="imgLink"
            placeholder="请网络图片地址"
            style="width: 426px"
          ></Input>
          <Button type="text" @click="pullImage">提取照片</Button>
        </div>
        <div v-if="formData.imgList.length" class="picture-list">
          <div
            v-for="(item, index) in formData.imgList"
            :key="item.url"
            class="item"
          >
            <img
              class="image"
              :src="item.url"
              @dragstart="dragstart(index)"
              @dragenter="dragenter(index, $event)"
            />
            <Icon
              type="ios-close-circle"
              size="16"
              color="#999999"
              @click="handleRemove(index)"
            ></Icon>
          </div>
        </div>
        <div v-if="formData.imgList.length > 1" class="tips">
          鼠标拖拽图片可调整图层顺序
        </div>
      </FormItem>
      <FormItem v-if="formData.type === 2" label="二维码：">
        <div class="scan-code-upload">
          <div class="qrcode-wrapper">
            <div class="qrcode" ref="qrcode"></div>
            <div class="tips large">扫描二维码，快速上传手机图片</div>
            <div class="tips">建议使用手机浏览器</div>
          </div>
          <div class="picture-wrapper">
            <Button @click="scanQRCodeImageList">刷新图库</Button>
            <div class="tips">刷新图库按钮，可显示移动端上传成功的图片</div>
            <div class="picture-list">
              <div
                v-for="(item, index) in formData.imgList"
                :key="item.att_id"
                class="item"
              >
                <img class="image" :src="item.att_dir" alt="" />
                <Icon
                  type="ios-close-circle"
                  size="16"
                  color="#999999"
                  @click="handleRemove(item.att_id)"
                ></Icon>
              </div>
            </div>
          </div>
        </div>
      </FormItem>
    </Form>
    <div slot="footer" class="modal-footer">
      <Button @click="close">取消</Button>
      <Button type="primary" @click="submitForm">确认</Button>
    </div>
  </Modal>
</template>
<script>
import { getCategoryListApi, moveApi, fileDelApi } from '@/api/uploadPictures';
import {
  uploadFile,
  scanQRCodeText,
  uploadOnlineFile,
  scanQRCodeImageList,
  removeQRCode,
  getWayData,
  setWayData
} from '@/api/upload';
import QRCode from 'qrcodejs2';
import compressImg from '@/utils/compressImg';

export default {
  data() {
    return {
      formData: {
        type: 0,
        region: [],
        imgList: []
      },
      imgLink: '',
      spinShow: false,
      visible: false,
      onClose: null,
      zIndex: 1000,
      categoryData: [],
      maxSize: 2097152
    };
  },
  computed: {
    cascaderData() {
      return this.mapCategoryList(this.categoryData);
    },
    previewImgList() {
      return this.formData.imgList.map((item) => ({
        url: URL.createObjectURL(item)
      }));
    },
    selectedCategoryId() {
      return this.formData.region[this.formData.region.length - 1] || 0;
    }
  },
  watch: {
    categories: {
      handler(value) {
        this.categoryData = value;
        this.findCategoryId(value);
      },
      immediate: true
    },
    categoryId: {
      handler() {
        this.findCategoryId(this.categories);
      },
      immediate: true
    }
  },
  mounted() {
    this.findCategoryId(this.categories);
    getWayData().then((res) => {
      this.formData.type = res.data.is_way;
      this.maxSize = res.data.upload_file_size_max;
      this.typeChange(this.formData.type);
    });
  },
  beforeDestroy() {
    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }
  },
  methods: {
    visibleChange(visible) {
      if (!visible) {
        this.$destroy();
        this.$el.parentNode.removeChild(this.$el);
      }
    },
    findCategoryId(list) {
      const region = [];
      const categoryId = this.categoryId || '';
      const result = list.find((item) => {
        if (item.id === categoryId) {
          return true;
        } else if (item.children) {
          return this.findCategoryId(item.children);
        } else {
          return false;
        }
      });
      if (result) {
        if (result.pid) {
          region.push(result.pid);
        }
        region.push(result.id);
        this.formData.region = region;
      }
    },
    mapCategoryList(list) {
      return list.map((item) => {
        item.value = item.id;
        item.label = item.title;
        if (item.children) {
          item.children = this.mapCategoryList(item.children);
        }
        return item;
      });
    },
    loadData(item, callback) {
      item.loading = true;
      getCategoryListApi({ pid: item.id, file_type: 1 }).then((res) => {
        item.children = res.data.list;
        item.loading = false;
        this.$nextTick(callback);
      });
    },
    // 上传方式改变
    typeChange(type) {
      this.formData.imgList = [];
      this.imgLink = '';
      if (this.timer) {
        clearTimeout(this.timer);
        this.timer = null;
      }
      if (type === 2) {
        this.scanQRCodeText();
      } else {
        if (this.qrcode) {
          this.qrcode = null;
          removeQRCode();
        }
      }
    },
    // 上传分组改变
    regionChange() {
      if (this.formData.type === 2) {
        this.scanQRCodeText();
      }
    },
    // 取消
    close() {
      this.visible = false;
      if (typeof this.onClose === 'function') {
        this.onClose(this);
      }
      this.formData.imgList = [];
      this.imgLink = '';
      if (this.timer) {
        clearTimeout(this.timer);
        this.timer = null;
      }
      removeQRCode();
      setWayData(this.formData.type);
    },
    dragstart(index) {
      this.dragIndex = index;
    },
    dragenter(index, event) {
      event.preventDefault();
      if (index !== this.dragIndex) {
        const source = this.formData.imgList[this.dragIndex];
        this.formData.imgList.splice(this.dragIndex, 1);
        this.formData.imgList.splice(index, 0, source);
        this.dragIndex = index;
      }
    },
    // 上传文件之前
    async beforeUpload(event) {
      const files = event.target.files;
      let file;
      for (let i = 0; i < files.length; i++) {
        file = files[i];
        if (file.size > this.maxSize) {
          file = await compressImg(file);
        }
        this.formData.imgList.push(file);
      }
    },
    // 移除文件
    handleRemove(id) {
      if (this.formData.type === 2) {
        fileDelApi({ ids: id }).then(() => {
          this.scanQRCodeImageList();
        });
      } else {
        this.formData.imgList.splice(id, 1);
      }
    },
    scanQRCodeImageList() {
      if (this.timer) {
        clearTimeout(this.timer);
        this.timer = null;
      }
      scanQRCodeImageList(this.token).then((res) => {
        this.formData.imgList = res.data;
        this.timer = setTimeout(this.scanQRCodeImageList, 2000);
      });
    },
    uploadFile(file) {
      return new Promise((resolve) => {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('pid', this.selectedCategoryId);
        uploadFile(formData)
          .then(resolve)
          .catch((err) => {
            this.spinShow = false;
            this.$Message.error(err.msg);
          });
      });
    },
    // 二维码的信息
    scanQRCodeText() {
      scanQRCodeText({ pid: this.selectedCategoryId }).then((res) => {
        this.createQRCode(res.data.url);
      });
    },
    // 生成二维码
    createQRCode(text) {
      if (this.qrcode) {
        this.qrcode.makeCode(text);
      } else {
        this.qrcode = new QRCode(this.$refs.qrcode, {
          text,
          width: 160,
          height: 160,
          colorDark: '#000000',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.H
        });
      }
      this.token = text.split('token=')[1];
      this.scanQRCodeImageList();
    },
    // 网络图片
    uploadOnlineFile() {
      this.spinShow = true;
      uploadOnlineFile({
        pid: this.selectedCategoryId,
        images: this.formData.imgList.map((item) => item.url)
      })
        .then(() => {
          this.spinShow = false;
          this.$Message.success('上传成功');
          // this.$emit('success');
          this.close();
        })
        .catch((res) => {
          this.spinShow = false;
          this.$Message.error(res.msg);
        });
    },
    submitForm() {
      switch (this.formData.type) {
        case 1:
          this.uploadOnlineFile();
          break;
        case 2:
          this.moveApi();
          break;
        default:
          this.localUpload();
          break;
      }
    },
    async localUpload() {
      this.spinShow = true;
      for (let i = 0; i < this.formData.imgList.length; i++) {
        await this.uploadFile(this.formData.imgList[i]);
        if (i === this.formData.imgList.length - 1) {
          this.spinShow = false;
          this.$Message.success('上传成功');
          // this.$emit('success');
          this.close();
        }
      }
    },
    moveApi() {
      const images = this.formData.imgList.map((item) => item.att_id);
      const data = {
        pid: this.selectedCategoryId,
        images: images.join()
      };
      // moveApi(data).then((res) => {
      this.spinShow = false;
      this.$Message.success('上传成功');
      removeQRCode();
      // this.$emit('success');
      this.close();
      // });
    },
    pullImage() {
      if (this.imgLink) {
        this.formData.imgList.push({ url: this.imgLink });
      }
    }
  }
};
</script>
<style lang="stylus" scoped>
/deep/.ivu-modal-body {
  height: 528px;
  overflow: auto;
}

.input-wrapper {
  display: flex;

  .ivu-btn {
    color: #1890FF;
  }

  .ivu-btn:focus {
    box-shadow: none;
  }

  +.picture-list {
    margin-top: 20px;
  }
}

.tips {
  font-size: 12px;
  color: #BBBBBB;
}

.picture-list {
  display: flex;
  flex-wrap: wrap;

  .item {
    position: relative;
    width: 64px;
    height: 64px;
    margin: 0 10px 10px 0;
  }

  .image {
    width: 100%;
    height: 100%;
    border-radius: 4px;
  }

  .ivu-icon-ios-close-circle {
    position: absolute;
    top: 0;
    right: 0;
    transform: translate(30%, -30%);
    cursor: pointer;
  }

  .ivu-icon-ios-add {
    border: 1px dashed #DDDDDD;
    border-radius: 4px;

    &:hover {
      border-color: #57a3f3;
    }
  }
}

.scan-code-upload {
  display: flex;

  .qrcode {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 200px;
    height: 200px;
    border: 1px solid #DDDDDD;
    border-radius: 4px;
  }

  .qrcode-wrapper {
    .tips {
      text-align: center;
      line-height: 28px;

      &.large {
        font-size: 14px;
        color: #333333;
      }
    }
  }

  .picture-wrapper {
    flex: 1;
    padding-left: 20px;

    .tips {
      font-size: 12px;
      color: #BBBBBB;
    }
  }
}

/deep/.upload-img-modal {
  .ivu-radio-wrapper {
    margin-right: 30px;
  }

  .ivu-radio {
    margin-right: 10px;
  }

  .ivu-input {
    padding-left: 15px;
    border-color: #DDDDDD;
  }

  .ivu-modal-footer {
    border-top: none;

    button + button {
      margin-left: 14px;
    }
  }
}
</style>
