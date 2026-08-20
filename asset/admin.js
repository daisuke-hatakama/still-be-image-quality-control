/**
 * WordPress Image Quality Control Admin Script
 * @module StillbeImageQualityControl
 */
(function(){

	"use strict";

	// Constants
	const WP_I18N            = window.wp?.i18n?.__;
	const STILLBE_FUNC       = window.$stillbe?.func?.__;
	const STILLBE_ADMIN      = window.$stillbe?.admin;
	const STILLBE_TEST_IMAGE = window.$stillbe?.admin?.testImage || {};

	/**
	 * Fallback implementation of WordPress translation function
	 * @param {string} text - Text to translate
	 * @param {string} domain - Text domain
	 * @returns {string} Translated text
	 */
	const __ = WP_I18N || (STILLBE_FUNC?.__ || function(text, domain) {
		if (!STILLBE_ADMIN?.translate) {
			return text;
		}
		return STILLBE_ADMIN.translate[text] || text;
	});

	/**
	 * Clone template and return a document fragment
	 * @param {Element} $temp - Template element to clone
	 * @param {Object} $params - Parameters to embed in the template
	 * @returns {DocumentFragment} Generated fragment
	 * @throws {Error} If template is invalid or parameters are missing
	 */
	const cloneTemplate = ($temp, $params) => {

		if(!$temp){
			return new DocumentFragment();
		}

		try {
			const generateHTML = $temp.innerHTML.replace(/{{([^}]+)}}/g, (match, key) => {
				const objectKey = key.split(".");
				let param = JSON.parse(JSON.stringify($params || {}));
				
				while (objectKey.length && param) {
					param = param[objectKey.shift()] || "";
				}
				
				// Sanitize the output to prevent XSS
				return String(param).replace(/[&<>"']/g, char => ({
					'&': '&amp;',
					'<': '&lt;',
					'>': '&gt;',
					'"': '&quot;',
					"'": '&#39;'
				})[char]);
			});

			const dummyTemp = document.createElement("template");
			dummyTemp.innerHTML = generateHTML;

			return dummyTemp.content;
		} catch (error) {
			console.error('Error cloning template:', error);
			return new DocumentFragment();
		}
	};


	/**
	 * Set up functionality to add new image size setting fields
	 * @returns {void}
	 */
	const setAddImageSize = () => {

		const tbody    = document.getElementById("quality_level_table_body");
		const button   = document.getElementById("add_image_size_button");
		const template = document.getElementById("temp_quality_level_fields");

		if (!tbody || !button || !template) {
			console.warn('Required elements for image size settings not found');
			return;
		}

		// Use event delegation for better performance
		const handleAddImageSize = (event) => {
			if (!event.target.matches('#add_image_size_button')) return;
			
			const addingImageSizes = document.getElementsByClassName("added-image-size");
			const i = addingImageSizes.length;
			
			try {
				tbody.appendChild(cloneTemplate(template, {i: String(i)}));
				checkUniqueSizeName();
			} catch (error) {
				console.error('Error adding image size:', error);
				alert(__('Failed to add image size. Please try again.', 'still-be-image-quality-control'));
			}
		};

		// Remove existing event listener if any
		button.removeEventListener('click', handleAddImageSize);
		// Add new event listener
		button.addEventListener('click', handleAddImageSize);

		// Initial check
		checkUniqueSizeName();
	};


	/**
	 * Set up functionality to add threshold for WebP of original image
	 * @returns {null|void} Returns null if required elements are not found
	 */
	const setAddThreashold = () => {

		const tbody    = document.getElementById("original_quality_table_body");
		const button   = document.getElementById("add_original_quality_button");
		const template = document.getElementById("temp_original_quality_fields");

		if(!tbody || !button || !template){
			return null;
		}

		button.onclick = function(){
			const i = tbody.children.length;
			tbody.insertBefore(cloneTemplate(template, {i: String(i)}), tbody.lastChild);
		};

	};


	/**
	 * Check if image size names are unique
	 * @returns {void}
	 */
	const checkUniqueSizeName = () => {

		const sizes      = window.$stillbe.admin.testImage.sizes;
		const sizeNames  = document.getElementsByClassName("add-image-size-name");
		const embedNames = document.getElementsByClassName("embed-image-size-name");

		const validateName = (name) => {
			if (!name) return false;
			return /^[a-zA-Z0-9_]+$/.test(name);
		};

		const isDuplicate = (name, elements) => {
			return Array.from(elements).some(el => 
				el !== this && (el.value || el.innerText) === name
			);
		};

		for (const name of sizeNames) {
			name.onchange = function() {
				const value = this.value;
				
				if (!validateName(value)) {
					this.select();
					alert(__('You can use only alphanumeric and underscore.', 'still-be-image-quality-control'));
					return false;
				}

				if (isDuplicate(value, [...sizeNames, ...embedNames])) {
					this.select();
					alert(__('You cannot use duplicate name.', 'still-be-image-quality-control'));
					return false;
				}
			};
		}

	};


	/**
	 * Parse URL parameters and return as an object
	 * @param {string} $url - URL to parse
	 * @returns {Object} Parsed parameters
	 */
	const parseUrlParams = $url => {

		const url = (() => {
			const _url = $url.indexOf("?") ? $url : "https://example.com/" + $url;
			try{
				return new URL(_url);
			} catch($e){
				console.error($e);
				return {};
			}
		})();

		if(!url.search){
			return {};
		}

		const params = {};

		for(const pair of url.searchParams.entries()){
			const keys  = pair[0].split(/\s*(?:\]\s*\[|\[|\])\s*/).filter(Boolean);
			const value = pair[1];
			let _key, _parentPointer = params;
			while(_key = keys.shift()){
				if(!_parentPointer.hasOwnProperty(_key)){
					_parentPointer[_key] = keys.length ? {} : value;
				}
				_parentPointer = _parentPointer[_key];
			}
		}

		return params;

	};


	/**
	 * Set up tab control functionality
	 * @returns {null|void} Returns null if tab elements are not found
	 */
	const setTabControl = () => {

		const tabs = document.querySelectorAll(".settings-tabs-wrapper label");
		if(tabs.length < 1){
			return null;
		}

		const tabWrapper = tabs[0].parentNode;

		const changeActiveTab = function($pushState = true){
			const current  = document.querySelector(".settings-tabs-wrapper label.active");
			const isChange = $pushState && this !== current;
			tabs.forEach($t => {
				if(this === $t){
					$t.classList.add("active");
				} else{
					$t.classList.remove("active");
				}
			});
			if(isChange){
				const id     = this.getAttribute("for");
				const name   = this.innerText;
				const title  = document.getElementsByTagName("title")[0].innreText;
				const search = location.search.replace(/&tab=.+$/, "");
				window.history.pushState({ tab: id }, `${name} | ${title}`,`${search}&tab=${id}`);
				// _wp_http_referer
				const referer = document.querySelector("input[name='_wp_http_referer']");
				if(referer){
					referer.value = /([&\?])tab=[^&$]+/.test(referer.value) ?
					                  referer.value.replace(/([&\?])tab=[^&$]+/, `$1tab=${id}`) :
					                  referer.value + `&tab=${id}`;
				}
			}
			const leftEnd = (tabWrapper.clientWidth - this.clientWidth) / 2;
			const nowPos  = this.getBoundingClientRect().left - tabWrapper.getBoundingClientRect().left;
			tabWrapper.scrollBy({ top: 0, left: nowPos - leftEnd, behavior: "smooth" });
		};

		tabs.forEach($t => {
			$t.onclick = changeActiveTab;
		});

		const tabInit = parseUrlParams(location.href).tab || tabs[0].getAttribute("for");
		if(tabInit){
			const tab     = document.querySelector(`.settings-tabs-wrapper label[for='${tabInit}']`);
			const section = document.querySelector(`#${tabInit}`);
			if(tab && section){
				// Chnage Tab
				changeActiveTab.call(tab, false);
				section.click();
				// Replace State
				const id    = tab.getAttribute("for");
				const name  = tab.innerText;
				const title = document.getElementsByTagName("title")[0].innreText;
				window.history.replaceState({ tab: id }, `${name} | ${title}`, location.href);
			}
		}

		window.addEventListener("popstate", function($e){
			const state   = $e.state;
			const tab     = document.querySelector(`.settings-tabs-wrapper label[for='${state.tab}']`);
			const section = document.querySelector(`#${state.tab}`);
			if(tab && section){
				// Chnage Tab
				changeActiveTab.call(tab, false);
				section.click();
			}
		}, false);

	};


	/**
	 * Handle target conditions height
	 * @returns {number} Set height or 0
	 */
	const handleTargetConditionsHeight = () => {

		const target = document.getElementsByClassName("target-conditions-setting-container");
		const height = Array.prototype.map.call(target, $t => $t.scrollHeight)
		                .reduce((_, h) => Math.max(_, h));

		if(height < 10){
			return 0;
		}

		const style = document.head.appendChild(document.createElement("style"));
		style.textContent = `#target_conditions_display:checked ~ .target-conditions-setting-container{max-height: ${~~height}px;}`;

		Array.prototype.forEach.call(target, $t => {
			$t.previousElementSibling.onclick = function(){
				Array.prototype.forEach.call(target, $_ => {
					$_.previousElementSibling.previousElementSibling.click();
				});
				return false;
			};
		});

		return height;

	};


	/**
	 * Initialize target conditions height
	 * @returns {boolean|null} Returns true on success, null if tab is not found
	 */
	const initTargetConditionsHeight = () => {

		if(handleTargetConditionsHeight() > 0){
			return true;
		}

		// 
		const tab = document.querySelector(".settings-tabs-wrapper label[for='tab_sb-imgq-ss-recomp']");
		if(!tab){
			return null;
		}

		const setRecompTabClickEvent = () => {
			
			if(handleTargetConditionsHeight() > 0){
				tab.removeEventListener("click", setRecompTabClickEvent, false);
				return true;
			}

			setTimeout(handleTargetConditionsHeight, 200);
			tab.removeEventListener("click", setRecompTabClickEvent, false);

		};

		tab.addEventListener("click", setRecompTabClickEvent, false);

	};

	// Initialize on DOM content loaded
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize);
	} else {
		initialize();
	}

	function initialize() {
		try {
			setAddImageSize();
			setAddThreashold();
			setTabControl();
			initTargetConditionsHeight();
		} catch (error) {
			console.error('Error initializing admin script:', error);
		}
	}

})();

