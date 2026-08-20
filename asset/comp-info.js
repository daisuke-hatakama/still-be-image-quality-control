window.addEventListener("DOMContentLoaded", function(){

	"use strict";


	const restBase = () => window.$stillbe.admin.restBase || "stillbe-iqc/v1";
	const apiFetch = window.wp && window.wp.apiFetch;

	const restTimeoutMs = () => {
		const sec = Number(window.$stillbe && window.$stillbe.admin && window.$stillbe.admin.restTimeoutSec);
		if(Number.isFinite(sec) && sec > 0){
			return Math.floor(sec * 1000);
		}
		return 600 * 1000;
	};

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


	const __ = $id => {
		if(!("translate" in window.$stillbe.admin)){
			return $id;
		}
		return window.$stillbe.admin.translate[$id] || $id;
	};


	const ssimToDb = ssim => {

		if(ssim == null || ssim === ""){
			return null;
		}

		const value = Number(ssim);
		if(!Number.isFinite(value) || value < 0 || value > 1){
			return null;
		}

		return (value >= 1 ? 9999 : -10 * Math.log10(1 - value)).toFixed(1);

	};


	const setQualityInfo = (cell, quality, ssim) => {

		cell.textContent = quality || "-";

		const ssimDb = ssimToDb(ssim);
		if(ssimDb != null){
			const detail = cell.appendChild(document.createElement("small"));
			detail.classList.add("info-table__quality-metrics");
			detail.textContent = `(${ssimDb} dB)`;
		}

	};


	const resolveWebpEncodeMode = compInfo => {

		if(compInfo.cwebp && compInfo.cwebp.method){
			return compInfo.cwebp.method;
		}
		if(compInfo["webp-method"]){
			return compInfo["webp-method"];
		}
		return compInfo["webp-quality"] ? "lossy" : "-";

	};


	const formatAvifQualityLabel = compInfo => {

		const quality = compInfo["avif-quality"];
		if(quality == null || quality === ""){
			return "-";
		}
		return quality;

	};


	const resolveAvifEncodeMode = compInfo => {

		if(compInfo["avif-method"]){
			return compInfo["avif-method"];
		}
		if(compInfo["avif-quality"] == null || compInfo["avif-quality"] === ""){
			return "-";
		}
		// Without stored method, do not infer lossless from quality alone (GD q=100 is still lossy).
		return "lossy";

	};


	const appendCompInfoRow = (table, subdirUrl, sizeName, sizeInfo, compInfo, mimeType) => {

		const tr = table.appendChild(document.createElement("tr"));
		const sizename = tr.appendChild(document.createElement("td"));
		const size     = tr.appendChild(document.createElement("td"));
		const mime     = tr.appendChild(document.createElement("td"));
		const filename = tr.appendChild(document.createElement("td"));
		const quality  = tr.appendChild(document.createElement("td"));
		const webpname = tr.appendChild(document.createElement("td"));
		const webp_q   = tr.appendChild(document.createElement("td"));
		const webp_m   = tr.appendChild(document.createElement("td"));
		const webp_z   = tr.appendChild(document.createElement("td"));
		const avifname = tr.appendChild(document.createElement("td"));
		const avif_q   = tr.appendChild(document.createElement("td"));
		const avif_m   = tr.appendChild(document.createElement("td"));

		sizename.innerText = sizeName;
		size    .innerText = `${sizeInfo.width}x${sizeInfo.height}`;
		mime    .innerText = mimeType || "-";
		filename.innerHTML = `<input type="url" size="25" value="${subdirUrl}/${sizeInfo.file}" disabled>`;
		webpname.innerHTML = "webp-file" in compInfo ? `<input type="url" size="25" value="${subdirUrl}/${compInfo["webp-file"]}" disabled>` : "-";
		webp_m  .innerText = resolveWebpEncodeMode(compInfo);
		webp_z  .innerText = compInfo["webp-lossless-level"] || (compInfo.cwebp && compInfo.cwebp.q) || "-";
		avifname.innerHTML = "avif-file" in compInfo ? `<input type="url" size="25" value="${subdirUrl}/${compInfo["avif-file"]}" disabled>` : "-";
		avif_m  .innerText = ("avif-file" in compInfo || "avif-quality" in compInfo) ? resolveAvifEncodeMode(compInfo) : "-";

		setQualityInfo(
			quality,
			compInfo.quality || (compInfo.cwebp && compInfo.cwebp.quality),
			compInfo.ssim
		);
		setQualityInfo(
			webp_q,
			compInfo["webp-quality"] || (compInfo.cwebp && compInfo.cwebp.quality),
			compInfo["webp-ssim"]
		);
		setQualityInfo(
			avif_q,
			formatAvifQualityLabel(compInfo),
			compInfo["avif-ssim"]
		);

	};


	const showInfoTable = $json => {

		const meta = $json.meta;
		const hasSizes = meta.sizes && Object.keys(meta.sizes).length > 0;
		const hasRootCompInfo = meta["sb-iqc"] && Object.keys(meta["sb-iqc"]).length > 0;

		if(!hasSizes && !hasRootCompInfo){
			return false;
		}

		const baseUrl   = window.$stillbe.uploadBaseUrl;   // without end '/'
		const subdirUrl = `${baseUrl}/${meta.file}`.replace(/\/[^\/]*$/, "");

		const back = document.body.appendChild(document.createElement("div"));
		back.classList.add("modal-back");
		back.onclick = function(){
			this.remove();
		};

		const fragment = new DocumentFragment();
		const wrapper  = fragment.appendChild(document.createElement("div"));
		const table    = wrapper.appendChild(document.createElement("table"));
		wrapper.classList.add("scroll-wrapper");
		table.classList.add("info-table");

		const thead = table.appendChild(document.createElement("thead"));
		const formatRow = thead.appendChild(document.createElement("tr"));
		const sizename = formatRow.appendChild(document.createElement("th"));
		const size     = formatRow.appendChild(document.createElement("th"));
		const original = formatRow.appendChild(document.createElement("th"));
		const webp     = formatRow.appendChild(document.createElement("th"));
		const avif     = formatRow.appendChild(document.createElement("th"));

		sizename.rowSpan = 2;
		size.rowSpan     = 2;
		original.colSpan = 3;
		webp.colSpan     = 4;
		avif.colSpan     = 3;

		sizename.innerText = __("Size Name");
		size.innerText     = __("Size");
		original.innerText = __("Original Format");
		webp.innerText     = "WebP";
		avif.innerText     = "AVIF";

		const detailRow = thead.appendChild(document.createElement("tr"));
		[
			__("Mime-Type"),
			__("Path"),
			null,
			__("Path"),
			null,
			__("Compression Mode"),
			__("Lossless Level"),
			__("Path"),
			null,
			__("Compression Mode"),
		].forEach(label => {
			const th = detailRow.appendChild(document.createElement("th"));
			if(label === null){
				th.append(
					document.createTextNode(__("Quality")),
					document.createElement("br"),
					document.createTextNode("(SSIM)")
				);
			}else{
				th.innerText = label;
			}
		});

		const tbody = table.appendChild(document.createElement("tbody"));

		const sizeNames = hasSizes ? Object.keys(meta.sizes).sort((_a, _b) => meta.sizes[_a].width - meta.sizes[_b].width) : [];

		for(const sizeName of sizeNames){
			const sizeInfo = meta.sizes[sizeName];
			appendCompInfoRow(
				tbody,
				subdirUrl,
				sizeName,
				sizeInfo,
				sizeInfo["sb-iqc"] || {},
				sizeInfo["mime-type"]
			);
		}

		if(hasRootCompInfo && meta.file){
			const originalFile = meta.file.split("/").pop();
			appendCompInfoRow(
				tbody,
				subdirUrl,
				__("Original"),
				{
					width: meta.width || 0,
					height: meta.height || 0,
					file: originalFile,
				},
				meta["sb-iqc"],
				$json.mime_type || "-"
			);
		}

		table.onclick = function($e){
			$e.stopPropagation();
		};

		back.append(fragment);

	};


	const showCompInfo = function(){

		const id = this.dataset.id || 0;

		if(!apiFetch){
			alert("An error has occurred....");
			return null;
		}

		apiFetch({ path: `/${restBase()}/attachments/${encodeURIComponent(id)}/meta` })
			.then(($json) => {
				if(!$json || !$json.ok){
					return Promise.reject(($json && $json.message) || "An error has occurred....");
				}
				return showInfoTable($json);
			})
			.catch(alert);

	};


	const setShowCompInfo = () => {

		const buttons = document.getElementsByClassName("show-comp-info");

		Array.from(buttons).forEach($b => {
			$b.onclick = showCompInfo;
		});

	};


	setShowCompInfo();




	const runingRecomp = {};

	const runRecomp = function(){

		const id = this.dataset.id || 0;

		if(!id){
			return null;
		}

		if(runingRecomp[id]){
			return null;
		}

		if(!apiFetch){
			alert("An error has occurred....");
			return null;
		}

		const _this      = this;
		_this.disabled   = true;
		runingRecomp[id] = true;

		const result  = this.nextElementSibling || this.parentNode.appendChild(document.createElement("p"));
		result.style.transition = "";
		result.style.opacity    = "";
		result.classList.add("result-message");
		result.innerText = __("Now processing...");

		const fin = $completed => {
			_this.disabled   = false;
			runingRecomp[id] = false;
			if($completed){
				result.style.transition = "1.2s";
				setTimeout(() => {
					if(runingRecomp[id]){
						return null;
					}
					result.style.opacity = "0";
				}, 6400);
			}
		};

		const fetchOptions = {
			path: `/${restBase()}/attachments/${encodeURIComponent(id)}/regenerate`,
			method: "POST",
		};
		const signal = restTimeoutSignal(restTimeoutMs());
		if(signal){
			fetchOptions.signal = signal;
		}

		apiFetch(fetchOptions)
			.then(($json) => {
				if(!$json || !$json.ok){
					return Promise.reject(($json && $json.message) || "An error has occurred....");
				}
				console.log($json);
				result.innerText = $json.message;
				fin(true);
			})
			.catch(($error) => {
				result.innerText = ($error && $error.message) || $error || "An error has occurred....";
				fin(false);
			});

	};


	const setRunRecomp = () => {

		const buttons = document.getElementsByClassName("run-recomp");

		Array.from(buttons).forEach($b => {
			$b.onclick = runRecomp;
		});

	};


	setRunRecomp();


	const pollOptimizeStatus = () => {

		if(!apiFetch){
			return;
		}

		const liveCells = document.querySelectorAll("[data-sb-iqc-ao-live]");

		Array.from(liveCells).forEach($cell => {

			const id = $cell.dataset.sbIqcAoLive || 0;
			if(!id){
				return;
			}

			apiFetch({ path: `/${restBase()}/attachments/${encodeURIComponent(id)}/optimize-status` })
				.then($json => {
					if(!$json || !$json.ok || !$json.html){
						return;
					}
					$cell.outerHTML = $json.html;
				})
				.catch(() => {});

		});

	};


	if(apiFetch){
		setInterval(pollOptimizeStatus, 2000);
	}


}, false);
