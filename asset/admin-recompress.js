/**
 * StillBE Image Quality Control - Settings: re-compress & reset
 *
 * Handles attachment ID gathering, bulk/single regeneration, and settings reset
 * via wp.apiFetch against stillbe-iqc/v1.
 */

"use strict";

// Initialize global namespace for the plugin
window.$stillbe       = (window.$stillbe       || {});
window.$stillbe.func  = (window.$stillbe.func  || {});
window.$stillbe.admin = (window.$stillbe.admin || {});

const restBase = () => window.$stillbe.admin.restBase || "stillbe-iqc/v1";

/**
 * REST request timeout [ms] (aligned with PHP STILLBE_IQ_WPCRON_MAX_EXECUTION_TIME)
 * @returns {number}
 */
const restTimeoutMs = () => {
	const sec = Number(window.$stillbe && window.$stillbe.admin && window.$stillbe.admin.restTimeoutSec);
	if(Number.isFinite(sec) && sec > 0){
		return Math.floor(sec * 1000);
	}
	return 600 * 1000;
};

/**
 * AbortSignal that fires after the given timeout
 * @param {number} ms
 * @returns {AbortSignal|undefined}
 */
const restTimeoutSignal = (ms) => {
	if(!(ms > 0)){
		return undefined;
	}
	if(typeof AbortSignal !== "undefined" && typeof AbortSignal.timeout === "function"){
		return AbortSignal.timeout(ms);
	}
	if(typeof AbortController === "undefined"){
		return undefined;
	}
	const controller = new AbortController();
	setTimeout(() => {
		try{
			controller.abort();
		}catch(_e){}
	}, ms);
	return controller.signal;
};

/**
 * Translation helper function
 * @param {string} $id - Translation key
 * @param {string} $domain - Text domain
 * @returns {string} Translated text or original key if translation not found
 */
window.$stillbe.func.__ = function($id, $domain){
	if(!("translate" in window.$stillbe.admin)){
		return $id;
	}
	return window.$stillbe.admin.translate[$id] || $id;
};

/**
 * REST request via wp.apiFetch
 * Callback shape: { ok, json, body }
 * @param {string} $method - HTTP method (GET/POST)
 * @param {string} $path - REST path relative to restBase (leading slash optional)
 * @param {Object} $data - Query (GET) or JSON body (POST)
 * @param {Function} $callback - Callback function to handle response
 * @returns {boolean} Success status
 */
window.$stillbe.func.restRequest = function($method = "POST", $path = null, $data = {}, $callback = null){
	if(!$path || typeof $callback !== "function"){
		return false;
	}
	const __ = window.wp && window.wp.i18n && window.wp.i18n.__ ? window.wp.i18n.__ : window.$stillbe.func.__;
	const apiFetch = window.wp && window.wp.apiFetch;
	if(!apiFetch){
		alert(__("An error has occurred.... Please try again.", "still-be-image-quality-control"));
		$callback.call(null, {
			ok: false,
			json: { ok: false, message: __("Network Error...", "still-be-image-quality-control") },
			body: ""
		});
		return false;
	}

	const method = String($method).toUpperCase();
	let path = String($path);
	if(path.indexOf("/") !== 0){
		path = "/" + path;
	}
	if(path.indexOf("/" + restBase()) !== 0 && path.indexOf(restBase()) !== 0){
		path = "/" + restBase() + path;
	}

	const options = { method, path };
	const signal = restTimeoutSignal(restTimeoutMs());
	if(signal){
		options.signal = signal;
	}
	$data = $data || {};
	if(method === "GET"){
		const qs = new URLSearchParams();
		Object.keys($data).forEach(($key) => {
			if($data[$key] === undefined || $data[$key] === null){
				return;
			}
			qs.append($key, $data[$key]);
		});
		const q = qs.toString();
		if(q){
			options.path += (options.path.indexOf("?") >= 0 ? "&" : "?") + q;
		}
	} else if(Object.keys($data).length){
		options.data = $data;
	}

	apiFetch(options)
		.then(($result) => {
			$callback.call(null, { ok: true, json: $result, body: JSON.stringify($result) });
		})
		.catch(($error) => {
			const message = ($error && $error.message) || __("An error has occurred.... Please try again.", "still-be-image-quality-control");
			alert(message);
			$callback.call(null, {
				ok: false,
				json: { ok: false, message: message },
				body: ""
			});
		});

	return true;
};

/**
 * Event Handler: Get Images IDs
 * Fetches attachment IDs based on specified conditions
 */
window.addEventListener("DOMContentLoaded", function(){
	const button = document.getElementById("get_attachment_id");
	const cache  = window.$stillbe.admin.reComp && window.$stillbe.admin.reComp.cache;
	const __     = window.wp && window.wp.i18n && window.wp.i18n.__ ? window.wp.i18n.__ : window.$stillbe.func.__;

	if(!button || !window.$stillbe.admin.reComp){
		return null;
	}

	let runing = false;

	const updateProgressDisplay = function(count, total) {
		const showCountElem = document.getElementById("count_ids");
		if (showCountElem) {
			showCountElem.innerText = `${count} / ${total} ${__("Files", "still-be-image-quality-control")}`;
		}
	};

	const showTargetCount = function($response){
		if(!$response || !$response.ok){
			if($response){
				alert($response.json?.message || __("An error has occurred.... Please try again.", "still-be-image-quality-control"));
			}
			runing = false;
			return false;
		}

		const showCountElem = ($exists => {
			if($exists){
				return $exists;
			}
			const regenButton = document.getElementById("regenerate_images");
			if(!regenButton){
				return regenButton;
			}
			const elem = regenButton.parentNode.insertBefore(document.createElement("p"), regenButton);
			elem.id = "count_ids";
			elem.dataset.label = __("Target Images", "still-be-image-quality-control");
			return elem;
		})(document.getElementById("count_ids"));

		if(!showCountElem){
			alert($response.message || __("An error has occurred.... Please try again.", "still-be-image-quality-control"));
			runing = false;
			return false;
		}

		const result  = $response.json || {};
		window.$stillbe.admin.reComp.image = {ids: (result.ids || [])};

		updateProgressDisplay(
			window.$stillbe.admin.reComp.image.ids.length,
			result.total || window.$stillbe.admin.reComp.image.ids.length
		);

		button.disabled = false;
		runing = false;
	};

	button.onclick = function(){
		if(runing){
			return null;
		}
		button.disabled = true;
		runing = true;

		const target = {};
		const conditions = document.querySelectorAll("[data-target-condition]");
		conditions.forEach($e => {
			const cond = $e.getAttribute("data-target-condition");
			const key  = $e.getAttribute("data-key");
			if(!cond || !key){
				return null;
			}
			target[cond]      = target[cond] || {};
			target[cond][key] = $e.type !== "checkbox" ? $e.value : $e.checked;
		});

		window.$stillbe.func.restRequest("POST", "/attachment-ids", { target: target }, showTargetCount);
	};

	// If it has the cache
	if(cache && cache.ids){
		showTargetCount({
			ok: true,
			message: "Cache",
			json: {
				ids: cache.ids,
				total: cache.total || cache.ids.length
			},
			finished: cache.current || 0,
		});
	}
}, false);

/**
 * Event Handler: Regenerate Images
 * Handles bulk image regeneration with progress tracking
 */
window.addEventListener("DOMContentLoaded", function(){
	const button  = document.getElementById("regenerate_images");
	const suspend = document.getElementById("suspend_regenerate");
	const __      = window.wp && window.wp.i18n && window.wp.i18n.__ ? window.wp.i18n.__ : window.$stillbe.func.__;
	if(!button || !window.$stillbe.admin.reComp){
		return null;
	}
	const progressBar = document.getElementsByClassName("progress-bar");
	const progress    = document.getElementsByClassName("progress");
	let runing = false;
	let suspendFlag = false;
	const doReGen = function(){
		window.$stillbe.func.restRequest("POST", "/regenerate", {}, function($response){
			if(!$response || !$response.ok){
				if($response){
					alert(__("An error has occurred.... Please try again.", "still-be-image-quality-control"));
				}
				button.disabled  = false;
				suspend.disabled = true;
				runing = false;
				return false;
			}
			const result = $response.json || {};
			const prog = ~~(result.progress_ratio * 100) + "%";
			progress[0].style.width      = prog;
			progress[0].dataset.progress = prog;
			if(result.next_id){
				if(suspendFlag){
					alert(__("It was interrupted! Even if you close the page, you can restart the conversion from the continuation.", "still-be-image-quality-control"));
					suspendFlag      = false;
					suspend.disabled = true;
					button.disabled  = false;
					runing = false;
				} else{
					setTimeout(doReGen, 1000);
				}
			} else{
				alert(__("All the regeneration is done!", "still-be-image-quality-control"));
				suspendFlag      = false;
				suspend.disabled = true;
				button.disabled  = false;
				runing = false;
			}
		});
	};
	button.onclick = function(){
		if(runing){
			return null;
		}
		button.disabled = true;
		runing = true;
		// Enable the Suspend Button
		suspend.disabled = false;
		// Progress Bar
		if(progressBar.length < 1){
			button.parentNode.insertBefore(document.createElement("div"), button)
				.classList.add("progress-bar");
			progressBar[0].appendChild(document.createElement("div"))
				.classList.add("progress");
		}
		const prog = "0%";
		progress[0].style.width = prog;
		progress[0].dataset.progress = prog;
		// Start!!
		doReGen();
	};
	if(!button){
		return null;
	}
	// Suspend Re-Comp
	suspend.onclick = function(){
		if(this.disabled){
			return false;
		}
		this.disabled = true;
		suspendFlag = true;
	};
}, false);

/**
 * Event Handler: Regenerate Single Image
 * Handles regeneration of a single image by ID
 */
window.addEventListener("DOMContentLoaded", function(){
	const button  = document.getElementById("conv_only_one_image_button");
	const result  = document.getElementById("conv_only_one_image_result");
	const id      = document.getElementById("one_attachment_id");
	const __      = window.wp && window.wp.i18n && window.wp.i18n.__ ? window.wp.i18n.__ : window.$stillbe.func.__;
	if(!button || !id || !window.$stillbe.admin.reComp){
		return null;
	}
	let runing = false;
	button.onclick = function(){
		if(runing){
			return null;
		}
		if(!id.value){
			return null;
		}
		button.disabled = true;
		runing = true;
		// Start!!
		result.innerText = "Now processing...";
		window.$stillbe.func.restRequest("POST", "/attachments/" + encodeURIComponent(id.value) + "/regenerate", {}, function($response){
			if(!$response || !$response.ok){
				if($response && $response.message){
					alert(__("An error has occurred.... Please try again.", "still-be-image-quality-control"));
				}
				result.innerText = __("An error has occurred.... Please try again.", "still-be-image-quality-control");
			} else{
				result.innerText = $response.json.message || $response.message;
			}
			button.disabled = false;
			runing = false;
			return false;
		});
	};
}, false);

/**
 * Event Handler: Reset Settings
 * Resets all plugin settings to default values
 */
window.addEventListener("DOMContentLoaded", function(){
	const button = document.getElementById("reset_settings");
	if(!button){
		return null;
	}
	const __  = window.wp && window.wp.i18n && window.wp.i18n.__ ? window.wp.i18n.__ : window.$stillbe.func.__;
	let refreshing = false;
	button.onclick = function(){
		if(refreshing || !window.confirm(__( "All settings will revert to their default values. This change is irreversible.", "still-be-image-quality-control"))){
			return null;
		}
		refreshing = true;
		window.$stillbe.func.restRequest("POST", "/settings/reset", {}, function($response){
			if(!$response || !$response.ok){
				alert(($response && $response.json && $response.json.message) || __("An error has occurred.... Please try again.", "still-be-image-quality-control"));
			} else{
				console.log($response.json);
				alert($response.json.message);
				if($response.json.ok){
					location.reload();
				}
			}
			refreshing = false;
		});
	};
}, false);
