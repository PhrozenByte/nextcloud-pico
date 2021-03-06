/**
 * CMS Pico - Create websites using Pico CMS for Nextcloud.
 *
 * @copyright Copyright (c) 2019, Daniel Rudolf (<picocms.org@daniel-rudolf.de>)
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/** global: OC */
/** global: OCA */
/** global: jQuery */

(function (document, $, OC, OCA) {
	'use strict';

	if (!OCA.CMSPico) {
		/** @namespace OCA.CMSPico */
		OCA.CMSPico = {};
	}

	/**
	 * @class
	 *
	 * @param {jQuery}        $element
	 * @param {Object}        [options]
	 * @param {string}        [options.route]
	 * @param {jQuery|string} [options.template]
	 * @param {jQuery|string} [options.loadingTemplate]
	 * @param {jQuery|string} [options.errorTemplate]
	 */
	OCA.CMSPico.List = function ($element, options) {
		this.initialize($element, options);
	};

	/**
	 * @lends OCA.CMSPico.List.prototype
	 */
	OCA.CMSPico.List.prototype = {
		/** @member {jQuery} */
		$element: $(),

		/** @member {string} */
		route: '',

		/** @member {jQuery} */
		$template: $(),

		/** @member {jQuery} */
		$loadingTemplate: $(),

		/** @member {jQuery} */
		$errorTemplate: $(),

		/**
		 * @constructs
		 *
		 * @param {jQuery}        $element
		 * @param {Object}        [options]
		 * @param {string}        [options.route]
		 * @param {jQuery|string} [options.template]
		 * @param {jQuery|string} [options.loadingTemplate]
		 * @param {jQuery|string} [options.errorTemplate]
		 */
		initialize: function ($element, options) {
			this.$element = $element;

			options = $.extend({
				route: $element.data('route'),
				template: $element.data('template'),
				loadingTemplate: $element.data('loadingTemplate'),
				errorTemplate: $element.data('errorTemplate')
			}, options);

			this.route = options.route;
			this.$template = $(options.template);
			this.$loadingTemplate = $(options.loadingTemplate);
			this.$errorTemplate = $(options.errorTemplate);

			var signature = 'OCA.CMSPico.List.initialize()';
			if (!this.route) throw signature + ': No route given';
			if (!this.$template.length) throw signature + ': No valid list template given';
			if (!this.$loadingTemplate.length) throw signature + ': No valid loading template given';
			if (!this.$errorTemplate.length) throw signature + ': No valid error template given';
		},

		/**
		 * @public
		 */
		reload: function () {
			this._api('GET');
		},

		/**
		 * @public
		 * @abstract
		 *
		 * @param {Object} data
		 */
		update: function (data) {},

		/**
		 * @protected
		 *
		 * @param {string}         method
		 * @param {string}         [item]
		 * @param {Object}         [data]
		 * @param {function(data)} [callback]
		 */
		_api: function (method, item, data, callback) {
			var that = this,
				url = this.route + (item ? ((this.route.substr(-1) !== '/') ? '/' : '') + item : '');

			this._content(this.$loadingTemplate);

			$.ajax({
				method: method,
				url: OC.generateUrl(url),
				data: data || {}
			}).done(function (data, textStatus, jqXHR) {
				if (callback === undefined) {
					that.update(data);
				} else if (typeof callback === 'function') {
					callback(data);
				}
			}).fail(function (jqXHR, textStatus, errorThrown) {
				that._error((jqXHR.responseJSON || {}).error);
			});
		},

		/**
		 * @protected
		 *
		 * @param {jQuery}  $template
		 * @param {object}  [vars]
		 * @param {boolean} [replaceContent]
		 *
		 * @returns {jQuery}
		 */
		_content: function ($template, vars, replaceContent) {
			var $baseElement = $($template.data('replaces') || $template.data('appendTo') || this.$element),
				$content = $template.octemplate(vars || {});

			$baseElement.find('.has-tooltip').tooltip('hide');
			$content.find('.has-tooltip').tooltip();

			if ((replaceContent !== undefined) ? replaceContent : $template.data('replaces')) {
				$baseElement.empty();
			}

			$content.appendTo($baseElement);

			return $content;
		},

		/**
		 * @protected
		 *
		 * @param {string} [message]
		 */
		_error: function (message) {
			var $error = this._content(this.$errorTemplate, { message: message || '' }),
				that = this;

			if (message) {
				$error.find('.error-details').show();
			}

			$error.find('.action-reload').on('click.CMSPicoAdminList', function (event) {
				event.preventDefault();
				that.reload();
			});
		}
	};

	/**
	 * @class
	 *
	 * @param {jQuery} $element
	 * @param {Object} [options]
	 * @param {string} [options.route]
	 */
	OCA.CMSPico.Form = function ($element, options) {
		this.initialize($element, options);
	};

	/**
	 * @lends OCA.CMSPico.Form.prototype
	 */
	OCA.CMSPico.Form.prototype = {
		/** @member {jQuery} */
		$element: $(),

		/** @member {string} */
		route: '',

		/**
		 * @constructs
		 *
		 * @param {jQuery} $element
		 * @param {Object} [options]
		 * @param {string} [options.route]
		 */
		initialize: function ($element, options) {
			this.$element = $element;

			options = $.extend({
				route: $element.data('route')
			}, options);

			this.route = options.route;

			var signature = 'OCA.CMSPico.Form.initialize()';
			if (!this.route) throw signature + ': No route given';
		},

		/**
		 * @public
		 * @abstract
		 */
		prepare: function () {},

		/**
		 * @public
		 * @abstract
		 */
		submit: function () {},

		/**
		 * @protected
		 *
		 * @param {jQuery}              $element
		 * @param {string|number|Array} [value]
		 *
		 * @returns {jQuery|string|number|Array}
		 */
		_val: function ($element, value) {
			if (value !== undefined) {
				return $element.is(':input') ? $element.val(value) : $element.text(value);
			}

			return $element.is(':input') ? $element.val() : $element.text();
		}
	};

	/** @namespace OCA.CMSPico.Util */
	OCA.CMSPico.Util = {
		/**
		 * @param {string} string
		 *
		 * @returns string
		 */
		unescape: function (string) {
			return string
				.replace(/&amp;/g, '&')
				.replace(/&lt;/g, '<')
				.replace(/&gt;/g, '>')
				.replace(/&quot;/g, '"')
				.replace(/&#039;/g, "'");
		}
	};
})(document, jQuery, OC, OCA);
