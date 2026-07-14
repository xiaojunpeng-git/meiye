<template>
  <Modal v-model="visible" :title="modalTitle" width="640" @on-cancel="close">
    <Alert type="warning" show-icon class="mb12">
      <div class="notice-title">操作注意事项</div>
      <ul class="notice-list">
        <li>仅支持 <b>.xlsx</b> 文件；请先下载模板再填写，勿改表头。</li>
        <li><b>商品ID</b>、<b>SKU唯一值</b>为系统识别列，禁止修改或手工编造。</li>
        <li>模板只预填已开启「参与库存管理」的商品规格；可用下方关键字缩小范围。</li>
        <li>单次预填不超过 <b>2000</b> 个规格，超出请缩小筛选后再下载，避免卡顿。</li>
        <li>导入会先整表校验：任一错误（含行号提示）则本次一条库存都不写入。</li>
        <li>导入入账请勿中途关闭页面或重复点击；整表校验通过后才会写入库存。</li>
        <li>入账失败时库存不会变化，可在导入记录查看失败原因并下载错误明细；失败后可改表重试。</li>
        <li>同一文件内容成功导入后永久不可再提交；失败可改表后重新上传。</li>
        <li v-if="stockSide === 'in'">初始入库类型固定为「初始入库」；普通入库仅支持采购/其他入库类型。</li>
        <li v-else>出库导入仅支持过期退货、试用、报废、其他出库；库存不足且不允许负库存时整单失败。</li>
        <li>导入失败明细可在「导入记录」中查看（含第 N 行原因）。</li>
      </ul>
    </Alert>

    <div class="mb12">
      <Button type="primary" ghost @click="downloadTpl" :loading="downLoading">下载Excel模板</Button>
      <span class="tip ml10">先设筛选再下载，模板会预填当前可导入的规格</span>
    </div>
    <Form inline v-if="needScene">
      <FormItem label="导入类型：">
        <Select v-model="scene" style="width:180px">
          <Option value="initial_in">初始入库</Option>
          <Option value="in">普通入库</Option>
        </Select>
      </FormItem>
    </Form>
    <Form inline>
      <FormItem label="预填筛选：">
        <Input v-model="keyword" placeholder="商品名/编码/条码（可选）" style="width:240px" clearable />
      </FormItem>
    </Form>
    <Upload
      type="drag"
      :action="uploadUrl"
      :headers="header"
      :show-upload-list="false"
      :before-upload="beforeUpload"
      :on-success="onUploadSuccess"
      accept=".xlsx"
    >
      <div style="padding: 24px 0">
        <Icon type="ios-cloud-upload" size="40"></Icon>
        <p>将 xlsx 拖到此处，或点击上传</p>
        <p class="tip">上传后点「立即导入」；校验不通过不会改库存</p>
      </div>
    </Upload>
    <div v-if="fileUrl" class="mt12">
      已选文件：{{ fileName }}
      <Button type="primary" class="ml10" :loading="importLoading" @click="doImport">立即导入</Button>
    </div>
    <div slot="footer">
      <Button @click="close">关闭</Button>
    </div>
  </Modal>
</template>

<script>
import Setting from '@/setting'
import util from '@/libs/util'
import {
  stockInTemplateApi,
  stockInImportApi,
  stockOutTemplateApi,
  stockOutImportApi
} from '@/api/stockManage'

export default {
  name: 'stockImport',
  props: {
    // in | out
    stockSide: {
      type: String,
      default: 'in'
    }
  },
  data() {
    return {
      visible: false,
      scene: 'initial_in',
      keyword: '',
      fileUrl: '',
      fileName: '',
      downLoading: false,
      importLoading: false,
      uploadUrl: Setting.apiBaseURL + '/file/upload/1',
      header: {}
    }
  },
  computed: {
    needScene() {
      return this.stockSide === 'in'
    },
    modalTitle() {
      return this.stockSide === 'out' ? '出库 Excel 导入' : '入库 Excel 导入'
    }
  },
  methods: {
    open(defaultScene) {
      if (defaultScene) this.scene = defaultScene
      this.fileUrl = ''
      this.fileName = ''
      this.visible = true
      const token = util.cookies.get('token')
      this.header = token ? { 'Authori-zation': 'Bearer ' + token } : {}
    },
    close() {
      this.visible = false
    },
    beforeUpload(file) {
      const ok = /\.xlsx$/i.test(file.name)
      if (!ok) {
        this.$Message.error('请上传 xlsx 文件')
        return false
      }
      return true
    },
    onUploadSuccess(res) {
      if (res.status === 200) {
        this.fileUrl = res.data.url
        this.fileName = res.data.name || 'import.xlsx'
      } else {
        this.$Message.error(res.msg || '上传失败')
      }
    },
    downloadTpl() {
      this.downLoading = true
      const req = this.stockSide === 'out'
        ? stockOutTemplateApi({ keyword: this.keyword })
        : stockInTemplateApi({ scene: this.scene, keyword: this.keyword })
      req.then((res) => {
        const path = res.data.path || (res.data && res.data[0])
        if (path) {
          window.open(path)
          this.$Message.success('模板已生成')
        } else {
          this.$Message.error('未返回下载地址')
        }
      }).catch((err) => {
        this.$Message.error(err.msg || '下载失败')
      }).finally(() => {
        this.downLoading = false
      })
    },
    doImport() {
      if (!this.fileUrl) {
        this.$Message.error('请先上传文件')
        return
      }
      this.importLoading = true
      const req = this.stockSide === 'out'
        ? stockOutImportApi({ file: this.fileUrl, real_name: this.fileName })
        : stockInImportApi({ scene: this.scene, file: this.fileUrl, real_name: this.fileName })
      req.then((res) => {
        this.$Message.success(res.msg || '导入成功')
        this.$emit('success')
        this.close()
      }).catch((err) => {
        this.$Message.error(err.msg || '导入失败')
      }).finally(() => {
        this.importLoading = false
      })
    }
  }
}
</script>

<style scoped>
.tip { color: #999; font-size: 12px; }
.ml10 { margin-left: 10px; }
.mt12 { margin-top: 12px; }
.mb12 { margin-bottom: 12px; }
.notice-title { font-weight: 600; margin-bottom: 6px; }
.notice-list { margin: 0; padding-left: 18px; line-height: 1.7; font-size: 12px; }
.notice-list li { margin-bottom: 2px; }
</style>
