<template>
  <Modal v-model="visible" :title="modalTitle" width="720" @on-cancel="close">
    <Alert type="warning" show-icon class="mb12">
      <div class="notice-title">操作注意事项</div>
      <ul class="notice-list">
        <li>仅支持 <b>.xlsx</b> 文件；请先导出 Excel 模板再填写，勿改表头。</li>
        <li><b>商品ID</b>、<b>SKU唯一值</b>为系统识别列，禁止修改或手工编造。</li>
        <li>未选择商品时，预填当前门店全部「上架中/仓库中」且参与库存的产品规格；选择商品后只预填所选商品的全部有效规格。</li>
        <li>单次预填不超过 <b>2000</b> 个规格；多选最多 <b>500</b> 个商品。</li>
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
      <Button type="primary" ghost @click="downloadTpl" :loading="downLoading">导出 Excel 模板</Button>
      <span class="tip ml10">可先选择商品再导出；不选则导出全部符合条件的规格</span>
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
        <Button type="primary" class="mr10" @click="goodsVisible = true">选择商品</Button>
        <Button v-if="selectedProducts.length" @click="clearSelected">清空</Button>
        <span class="tip ml10">已选 {{ selectedProducts.length }} 个商品</span>
      </FormItem>
    </Form>
    <Form inline>
      <FormItem label="关键字：">
        <Input v-model="keyword" placeholder="商品名/编码/条码（可选，与已选商品取交集）" style="width:320px" clearable />
      </FormItem>
    </Form>
    <div v-if="selectedProducts.length" class="selected-box mb12">
      <Tag
        v-for="item in selectedProducts"
        :key="item.id"
        closable
        class="selected-tag"
        @on-close="removeSelected(item.id)"
      >{{ item.name }}</Tag>
    </div>
    <Upload
      type="drag"
      :action="uploadUrl"
      :headers="header"
      :show-upload-list="false"
      :before-upload="beforeUpload"
      :on-success="onUploadSuccess"
      :on-error="onUploadError"
      :on-format-error="onUploadError"
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
    <selectGoodsBox
      v-model="goodsVisible"
      :chooseType="94"
      :ischeckbox="true"
      @getProductId="onSelectGoods"
    />
  </Modal>
</template>

<script>
import Setting from '@/setting'
import util from '@/libs/util'
import selectGoodsBox from '@/components/selectGoodsBox'
import {
  stockInTemplateApi,
  stockInTemplateFileApi,
  stockInImportApi,
  stockOutTemplateApi,
  stockOutTemplateFileApi,
  stockOutImportApi
} from '@/api/stockManage'

const PRODUCT_IDS_MAX = 500

export default {
  name: 'stockImport',
  components: { selectGoodsBox },
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
      selectedProducts: [],
      goodsVisible: false,
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
    },
    productIds() {
      return this.selectedProducts.map((item) => item.id)
    }
  },
  methods: {
    open(defaultScene) {
      if (defaultScene) this.scene = defaultScene
      this.fileUrl = ''
      this.fileName = ''
      // 关闭弹窗后保留已选商品，便于连续下载初始/普通入库模板；刷新页面不持久化
      this.visible = true
      const token = util.cookies.get('token')
      this.header = token ? { 'Authori-zation': 'Bearer ' + token } : {}
    },
    close() {
      this.visible = false
      this.goodsVisible = false
    },
    onSelectGoods(list) {
      // goodsAttr 回传 SKU 行：按 product_id 去重为商品集合（同一商品多规格只保留一个标签）
      const map = {}
      ;(list || []).forEach((row) => {
        const id = Number(row.product_id || 0)
        if (!id || map[id]) return
        const name = row.store_names || row.store_name || row.product_name || ('商品#' + id)
        map[id] = { id, name }
      })
      const next = Object.values(map)
      if (next.length > PRODUCT_IDS_MAX) {
        this.$Message.warning('一次最多选择 ' + PRODUCT_IDS_MAX + ' 个商品')
        this.selectedProducts = next.slice(0, PRODUCT_IDS_MAX)
        return
      }
      this.selectedProducts = next
    },
    removeSelected(id) {
      this.selectedProducts = this.selectedProducts.filter((item) => item.id !== id)
    },
    clearSelected() {
      this.selectedProducts = []
    },
    beforeUpload(file) {
      const ok = /\.xlsx$/i.test(file.name)
      if (!ok) {
        this.$Message.error('请上传 xlsx 文件')
        return false
      }
      this.fileName = file.name || 'import.xlsx'
      return true
    },
    onUploadSuccess(res) {
      // 上传接口返回 data.src（与商品/用户导入一致），不是 data.url
      const data = (res && res.data) || {}
      const src = data.src || data.url || ''
      if (res && res.status === 200 && src) {
        this.fileUrl = src
        if (data.name) this.fileName = data.name
        this.$Message.success(res.msg || '上传成功')
      } else {
        this.fileUrl = ''
        this.$Message.error((res && res.msg) || '上传失败')
      }
    },
    onUploadError(err) {
      this.fileUrl = ''
      const msg = (err && (err.message || err.msg)) || '上传失败，请重试'
      this.$Message.error(msg)
    },
    saveBlob(blob, fileName) {
      const url = window.URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = fileName || 'stock-template.xlsx'
      a.style.display = 'none'
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      window.URL.revokeObjectURL(url)
    },
    downloadTpl() {
      this.downLoading = true
      const payload = {
        keyword: this.keyword,
        product_ids: this.productIds
      }
      const metaReq = this.stockSide === 'out'
        ? stockOutTemplateApi(payload)
        : stockInTemplateApi({ scene: this.scene, ...payload })
      metaReq.then((res) => {
        const data = res.data || {}
        const key = data.download_key
        if (!key) {
          return Promise.reject({ msg: '未返回下载凭证，请刷新后重试' })
        }
        const fileApi = this.stockSide === 'out' ? stockOutTemplateFileApi : stockInTemplateFileApi
        // 展示名用接口 file_name（中文）；接口层已校验 xlsx 魔数
        return fileApi(key, data.file_name).then((file) => {
          this.saveBlob(file.blob, data.file_name || file.fileName)
          this.$Message.success('模板已下载' + (data.count != null ? '（' + data.count + ' 行）' : ''))
        })
      }).catch((err) => {
        this.$Message.error((err && err.msg) || '下载失败')
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
.mr10 { margin-right: 10px; }
.mt12 { margin-top: 12px; }
.mb12 { margin-bottom: 12px; }
.notice-title { font-weight: 600; margin-bottom: 6px; }
.notice-list { margin: 0; padding-left: 18px; line-height: 1.7; font-size: 12px; }
.notice-list li { margin-bottom: 2px; }
.selected-box {
  max-height: 120px;
  overflow-y: auto;
  padding: 8px 10px;
  background: #f8f8f9;
  border-radius: 4px;
}
.selected-tag { margin: 0 8px 8px 0; }
</style>
