<template>
  <div>
    <div v-if="page1Visible" class="page1">
      <div class="main">
        <div v-if="imgList.length" class="img-list">
          <div v-for="item in imgListPreview" :key="item.url" class="item">
            <img :src="item.url" />
            <Icon
              type="ios-close"
              size="17"
              color="#FFFFFF"
              @click="removeImage(item.name)"
            ></Icon>
          </div>
        </div>
        <label class="flex-col flex-center h-150 upload-box" v-show="!imgList.length">
          <input ref="upload" type="file" name="" id="" multiple hidden accept="image/*" @change="beforeUpload">
          <Icon type="ios-add" size="44" color="#CCCCCC"></Icon>
          <div>点击选择图片</div>
        </label>
      </div>
      <div class="footer">
        <div class="text">
          <template v-if="imgList.length">
            共{{ imgList.length }}张，{{ allSize }}M
          </template>
        </div>
        <div class="btn" @click="chooseImage">
          {{ imgList.length ? '继续选择' : '选择图片' }}
        </div>
        <div
          class="btn primary"
          :class="{ disabled: !imgList.length || uploading }"
          @click="confirmUpload"
        >
          确认上传
        </div>
      </div>
    </div>
    <div v-else class="page2">
      <div class="success">
        <img src="@/assets/images/success.jpg" />
      </div>
      <div class="text">图片上传成功</div>
      <div class="btn" @click="againUpload">继续上传</div>
    </div>
    <Spin v-if="uploading" fix></Spin>
  </div>
</template>

<script>
import Setting from '@/setting';
import { scanUpload } from '@/api/setting';

export default {
  name: 'mobile_upload',
  data() {
    return {
      action: `${Setting.apiBaseURL}file/scan/upload`,
      imgList: [],
      uploading: false,
      pid: 0,
      cache: 0,
      type: 0,
      relation_id: 0,
      token: 0,
      maxSize: 0,
      page1Visible: true,
    };
  },
  computed: {
    imgListPreview() {
      return this.imgList.map((img) => ({
        name: img.name,
        url: URL.createObjectURL(img),
      }));
    },
    allSize() {
      let allSize = this.imgList.reduce((total, { size }) => {
        return total + size;
      }, 0);
      return (allSize / 1000000).toFixed(2);
    },
  },
  created() {
    let viewportEl = document.getElementsByName('viewport')[0];
    viewportEl.content += ',viewport-fit=cover';
    document.title = '手机端扫码上传';
    this.pid = this.$route.query.pid;
    this.cache = this.$route.query.cache;
    this.type = this.$route.query.type;
    this.relation_id = this.$route.query.relation_id;
    this.token = this.$route.query.token;
    this.maxSize = this.$route.query.upload_file_size_max;
  },
  methods: {
    // 选择图片
    chooseImage() {
      this.$refs.upload.click();
    },
    // 删除图片
    removeImage(name) {
      for (let i = 0; i < this.imgList.length; i++) {
        if (this.imgList[i].name === name) {
          this.imgList.splice(i, 1);
          break;
        }
      }
    },
    againUpload() {
      this.page1Visible = true;
    },
    async confirmUpload() {
      if (!this.imgList.length || this.uploading) return;
      this.uploading = true;
      for (let i = 0; i < this.imgList.length; i++) {
        const result = await this.uploadImage(this.imgList[i]).catch((err) => {
          this.$Message.error(err);
        });
        if (i === this.imgList.length - 1) {
          this.imgList = [];
          this.uploading = false;
          this.page1Visible = false;
        }
      }
    },
    // 上传
    uploadImage(file) {
      return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('relation_id', this.relation_id);
        formData.append('uploadToken', this.token);
        formData.append('cache', this.cache);
        formData.append('type', this.type);
        formData.append('pid', this.pid);
        formData.append('file', file);
        formData.append('upload_type', 0);
        scanUpload(formData)
          .then((res) => {
            if (res.status === 200) {
              resolve();
            } else {
              reject(`${file.name}上传失败`);
            }
          })
          .catch((err) => {
            reject(err.msg);
          });
      });
    },
    beforeUpload(event) {
      const newFiles = Array.from(event.target.files);
      const validFiles = newFiles.filter((file) => {
        if (!['image/jpeg', 'image/png', 'image/jpg'].includes(file.type)) {
          this.$Message.error('只支持上传jpg/png/jpeg格式的图片');
          return false;
        }
        if (file.size > this.maxSize) {
          this.$Message.error(`图片大小超过限制`);
          return false;
        }
        return true;
      });
      validFiles.forEach((file) => {
        this.imgList.push(file);
      });
    },
  },
};
</script>
<style lang="stylus" scoped>
.page1 {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;

  .main {
    flex: 1;
    padding: 10px;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
  }

  .footer {
    position: relative;
    display: flex;
    align-items: center;
    padding: 10px 15px;
    padding: 10px 15px calc(10px + constant(safe-area-inset-bottom));
    padding: 10px 15px calc(10px + env(safe-area-inset-bottom));

    &:before {
      content: ' ';
      position: absolute;
      left: 0;
      top: 0;
      right: 0;
      height: 1px;
      border-top: 1px solid #EFEFEF;
      color: #EFEFEF;
      -webkit-transform-origin: 0 0;
      transform-origin: 0 0;
      -webkit-transform: scaleY(0.5);
      transform: scaleY(0.5);
    }

    .text {
      flex: 1;
    }

    .btn {
      width: 88px;
      height: 30px;
      border: 1px solid #CCCCCC;
      border-radius: 15px;
      text-align: center;
      font-size: 14px;
      line-height: 28px;
      color: #666666;
      cursor: pointer;

      +.btn {
        margin-left: 10px;
      }

      &.primary {
        border-color: #E93323;
        background: #E93323;
        color: #FFFFFF;
      }

      &.disabled {
        opacity: 0.3;
      }
    }
  }

  .img-list {
    display: flex;
    flex-wrap: wrap;
    margin: 0 -8px -8px 0;

    .item {
      position: relative;
      width: 113px;
      height: 113px;
      border-radius: 6px;
      margin: 0 8px 8px 0;
      overflow: hidden;

      img {
        width: 100%;
        height: 100%;
      }

      .ivu-icon {
        position: absolute;
        top: 0;
        right: 0;
        width: 17px;
        height: 17px;
        border-radius: 0px 6px 0px 6px;
        background: rgba(40, 40, 40, 0.5);
        cursor: pointer;
      }
    }
  }

  .upload-box {
    border: 1px dashed #DDDDDD;
    border-radius: 6px;
    background: #F9F9F9;
    font-size: 13px;
    color: #999999;

    .ivu-icon {
      margin-bottom: 10px;
    }
  }

  /deep/.ivu-upload-select {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    font-size: 13px;
    color: #999999;

    .ivu-icon {
      margin-bottom: 10px;
    }
  }
}

.page2 {
  padding-top: 128px;

  .success {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    margin: 0 auto;
    background: #28C445;
    text-align: center;
    font-size: 0;
    line-height: 50px;

    img {
      width: 24px;
      vertical-align: middle;
    }
  }

  .text {
    margin-top: 20px;
    text-align: center;
    font-weight: 500;
    font-size: 16px;
    color: #282828;
  }

  .btn {
    width: 150px;
    height: 43px;
    border-radius: 21px;
    border: 1px solid #CCCCCC;
    margin: 40px auto 0;
    text-align: center;
    font-size: 15px;
    line-height: 41px;
    color: #333333;
    cursor: pointer;
  }
}

.ivu-spin-fix {
  background-color: rgba(255, 255, 255, 0.1);
}
</style>
