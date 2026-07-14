<!-- 商品导入 -->
<template>
  <div class="goods-import">
    <!-- 下载模板 -->
    <div class="download acea-row row-middle" v-if="!importStatus">
      <span>上传前请先按Excel模板中的格式编辑内容</span>
      <img src="@/assets/images/excel-icon.png" alt="" />
      <a :href="`${BaseURL}/statics/用户导入模板.xlsx`" download class="download-text cup">下载Excel模板</a>
    </div>

    <div class="goods-upload mt20">
      <Upload
        ref="upload"
        v-show="!fileUrl && !importStatus"
        class="upload-demo"
        type="drag"
        :show-upload-list="false"
        :action="uploadUrl"
        :before-upload="fileChange"
        :headers="header"
        :on-success="handleSuccess"
        accept=".xls, .xlsx"
      >
      <template>
          <img class="dragger__icon mb20" src="@/assets/images/upload-icon.png" alt="" />
          <div class="el-upload__text">将文件拖到此处，或<span class="add-text">点击添加</span></div>
          <div class="el-upload__trip">支持 .xls，.xlsx，限10M以内</div>
        </template>
      </Upload>
      <div v-show="fileUrl && !importStatus" class="file-info">
        <img class="dragger__icon mb20" src="@/assets/images/upload-icon.png" alt="" />
        <div class="el-upload__text">{{ fileName }}</div>
        <div class="flex mt12" v-if="fileUrl && !importLoading">
          <div class="active-btn" @click="selectFile">重新上传</div>
          <div class="active-btn" @click="fileUrl = ''">删除</div>
        </div>
        <div class="el-upload__trip" v-if="importLoading">
          正在上传中
          <i class="el-icon-loading"></i>
        </div>
        <div class="el-upload__trip" v-if="resultData.type == 2">
          正在导入，您可关闭当前弹窗，稍候可在列表查看导入结果
          <i class="el-icon-loading"></i>
        </div>
        <Button v-else class="btn-import" type="primary" @click="importGoods">立即导入</Button>
      </div>
      <div v-show="fileUrl && importStatus" class="file-info">
        <img class="dragger__icon mb20" :src="statusImage" alt="" />
        <div class="el-upload__text" v-show="resultData.type != 2">
          共导入 {{ resultData.all }} 个，成功 {{ resultData.success }} 个，失败 <span class="text-err">{{ resultData.fail }}</span>个
        </div>
        <div class="el-upload__text"  v-show="resultData.type == 2">
          正在执行队列，稍后请在导入记录查看导入结果
        </div>
        <div class="el-upload__trip" v-if="resultData.fail > 0">
          您可以下载失败数据，修改后再重新导入 <span class="active-btn" @click="exports">下载失败数据</span>
        </div>
        <div>
          <Button class="btn-import" @click="selectFile">再次导入</Button>
          <Button type="primary" class="btn-import ml-14" @click="close">完成</Button>
        </div>
      </div>
    </div>
    <!-- 导入规则 -->
    <div class="import-rule mt20" v-if="!importStatus">
      <div class="rule-title">导入规则</div>
      <div class="rule-text">1. 请先下载模板，在模板中按字段填写信息，然后上传该文件。</div>
      <div class="rule-text">2. 导入以手机号为唯一标识，同一文件不允许出现相同手机号，请正确输入手机号。</div>
      <div class="rule-text">3. 导入未完成之前，请勿关闭页面，否则可能数据错误。</div>
      <div class="rule-text">4. 文件大小不超过10MB。</div>
      <div class="rule-text">5. 限制导入10000行记录，超出部分请分多次导入。</div>
    </div>
  </div>
</template>

<script>
import { userImportApi, userDownRecordApi } from '@/api/user';
import { getImportErrorDown } from '@/api/system';
import Setting from '@/setting';
import { isXlsUpload } from '@/utils/index';
import util from "@/libs/util";
import exportExcel from "@/utils/newToExcel.js";
export default {
  name: 'goodsImport',
  data() {
    return {
      uploadUrl: Setting.apiBaseURL + '/file/upload/1',
      header: {
        'Authori-zation': "Bearer " + util.cookies.get("token"),
      },
      fileName: '',
      fileUrl: '',
      importStatus: false,
      importLoading: false,
      resultData: {
        all: 0,
        success: 0,
        fail: 0,
        type:0
      },
      statusImage: require('@/assets/images/file-success.png'),
      BaseURL: Setting.apiBaseURL.replace(/adminapi/, ""),
    };
  },
  watch: {
    resultData: {
      handler(newValue) {
        if (newValue.fail > 0) {
          this.statusImage = require('@/assets/images/file-fail.png');
        } else {
          this.statusImage = require('@/assets/images/file-success.png');
        }
      },
      deep: true, // 默认值是 false，代表是否深度监听
    },
  },
  mounted() {},
  methods: {
    fileChange(file, fileList) {
      if (isXlsUpload(file)) {
        // 限制10M
        if (file.size >= 10485760) {
          this.$Message.error('文件大小不能超过10MB');
          return false;
        } else {
          this.fileName = file.name;
        }
      } else {
        return false;
      }
    },
    selectFile() {
      this.importStatus = false;
      this.importLoading = false;
      this.fileName = "";
      this.fileUrl = "";
      this.resultData = {
        all: 0,
        success: 0,
        fail: 0,
        type:0
      };
      // 调起选择文件
      this.$refs.upload.handleClick();
      // this.$refs['upload'].$refs['upload-inner'].handleClick();
    },
    handleSuccess(res, file, fileList) {
      if (res.status === 200) {
        this.fileUrl = res.data.src;
      }
    },
    importGoods() {
      this.importLoading = true;
      this.importStatus = false;
      userImportApi({file: this.fileUrl,real_name: this.fileName}).then((res) => {
          // 返回导入结果
          this.importStatus = true;
          this.importLoading = false;
          this.resultData = {
            all: res.data.allCount,
            success: res.data.allCount - res.data.failCount,
            fail: res.data.failCount,
            type: res.data.type,
            id: res.data.id
          }
        })
        .catch((err) => {
          this.$Message.error(err.msg);
          this.importLoading = false;
          this.importStatus = false;
        });
    },
    close() {
      this.fileUrl = '';
      this.fileName = '';
      this.importStatus = false;
      this.$emit('close');
    },
    // 数据导出；
    async exports() {
      let [th, filekey, data, fileName] = [[], [], [], ""];
      let lebData = await this.getExcelData(this.resultData.id);
      if (!fileName) fileName = lebData.filename;
      filekey = lebData.filekey;
      if (!th.length) th = lebData.header; //表头
      data = data.concat(lebData.export);
      exportExcel(th, filekey, fileName, data);
    },
    getExcelData(id) {
      return new Promise((resolve, reject) => {
        getImportErrorDown({
          record_id: id,
          type: 'user',
        }).then((res) => {
          return resolve(res.data);
        })
      });
    },
  },
};
</script>

<style lang="less" scoped>
.download {
  background-color: #e5eeff;
  padding: 12px;
  border-radius: 4px;
  color: #303133;
  font-size: 12px;
  img {
    width: 19px;
    height: 19px;
    margin: 0 4px 0 8px;
  }
  .download-text {
    color: #2d8cf0;
  }
}
.goods-upload {
  width: 100%;
  /deep/ .ivu-upload {
    width: 100%;
    .ivu-upload-drag {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      padding: 100px 0;
      .dragger__icon {
        width: 42px;
        height: 57px;
      }
      .el-upload__trip {
        font-weight: 400;
        font-size: 12px;
        color: #999999;
        margin-top: 6px;
      }
    }
  }
  .file-info {
    height: 342px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    border: 1px dashed #d9d9d9;
    border-radius: 4px;
    margin-top: 10px;
    .active-btn {
      color: #2d8cf0;
      font-size: 12px;
      font-weight: 400;
      margin: 0 6px;
      cursor: pointer;
    }
    .btn-import {
      margin-top: 26px;
    }
    .dragger__icon {
      width: 42px;
      height: 57px;
    }
    .el-upload__trip {
      display: flex;
      align-items: center;
      font-weight: 400;
      font-size: 12px;
      color: #999;
      margin-top: 6px;
    }
  }
}
.import-rule {
  .rule-title {
    font-weight: 500;
    font-size: 14px;
    color: #303133;
    margin-bottom: 6px;
  }
  .rule-text {
    font-size: 12px;
    color: #303133;
  }
}
.add-text{
  color: #0256FF;
  padding-left: 4px;
}
.text-err{
  color: #ED4014;
}
</style>
