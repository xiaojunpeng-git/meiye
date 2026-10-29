<template>
  <Modal :value="visible" title="调查完成奖励" width="868" :mask-closable="false" :footer-hide="true" @on-cancel="$emit('close')">
    <div ref="formArea">
      <p>项目、实物产品和优惠券按010赠送规则发放；会员完成答卷当天生效，实物到账后到门店领取。</p>
      <Form :label-width="110">
        <FormItem label="奖励发放门店" data-required-key="store"><Select v-model="form.store_id" :disabled="readonly" filterable @on-change="changeStore"><Option v-for="store in stores" :key="store.id" :value="Number(store.id)">{{ store.name }}</Option></Select></FormItem>
        <FormItem label="每轮都奖励"><Checkbox v-model="form.reward_each_round" :disabled="readonly">重发任务后，新一轮完成可再次领奖；关闭则整个任务每人仅奖励一次</Checkbox></FormItem>
      </Form>
      <div v-if="!readonly" class="survey-reward-search"><Button type="primary" size="large" :disabled="!form.store_id" @click="openProducts">添加品项</Button><Button size="large" :disabled="!form.store_id" @click="openCoupons">添加优惠券</Button></div>
      <div class="survey-reward-table-scroll"><table class="survey-reward-table"><thead><tr><th>类型</th><th>名称</th><th>数量</th><th>生效时间</th><th>有效天数</th><th>结束时间</th><th>操作</th></tr></thead><tbody><tr v-for="item in rows" :key="item.kind + item.id"><td>{{ item.kind === 'coupon' ? '券' : Number(item.product_type) === 6 ? '项目' : '产品' }}</td><td>{{ item.store_name }}</td><td><InputNumber :data-required-key="'num-' + item.kind + item.id" v-model="item.num" :min="1" :max="99" :precision="0" :disabled="readonly" /></td><td>答卷完成当日</td><td><InputNumber :data-required-key="'days-' + item.kind + item.id" v-model="item.write_days" :min="1" :max="3650" :precision="0" :disabled="readonly" /></td><td>完成后第 {{ item.write_days }} 天</td><td><a v-if="!readonly" @click="remove(item)">删除</a></td></tr><tr v-if="!rows.length"><td colspan="7">未配置奖励，会员仍可填写问卷。</td></tr></tbody></table></div>
      <p v-if="error" role="status" aria-live="polite" class="survey-reward-error">{{ error }}</p>
      <div class="survey-reward-actions"><Button @click="$emit('close')">关闭</Button><Button v-if="!readonly" type="primary" :loading="saving" :disabled="loadingConfig" @click="save">保存奖励</Button></div>
      <Modal v-model="goodsModal" title="商品列表" footer-hide scrollable width="900"><goods-attr v-if="goodsModal" :flatSelect="true" :giftPickerTypes="[0, 6]" :giftListProvider="fetchProducts" @getProductId="addProducts" /></Modal>
      <coupon-list ref="couponTemplates" @nameId="addCoupons" />
    </div>
  </Modal>
</template>
<script>
import goodsAttr from '@/components/goodsAttr';
import couponList from '@/components/couponList';
import { surveyGifts, surveyUpdateGifts, questionnaireGifts, questionnaireUpdateGifts, surveyGiftOptions, surveyGiftCatalog } from '@/api/memberSurvey';
import { guideRequired, clearRequiredGuidance } from '@/utils/requiredGuidance';
const empty = () => ({ store_id: 0, reward_each_round: true, product: [], coupon: [] });
export default {
  name: 'MemberSurveyReward', components: { goodsAttr, couponList }, props: { visible: Boolean, surveyId: Number, questionnaireId: Number, readonly: Boolean, inherited: Boolean },
  data: () => ({ form: empty(), stores: [], goodsModal: false, saving: false, loadingConfig: false, loadEpoch: 0, error: '' }),
  computed: { rows() { return this.form.product.concat(this.form.coupon); } },
  watch: { visible(open) { if (open) this.load(); else { this.loadEpoch++; this.goodsModal = false; clearRequiredGuidance(); } }, surveyId() { if (this.visible) this.load(); }, questionnaireId() { if (this.visible) this.load(); } },
  beforeDestroy() { clearRequiredGuidance(); },
  methods: {
    // 请求身份随弹窗切换失效，迟到的旧任务配置不得覆盖当前任务。
    async load() { const epoch = ++this.loadEpoch; this.loadingConfig = true; this.error = ''; try { const [config, options] = await Promise.all([this.questionnaireId ? questionnaireGifts(this.questionnaireId) : surveyGifts(this.surveyId), surveyGiftOptions(0)]); if (epoch !== this.loadEpoch || !this.visible) return; this.form = { ...empty(), ...config.data }; ['product', 'coupon'].forEach(kind => { this.form[kind] = (this.form[kind] || []).map(row => ({ ...row, kind })); }); this.stores = options.data.stores || []; } catch (e) { if (epoch === this.loadEpoch) this.error = e.msg || e.message || '奖励加载失败'; } finally { if (epoch === this.loadEpoch) this.loadingConfig = false; } },
    // 问卷选品只读取所选发放门店的可赠目录，保存及发奖时后端还会再次校验SKU。
    fetchProducts(filters) { return surveyGiftCatalog(this.form.store_id, filters); },
    changeStore() { if (this.loadingConfig) return; this.form.product = []; this.form.coupon = []; this.goodsModal = false; },
    openProducts() { if (!this.form.store_id) return guideRequired(this.$refs.formArea, 'store', '请在这里选择奖励发放门店'); this.goodsModal = true; },
    openCoupons() { if (!this.form.store_id) return guideRequired(this.$refs.formArea, 'store', '请在这里选择奖励发放门店'); this.$refs.couponTemplates.isTemplate = true; this.$refs.couponTemplates.tableList(); },
    addProducts(selected) { this.goodsModal = false; selected.forEach(item => { const sku = (item.attrValue || [])[0]; const type = Number(item.product_type); if (![0, 6].includes(type) || !sku || !Number(sku.id)) { this.error = '所选品项缺少可赠规格，请换一个品项'; return; } const id = Number(sku.id); if (!this.form.product.some(row => Number(row.id) === id)) this.form.product.push({ id, product_type: type, store_name: item.store_name, num: 1, write_days: 30, kind: 'product' }); }); },
    addCoupons(ids, names = []) { const list = names.length ? names : (this.$refs.couponTemplates.couponList || []); ids.forEach(id => { const item = list.find(row => Number(row.id) === Number(id)); if (item && !this.form.coupon.some(row => Number(row.id) === Number(id))) this.form.coupon.push({ id: Number(id), store_name: item.title, num: 1, write_days: 30, kind: 'coupon' }); }); },
    remove(item) { this.form[item.kind] = this.form[item.kind].filter(row => row !== item); },
    async save() { if (this.rows.length && !this.form.store_id) { guideRequired(this.$refs.formArea, 'store', '请在这里选择奖励发放门店'); return; } for (const item of this.rows) { if (!Number.isInteger(item.num) || item.num < 1 || item.num > 99) return guideRequired(this.$refs.formArea, 'num-' + item.kind + item.id, '请在这里填写1至99的整数数量'); if (!Number.isInteger(item.write_days) || item.write_days < 1 || item.write_days > 3650) return guideRequired(this.$refs.formArea, 'days-' + item.kind + item.id, '请在这里填写1至3650的有效天数'); } this.saving = true; this.error = ''; try { const payload = { sendAll: this.form }; if (this.questionnaireId) await questionnaireUpdateGifts(this.questionnaireId, payload); else await surveyUpdateGifts(this.surveyId, payload); this.$emit('saved'); this.$emit('close'); } catch (e) { this.error = e.msg || e.message || '保存失败，请重试'; } finally { this.saving = false; } }
  }
};
</script>
<style scoped>
.survey-reward-search{display:flex;gap:16px;margin:16px 0}.survey-reward-table-scroll{overflow-x:auto}.survey-reward-table{width:100%;min-width:790px;border-collapse:collapse;margin-top:16px}.survey-reward-table th,.survey-reward-table td{height:48px;padding:8px;border:1px solid #e1e8ef;text-align:center}.survey-reward-table th{background:#f1f4f8}.survey-reward-table .ivu-input-number{width:90px}.survey-reward-actions{display:flex;justify-content:flex-end;gap:12px;margin-top:20px}.survey-reward-error{color:#b92b2b}
</style>
