import luminanceWgsl from '@stillbe-iqc/ssim-shader-luminance';
import blurWgsl from '@stillbe-iqc/ssim-shader-blur';
import ssimWgsl from '@stillbe-iqc/ssim-shader-local';
import reducePartialWgsl from '@stillbe-iqc/ssim-shader-reduce-partial';
import reduceFinalWgsl from '@stillbe-iqc/ssim-shader-reduce-final';

const SSIM_WEBGPU_SHADERS = {
	luminance: luminanceWgsl,
	blur: blurWgsl,
	ssim: ssimWgsl,
	reducePartial: reducePartialWgsl,
	reduceFinal: reduceFinalWgsl,
};




/**
 * StillBE SSIM (WebGPU)
 *
 * 前処理（輝度化・任意のぼかし）から局所 SSIM、集約までを GPU 上で完結させる。
 * CPU へ読み戻すのは最終集計（sum / count / min / max）の 16 bytes のみ。
 *
 * dense: true のときは全画素を窓中心として MSSIM を計算する（開発者向け精密比較用）。
 *
 * @example
 * const ssim = new StillBE_SSIM_WebGPU(imgA, imgB);
 * const result = await ssim.calc({ dense: true });
 * // { mssim, min, max, times, sampleCount, mode: 'dense'|'sparse', backend: 'webgpu' }
 */
class StillBE_SSIM_WebGPU {

	static get WINDOW_PARAM_RADIUS(){ return 5;   }
	static get WINDOW_PARAM_SIGMA(){  return 1.7; }
	static get SSIM_CONSTANT_K1(){ return 0.01; }
	static get SSIM_CONSTANT_K2(){ return 0.03; }

	/** Canvas filter blur を近似するときの σ ≈ radius * この係数 */
	static get BLUR_SIGMA_SCALE(){ return 0.4; }

	/** @type {GPUDevice|null} */
	static #device = null;

	/** @type {GPUAdapter|null} */
	static #adapter = null;

	/** @type {Promise<GPUDevice>|null} */
	static #devicePromise = null;

	original = null;
	compare  = null;


	/**
	 * @param {HTMLImageElement|HTMLCanvasElement|OffscreenCanvas|ImageBitmap|null} $original
	 * @param {HTMLImageElement|HTMLCanvasElement|OffscreenCanvas|ImageBitmap|null} $compare
	 */
	constructor($original = null, $compare = null){
		this.original = $original;
		this.compare  = $compare;
	}


	/**
	 * WebGPU が利用可能か調べる（初回は adapter/device を初期化する）
	 * @returns {Promise<boolean>}
	 */
	static async isSupported(){
		try {
			await this.#ensureDevice();
			return true;
		} catch(_e) {
			return false;
		}
	}


	/**
	 * @returns {Promise<GPUDevice>}
	 */
	static async #ensureDevice(){
		if(this.#device){
			return this.#device;
		}
		if(this.#devicePromise){
			return this.#devicePromise;
		}
		this.#devicePromise = (async () => {
			if(!navigator.gpu){
				throw new Error("WebGPU is not available in this browser.");
			}
			const adapter = await navigator.gpu.requestAdapter();
			if(!adapter){
				throw new Error("Failed to acquire a WebGPU adapter.");
			}
			const device = await adapter.requestDevice();
			device.lost.then(($info) => {
				console.warn("[StillBE SSIM WebGPU] device lost:", $info?.message || $info);
				StillBE_SSIM_WebGPU.#device = null;
				StillBE_SSIM_WebGPU.#adapter = null;
				StillBE_SSIM_WebGPU.#devicePromise = null;
			});
			this.#adapter = adapter;
			this.#device  = device;
			return device;
		})();
		try {
			return await this.#devicePromise;
		} catch($e) {
			this.#devicePromise = null;
			throw $e;
		}
	}


	/**
	 * @param {Object} [$params]
	 * @param {boolean} [$params.dense=false] 全画素を窓中心として計算する
	 * @param {number}  [$params.k1]
	 * @param {number}  [$params.k2]
	 * @param {number}  [$params.radius]
	 * @param {number}  [$params.sigma]
	 * @param {number}  [$params.guassianBlur=0] 輝度化後のガウスぼかし半径 (px)
	 * @returns {Promise<{mssim:number, min:number, max:number, times:number[], sampleCount:number, mode:string, backend:string}>}
	 */
	async calc($params = null){

		const params = $params || {};
		const dense  = !!params.dense;
		const radius = Math.max(1, parseInt(params.radius ?? this.constructor.WINDOW_PARAM_RADIUS, 10) || this.constructor.WINDOW_PARAM_RADIUS);
		const sigma  = Math.abs(params.sigma ?? this.constructor.WINDOW_PARAM_SIGMA) || this.constructor.WINDOW_PARAM_SIGMA;
		const k1     = params.k1 ?? this.constructor.SSIM_CONSTANT_K1;
		const k2     = params.k2 ?? this.constructor.SSIM_CONSTANT_K2;
		const blurRadius = Math.max(0, parseInt(params.guassianBlur, 10) || 0);

		const size = this.#assertSameSize();
		const { width, height } = size;
		const pixelCount = width * height;

		const t0 = performance.now();
		const device = await this.constructor.#ensureDevice();

		const stepX = dense ? 1 : (radius + 1);
		const stepY = dense ? 1 : (radius * 2 + 1);
		const gridX = dense ? width  : (Math.floor((width  - 1) / stepX) + 1);
		const gridY = dense ? height : (Math.floor((height - 1) / stepY) + 1);
		const capacity = gridX * gridY;

		const il = 255;
		const c1 = (k1 * il) ** 2;
		const c2 = (k2 * il) ** 2;

		const windowCoeffs = this.#calcWindowCoefficient(radius, sigma);
		const blurKernel   = blurRadius > 0
			? this.#calcBlurKernel(blurRadius, blurRadius * this.constructor.BLUR_SIGMA_SCALE)
			: null;

		const toDestroy = [];
		const retain = ($buf) => { toDestroy.push($buf); return $buf; };

		const texA = await this.#createSourceTexture(device, this.original, width, height);
		const texB = await this.#createSourceTexture(device, this.compare,  width, height);
		retain(texA);
		retain(texB);
		const tUploaded = performance.now();

		const sampler = device.createSampler({
			magFilter: "nearest",
			minFilter: "nearest",
		});

		const lumaParams = retain(this.#writeParams(device, "luma-params", [
			["u32", width], ["u32", height], ["u32", 0], ["u32", 0],
		]));

		const lumA0 = retain(this.#createFloatStorage(device, pixelCount, "lum-a-0"));
		const lumB0 = retain(this.#createFloatStorage(device, pixelCount, "lum-b-0"));

		let lumA = lumA0;
		let lumB = lumB0;
		let blurKernelBuf = null;
		let lumA1 = null, lumA2 = null, lumB1 = null, lumB2 = null;

		if(blurKernel){
			blurKernelBuf = retain(this.#createFloatStorageFromData(device, blurKernel, "blur-kernel"));
			lumA1 = retain(this.#createFloatStorage(device, pixelCount, "lum-a-1"));
			lumA2 = retain(this.#createFloatStorage(device, pixelCount, "lum-a-2"));
			lumB1 = retain(this.#createFloatStorage(device, pixelCount, "lum-b-1"));
			lumB2 = retain(this.#createFloatStorage(device, pixelCount, "lum-b-2"));
			lumA = lumA2;
			lumB = lumB2;
		}

		const windowBuffer = retain(this.#createFloatStorageFromData(device, windowCoeffs, "ssim-window"));

		const ssimParams = retain(this.#writeParams(device, "ssim-params", [
			["u32", width],
			["u32", height],
			["u32", radius],
			["u32", dense ? 1 : 0],
			["u32", stepX],
			["u32", stepY],
			["u32", gridX],
			["u32", gridY],
			["f32", c1],
			["f32", c2],
			["u32", capacity],
			["u32", 0],
		]));

		const localBuffer = retain(device.createBuffer({
			label: "ssim-local",
			size: Math.max(4, capacity * 4),
			usage: GPUBufferUsage.STORAGE | GPUBufferUsage.COPY_DST,
		}));
		device.queue.writeBuffer(localBuffer, 0, new Float32Array(capacity).fill(Number.NaN));

		const partialCount = Math.ceil(capacity / 256);
		const reduceParams = retain(this.#writeParams(device, "reduce-params", [
			["u32", capacity], ["u32", partialCount], ["u32", 0], ["u32", 0],
		]));
		const partialBuffer = retain(device.createBuffer({
			label: "ssim-partial-stats",
			size: Math.max(16, partialCount * 16),
			usage: GPUBufferUsage.STORAGE | GPUBufferUsage.COPY_DST,
		}));
		const finalStatsBuffer = retain(device.createBuffer({
			label: "ssim-final-stats",
			size: 16,
			usage: GPUBufferUsage.STORAGE | GPUBufferUsage.COPY_SRC | GPUBufferUsage.COPY_DST,
		}));
		device.queue.writeBuffer(finalStatsBuffer, 0, new Float32Array([0, 0, 1, 0]));

		const readBuffer = retain(device.createBuffer({
			label: "ssim-stats-read",
			size: 16,
			usage: GPUBufferUsage.COPY_DST | GPUBufferUsage.MAP_READ,
		}));

		const shaders = SSIM_WEBGPU_SHADERS;
		const pipeLuma = device.createComputePipeline({
			layout: "auto",
			compute: { module: device.createShaderModule({ code: shaders.luminance }), entryPoint: "main" },
		});
		const pipeBlur = device.createComputePipeline({
			layout: "auto",
			compute: { module: device.createShaderModule({ code: shaders.blur }), entryPoint: "main" },
		});
		const pipeSsim = device.createComputePipeline({
			layout: "auto",
			compute: { module: device.createShaderModule({ code: shaders.ssim }), entryPoint: "main" },
		});
		const pipeRed1 = device.createComputePipeline({
			layout: "auto",
			compute: { module: device.createShaderModule({ code: shaders.reducePartial }), entryPoint: "main" },
		});
		const pipeRed2 = device.createComputePipeline({
			layout: "auto",
			compute: { module: device.createShaderModule({ code: shaders.reduceFinal }), entryPoint: "main" },
		});

		const encoder = device.createCommandEncoder({ label: "stillbe-ssim-full" });

		// 1) RGBA texture → luminance (0..255)
		this.#encodeLuma(encoder, pipeLuma, device, texA, sampler, lumaParams, lumA0, width, height);
		this.#encodeLuma(encoder, pipeLuma, device, texB, sampler, lumaParams, lumB0, width, height);

		// 2) Optional separable Gaussian blur
		if(blurKernel){
			const kernelLen = blurKernel.length;
			const bpAH = retain(this.#writeParams(device, "blur-a-h", [
				["u32", width], ["u32", height], ["u32", kernelLen], ["u32", 0],
			]));
			const bpAV = retain(this.#writeParams(device, "blur-a-v", [
				["u32", width], ["u32", height], ["u32", kernelLen], ["u32", 1],
			]));
			const bpBH = retain(this.#writeParams(device, "blur-b-h", [
				["u32", width], ["u32", height], ["u32", kernelLen], ["u32", 0],
			]));
			const bpBV = retain(this.#writeParams(device, "blur-b-v", [
				["u32", width], ["u32", height], ["u32", kernelLen], ["u32", 1],
			]));
			this.#encodeBlur(encoder, pipeBlur, device, bpAH, blurKernelBuf, lumA0, lumA1, width, height);
			this.#encodeBlur(encoder, pipeBlur, device, bpAV, blurKernelBuf, lumA1, lumA2, width, height);
			this.#encodeBlur(encoder, pipeBlur, device, bpBH, blurKernelBuf, lumB0, lumB1, width, height);
			this.#encodeBlur(encoder, pipeBlur, device, bpBV, blurKernelBuf, lumB1, lumB2, width, height);
		}

		// 3) Local SSIM
		{
			const bg = device.createBindGroup({
				layout: pipeSsim.getBindGroupLayout(0),
				entries: [
					{ binding: 0, resource: { buffer: ssimParams } },
					{ binding: 1, resource: { buffer: lumA } },
					{ binding: 2, resource: { buffer: lumB } },
					{ binding: 3, resource: { buffer: windowBuffer } },
					{ binding: 4, resource: { buffer: localBuffer } },
				],
			});
			const pass = encoder.beginComputePass();
			pass.setPipeline(pipeSsim);
			pass.setBindGroup(0, bg);
			pass.dispatchWorkgroups(Math.ceil(gridX / 8), Math.ceil(gridY / 8), 1);
			pass.end();
		}

		// 4) Partial reduce
		{
			const bg = device.createBindGroup({
				layout: pipeRed1.getBindGroupLayout(0),
				entries: [
					{ binding: 0, resource: { buffer: reduceParams } },
					{ binding: 1, resource: { buffer: localBuffer } },
					{ binding: 2, resource: { buffer: partialBuffer } },
				],
			});
			const pass = encoder.beginComputePass();
			pass.setPipeline(pipeRed1);
			pass.setBindGroup(0, bg);
			pass.dispatchWorkgroups(partialCount, 1, 1);
			pass.end();
		}

		// 5) Final reduce
		{
			const bg = device.createBindGroup({
				layout: pipeRed2.getBindGroupLayout(0),
				entries: [
					{ binding: 0, resource: { buffer: reduceParams } },
					{ binding: 1, resource: { buffer: partialBuffer } },
					{ binding: 2, resource: { buffer: finalStatsBuffer } },
				],
			});
			const pass = encoder.beginComputePass();
			pass.setPipeline(pipeRed2);
			pass.setBindGroup(0, bg);
			pass.dispatchWorkgroups(1, 1, 1);
			pass.end();
		}

		encoder.copyBufferToBuffer(finalStatsBuffer, 0, readBuffer, 0, 16);
		device.queue.submit([encoder.finish()]);

		await readBuffer.mapAsync(GPUMapMode.READ);
		const stats = new Float32Array(readBuffer.getMappedRange().slice(0));
		readBuffer.unmap();
		const tDone = performance.now();

		const sum   = stats[0];
		const count = stats[1];
		const minV  = stats[2];
		const maxV  = stats[3];

		for(const obj of toDestroy){
			if(obj && typeof obj.destroy === "function"){
				obj.destroy();
			}
		}

		if(!(count > 0)){
			throw new Error("No SSIM samples were computed.");
		}

		return {
			mssim       : sum / count,
			min         : minV,
			max         : maxV,
			sampleCount : count,
			mode        : dense ? "dense" : "sparse",
			backend     : "webgpu",
			times       : [
				tUploaded - t0,    // texture upload
				tDone - tUploaded, // GPU pipeline + map 16B
				tDone - t0,        // total
			],
		};

	}


	#assertSameSize(){
		const a = this.#getSize(this.original);
		const b = this.#getSize(this.compare);
		if(!a.width || !a.height || a.width !== b.width || a.height !== b.height){
			throw new Error("The images to be compared are of different sizes. Please specify images of the same size.");
		}
		return a;
	}


	#getSize($source){
		if(!$source){
			return { width: 0, height: 0 };
		}
		if($source instanceof HTMLImageElement){
			return { width: $source.naturalWidth || $source.width, height: $source.naturalHeight || $source.height };
		}
		if($source instanceof HTMLCanvasElement || (typeof OffscreenCanvas !== "undefined" && $source instanceof OffscreenCanvas)){
			return { width: $source.width, height: $source.height };
		}
		if(typeof ImageBitmap !== "undefined" && $source instanceof ImageBitmap){
			return { width: $source.width, height: $source.height };
		}
		return { width: 0, height: 0 };
	}


	async #createSourceTexture($device, $source, $width, $height){
		const bitmap = await createImageBitmap($source, {
			resizeWidth: $width,
			resizeHeight: $height,
			resizeQuality: "pixelated",
		});
		const texture = $device.createTexture({
			label: "ssim-source-rgba",
			size: [$width, $height],
			format: "rgba8unorm",
			usage:
				GPUTextureUsage.TEXTURE_BINDING |
				GPUTextureUsage.COPY_DST |
				GPUTextureUsage.RENDER_ATTACHMENT,
		});
		$device.queue.copyExternalImageToTexture(
			{ source: bitmap },
			{ texture },
			[$width, $height]
		);
		bitmap.close();
		return texture;
	}


	#createFloatStorage($device, $floatCount, $label){
		return $device.createBuffer({
			label: $label,
			size: Math.max(4, $floatCount * 4),
			usage: GPUBufferUsage.STORAGE | GPUBufferUsage.COPY_DST | GPUBufferUsage.COPY_SRC,
		});
	}


	#createFloatStorageFromData($device, $float32Array, $label){
		const buffer = $device.createBuffer({
			label: $label,
			size: Math.max(4, $float32Array.byteLength),
			usage: GPUBufferUsage.STORAGE | GPUBufferUsage.COPY_DST,
		});
		$device.queue.writeBuffer(buffer, 0, $float32Array);
		return buffer;
	}


	#writeParams($device, $label, $entries){
		const bytes = new ArrayBuffer($entries.length * 4);
		const u32 = new Uint32Array(bytes);
		const f32 = new Float32Array(bytes);
		$entries.forEach(([$type, $value], $i) => {
			if($type === "f32"){
				f32[$i] = $value;
			} else {
				u32[$i] = $value >>> 0;
			}
		});
		const buffer = $device.createBuffer({
			label: $label,
			size: bytes.byteLength,
			usage: GPUBufferUsage.UNIFORM | GPUBufferUsage.COPY_DST,
		});
		$device.queue.writeBuffer(buffer, 0, bytes);
		return buffer;
	}


	#encodeLuma($encoder, $pipeline, $device, $texture, $sampler, $params, $out, $w, $h){
		const bg = $device.createBindGroup({
			layout: $pipeline.getBindGroupLayout(0),
			entries: [
				{ binding: 0, resource: { buffer: $params } },
				{ binding: 1, resource: $texture.createView() },
				{ binding: 2, resource: $sampler },
				{ binding: 3, resource: { buffer: $out } },
			],
		});
		const pass = $encoder.beginComputePass();
		pass.setPipeline($pipeline);
		pass.setBindGroup(0, bg);
		pass.dispatchWorkgroups(Math.ceil($w / 8), Math.ceil($h / 8), 1);
		pass.end();
	}


	#encodeBlur($encoder, $pipeline, $device, $params, $kernel, $src, $dst, $w, $h){
		const bg = $device.createBindGroup({
			layout: $pipeline.getBindGroupLayout(0),
			entries: [
				{ binding: 0, resource: { buffer: $params } },
				{ binding: 1, resource: { buffer: $kernel } },
				{ binding: 2, resource: { buffer: $src } },
				{ binding: 3, resource: { buffer: $dst } },
			],
		});
		const pass = $encoder.beginComputePass();
		pass.setPipeline($pipeline);
		pass.setBindGroup(0, bg);
		pass.dispatchWorkgroups(Math.ceil($w / 8), Math.ceil($h / 8), 1);
		pass.end();
	}


	#calcWindowCoefficient($radius, $sigma){
		const c = 1 / (Math.sqrt(2 * Math.PI) * $sigma);
		const t = 2 * ($sigma ** 2);
		const size = $radius * 2 + 1;
		const window = new Float32Array(size * size);
		let integrate = 0;
		for(let y = -$radius; y <= $radius; ++y){
			for(let x = -$radius; x <= $radius; ++x){
				const i = (y + $radius) * size + (x + $radius);
				const g = (c * Math.exp(-((x ** 2) / t))) * (c * Math.exp(-((y ** 2) / t)));
				window[i] = g;
				integrate += g;
			}
		}
		for(let i = 0; i < window.length; ++i){
			window[i] /= integrate;
		}
		return window;
	}


	#calcBlurKernel($radius, $sigma){
		const r = Math.max(1, $radius | 0);
		const sigma = Math.max(0.01, $sigma);
		const c = 1 / (Math.sqrt(2 * Math.PI) * sigma);
		const t = 2 * (sigma ** 2);
		const k = new Float32Array(r * 2 + 1);
		let sum = 0;
		for(let i = -r; i <= r; ++i){
			const v = c * Math.exp(-((i ** 2) / t));
			k[i + r] = v;
			sum += v;
		}
		for(let i = 0; i < k.length; ++i){
			k[i] /= sum;
		}
		return k;
	}

}


export { StillBE_SSIM_WebGPU };
if (typeof globalThis !== 'undefined') {
	globalThis.StillBE_SSIM_WebGPU = StillBE_SSIM_WebGPU;
}
