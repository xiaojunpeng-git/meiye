<template>
  <Drawer
    ref="drawer"
    width="90%"
  	title='收银台'
	  :class-name="isRecharge?'recharge-drawer':'settle-drawer'"
    :value="visible"
    @on-visible-change="visibleChange"
  >
    <div class="acea-row row-between">
      <div class="left-handle">
        <div class="sub-left-pay-type">
        <div class="left-pay-type-title">
<!--          <div class="pay-type-title">选择收款方式</div>-->
          <div class="pay-type-title">添加收款方式
            <div class="sub-title">同一收款方式可添加多次</div>
          </div>
            <div class="combine-pay-switch">
            <span class="handle-title">组合收款</span>
            <Switch @on-change="setCombinationPay" :disabled="forceCombinationPay" size="large" v-model="combinationPay" :false-value="0" :true-value="1"></Switch>
          </div>
        </div>
          <div class="left-pay-type-content">
            <div class="order_time_out">
              <div class="combine-pay-switch">
                <span class="handle-title">补单</span>
                <Switch  size="large" v-model="budan" :false-value="0" :true-value="1"></Switch>
              </div>
              <DatePicker v-if="budan == 1" class="order_time" format="yyyy/MM/dd HH:mm:ss" @on-change="changeBudanTime"  :transfer='true'  type="datetime" placeholder="设置下单时间" />
            </div>
            <div class="pay-title charge-type-title" v-if="!lockDebtRepaySource && this.type !='yue'">
              <div class="recommend-type-title-icon">
                <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACgAAAAoCAYAAACM/rhtAAABQklEQVRYR2NkGOSAcZC7jwGnA7tj/yv++fvb9R8DoxQtPcHE8P8ZCzPr7tLFjPex2YPVge1RfxL+//s/4z/Df3ZaOg5mNiMD409GJsaMymUsC9Dtw3AgKOR+//5znV6OQ3YkKyuLJnpIYjiwPepX2r9/DDNBGsXkGBnUzZhoGog3T/1jePXoP9gOJiaG9MplbLOQLcRwYGvU7waGf//rQYpsQ5gZbINp68DDa/8xHF7zF+ImJsbG6mWsDaMOJCVNjIYgKaGFTe1oCI6GIHoIEFsOtkX+JinwqpazYlVPszQ46kBoeI9WdYQSKs3SICGLiZUfdSCxIYVLHc1CcLSYobSYGfQhSGnag+mnWRocdSClaXA0BEdDkMg0MJqLiQwonMpIDsFBP3g06IffQHExqAcwYYllUA8BU5rwqal/0I/yAwDye9o4y13FbAAAAABJRU5ErkJggg==" alt="记账收款"></div>
               <div class="charge-type-title-text">来源</div>
            </div>
            <div class="pay-type-list charge-type-list" v-if="!lockDebtRepaySource && this.type !='yue'">
              <div v-for="(item,index) in sourceType" :class="[source== item.type ?'item-active':'']" class="src-pages-cashier-components-select-card-index__style combine-item combine-item_top" @click="chooseSource(item)">
                <div>
                  <p class="card-title">{{ item.name }}</p>
                </div>
              </div>
            </div>
<!--          <div class="pay-title recommend-type-title" v-if="!isRecharge && combinationPay == 0">-->
<!--            <div class="recommend-type-title-icon">-->
<!--              <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACgAAAAoCAYAAACM/rhtAAACAElEQVRYR+2YPUjDQBTH3+XDSQfdKrpYxNGKi7joLIiTH1FXF0UnaaEFB8GCxUnRxVWNpYsiONuluIg6itSlYrc66NR8nCQxMSWpvaRNuCGZQnj38rv/5X/vXhBQfiHK+SACbHeFXBXcX8EDiirlMKBpwDjW7kv08QhVEeA7luGTqXP0TprTAajByYr8DID7SJN4i0M1juVGSSEdgFmhfoExCN5e6i0aIRDTYtcyySgH4J4gfZjLupjiIJ7ojI/KjxjyOdlgQqiaEfl+f4BLdWwOTIs8SQ7imKwgWbGZyy6imTsVpBnQMIhUIZakk4FNXG4pGLx7SWfT6HILMAz3EiPaXG4BBuVeUqhmLv8DDNAcpJBuLo8ASdXT4iIF7WpVXjDc36hQfTMKU2wIwcQsA4MjzQtIaAqWrlUo5lXA2KqaRglmEEwtMDA5x7iufCiAmnJnuwpgtRHOJNIgV3dYVyVDASwcKPD6oOo88QQDM2usfn97qkD5yXg+PM7A/Lbx3H6FAni4LsP3p6He5jEPPb/H3q8awNGGcZrp7kWwdcJFgK4fOvVLTL1JNFlLVyoUC6rDyVRsM+a6U71Re6m/oW8zfuGiw0I7yplj/68k9oY9yUF8jKht7QSXnqPlkZ/6pon6tlOTOZDfbqQfQavGnTRP2HHhOsHH7CJAH6I1DPkBLSVxOJxYmBUAAAAASUVORK5CYII=" alt="推荐收款">-->
<!--            </div>-->
<!--            <div class="recommend-type-title-text">推荐收款</div>-->
<!--          </div>-->
          <div class="pay-type-list recommend-type-list" v-if="!isRecharge && (combinationPay == 0 || lockDebtRepaySource)">
            <div v-for="(item,index) in list" v-if="item.id == 1 && isRecharge" :class="combinationPay == 0 && activePay == item.id ?'item-active':''" @click="handChoose(item,index)" class="src-pages-cashier-components-select-card-index__style combine-item">
              <div>
                <p class="card-title">{{ item.label }}</p>
                <p class="card-desc"></p></div>
            </div>
            <div v-for="(item,index) in list" v-if="item.id == 3 && !isRecharge && !hideYuePayOption && (combinationPay == 0 || lockDebtRepaySource)" :class="combinationPay == 0 && activePay == item.id ?'item-active':''" @click="handChoose(item,index)" class="src-pages-cashier-components-select-card-index__style combine-item">
              <div>
                <p class="card-title">{{ item.label }}</p>
                <p class="card-desc"></p></div>
            </div>
          </div>
          <div class="pay-title charge-type-title">
            <div class="recommend-type-title-icon">
          <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACgAAAAoCAYAAACM/rhtAAABQklEQVRYR2NkGOSAcZC7jwGnA7tj/yv++fvb9R8DoxQtPcHE8P8ZCzPr7tLFjPex2YPVge1RfxL+//s/4z/Df3ZaOg5mNiMD409GJsaMymUsC9Dtw3AgKOR+//5znV6OQ3YkKyuLJnpIYjiwPepX2r9/DDNBGsXkGBnUzZhoGog3T/1jePXoP9gOJiaG9MplbLOQLcRwYGvU7waGf//rQYpsQ5gZbINp68DDa/8xHF7zF+ImJsbG6mWsDaMOJCVNjIYgKaGFTe1oCI6GIHoIEFsOtkX+JinwqpazYlVPszQ46kBoeI9WdYQSKs3SICGLiZUfdSCxIYVLHc1CcLSYobSYGfQhSGnag+mnWRocdSClaXA0BEdDkMg0MJqLiQwonMpIDsFBP3g06IffQHExqAcwYYllUA8BU5rwqal/0I/yAwDye9o4y13FbAAAAABJRU5ErkJggg==" alt="记账收款"></div>
            <div class="charge-type-title-text">记账收款</div>
          </div>
          <div class="pay-type-list charge-type-list">
           <div v-for="(item,index) in displayCashType" :class="combinationPay == 0 && activePay == 2 &&  cashChoose== item.type ?'item-active':''" class="src-pages-cashier-components-select-card-index__style combine-item" @click="doCash(item)">
              <div>
                   <p class="card-title">{{ item.name }}</p><p class="card-desc">记账收款</p>
               </div>
          </div>
          </div>
         </div>
        </div>
        <div class="sub-right-pay-result">
          <div class="center-details-single">
        <div class="app-pay-container ">
        <div class="pay-title">
                <div class="pay-type" v-if="combinationPay == 1"><span>总计</span></div>
                <div class="pay-type" v-else>
                  <span>{{ payLabel }}</span>
                  <span class="remark" v-if="activePay == 2 && remarkInfo.remark == ''" @click="showRemark">备注</span>
                  <span class="remark" v-if="activePay == 2 && remarkInfo.remark != ''" @click="showRemark">修改备注</span>
                </div>
                <div class="pay-count">￥<span>{{ money }}</span></div>
              <div v-if="activePay == 3 && combinationPay == 0">
                   <div class="text-wlll-303133 mt-12 fs-16 ml-16" v-if="nowMoney>= Number(money)">当前会员余额：{{nowMoney}}，支付后剩余：{{this.$computes.Sub(nowMoney, Number(money) || 0)}}元</div>
                   <div class="text-wlll-E93323 mt-12 fs-16 ml-16" v-else>当前会员余额：{{nowMoney}}，余额不足，请切换支付方式</div>
              </div>
              <div v-if="combinationPay == 1">
                    <div class="text-wlll-303133 mt-12 fs-16 ml-16">当前会员余额：{{nowMoney}}元</div>
               </div>
         </div>
          <div v-if="combinationPay == 0 && activePay == 1">
            <div class="text-center fs-20 text-wlll-303133 mt-30 fw-500">扫码收款<span class="ml-4">¥{{money}}</span></div>
            <div class="w-316 h-46 auto mt-30">
              <Input
                  ref="input"
                  :key="type"
                  v-model.trim="payNum"
                  :placeholder="activePay==3?'请聚焦扫描用户会员码或输入会员编码':'请聚焦扫描微信/支付宝付款码'"
                  @on-enter="payFun"
              ></Input>
            </div>
            <div class="w-240 h-155 auto mt-46 relative">
              <img src="@/assets/images/payImg.png" class="w-full h-full"/>
              <div class="mask acea-row row-center-wrapper" v-if="payIng">
                <span class="iconfont iconic_loading fs-23 mr-5"></span>正在支付
              </div>
            </div>
          </div>
          <div v-if="combinationPay == 0 && activePay == 2" class="zent-loading zent-loading--block zent-loading--has-children" style="height: initial;">
            <div class="code-pay-box"><img src="../../assets/images/jizhang.png" style="width: 206px; height: 206px;">
            </div>
          </div>
          <div class="combine-pay-list" v-if="combinationPay == 1">
            <div class="combine-pay-item aeuida6yha4a" v-for="(item,index) in combination_info">
              <div class="pay-item-box">
                <div class="pay-item-title">{{ item.name }}</div>
                <div class="pay-item-detail" v-if="item.activePay == 2 || item.activePay == 3">
                  <div class="zent-input-wrapper zent-input--size-normal zent-number-input">
                    <input
                      v-model="item.price"
                      :disabled="item.activePay == 3 && (lockYueEdit || item.pay_sub_type === 'card_upgrade' || item.pay_sub_type === 'debt')"
                      @input="inputZhi(item)"
                      class="zent-input"
                      autocomplete="off"
                      placeholder="输入金额"
                      type="text"
                    ></div>
                    <div
                      class="del-butn"
                      v-if="!(item.activePay == 3 && (lockYueEdit || item.pay_sub_type === 'card_upgrade' || item.pay_sub_type === 'debt'))"
                      @click="delCombination(index)"
                    >
                      <img class="del-img" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAACFklEQVRYR82XsWsUQRTGv28OlUSxUyR43q4rFhZ2gmWsEiwEa/8CU9qKMYnY2hn/AmtBEOySMmAZEJS7291cCKJdogkq2U/m7vbYJLu3e+Fk7sq99973m/dm3rwhKv4knY2izr1EyQMRtyjMCJyx7oR2ROxQ+Gxo3ntefY3knyqhWWYUht+vJNhflPAIwMUy+/7/uyTeGkyv+P7lb8N8CgEknQvjzlNJTySdryh8xIzkL5Kv/Eb9JcnfeTFyAeyqD7H/DsLd0wif8CE2aph+mJeNEwDtTud28jf5AOjqWMQHQbjNM+Z+UK9vZuMeAeiuXAefxi+eSnK7xqk72UwMAGzNW1G8Pra0F6WP2Ai8xmy6JwYA7WhrJUmSZ+NNe340Y8yL6961xd4RBtA7agfNot1OctnaSXpeBbDM3p4Og6kbthRdgFYYrUp4nHtMyOXAbyz17OKlMggrXsWexJvA9xZoO1wrjH8UNZlswDKIUWwB7AZ+4xLDcGvuUMnHod0qs6oiiBHFu3I1mnm22tFrAQtltR0mcBrx/gZcZTOM1iDMlgF0HXIyYb+nNS8rUU6HXLcZ+CLgZhWAPIisX5VNeqwLfmWzHe8BulAVoAhiVPGeHn86BsDeBJRgAjah22PovBE5b8XOL6OJuI4thNOBpD9suB3J0lI4G0rTu8DpWJ5COH2YpBBOn2bZq9nZ4/T4fPC/nuf/AK3G8xosaoxiAAAAAElFTkSuQmCC" alt="删除">
                    </div>
                   <div class="add-remark-butn" @click="setCombinationRemark(item,index)" v-if=" item.remarkInfo.remark != ''">修改备注</div>
                   <div class="add-remark-butn" @click="setCombinationRemark(item,index)" v-if="item.remarkInfo.remark == ''">备注</div>
                </div>
                <div class="pay-item-detail" v-else>
                  <div class="zent-input-wrapper zent-input--size-normal zent-number-input">
                     <input v-model="item.price" :disabled="item.is_pay"  @input="inputZhi(item)" class="zent-input" autocomplete="off" placeholder="0.00" type="text"></div>
                      <div class="butn-Qrcode" v-if="!item.is_pay" @click="showSao(index)">扫码</div>
                      <div class="del-butn" v-if="!item.is_pay" @click="delCombination(index)">
                       <img  class="del-img" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAACFklEQVRYR82XsWsUQRTGv28OlUSxUyR43q4rFhZ2gmWsEiwEa/8CU9qKMYnY2hn/AmtBEOySMmAZEJS7291cCKJdogkq2U/m7vbYJLu3e+Fk7sq99973m/dm3rwhKv4knY2izr1EyQMRtyjMCJyx7oR2ROxQ+Gxo3ntefY3knyqhWWYUht+vJNhflPAIwMUy+/7/uyTeGkyv+P7lb8N8CgEknQvjzlNJTySdryh8xIzkL5Kv/Eb9JcnfeTFyAeyqD7H/DsLd0wif8CE2aph+mJeNEwDtTud28jf5AOjqWMQHQbjNM+Z+UK9vZuMeAeiuXAefxi+eSnK7xqk72UwMAGzNW1G8Pra0F6WP2Ai8xmy6JwYA7WhrJUmSZ+NNe340Y8yL6961xd4RBtA7agfNot1OctnaSXpeBbDM3p4Og6kbthRdgFYYrUp4nHtMyOXAbyz17OKlMggrXsWexJvA9xZoO1wrjH8UNZlswDKIUWwB7AZ+4xLDcGvuUMnHod0qs6oiiBHFu3I1mnm22tFrAQtltR0mcBrx/gZcZTOM1iDMlgF0HXIyYb+nNS8rUU6HXLcZ+CLgZhWAPIisX5VNeqwLfmWzHe8BulAVoAhiVPGeHn86BsDeBJRgAjah22PovBE5b8XOL6OJuI4thNOBpD9suB3J0lI4G0rTu8DpWJ5COH2YpBBOn2bZq9nZ4/T4fPC/nuf/AK3G8xosaoxiAAAAAElFTkSuQmCC" alt="删除">
                     </div>
                     <div class="successOut" v-else>
                       <img src="../../assets/images/successPay.png" class="sucess-img">
                     </div>
                </div>
              </div>
            </div>
            <div class="empty-combine" v-if="combination_info.length == 0">
              <img src="../../assets/images/shouyin1.png" alt="textTasks" class="text-tasks">
              <div class="text-tasks-add">请从左侧添加收款方式</div>
            </div>
          </div>
        </div>
         </div>
          <div class="footer-butns">
              <button v-if="combinationPay == 1 && combination_info.length == 0" type="button" class="zent-btn-primary zent-btn-disabled zent-btn" disabled>确认收款</button>
              <button v-if="combinationPay == 1 && combination_info.length > 0" @click="payFun" type="button" class="zent-btn-primary zent-btn">确认收款</button>
              <button v-if="activePay == 2 && combinationPay == 0" @click="payFun" type="button" class="zent-btn-primary zent-btn">确认收款</button>
              <button v-if="combinationPay == 0 && nowMoney < Number(money) && activePay == 3" type="button" class="zent-btn-primary zent-btn-disabled zent-btn" disabled>确认收款</button>
              <button v-if="activePay == 3 && combinationPay == 0 && nowMoney >= Number(money)" @click="payFun" type="button" class="zent-btn-primary zent-btn">确认收款</button>
          </div>
      </div>
      </div>
      <div style="margin-left: 17px;width: 380px" class="ticket bg-w111-FAFAFA rd-4 pl-40 pr-40 pt-40 text-wlll-303133 relative" v-if="isRecharge==0">
        <div class="text-center fs-16 mb-30 fw-500">交易明细</div>
        <div class="acea-row row-between-wrapper mb-26">
          <div>商品总额</div>
          <div>¥{{ priceInfo.sumPrice || 0 }}</div>
        </div>
        <div class="acea-row row-between-wrapper mb-26">
          <div>积分抵扣</div>
          <div>-¥{{ priceInfo.deductionPrice || 0 }}</div>
        </div>
        <div class="acea-row row-between-wrapper mb-26">
          <div>优惠券抵扣</div>
          <div>-¥{{ priceInfo.couponPrice || 0 }}</div>
        </div>
        <div class="acea-row row-between-wrapper mb-26">
          <div>会员优惠</div>
          <div>-¥{{ priceInfo.vipPrice || 0 }}</div>
        </div>
        <div class="acea-row row-between-wrapper mb-26" v-if="priceInfo.firstOrderPrice > 0">
          <div>首单优惠</div>
          <div>-¥{{ priceInfo.firstOrderPrice || 0 }}</div>
        </div>
        <div class="acea-row row-between-wrapper mb-26"
             v-for="(item, index) in priceInfo.promotionsDetail"
             :key="index"
        >
          <div>{{ item.title }}：</div>
          <div>-¥{{ item.promotions_price || 0 }}</div>
        </div>
        <div class="item acea-row row-between-wrapper mb-26" v-if="submitData.payPrice">
          <div>改价优惠：</div>
          <div>-¥{{ $computes.Sub(submitData.payPrice,submitData.resultPayPrice) }}</div>
        </div>
        <div class="acea-row row-between-wrapper mb-26 pt-26 border-dashed-top-1-CCCCCC">
          <div>应付金额</div>
          <div class="text-wlll-FF7700">¥{{money}}</div>
        </div>
        <div class="w-303 h-10 absolute bottom-f16 left-0"><img class="w-full h-full" src="../../assets/images/juchi.png"/></div>
      </div>
    </div>
    <Modal  :z-index="99999999"  v-model="setRemark" footer-hide  title="备注" class="order_box"  width="516" @on-cancel="cancelRemark">
          <form class="zent-form zent-form--horizontal">
             <div class="zent-form__control-group zent-form__control-group--active reamrk-num">
               <label class="zent-form__control-label">外部流水号:</label>
               <div class="zent-form__controls">
                 <div class="zent-self zent-input-wrapper zent-input--size-normal">
                   <input v-model="remarkInfo.water_number" class="zent-input" name="remarkNum" placeholder="请填写外部流水号，50字内" type="text" value=""></div>
               </div>
             </div>
            <div class="zent-form__control-group reamrk-text">
            <label class="zent-form__control-label">备注:</label>
            <div class="zent-form__controls">
              <div class="zent-input-wrapper zent-textarea-wrapper zent-input--size-normal">
               <textarea v-model="remarkInfo.remark" class="zent-self zent-textarea zent-textarea-with-count" name="remarkText" placeholder="请填写要备注的内容，200字内" maxlength="200"></textarea>
                <span class="zent-textarea-count">{{ remarkInfo.remark.length }}/200</span>
              </div>
            </div>
            </div>
             <div class="zent-form-actions">
            <button type="button" class="zent-btn" @click="cancelRemark">取消</button>
            <button type="button" class="zent-btn-primary zent-btn submit" @click="saveRemark">确认</button></div>
          </form>
    </Modal>
    <Modal :z-index="99999999" v-model="show_combination_remark" @on-cancel="cancelCombinationRemark" footer-hide  title="备注"   width="516">
      <form class="zent-form zent-form--horizontal" v-if="combination_remark[remarkIndex]">
        <div class="zent-form__control-group zent-form__control-group--active reamrk-num">
          <label class="zent-form__control-label">外部流水号:</label>
          <div class="zent-form__controls">
            <div class="zent-self zent-input-wrapper zent-input--size-normal">
              <input v-model="combination_remark[remarkIndex].water_number" class="zent-input" name="remarkNum" placeholder="请填写外部流水号，50字内" type="text" value=""></div>
          </div>
        </div>
        <div class="zent-form__control-group reamrk-text">
          <label class="zent-form__control-label">备注:</label>
          <div class="zent-form__controls">
            <div class="zent-input-wrapper zent-textarea-wrapper zent-input--size-normal">
              <textarea v-model="combination_remark[remarkIndex].remark" class="zent-self zent-textarea zent-textarea-with-count" name="remarkText" placeholder="请填写要备注的内容，200字内" maxlength="200"></textarea>
              <span class="zent-textarea-count">{{ combination_remark[remarkIndex].remark.length }}/200</span>
            </div>
          </div>
        </div>
        <div class="zent-form-actions">
          <button type="button" class="zent-btn" @click="cancelCombinationRemark">取消</button>
          <button type="button" class="zent-btn-primary zent-btn submit" @click="saveCombinationRemark">确认</button></div>
      </form>
    </Modal>
    <Modal :z-index="9999999" v-model="payShow" footer-hide class-name="payStyle-modal vertical-center-modal">
      <div>
        <div class="text-center fs-20 text-wlll-303133 mt-30 fw-500">扫码收款
          <span class="ml-4">
                          <div class="pay-count">￥<span>{{ choosePay }}</span></div>
          </span>

        </div>
        <div class="w-316 h-46 auto mt-30">
          <Input
              ref="input"
              :key="type"
              v-model.trim="payNum"
              :placeholder="chooseActivePay==3?'请聚焦扫描用户会员码或输入会员编码':'请聚焦扫描微信/支付宝付款码'"
              @on-enter="combinationPayFun"
          ></Input>
        </div>
        <div class="w-240 h-155 auto mt-46 relative">
          <img src="@/assets/images/payImg.png" class="w-full h-full"/>
          <div class="mask acea-row row-center-wrapper" v-if="payIng">
            <span class="iconfont iconic_loading fs-23 mr-5"></span>正在支付
          </div>
        </div>
      </div>
    </Modal>
  </Drawer>
</template>

<script>
import { cashType,cashSource } from '@/api/order';
export default {
  model: {
    prop: 'visible',
    event: 'change',
  },
  props: {
    visible: {
      type: Boolean,
      default: false,
    },
    money: {
      type: [Number, String],
      default: 0,
    },
    collection: {
      type: [Number, String],
      default: 0,
    },
	priceInfo: {
		type: Object,
		default:{}
	},
    hasRemark: {
		type: Object,
		default:{}
	},
	nowMoney: {
		type: [Number, String],
		default:0
	},
	submitData: {
		type: Object,
		default:{}
	},
    zIndex: {
      type: [Number, String],
      default: 9999,
    },
    type: {
      type: String,
      default: '',
    },
    verify: {
      type: Boolean,
      default: false,
    },
    list: {
      type: Array,
      default() {
        return [];
      },
    },
	isRecharge: {
	  type: [Number],
	  default:0
	},
    hideYuePayOption: {
      type: Boolean,
      default: false
    },
    forceCombinationPay: {
      type: Boolean,
      default: false
    },
    initCombinationInfo: {
      type: Array,
      default() {
        return [];
      }
    },
    lockYueEdit: {
      type: Boolean,
      default: false
    },
    lockDebtRepaySource: {
      type: Boolean,
      default: false
    },
    initialSource: {
      type: [Number, String],
      default: 0
    },
  },
  data() {
    return {
      source:0,
      order_time:'',
      budan:0,
      remarkIndex:0,
      show_combination_remark:false,
      combination_remark:[],
      combination_info:[],
      remarkInfo:{
        water_number:'',
        remark:''
      },
      setRemark:false,
      payLabel:'微信/支付宝',
      activePay:1,
      cashChoose:'',
      sourceType:[],
      cashType:[],
      numList: ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '00', '.'],
      payNum: '',
      combinationPay:0,
      beforeIndex:0,
      before_cash_choose:0,
	   userInfoShow:false,
	  modalUserInfo:{},
	  codeType:'',
	  payShow:false,
     combination_info_index:0,
    choosePay:0,
    chooseActivePay:0,
	  payIng:false
    };
  },
  computed: {
    displayCashType() {
      if (this.combinationPay === 1) {
        return this.cashType.filter((item) => item.type !== 9);
      }
      return this.cashType;
    },
  },
  created() {
    this.money=Number(this.money);
    this.getCashType();
    this.getSourceType();
  },
  watch: {
    initialSource: {
      immediate: true,
      handler() {
        this.syncDebtRepaySource();
      },
    },
    lockDebtRepaySource(val) {
      if (val) {
        this.syncDebtRepaySource();
      }
    },
    type(value) {
      this.$nextTick(() => {
        if (!value || (value === 'yue' && this.verify)) {
          this.$refs.input.focus();
        }
      });
    },
    zIndex(value) {
      this.applyDrawerZIndex(value);
    },
  },
  methods: {
    applyDrawerZIndex(value) {
      const zIndex = value !== undefined ? value : this.zIndex;
      const drawerEl = this.$refs.drawer && this.$refs.drawer.$el;
      if (!drawerEl) return;
      const mask = drawerEl.querySelector('.ivu-drawer-mask');
      const wrap = drawerEl.querySelector('.ivu-drawer-wrap');
      if (mask) mask.style.zIndex = zIndex;
      if (wrap) wrap.style.zIndex = zIndex;
    },
    showSettleError(content) {
      this.$Message.error(content);
      this.$nextTick(() => {
        const messageEl = document.querySelector('.ivu-message');
        if (messageEl) {
          messageEl.style.zIndex = String(Number(this.zIndex || 9999) + 100);
        }
      });
    },
    syncDebtRepaySource() {
      if (!this.lockDebtRepaySource) {
        return;
      }
      const source = Number(this.initialSource || 0);
      this.source = source;
      this.$emit('changeSource', { source });
    },
    needSourceSelection() {
      if (this.lockDebtRepaySource) {
        return false;
      }
      if (this.type === 'yue') {
        return false;
      }
      if (Number(this.cashChoose) === 9) {
        return false;
      }
      return true;
    },
    getCashType(){
        cashType({}).then(res=>{
              this.cashType=res.data;
        })
    },
    getSourceType(){
      cashSource({}).then(res=>{
              this.sourceType=res.data;
        })
    },
    showSao(index){
       return false;
       let price=this.combination_info[index].price;
       if(price <= 0){
             return this.$Message.error('付款金额必须大于0');
       }
       if(price > this.nowMoney){
         return this.$Message.error('付款金额不能大于当前余额！');
       }
       this.combination_info_index=index;
       this.chooseActivePay=this.combination_info[index].activePay;
       this.choosePay=price;
       this.payShow=true;
    },
    setCombinationRemark(item,index){
        this.remarkIndex=index;
        this.show_combination_remark=true;
        this.combination_remark[index]=this.deepClone(item.remarkInfo);
    },
    delCombination(index){
       this.combination_info.splice(index,1);
    },
    inputZhi(item){
       if(item.price > Number(this.money)){
             item.price=Number(this.money);
       }
       var val=item.price;
      // 1. 先过滤掉 除了数字和小数点 以外的所有字符
      val = val.replace(/[^\d.]/g, '')
      // 2. 只保留第一个小数点，多个小数点自动过滤（比如 12..34 → 12.34）
      val = val.replace(/\.{2,}/g, '.')
      // 3. 如果输入框第一位就是小数点，自动补0（比如 .123 → 0.123，更规范）
      val = val.replace(/^\./g, '0.')
      // 4. 处理 0开头的多位整数 可选需求（比如 00123 → 0.123，金额输入框常用）
      val = val.replace(/^0+(\d)/, '$1')
      // 把过滤后的合法值，重新赋值给输入框，页面实时更新
        item.price = val
    },
    cancelCombinationRemark(){
       this.show_combination_remark=false;
    },
    saveCombinationRemark(){
      this.show_combination_remark=false;
      this.combination_info[this.remarkIndex].remarkInfo=this.deepClone(this.combination_remark[this.remarkIndex]);
    },
    cancelRemark(){
       this.setRemark=false
       var remark = this.deepClone(this.hasRemark);
       this.remarkInfo=remark;
    },
    deepClone(obj) {
      return JSON.parse(JSON.stringify(obj));
    },
    saveRemark(){
       this.setRemark=false;
      this.$emit('saveRemark', this.deepClone(this.remarkInfo));
    },
    showRemark(){
       this.setRemark=true;
    },
    chooseSource(source){
       if (this.lockDebtRepaySource) {
         return this.$Message.warning('补交订单来源须与原订单一致');
       }
       this.source=source.type;
      this.$emit('changeSource', {source:source.type});
    },
    getCombinationRemainAmount() {
      let allocated = 0;
      this.combination_info.forEach((item) => {
        allocated = Number(allocated) + Number(item.price || 0);
      });
      const remain = Number(this.money) - allocated;
      return remain > 0 ? remain : 0;
    },
    formatCombinationPrice(amount) {
      const num = Number(amount);
      if (!num || num <= 0) return '';
      return num.toFixed(2);
    },
    doCash(payType){
      if (this.combinationPay == 1 && payType.type == 9) {
        return this.$Message.error('组合支付不支持旧卡录入');
      }
      if(this.combinationPay == 1){
           const diffAmount = this.getCombinationRemainAmount();
           
           let obj={
               type:payType.type,
               activePay:2,
               price: this.formatCombinationPrice(diffAmount),
               is_pay:false,
               name:payType.name+'（记账收款）',
               remarkInfo:{
                 water_number:'',
                 remark:''
               }
           };
           this.combination_info.push(obj);
           this.typeChange(this.list[3]);
      }else{
        this.cashChoose=payType.type;
        this.payLabel=payType.name+'（记账收款）';
        this.beforeIndex=1;
        this.before_cash_choose=this.cashChoose;
        this.typeChange(this.list[1]);
      }
    },
    // 抽屉显示状态发生变化
    visibleChange(visible) {
      if (visible) {
        if (this.lockDebtRepaySource) {
          this.syncDebtRepaySource();
        } else {
          this.source = 0;
          this.$emit('changeSource', { source: 0 });
        }
        this.$nextTick(() => {
          this.applyDrawerZIndex();
		     // if(this.isRecharge == 0){
	      	// 	this.$refs.inputs.focus();
	    	 //  }
          if(this.type != 'combination') {
               this.combinationPay = 0;
          }
          if (this.forceCombinationPay) {
            this.combinationPay = 1;
            this.typeChange(this.list[3]);
          }
          if (this.initCombinationInfo && this.initCombinationInfo.length) {
            this.combination_info = this.deepClone(this.initCombinationInfo);
          } else {
            this.combination_info = [];
          }
        });
      } else {
        this.payNum = '';
        if (this.lockDebtRepaySource) {
          this.activePay = 0;
          this.payLabel = '请选择支付方式';
          this.cashChoose = 0;
        }
      }
      // this.budan=0;
      // this.budan_time='';
      this.$emit('change', visible);
    },
    setCombinationPay(e){
         if(this.forceCombinationPay){
           this.combinationPay = 1;
           return;
         }
         if(e == 1){
           this.combination_info = this.combination_info.filter((item) => item.type != 9);
           this.typeChange(this.list[3]);
         }else{
           this.cashChoose=this.before_cash_choose;
           this.typeChange(this.list[this.beforeIndex]);
         }
    },
    handChoose(item,index){
      if(this.combinationPay == 1) {
        const diffAmount = this.getCombinationRemainAmount();
        let obj = {
          type: 0,
          activePay: item.id,
          price: this.formatCombinationPrice(diffAmount),
          is_pay:false,
          name: item.label,
          remarkInfo: {
            water_number: '',
            remark: ''
          }
        };
        if (item.id === 3) {
          obj.pay_sub_type = 'balance';
        }
        this.combination_info.push(obj);
        this.typeChange(this.list[3]);
      }else {
        this.beforeIndex=index;
        this.typeChange(item);
      }
    },
    // 选择支付方式
    typeChange(item) {
      this.payNum = '';
   	  this.activePay = item.id;
       if(this.activePay != 2) {
         this.payLabel = item.label;
         this.cashChoose=0;
       }
      this.$emit('payPrice', {type:item.value,cashChoose:this.cashChoose});
	    if(item.id == 3 && this.nowMoney< Number(this.money) && this.combinationPay == 0){
	     	  return this.$Message.error('余额不足，请切换支付方式');
	    }
    },
	payMoney(){
		if(this.activePay==3 && this.nowMoney< Number(this.money)){
			return this.$Message.error('余额不足，请切换支付方式');
		}
		if(this.activePay==3 && this.verify==0){
			this.payFun();
		}else{
			this.payShow = true;
			this.$nextTick(() => {
			  this.$refs.input.focus();
			});
		}
	},
    numTap(item) {
      this.$emit('numTap', item);
    },
    delNum(type) {
      this.$emit('delNum', type);
    },
	checkUser(){
		let that = this;
		this.userInfoShow = false;
		this.$emit('getUserId',this.modalUserInfo);
		//用户id查询时，只查询用户，不用走支付接口
		if(!this.codeType){
			this.payNum = '';
			return false
		}
		setTimeout(function(){
			that.payFun();
		},500)
	},
    changeBudanTime(e){
       this.order_time=e;
    },
    combinationPayFun(){
         //发起支付
         //支付成功
         //锁定
       this.combination_info[this.combination_info_index].is_pay=true;
    },
  	payFun(){
    if (this.needSourceSelection() && (!this.source || this.source === '')) {
      return this.showSettleError('请选择来源！');
    }
    const activePay = Number(this.activePay);
    if (this.lockDebtRepaySource) {
      if (!activePay) {
        return this.showSettleError('请选择支付方式');
      }
    } else if (!this.payNum) {
      if (this.type === '' && activePay !== 2 && activePay !== 3) {
        if (activePay === 1) {
          return this.showSettleError('请扫描您的付款码');
        }
        return this.showSettleError('请选择支付方式');
      }
		}
		if (this.isURL(this.payNum)) {
		  this.payNum = this.getCodeFromLink(this.payNum);
		}
    if(this.combinationPay == 1) {
      const hasOldCardEntry = this.combination_info.some((item) => item.activePay == 2 && item.type == 9);
      if (hasOldCardEntry) {
        return this.$Message.error('组合支付不支持旧卡录入');
      }
      let total = 0;
      let yuePay=0;
      this.combination_info.forEach(function (item, index) {
        // activePay==3 既可能是“余额收款”，也可能是“卡升级”
        if(item.activePay == 3 && item.pay_sub_type !== 'card_upgrade' && item.pay_sub_type !== 'debt'){
          yuePay = Number(yuePay) + Number(item.price);
        }
        total = Number(total) + Number(item.price);
      })
      if (total != Number(this.money)) {
        return this.$Message.error('组合支付的累计金额需要等于总计金额');
      }
      if (yuePay > this.nowMoney) {
        return this.$Message.error('组合支付的累计余额支付金额'+yuePay+',超过当前会员余额：'+this.nowMoney);
      }
      const isOnlyBalance = this.combination_info.length > 0 && this.combination_info.every((item) => {
        return item.activePay == 3 && item.pay_sub_type !== 'card_upgrade';
      });
      if (this.combination_info.length < 2 && !isOnlyBalance) {
        return this.$Message.error('组合支付不能只有一种支付方式');
      }
    }
    this.$emit('saveCombinationinfo', this.combinationPay == 1 ? this.combination_info : []);
    this.$emit('setBudan',{is_budan:this.budan,budan_time:this.order_time});
		this.$emit('cashBnt', this.payNum);
		this.payNum = '';
	},
    // 判断字符串是否为URL
    isURL(str) {
      const pattern = /^(http|https):\/\/[^ "]+$/;
      return pattern.test(str);
    },
    // 从URL中提取参数code
    getCodeFromLink(link) {
      const url = new URL(link);
      const searchParams = new URLSearchParams(url.search);
      const code = searchParams.get('code');
      return code;
    },
    // 取消收款
    handleCancel() {
      this.$emit('change', false);
    },
  },
};
</script>

<style lang="stylus" scoped>
.pay-item-detail .successOut .sucess-img {
  width: 100%;
  height: 100%;
  cursor: pointer;
  vertical-align: middle;
}
.pay-item-detail .successOut {
  margin-left: 16px;
  width: 20px;
}
.pay-item-detail .butn-Qrcode {
  position: absolute;
  left: 184px;
  width: 74px;
  height: 38px;
  text-align: center;
  background: #f7f8fa;
  font-weight: 400;
  color: #646566;
  cursor: pointer;
  border-left: 1px solid #dcdee0;
  line-height: 38px;
  font-size: 16px;
  border-radius: 0px 4px 4px 0px;
}
.zent-input:focus{
  border-color: #8558fa;
  box-shadow: 0 0 0.02589rem 0 rgba(237, 234, 255, 0.2);
}
::placeholder {
  color: #c8c9cc; /* 你要修改的占位符颜色，例：浅灰色 */
  font-size: 12px; /* 可选：顺便修改占位符字号 */
  opacity: 1; /* 必加！解决部分浏览器默认透明度导致颜色偏浅的问题 */
}
:-moz-placeholder {
  color: #c8c9cc;
  opacity: 1;
}
::-moz-placeholder {
  color: #c8c9cc;
  opacity: 1;
}
:-ms-input-placeholder {
  color: #c8c9cc;
}
.zent-textarea{
  height: 54px  !important;
  padding: 5px 10px !important;
  -webkit-appearance: none;
  -moz-appearance: none;
  appearance: none;
  line-height: 1.5 !important;
}
.zent-textarea-wrapper.zent-input-wrapper {
  height: auto !important;
  max-height: none;
}
.zent-input-wrapper.zent-input--size-normal {
  height: 32px;
}
.zent-input-wrapper {
  display: flex;
  position: relative;
  box-sizing: border-box;
  border: 1px solid #dcdee0;
  border-radius: 2px;
  transition: border .2s ease-in-out, box-shadow .2s ease-in-out;
  overflow: hidden;
}
.zent-form-actions .submit {
  margin-left: 16px;
}
.zent-form-actions {
  display: flex;
  justify-content: center;
}
.zent-btn:not(.zent-btn-small):not(.zent-btn-large):not(.zent-pagination-arrow-button):not(.zent-pagination-page-number-button) {
  min-width: 74px;
}
.zent-textarea-count {
  display: inline-block;
  position: absolute;
  bottom: 2px;
  right: 15px;
  font-size: 10px;
  color: #969799;
}
.zent-textarea-with-count {
  padding-bottom: 21px;
}
.zent-textarea {
  height: 54px;
  padding: 5px 10px;
  -webkit-appearance: none;
  -moz-appearance: none;
  appearance: none;
  line-height: 1.5;
}
.zent-form--horizontal .zent-form__control-group {
  margin-bottom: 24px;
}
.zent-input, .zent-input[type=text], .zent-input[type=password], .zent-input[type=datetime], .zent-input[type=date], .zent-input[type=month], .zent-input[type=time], .zent-input[type=week], .zent-input[type=number], .zent-input[type=email], .zent-input[type=url], .zent-input[type=tel], .zent-input[type=color], .zent-input[type=search], .zent-textarea {
  display: inline-block;
  flex: 1;
  min-width: 80px;
  height: 100%;
  box-sizing: border-box;
  padding: 0 12px;
  margin: 0;
  color: #323233;
  font-size: 14px;
  box-shadow: none;
  -webkit-appearance: none;
  -moz-appearance: none;
  appearance: none;
  outline: none;
  border: 0;
}
.zent-form__controls {
  width: 360px !important;
}
.zent-form--horizontal .zent-form__control-label+.zent-form__controls {
  margin-left: 10px;
}
.zent-form--horizontal .zent-form__controls {
  display: inline-block;
  word-break: break-all;
  vertical-align: top;
}
.zent-form.zent-form--horizontal {
  padding: 16px;
  margin-bottom: 0;
}
.zent-form__control-label {
  width: 80px !important;
}
 .zent-form--horizontal .zent-form__control-group .zent-form__control-label {
   line-height: 32px;
 }
.zent-form--horizontal .zent-form__control-label {
  display: inline-block;
  width: 120px;
  font-size: 14px;
  line-height: 30px;
  text-align: right;
  vertical-align: top;
}
.zent-form--horizontal .zent-form__control-group {
  margin-bottom: 24px;
}
.remarkInput{
  border-radius: 0.02589rem;
  height: 0.2589rem;
  border: 0.006472rem solid #8558fa;
  box-shadow: 0 0 0.02589rem 0 rgba(237, 234, 255, 0.2);
  font-size: 0.116505rem;
  display: inline-block;
  flex: 1;
  min-width: 0.517799rem;
  padding: 0 0.07767rem;
  margin: 0;
  color: #323233;
  -webkit-appearance: none;
  -moz-appearance: none;
  appearance: none;
  outline: none;
}
.remark {
  font-size: 14px;
  font-weight: 400;
  color: #8558fa;
  line-height: 20px;
  cursor: pointer;
  margin-left: 6px;
}
.zent-btn-primary {
  color: #fff !important;
  background: #8558fa !important;
  border-color: #8558fa !important;
}
/deep/.ivu-switch-checked {
  border-color: #8558fa;
  background-color: #8558fa;
}
.combine-item{
  cursor: pointer;
}
.combine-item:hover {
  background-color: rgba(133,88,250, 0.1);
  color: #8558fa;
}
.zent-input{
  width: 258px;
  border-radius: 4px;
  height: 40px;
  border:1px solid #8558fa;
  box-shadow: 0 0 4px 0 rgba(237, 234, 255, 0.2);
  font-size: 18px;
  display: inline-block;
  flex: 1;
  min-width: 80px;
  padding: 0 12px;
  margin: 0;
  color: #323233;
  -webkit-appearance: none;
  -moz-appearance: none;
  appearance: none;
  outline: none;
}
.pay-item-detail .add-remark-butn {
  position: absolute;
  left: 100%;
  width: -moz-max-content;
  width: max-content;
  cursor: pointer;
  margin-left: 16px;
  font-size: 14px;
  font-weight: 400;
  color: #8558fa;
  line-height: 20px;
}
 .pay-item-detail .del-butn .del-img {
  width: 100%;
  height: 100%;
  cursor: pointer;
}
.pay-item-detail .del-butn {
  margin-left: 16px;
  width: 16px;
  height: 16px;
}
.pay-item-detail .zent-number-input {
  width: 258px;
  border-radius: 4px;
  height: 40px;
}
 .pay-item-box {
  width: 295px;
  display: inline-block;
  text-align: left;
}
.pay-item-detail {
  margin-top: 8px;
  display: flex;
  align-items: center;
  position: relative;
}
.pay-item-title {
  width: 256px;
  font-size: 14px;
  font-weight: 400;
  color: #323233;
  line-height: 20px;
}
.combine-pay-item {
  margin-top: 24px;
  text-align: center;
}
.combine-pay-item .pay-item-box {
  width: 295px;
  display: inline-block;
  text-align: left;
}
.zent-btn {
  display: inline-block;
  height: 32px;
  line-height: 30px;
  font-size: 14px;
  padding: 0 16px;
  border-radius: 2px;
  font-family: inherit;
  color: #323233;
  background: #fff;
  border: 1px solid #dcdee0;
  text-align: center;
  vertical-align: middle;
  box-sizing: border-box;
  cursor: pointer;
  transition: all .3s;
}
.zent-btn-disabled:hover, .zent-btn-disabled[disabled]:hover, .zent-btn-disabled {
  color: #c8c9cc !important;
  background: #f7f8fa !important;
  border-color: #ebedf0 !important;
}
.zent-btn:not(.zent-btn-small):not(.zent-btn-large):not(.zent-pagination-arrow-button):not(.zent-pagination-page-number-button) {
  min-width: 74px;
}
.sub-right-pay-result .footer-butns .zent-btn {
  width: 240px;
  height: 48px;
  font-size: 16px;
}
.sub-right-pay-result .footer-butns {
  height: 95px;
  flex-grow: 0;
  display: flex;
  align-items: center;
  border-top: 1px solid #ebedf0;
  justify-content: center;
  margin: 0 32px;
}
.combine-pay-list .empty-combine .text-tasks-add {
  font-size: 14px;
  font-weight: 400;
  color: #969799;
  line-height: 20px;
}
.combine-pay-list .empty-combine .text-tasks {
  width: 160px;
  height: 160px;
}
.combine-pay-list .empty-combine {
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.left-handle .sub-right-pay-result .center-details-combinePayFlag .combine-pay-list {
  padding-left: 32px;
  height: calc(100% - 77px);
  overflow: auto;
  padding-bottom: 24px;
}
.pay-count span {
  font-size: 36px;
}
.pay-count {
  font-weight: 500;
  line-height: 46px;
  font-size: 24px;
  color: #ff2278;
  margin-bottom: 0px;
}
.sub-right-pay-result .center-details-single>div .pay-title .pay-type {
  font-size: 14px;
  font-weight: 400;
  color: #323233;
  line-height: 20px;
}
.center-details-single>div .pay-title {
  margin-bottom: 0px;
  text-align: center;
}
.center-details-single>div {
  padding: 24px 32px 0 32px;
  width: calc(100% - 64px);
  height: calc(100% - 24px);
  position: relative;
}
.center-details-single {
  flex-grow: 0;
  height: 70%;
}
.code-pay-box{
  text-align: center;
}
.combine-pay-list{
  height: 380px;
  overflow: scroll;
}
.code-pay-box>p, .app-pay-container .qr-pay-box>p {
  height: 28px;
  font-size: 16px;
  line-height: 28px;
  margin: 16px 0 32px;
  color: #969799;
}
.left-handle .sub-right-pay-result {
  background-color: #fff;
  margin-left: 16px;
  width: 100%;
  min-width: 500px;
  display: flex;
  flex-direction: column;
}
.pay-type-title .sub-title {
  font-size: 12px;
  font-weight: 400;
  color: #969799;
  line-height: 18px;
  position: absolute;
}
.src-pages-cashier-components-select-card-index__style {
  width: 160px;
  height: 72px;
  background: #f7f8fa;
  border-radius: 4px;
  margin: 16px 16px 0 0;
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
  cursor: pointer;
}
.combine-item_top{
   width: 110px !important;
   height: 40px !important;
  margin: 16px 5px 0 0;
}
.src-pages-cashier-components-select-card-index__style p.card-desc {
  font-size: 12px;
  line-height: 22px;
}
.src-pages-cashier-components-select-card-index__style p.card-title {
  font-weight: bold;
  font-size: 14px;
  line-height: 20px;
  overflow: hidden;
  text-overflow: ellipsis;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  word-break: break-all;
}
.src-pages-cashier-components-select-card-index__style.item-active .icon {
  display: block;
}

.src-pages-cashier-components-select-card-index__style .icon {
  position: absolute;
  right: 0;
  bottom: 0;
  display: none;
}
.item-active {
  position: relative;
}
.src-pages-cashier-components-select-card-index__style.item-active>div {
  padding: 0 22px;
}
.src-pages-cashier-components-select-card-index__style.item-active {
  border: 1px solid #8558fa;
  background-color: #fff;
  color: #8558fa;
  width: 158px;
  height: 70px;
}
.left-pay-type-content .pay-type-list {
  display: flex;
  flex-wrap: wrap;
}
.recommend-type-title-icon img {
  width: 100%;
  height: 100%;
}
.recommend-type-title-icon {
  width: 20px;
  height: 20px;
  margin-right: 8px;
}
.left-pay-type-content .pay-title {
  font-size: 14px;
  font-weight: 400;
  color: #323233;
  line-height: 20px;
  margin-top: 24px;
  display: flex;
}
.sub-left-pay-type .left-pay-type-content {
  overflow-y: auto;
  height: 100%;
  margin-top: 16px;
  padding-right: 16px;
}
.zent-switch:after {
  position: absolute;
  width: 18px;
  height: 18px;
  left: 1px;
  top: 1px;
  border-radius: 100%;
  background-color: #fff;
  content: " ";
  cursor: pointer;
  transition: left .16s cubic-bezier(0.5, 0, 0.5, 0.1);
}
.zent-switch {
  position: relative;
  display: inline-block;
  box-sizing: border-box;
  width: 44px;
  height: 22px;
  line-height: 20px;
  border-radius: 100px;
  border: 1px solid #c8c9cc;
  background-color: #c8c9cc;
  cursor: pointer;
  transition: all .16s cubic-bezier(0.5, 0, 0.5, 0.1);
}
.handle-title {
  margin-right: 10px;
  font-size: 14px;
  font-weight: 400;
  color: #323233;
  line-height: 20px;
}
.combine-pay-switch {
  display: flex;
  justify-content: center;
  align-items: center;
}
.left-pay-type-title .pay-type-title {
  font-size: 20px;
  font-weight: 500;
  color: #323233;
  line-height: 28px;
  position: relative;
  min-width: 160px;
}
.left-pay-type-title {
  display: flex;
  flex-direction: row;
  justify-content: space-between;
  margin-top: 40px;
  padding-right: 32px;
}
.left-handle {
  flex: 1;
  display: flex;
  width: 100%;
}
.left-handle .sub-left-pay-type {
  width: 400px;
  flex-shrink: 0;
  background-color: #fff;
  display: flex;
  flex-direction: column;
  padding-left: 32px;
  overflow-y: hidden;
  height: 100%;
  padding-bottom: 20px;
}
/deep/.ivu-drawer-close .ivu-icon-ios-close{
   color: red;
   font-size: 40px;
   font-weight: bold;
}
/deep/.ivu-drawer-header{
  background-color: #ffffff;
}
::-webkit-scrollbar {
  display: none;
}
.iconic_loading{
	animation: loading 1s linear infinite;
}
@keyframes loading {
	from { transform: rotate(0deg);}
	50%  { transform: rotate(180deg);}
	to   { transform: rotate(360deg);}
}
.mask{
	position: absolute;
	top:0;
	right:0;
	left:0;
	bottom:0;
	background-color: rgba(255,255,255,0.9)
	width: 100%;
	height: 100%;
}
/deep/.ivu-drawer-content{
   background-color: #f2f3f5;
}
/deep/.payStyle-modal{
	.ivu-modal{
		width: 396px !important;
	}
	.ivu-modal-body{
		padding-bottom: 45px;
	}
}
.activeOn{
	border: 1px solid #1890FF;
	background: rgba(24,144,255,0.04);
}
.payOn2{
	width: 49% !important;
}
.payOn3{
	width: 32% !important;
}
.leftCon{
	height: calc(100vh - 101px);
	padding-bottom: 15px;
}
/deep/.ivu-drawer-body{
	padding: 25px 0px;
}
/deep/.ivu-input{
	height: 60px;
	font-size: 16px !important;
	padding: 0 16px;
}
.order_time_out{
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 10px;
  height: 40px;
}
.order_time{
  color: #8558fa;
}
/deep/.order_time .ivu-input{
  width: 180px !important;
  border-radius: 4px;
  height: 40px !important;
  line-height: 40px !important;
  font-size: 14px !important;
}
.ticket{
	height: calc(100vh - 120px);
}
.search_user_info {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;

  .picture {
    width: 110px;
    height: 110px;
    margin: 20px 0 20px;

    img {
      width: 100%;
      height: 100%;
      border-radius: 50%;
    }
  }

  .user_name {
    font-size: 18px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.85);
    margin-bottom: 14px;
  }

  .user_id {
    font-size: 12px;
    font-weight: 400;
    color: #999999;
  }

  .user_phone {
    font-size: 14px;
    font-weight: 400;
    color: rgba(0, 0, 0, 0.85);
    margin: 14px 0 40px;
  }

  .sure_btn {
    width: 176px;
    height: 46px;
    line-height: 46px;
    text-align: center;
    color: #fff;
    font-size: 16px;
    background: #1890FF;
    border-radius: 6px;
    margin-bottom: 30px;
	cursor: pointer;
  }
}
.keypad {
	display: flex;
	.left {
		flex: 0 0 75%;
		display: flex;
		flex-wrap: wrap;
		.ivu-btn {
		  width: calc((100% - 21px) / 3)
		}
	}
	.right {
		flex: 0 0 25%;
		display: flex;
		flex-direction: column;
		/deep/.ivu-icon{
			font-weight: 600 !important;
			font-size: 31px;
		}
	}
	.ivu-btn {
	  height: 62px;
	  border: 0;
	  border-radius: 8px;
	  margin: 3.5px;
	  font-weight: 500;
	  font-size: 28px !important;
	  line-height: 62px;
	  color: #303133;
	  background-color: #fff;

	  &:focus {
	    box-shadow: none;
	  }
	}
	.enter {
	  height: 131px;
	  background-color: #1890FF;
	  font-weight: 500;
	  font-size: 22px !important;
	  line-height: 131px;
	  color: #FFFFFF;
	  border-radius: 8px;
	}
}
</style>
