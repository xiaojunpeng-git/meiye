const path = require('path');

function resolveBundledPlugin(name) {
	try {
		return require.resolve(name);
	} catch (error) {
		const extensionsDir = process.env.HBUILDER_EXTENSIONS_DIR;
		if (!extensionsDir) throw error;
		return path.join(extensionsDir, 'uniapp-cli', 'node_modules', name);
	}
}

module.exports = {
	plugins: [
		resolveBundledPlugin('@babel/plugin-proposal-optional-chaining'),
		resolveBundledPlugin('@babel/plugin-proposal-nullish-coalescing-operator'),
	],
};
