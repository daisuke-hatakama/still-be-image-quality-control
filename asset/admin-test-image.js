/**
 * StillBE Image Quality Control - Settings: quality test image UI
 *
 * TwentyTwenty compare UI, test image generation, and SSIM info via wp.apiFetch.
 */
(function(){

	"use strict";

	// Global Variables
	// Initialize global namespace for the plugin
	window.$stillbe = (window.$stillbe || {});
	window.$stillbe.admin = (window.$stillbe.admin || {});
	window.$stillbe.admin.testImage = (window.$stillbe.admin.testImage || {});

	// Base64 encoded 1x1 transparent GIF for placeholder
	const dummy = "data:image/gif;base64,R0lGODlhAQABAGAAACH5BAEKAP8ALAAAAAABAAEAAAgEAP8FBAA7";

	// Test Settings Object to store current test configuration
	const testSettings = { left: {}, right: {}, size: null };

	/** @type {number} */
	let compareSsimSeq = 0;

	const restBase = () => window.$stillbe.admin.restBase || "stillbe-iqc/v1";
	const apiFetch = () => window.wp && window.wp.apiFetch;

	/**
	 * Convert MSSIM (0-1) to dB (-10 * log10(1 - ssim))
	 * @param {number} $ssim
	 * @returns {number|null}
	 */
	const ssimToDb = ($ssim) => {
		const s = Number($ssim);
		if(!Number.isFinite(s) || s < 0 || s > 1){
			return null;
		}
		if(s >= 1){
			return Infinity;
		}
		return -10 * Math.log10(1 - s);
	};

	/**
	 * Format milliseconds with comma thousands separators when 4+ digits
	 * @param {number} $ms
	 * @returns {string}
	 */
	const formatMs = ($ms) => {
		if(!Number.isFinite($ms)){
			return "-";
		}
		const n = String(Math.round($ms));
		return n.replace(/(?<=\d)(?=(\d{3})+(?!\d))/g, ",") + "ms";
	};

	/**
	 * Format compare-SSIM line: "xx.xx dB (1,234ms, WebGPU)"
	 * @param {number} $ssim
	 * @param {number} $ms
	 * @param {string} $backend
	 * @returns {string}
	 */
	const formatCompareSsimLine = ($ssim, $ms, $backend) => {
		const db = ssimToDb($ssim);
		const dbText = db === null ? "-" : (db === Infinity ? "∞" : db.toFixed(2));
		return `${dbText} dB (${formatMs($ms)}, ${$backend})`;
	};

	/**
	 * Wait until the image has a real decoded size (not the 1x1 placeholder)
	 * @param {HTMLImageElement} $img
	 * @returns {Promise<boolean>}
	 */
	const waitImageReady = ($img) => new Promise(($resolve) => {
		if(!$img){
			$resolve(false);
			return;
		}
		const ok = () => $img.naturalWidth > 1 && $img.naturalHeight > 1 && !$img.classList.contains("loading");
		if(ok()){
			$resolve(true);
			return;
		}
		let n = 0;
		const timer = setInterval(() => {
			if(ok()){
				clearInterval(timer);
				$resolve(true);
			} else if(++n > 200){
				clearInterval(timer);
				$resolve(false);
			}
		}, 50);
	});

	/**
	 * Whether Script Modules for WebGPU SSIM were enqueued (WP 6.5+)
	 * @returns {boolean}
	 */
	const isWebGpuModuleAvailable = () =>
		!!(window.$stillbe && window.$stillbe.admin && window.$stillbe.admin.testImage && window.$stillbe.admin.testImage.webgpuModuleAvailable);

	/**
	 * Resolve StillBE_SSIM_WebGPU class when Script Modules are available
	 * @returns {Promise<typeof StillBE_SSIM_WebGPU|null>}
	 */
	const resolveWebGpuSsimClass = async () => {
		if(!isWebGpuModuleAvailable()){
			return null;
		}
		if(typeof globalThis.StillBE_SSIM_WebGPU === "function"){
			return globalThis.StillBE_SSIM_WebGPU;
		}
		try {
			const mod = await import("@stillbe-iqc/ssim-webgpu");
			const cls = mod && (mod.StillBE_SSIM_WebGPU || (mod.default && mod.default.StillBE_SSIM_WebGPU));
			if(typeof cls === "function"){
				globalThis.StillBE_SSIM_WebGPU = cls;
				return cls;
			}
		} catch($e) {
			console.warn("Failed to import WebGPU SSIM module:", $e);
		}
		return typeof globalThis.StillBE_SSIM_WebGPU === "function" ? globalThis.StillBE_SSIM_WebGPU : null;
	};

	/**
	 * Disable Exact mode UI when WebGPU Script Module is not available
	 */
	const syncCompareSsimModeUi = () => {
		const denseInput = document.querySelector('input[name="sb_compare_ssim_mode"][value="dense"]');
		const sparseInput = document.querySelector('input[name="sb_compare_ssim_mode"][value="sparse"]');
		const modeWrap = document.querySelector(".sb-compare-ssim-mode");
		if(!denseInput || !sparseInput){
			return;
		}
		const allowExact = isWebGpuModuleAvailable();
		denseInput.disabled = !allowExact;
		if(modeWrap){
			modeWrap.classList.toggle("is-webgpu-unavailable", !allowExact);
		}
		if(!allowExact && denseInput.checked){
			sparseInput.checked = true;
		}
	};

	/**
	 * Whether Exact (all-pixel / dense) SSIM is selected
	 * @returns {boolean}
	 */
	const isCompareSsimDense = () => {
		if(!isWebGpuModuleAvailable()){
			return false;
		}
		const checked = document.querySelector('input[name="sb_compare_ssim_mode"]:checked');
		return !!(checked && checked.value === "dense");
	};

	/**
	 * Compute Left↔Right SSIM (WebGPU preferred on WP 6.5+; otherwise CPU)
	 */
	const updateCompareSsim = async () => {
		const panel = document.getElementById("sb_compare_ssim");
		if(!panel){
			return;
		}
		const valueEl = panel.querySelector(".sb-compare-ssim-value");
		const leftImg  = document.querySelector("#sb_iqc_test_image_left");
		const rightImg = document.querySelector("#sb_iqc_test_image_right");
		const dense = isCompareSsimDense();
		const seq = ++compareSsimSeq;

		panel.classList.add("loading");
		if(valueEl){ valueEl.textContent = "…"; }

		const ready = await Promise.all([waitImageReady(leftImg), waitImageReady(rightImg)]);
		if(seq !== compareSsimSeq){
			return;
		}
		if(!ready[0] || !ready[1]
		    || leftImg.naturalWidth !== rightImg.naturalWidth
		    || leftImg.naturalHeight !== rightImg.naturalHeight){
			if(valueEl){ valueEl.textContent = "-"; }
			panel.classList.remove("loading");
			return;
		}

		let resultText = "-";
		const WebGpuSsim = await resolveWebGpuSsimClass();

		// Prefer WebGPU when the Script Module is available and the browser supports it
		if(WebGpuSsim){
			try {
				if(await WebGpuSsim.isSupported()){
					const gpu = new WebGpuSsim(leftImg, rightImg);
					const gpuResult = await gpu.calc({ dense });
					if(seq !== compareSsimSeq){
						return;
					}
					const gpuMs = Array.isArray(gpuResult.times) && gpuResult.times.length
						? Number(gpuResult.times[gpuResult.times.length - 1]) || 0
						: 0;
					resultText = formatCompareSsimLine(gpuResult.mssim, gpuMs, "WebGPU");
					if(valueEl){ valueEl.textContent = resultText; }
					panel.classList.remove("loading");
					return;
				}
			} catch($e) {
				console.warn("Compare SSIM (WebGPU) failed; falling back to CPU:", $e);
			}
		}

		// CPU path (always available; sampled only)
		try {
			if(typeof StillBE_SSIM !== "function"){
				throw new Error("StillBE_SSIM is not available");
			}
			const cpu = new StillBE_SSIM(leftImg, rightImg);
			const cpuResult = await cpu.calc({ forceSingleThread: true });
			if(seq !== compareSsimSeq){
				return;
			}
			const cpuMs = Array.isArray(cpuResult.times)
				? cpuResult.times.reduce((a, b) => a + (Number(b) || 0), 0)
				: 0;
			resultText = formatCompareSsimLine(cpuResult.mssim, cpuMs, "CPU");
		} catch($e) {
			console.warn("Compare SSIM (CPU) failed:", $e);
			resultText = "-";
		}

		if(valueEl){ valueEl.textContent = resultText; }
		panel.classList.remove("loading");
	};

	/**
	 * Convert bytes to human readable format (e.g. 1024 -> 1Ki)
	 * @param {number} $byte - Size in bytes
	 * @returns {string} Human readable size with appropriate unit
	 */
	const sizeHumanReadable = $byte => {
		const suffixes = ["ki", "Mi", "Gi", "Ti"];
		let size       = $byte * 1;
		let suffix     = "";
		while(size > 999){
			size /= 1024;
			suffix = suffixes.shift();
		}
		return String(size).substring(0, 4).replace(/\.$/, "") + suffix;
	};

	/**
	 * Fetch test image via REST with specified parameters
	 * @param {string} $targetSide - Target side ('left' or 'right')
	 * @param {Object} $param - Request parameters
	 * @returns {Promise} Promise resolving to the test image result
	 */
	const getTestImage = async ($targetSide, $param) => {
		const imgElem = document.querySelector(`.twentytwenty-image.${$targetSide}`);
		if(!imgElem){
			throw new Error(`Image element not found for side: ${$targetSide}`);
		}
		imgElem.classList.add("loading");
		try {
			const attachmentId = $param.attachment_id || window.$stillbe.admin.testImage.attachmentId;
			const filters = Object.assign({}, $param.filters || {});
			const query = new URLSearchParams();
			if($param.size){
				query.set("size", $param.size);
			}
			if($param.quality !== undefined && $param.quality !== null && $param.quality !== ""){
				query.set("quality", $param.quality);
			}
			if($param.mime){
				query.set("mime", $param.mime);
			}
			Object.keys(filters).forEach(($hook) => {
				query.set(`filters[${$hook}]`, filters[$hook]);
			});

			const path = `/${restBase()}/attachments/${encodeURIComponent(attachmentId)}/test-image?${query.toString()}`;
			const response = await apiFetch()({ path, parse: false });
			const result   = await parseResponse(response);
			result.targetSide = $targetSide;
			await handleResult(result);
			imgElem.classList.remove("loading");
			return result;
		} catch (error) {
			console.error("Error fetching test image: ", error);
			throw error;
		}
	};

	/**
	 * Parse the response from the server
	 * @param {Response} $response - Fetch response object
	 * @returns {Promise} Promise resolving to parsed result
	 */
	const parseResponse = async $response => {
		if(!$response.ok){
			throw new Error("Network response was not OK");
		}

		const contentType = ($response.headers.get("Content-Type") || "").split(";")[0].trim();
		if(contentType === "application/json"){
			const json = await $response.json();
			return {
				type: "json",
				data: json,
			};
		}

		// Information Key
		const infoKey = $response.headers.get("X-IQC-Info-Key");
		if(!infoKey){
			throw new Error("Information key not found in the response headers");
		}

		// Fetch conversion info via REST
		const infoResult = await apiFetch()({
			path: `/${restBase()}/test-image-info?key=${encodeURIComponent(infoKey)}`,
		});
		if(!infoResult.ok){
			throw new Error("Failed to fetch information");
		}

		// Information Data
		const infoData = infoResult.info;

		// Information of Converting
		const quality  = infoData["Quality-Level"];
		const editor   = infoData["Image-Editor"] || "-";
		const mode     = infoData["Encode-Mode"] || "-";
		const compress = infoData["Compression-Level"] || "-";
		const time     = infoData["Convert-Time"];
		const memory   = infoData["Memory-Peak"];
		const cpu      = infoData["Average-CPU"];
		const ssim     = infoData["SSIM"];
		const ssimInDB = infoData["SSIM-In-dB"];
		const ssimTime = infoData["SSIM-Time"];

		// Resolves with a Blob
		const blob = await $response.blob();
		return {
			type: "blob",
			data: blob,
			size: blob.size,
			mime: blob.type,
			quality,
			editor,
			mode,
			compress: compress != 0 ? compress : "-",
			time,
			memory,
			cpu,
			ssim,
			ssimInDB,
			ssimTime,
		};
	};

	/**
	 * Handle the result of the test image request
	 * @param {Object} $result - Result object from parseResponse
	 */
	const handleResult = async $result => {
		if ($result.type !== "blob") {
			const consoleMessage = $result.data.ok ? console.log : console.warn;
			consoleMessage($result.data.message, $result.data);
			return $result.data.ok;
		}

		const targetSide = $result.targetSide;

		// Render the Test Image
		const imgElem = document.querySelector(`.twentytwenty-image.${targetSide}`);
		if (!imgElem) {
			throw new Error(`Image element not found for side: ${targetSide}`);
		}

		// Create and revoke object URL
		const objectUrl = URL.createObjectURL($result.data);
		imgElem.onload = () => {
			URL.revokeObjectURL(objectUrl);
			document.querySelector(`.side-label.${targetSide}`).classList.remove("loading");
			updateTwentyTwenty(imgElem);
			updateCompareSsim();
		};
		imgElem.src = objectUrl;

		// Update conversion information
		updateConversionInfo(targetSide, $result);
	};

	/**
	 * Update TwentyTwenty comparison interface
	 * @param {HTMLImageElement} imgElem - Image element to update
	 */
	const updateTwentyTwenty = async (imgElem) => {
		if (!window.$stillbe.admin.testImage.clearTwentytwenty) {
			return;
		}

		await new Promise($resolve => {
			if (!imgElem.previousElementSibling) {
				$resolve();
				return;
			}
			const timerID = setInterval(() => {
				const prevElem = imgElem.previousElementSibling;
				if (!prevElem || prevElem.naturalWidth > 1) {
					clearInterval(timerID);
					$resolve();
				}
			}, 50);
		});

		// Set the TwentyTwenty Wrapper to the Same Size as the Image
		jQuery("#sb_compare_image .twentytwenty-container").twentytwenty();
		const size = window.$stillbe.admin.testImage.currentSizes[testSettings.size];
		const wrapper = document.querySelector("#sb_compare_image .twentytwenty-wrapper");
		const dpr = window.devicePixelRatio || 1;
		wrapper.style.width = `${~~(size.width / dpr)}px`;
		wrapper.style.height = `${~~(size.height / dpr)}px`;
		window.$stillbe.admin.testImage.clearTwentytwenty = false;
	};

	/**
	 * Update conversion information display
	 * @param {string} $targetSide - Target side ('left' or 'right')
	 * @param {Object} $result - Result object containing conversion info
	 */
	const updateConversionInfo = ($targetSide, $result) => {
		const elements = {
			quality: document.querySelector(`.sb-ti-quality.${$targetSide}`),
			editor: document.querySelector(`.sb-ti-editor.${$targetSide}`),
			mime: document.querySelector(`.sb-ti-mime.${$targetSide}`),
			size: document.querySelector(`.sb-ti-size.${$targetSide}`),
			time: document.querySelector(`.sb-ti-time.${$targetSide}`),
			memory: document.querySelector(`.sb-ti-memory.${$targetSide}`),
			mode: document.querySelector(`.sb-ti-mode.${$targetSide}`),
			compLevel: document.querySelector(`.sb-ti-comp-level.${$targetSide}`),
			ssim: document.querySelector(`.sb-ti-ssim.${$targetSide}`),
			ssimInDB: document.querySelector(`.sb-ti-ssim-in-db.${$targetSide}`),
			ssimTime: document.querySelector(`.sb-ti-ssim-time.${$targetSide}`),
		};

		if(elements.quality){
			elements.quality.innerText = $result.quality;
		}
		if(elements.editor){
			elements.editor.innerText = $result.editor || "-";
		}
		if(elements.mime){
			elements.mime.innerText = $result.mime;
		}
		if(elements.size){
			elements.size.innerHTML = `${sizeHumanReadable($result.size)}B<br>(${String($result.size).replace(/\B(?=(\d{3})+(?!\d))/g, ",")}B)`;
		}
		if(elements.time){
			elements.time.innerText = $result.time;
		}
		if(elements.memory){
			elements.memory.innerText = $result.memory;
		}
		if(elements.mode){
			elements.mode.innerText = $result.mode;
		}
		if(elements.compLevel){
			elements.compLevel.innerText = $result.compress;
		}
		if(elements.ssim && $result.ssim){
			elements.ssim.innerText = Number.parseFloat($result.ssim).toFixed(3);
		}
		if(elements.ssimInDB){
			const db = Number.parseFloat($result.ssimInDB);
			elements.ssimInDB.innerText = Number.isFinite(db) ? db.toFixed(2) : ($result.ssimInDB || "-");
		}
		if(elements.ssimTime){
			elements.ssimTime.innerText = $result.ssimTime;
		}
	};

	/**
	 * Create and initialize TwentyTwenty comparison container
	 * Sets up the image comparison interface with placeholder images
	 */
	const putContainerForTwentytwenty = () => {
		const compImages = document.getElementById("sb_compare_image");
		while(compImages.firstChild){
			compImages.firstChild.remove();
		}
		const container      = compImages.appendChild(document.createElement("div"));
		container.className  = "twentytwenty-container";
		const leftImage      = container.appendChild(new Image());
		leftImage.id         = "sb_iqc_test_image_left";
		leftImage.src        = dummy;
		leftImage.className  = "twentytwenty-image left";
		const rightImage     = container.appendChild(new Image());
		rightImage.id        = "sb_iqc_test_image_right";
		rightImage.src       = dummy;
		rightImage.className = "twentytwenty-image right";

		const compareValue = document.querySelector("#sb_compare_ssim .sb-compare-ssim-value");
		if(compareValue){
			compareValue.textContent = "-";
		}

		if(!window.$stillbe.admin.testImage.currentSizes){
			return;
		}
		const size   = window.$stillbe.admin.testImage.currentSizes[testSettings.size];
		const images = document.querySelectorAll("#sb_compare_image .twentytwenty-image");
		const dpr    = window.devicePixelRatio || 1;
		images.forEach($i => {
			$i.style.width  = `${~~(size.width / dpr)}px`;
			$i.style.heught = `${~~(size.heught / dpr)}px`;
		});
		// Initialized Flag
		window.$stillbe.admin.testImage.clearTwentytwenty = true;
	};

	/**
	 * Handle test setting changes and trigger image updates
	 * Called when any test parameter is modified
	 */
	const settingChange = function(){

		if(!window.$stillbe.admin.testImage.attachmentId){
			return false;
		}

		const changed = [];

		// Settings
		const size         = document.getElementById("sb_image_sizes"     ).value;
		const leftQuality  = document.getElementById("sb_ti_left_quality" ).value;
		const leftMime     = document.getElementById("sb_ti_left_mime"    ).value;
		const rightQuality = document.getElementById("sb_ti_right_quality").value;
		const rightMime    = document.getElementById("sb_ti_right_mime"   ).value;

		// Changed Settings
		if(testSettings.size !== size){
			testSettings.size          = size;
			testSettings.left.quality  = leftQuality;
			testSettings.left.mime     = leftMime;
			testSettings.left.filters  = {};
			testSettings.right.quality = rightQuality;
			testSettings.right.mime    = rightMime;
			testSettings.right.filters = {};
			changed.push("left", "right");
			// Put a Container Elements for TwentyTwenty
			putContainerForTwentytwenty();
		} else{
			if(testSettings.left.quality !== leftQuality){
				testSettings.left.quality = leftQuality;
				if(changed.indexOf("left") < 0){
					changed.push("left");
				}
			}
			if(testSettings.left.mime !== leftMime){
				testSettings.left.mime = leftMime;
				if(changed.indexOf("left") < 0){
					changed.push("left");
				}
			}
			if(testSettings.right.quality !== rightQuality){
				testSettings.right.quality = rightQuality;
				if(changed.indexOf("right") < 0){
					changed.push("right");
				}
			}
			if(testSettings.right.mime !== rightMime){
				testSettings.right.mime = rightMime;
				if(changed.indexOf("right") < 0){
					changed.push("right");
				}
			}
			// Toggle Options
			const classes = this.classList;
			if(classes.contains("toggle-option-radio")){
				const side = (this.getAttribute("name") || ".").split(".")[0];
				if(side === "left" || side === "right"){
					const filters = {};
					const radio   = document.querySelectorAll(`.toggle-option-radio.${side}`);
					radio.forEach($r => {
						if($r.checked && $r.value !== "-"){
							const filter = ($r.getAttribute("name") || ".").split(".")[1] || "";
							if(!filter){
								return null;
							}
							filters[filter] = $r.value;
						}
					});
					let isChanged = false;
					for(const filter in filters){
						if(testSettings[side].filters[filter] !== filters[filter]){
							isChanged = true;
							break;
						}
					}
					if(Object.keys(testSettings[side].filters).length !== Object.keys(filters).length){
						isChanged = true;
					}
					testSettings[side].filters = Object.assign({}, filters);
					if(isChanged){
						changed.push(side);
					}
				}
			}
		}

		// Get the Test Image(s)
		changed.forEach($targetSide => {
			// Query Parameters
			const param = {
				attachment_id : window.$stillbe.admin.testImage.attachmentId,
				size          : testSettings.size,
				quality       : testSettings[$targetSide].quality,
				mime          : testSettings[$targetSide].mime,
				filters       : testSettings[$targetSide].filters,
			};
			// Loading
			document.querySelector(`.side-label.${$targetSide}`).classList.add("loading");
			// Get Image
			getTestImage($targetSide, param);
		});

	};

	/**
	 * Fetch attachment sizes and metadata via REST
	 * @param {number} $id - Attachment ID
	 * @returns {Promise} Promise resolving to sizes object
	 */
	const getAttachmentSizes = async $id => {

		const json = await apiFetch()({
			path: `/${restBase()}/attachments/${encodeURIComponent($id)}/meta`,
		});

		if(!json.ok){
			return Promise.reject(json.message || "An error has occurred....");
		}

		// Original Dimension
		const width  = json.meta.width;
		const height = json.meta.height;

		// Size Array
		const sizes = {};
		const maxSizes = window.$stillbe.admin.testImage.sizes;
		for(const name in maxSizes){
			const maxSize = maxSizes[name];
			let _w = width;
			let _h = height;
			if(maxSize.crop){
				_w = Math.min(_w, maxSize.width);
				_h = Math.min(_h, maxSize.height);
			} else{
				if(maxSize.width > _w && maxSize.height > _h){
					continue;
				}
				const aspect = width / height;
				_w = maxSize.width;
				_h = Math.round(_w / aspect);
				if(maxSize.height > 0 && _h > maxSize.height){
					_h = maxSize.height;
					_w = Math.round(_h * aspect);
				}
			}
			sizes[name] = {
				width  : _w,
				height : _h,
				crop   : maxSize.crop,
			};
		}

		// Add Full Size to Sizes Array
		sizes.Original = {
			width  : width,
			height : height,
		};

		// Save the Sizes to Global Var
		window.$stillbe.admin.testImage.currentSizes = sizes;

		// Return the Sizes
		return Promise.resolve(sizes);

	};

	/**
	 * Initialize test settings event listeners
	 * Sets up change handlers for all test parameter inputs
	 */
	window.addEventListener("DOMContentLoaded", () => {

		const selector = ".sb-test-image-settings select, .sb-test-image-settings input[type=number]";

		document.querySelectorAll(selector).forEach($i => {
			$i.onchange   = settingChange;
			$i.onkeypress = $event => $event.key !== "Enter";
		});

		document.querySelectorAll(".toggle-option-radio").forEach($r => {
			$r.onclick = settingChange;
		});

		document.querySelectorAll('input[name="sb_compare_ssim_mode"]').forEach($r => {
			$r.addEventListener("change", () => {
				updateCompareSsim();
			});
		});

		syncCompareSsimModeUi();

	}, false);

	/**
	 * Initialize media selector and related UI elements
	 * Sets up image selection, deletion, and display functionality
	 */
	window.addEventListener("DOMContentLoaded", () => {

		const selectButton = document.getElementById("sb_select_img");
		const deleteButton = document.getElementById("sb_delete_img");
		const thumbImage   = document.getElementById("sb_thumb");
		const filename     = document.getElementById("sb_iqc_filename");
		const compImages   = document.getElementById("sb_compare_image");
		const listSizes    = document.getElementById("sb_image_sizes");
		const convertInfos = document.getElementsByClassName("sb-ti-info");

		const imgSelector  = wp.media({
			title    : selectButton.dataset.title,
			library  : { type : "image" },
			button   : { text : selectButton.dataset.submit },
			multiple : false, // "add",
		});

		selectButton.onclick = () => {
			imgSelector.open();
		};

		imgSelector.on("select", () => {
			const img   = imgSelector.state().get("selection").first().toJSON();
			const sizes = img.sizes;
			const thumb = sizes && sizes.medium ? sizes.medium.url : img.url;
			// Set Attachment ID
			window.$stillbe.admin.testImage.attachmentId = img.id;
			// Set the Sizes Option
			while(listSizes.firstChild && listSizes.dataset.init){
				listSizes.firstChild.remove();
			}
			// Set the Filename
			filename.innerText = img.filename;
			// Get Attachment Sizes
			getAttachmentSizes(img.id)   // via REST
				// Add Options
				.then($sizes => {
					const sizeArray = [];
					for(const key in $sizes){
						sizeArray.push({
							name: key,
							data: $sizes[key],
						});
					}
					listSizes.dataset.init = listSizes.dataset.init || listSizes.firstChild.innerText;
					while(listSizes.firstChild){
						listSizes.firstChild.remove();
					}
					sizeArray.sort(($a, $b) => {
						return $a.data.width * $a.data.height - $b.data.width * $b.data.height;
					});
					sizeArray.forEach($s => {
						const optElem = listSizes.appendChild(document.createElement("option"));
						optElem.value     = $s.name;
						optElem.innerText = `${$s.name} (${$s.data.width}x${$s.data.height})`;
					});
					if("medium" in $sizes){
						listSizes.value = "medium";
					}
					// Reset Settings
					testSettings.left  = {};
					testSettings.right = {};
					testSettings.size  = null;
				//	console.log(testSettings);
					// Run
					listSizes.onchange = settingChange;
					settingChange();
				})
				// Failed to Get Sizes
				.catch(console.error);
			// Set the Thumbnail
			thumbImage.dataset.none = thumbImage.dataset.none || thumbImage.src;
			thumbImage.src = thumb;
			// Clear the Comap Images
			while(compImages.firstChild){
				compImages.firstChild.remove();
			}
			// Clear Information
			Array.prototype.forEach.call(convertInfos, $s => {
				$s.innerText = "-";
			});
		});

		deleteButton.onclick = () => {
			thumbImage.src = thumbImage.dataset.none || dummy;
			filename.innerText = "";
			// Clear the Comap Images
			while(compImages.firstChild){
				compImages.firstChild.remove();
			}
			// Clear Information
			Array.prototype.forEach.call(convertInfos, $s => {
				$s.innerText = "-";
			});
			// Clear Size
			if(listSizes.dataset.init){
				while(listSizes.firstChild){
					listSizes.firstChild.remove();
				}
				listSizes.appendChild(document.createElement("option")).innerText = listSizes.dataset.init;
			}
		};

	}, false);

	/**
	 * Calculate and set optimal height for toggle options
	 * Ensures consistent height across all toggle option sections
	 * @returns {number} Calculated height or 0 if not applicable
	 */
	const handleToggleOptionsHeight = () => {

		const options = document.getElementsByClassName("toggle-options");
		const height  = Array.prototype.map.call(options, $o => $o.scrollHeight)
		                .reduce((_, h) => Math.max(_, h));

		if(height < 10){
			return 0;
		}

		const style = document.head.appendChild(document.createElement("style"));
		style.textContent = `.toggle-options-display:checked + .show-toggle-options + .toggle-options{max-height: ${~~height}px;}`;

		Array.prototype.forEach.call(options, $o => {
			$o.previousElementSibling.onclick = function(){
				Array.prototype.forEach.call(options, $_ => {
					$_.previousElementSibling.previousElementSibling.click();
				});
				return false;
			};
		});

		return height;

	};

	/**
	 * Initialize toggle options height calculation
	 * Handles delayed initialization for tabbed interface
	 */
	window.addEventListener("DOMContentLoaded", () => {

		if(handleToggleOptionsHeight() > 0){
			return true;
		}

		// 
		const tab = document.querySelector(".settings-tabs-wrapper label[for='tab_sb-imgq-ss-test-quality']");
		if(!tab){
			return null;
		}

		const setTestImageTabClickEvent = () => {
			
			if(handleToggleOptionsHeight() > 0){
				tab.removeEventListener("click", setTestImageTabClickEvent, false);
				return true;
			}

			setTimeout(handleToggleOptionsHeight, 200);
			tab.removeEventListener("click", setTestImageTabClickEvent, false);

		};

		tab.addEventListener("click", setTestImageTabClickEvent, false);

	}, false);

})();
