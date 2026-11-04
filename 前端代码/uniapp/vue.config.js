module.exports = {
	productionSourceMap: false, // 生产打包时不输出map文件，增加打包速度
	chainWebpack: config => {
		// HBuilderX loads babel-loader from its own CLI context. Explicitly attach
		// the app's syntax transforms so H5 can compile existing optional chains.
		config.module.rule('js').use('babel-loader').tap(options => ({
			...(options || {}),
			plugins: [...((options && options.plugins) || []), ...require('./babel.config.js').plugins]
		}))
	},
	configureWebpack: config => {
		// WXSS imports stay inside each package and preserve the exact original
		// rule sequence. Only Weixin production packaging uses this optimization.
		if (process.env.UNI_PLATFORM === 'mp-weixin' && process.env.NODE_ENV === 'production') {
			const path = require('path')
			const cli = process.env.UNI_HBUILDERX_PLUGINS && path.join(process.env.UNI_HBUILDERX_PLUGINS, 'uniapp-cli')
			const dependency = name => require(require.resolve(name, { paths: [__dirname, cli].filter(Boolean) }))
			const { MemberMiniCssDedupePlugin } = require('../../scripts/build/member-mini-css-dedupe.cjs')
			// 推广页独占图片随所属分包加载，保持主包预算且不删除既有资源。
			const { MemberMiniPromotionAssetsPlugin } = require('../../scripts/build/member-mini-promotion-assets.cjs')
			config.plugins.push(new MemberMiniPromotionAssetsPlugin())
			config.plugins.push(new MemberMiniCssDedupePlugin({ postcss: dependency('postcss'), valueParser: dependency('postcss-value-parser') }))
		}
		if (process.env.NODE_ENV === 'production') {
			config.optimization.minimizer[0].options.terserOptions.compress.warnings = false
			config.optimization.minimizer[0].options.terserOptions.compress.drop_console = true
			config.optimization.minimizer[0].options.terserOptions.compress.drop_debugger = true
			config.optimization.minimizer[0].options.terserOptions.compress.pure_funcs = ['console.log']
		}
	}
}
