// Customer mini-program builds copy this file to the ignored config/app.js.
// The customer origin is packaged with the mini program; never inherit a local customer's config.
const configuredOrigin = require('./member-customer.json').apiOrigin;
const origin = /^https:\/\/[a-z0-9.-]+(?::\d+)?$/i.test(configuredOrigin)
	? configuredOrigin
	: 'https://example.invalid';

module.exports = {
	HTTP_REQUEST_URL: origin,
	VUE_APP_WS_URL: origin.replace(/^https:/, 'wss:') + '/ws',
	HEADER: {
		'content-type': 'application/json',
		'X-Source': 'f76d38d0ee4f854f',
		'Form-type': 'routine'
	},
	TOKENNAME: 'Authori-zation',
	EXPIRE: 0,
	LIMIT: 100
};
