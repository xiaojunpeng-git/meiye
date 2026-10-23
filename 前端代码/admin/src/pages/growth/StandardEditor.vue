<template>
  <div class="growth-modal">
    <section class="growth-dialog" role="dialog" aria-modal="true">
      <header class="growth-dialog-head">
        <h2>检查与评分标准</h2>
        <button :disabled="busy" @click="$emit('close')">关闭</button>
      </header>
      <div class="growth-dialog-body">
        <p v-if="error" class="growth-error">{{ error }}</p>
        <label>标准名称</label><input v-model="form.title" />
        <div class="growth-form-grid">
          <div>
            <label>评价方式</label
            ><select v-model="form.mode">
              <option value="pass">逐项通过制</option>
              <option value="score">评分制</option>
            </select>
          </div>
          <div v-if="form.mode === 'score'">
            <label>总分计算</label
            ><select v-model="form.calculation">
              <option value="sum">分项直接求和</option>
              <option value="proportion">按有效项比例折算</option>
            </select>
          </div>
          <div v-if="form.mode === 'score' && form.calculation === 'proportion'">
            <label>折算满分</label><input v-model.number="form.max_score" type="number" min="1" />
          </div>
          <div v-if="form.mode === 'score'">
            <label>及格线</label><input v-model.number="form.pass_score" type="number" min="0" />
          </div>
        </div>
        <section v-for="(item, i) in form.items" :key="item.key" class="growth-chapter">
          <div class="growth-actions">
            <strong>检查项 {{ i + 1 }}</strong
            ><button @click="form.items.splice(i, 1)">移除</button>
          </div>
          <label>检查内容</label><input v-model="item.title" /><label>标准说明</label
          ><textarea v-model="item.description" rows="2"></textarea
          ><label v-if="form.mode === 'score'">单项满分</label
          ><input v-if="form.mode === 'score'" v-model.number="item.max_score" type="number" min="1" />
          <div class="growth-form-grid">
            <label><input v-model="item.required" type="checkbox" />必评项</label
            ><label
              ><input
                v-model="item.critical"
                type="checkbox"
                @change="item.allow_na = false"
              />关键项，失败则整体不通过</label
            ><label
              ><input
                v-model="item.allow_na"
                type="checkbox"
                :disabled="item.critical"
              />允许说明不适用</label
            ><label><input v-model="item.evidence_required" type="checkbox" />必须提供证据</label>
          </div>
        </section>
        <button style="margin-top: 16px" @click="add">＋ 添加检查项</button>
      </div>
      <footer class="growth-dialog-foot">
        <button :disabled="busy" @click="$emit('close')">取消</button
        ><button class="growth-primary" :disabled="busy" @click="publish">确认发布标准</button>
      </footer>
    </section>
  </div>
</template>
<script>
import { growthWrite } from '@/api/growth';
/** 统一检查表配置，关键项不能通过不适用绕过；计算由后端执行。 */
export default {
  name: 'GrowthStandardEditor',
  props: { purpose: { type: String, default: 'evaluation' }, menu: { type: String, default: 'practice' } },
  data: () => ({
    form: { title: '', mode: 'pass', calculation: 'sum', max_score: 100, pass_score: 60, items: [] },
    busy: false,
    error: ''
  }),
  created() {
    this.add();
  },
  methods: {
    add() {
      this.form.items.push({
        key: `item-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
        title: '',
        description: '',
        max_score: 10,
        required: true,
        critical: false,
        allow_na: false,
        evidence_required: false
      });
    },
    async publish() {
      this.busy = true;
      this.error = '';
      try {
        await growthWrite('standard/publish', { ...this.form, purpose: this.purpose, menu: this.menu });
        this.$emit('saved');
      } catch (e) {
        this.error = e.msg || e.message;
      } finally {
        this.busy = false;
      }
    }
  }
};
</script>
