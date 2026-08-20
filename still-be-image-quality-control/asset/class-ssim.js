class StillBE_SSIM {

	/**
	 * Window parameter radius for SSIM calculation
	 * @returns {number} The radius value for the window
	 */
	static get WINDOW_PARAM_RADIUS(){ return 5;   }

	/**
	 * Window parameter sigma for SSIM calculation
	 * @returns {number} The sigma value for the window
	 */
	static get WINDOW_PARAM_SIGMA(){  return 1.7; }

	/**
	 * SSIM constant K1 for stability
	 * @returns {number} The K1 constant value
	 */
	static get SSIM_CONSTANT_K1(){ return 0.01; }

	/**
	 * SSIM constant K2 for stability
	 * @returns {number} The K2 constant value
	 */
	static get SSIM_CONSTANT_K2(){ return 0.03; }

	/**
	 * Number of parallel threads to use for processing
	 * @returns {number} The number of parallel threads (max 32, divided by 4)
	 */
	static get PARALLEL_THREADS(){
		let maxThreads = 1;
		if(navigator.hardwareConcurrency){
			maxThreads = navigator.hardwareConcurrency;
		}
		maxThreads = Math.min(maxThreads, 32);
		return Math.floor(maxThreads / 4);
	}


	original = null;
	compare  = null;

	#windowCoefficient = null;

	#workerResults = [];

	#receiveWorkerResultBinded = null;

	#drwA = null;
	#drwB = null;

	#colorA = null;
	#colorB = null;


	/**
	 * Constructor for StillBE_SSIM class
	 * @param {HTMLImageElement|HTMLCanvasElement} $original - The original image to compare
	 * @param {HTMLImageElement|HTMLCanvasElement} $compare - The image to compare against
	 */
	constructor($original = null, $compare = null){

		// 元画像
		this.original = $original;

		// 比較画像
		this.compare  = $compare;

		// 重み付けのガウス分布を計算しておく
		// 積算値が1.0になるように正規化済み
		this.#windowCoefficient = this.#calcWindowCoefficient();

	}


	/**
	 * Calculates the SSIM (Structural Similarity Index) between two images
	 * @param {Object} $params - Optional parameters for SSIM calculation
	 * @param {number} $params.k1 - K1 constant override
	 * @param {number} $params.k2 - K2 constant override
	 * @param {number} $params.radius - Window radius override
	 * @param {number} $params.guassianBlur - Blur radius for preprocessing
	 * @param {boolean} $params.forceSingleThread - Force single thread processing
	 * @returns {Promise<Object>} Object containing SSIM results and timing information
	 */
	calc($params = null){

		if(self.document &&
		    (!(this.original instanceof HTMLImageElement) || !(this.compare instanceof HTMLImageElement)) &&
		      (!(this.original instanceof HTMLCanvasElement) || !(this.compare instanceof HTMLCanvasElement))){
			throw new Error("Image not loaded. Please load a valid image.");
		}

		const originalSize = { width: this.original.naturalWidth || this.original.width, height: this.original.naturalHeight || this.original.height };
		const comapreSize  = { width: this.compare.naturalWidth  || this.compare.width,  height: this.compare.naturalHeight  || this.compare.height  };

		if(!originalSize.width || originalSize.width !== comapreSize.width || originalSize.height !== comapreSize.height){
			throw new Error("The images to be compared are of different sizes. Please specify images of the same size.");
		}

		const canvases = !self.document || this.original instanceof HTMLCanvasElement ? this : this.#preparePairGrayscaleCavas($params?.guassianBlur || 0);
		this.#drwA = canvases.original.getContext("2d", { willReadFrequently: true });
		this.#drwB = canvases.compare .getContext("2d", { willReadFrequently: true });

		const size = originalSize;

		const depth = 8;

		if(!this.#windowCoefficient){
			this.#windowCoefficient = this.#calcWindowCoefficient();
		}

		const w = this.#windowCoefficient;

		const k1 = $params?.k1 || this.constructor.SSIM_CONSTANT_K1;
		const k2 = $params?.k2 || this.constructor.SSIM_CONSTANT_K2;
		const il = 2 ** depth - 1;

		const c1 = ( k1 * il ) ** 2;
		const c2 = ( k2 * il ) ** 2;
		const c3 = c2 / 2;   // 使用しない

		const r = $params?.radius || this.constructor.WINDOW_PARAM_RADIUS;
		const m = ( 2 * r + 1 ) ** 2;

		const _this = this;
		this.#workerResults = [];

		return new Promise((resolve, reject) => {

			if(!$params?.forceSingleThread && "Worker" in window && window.__classScriptSSIM?.src){

				const currentScriptSrc = window.__classScriptSSIM.src;

				const parallelThreads = this.constructor.PARALLEL_THREADS;
				const interval        = Math.ceil(size.width / parallelThreads);

				const generateWorkerPromises = [];

				for(let i = 0; i < parallelThreads; ++i){

					generateWorkerPromises.push((async () => {

						const thisClass        = await fetch(currentScriptSrc);
						const thisClassContent = await thisClass.text();

						const workerScriptContent  = `
							${thisClassContent}
							// シングルスレッドで割り当てられた範囲の MeanSSIM を計算する
							self.onmessage = async $e => {
							//	if($e.origin !== self.location.origin) return;
								if($e.data?.index != ${i} || !$e.data?.blobs || !$e.data?.canvases || $e.data.blobs.length !== $e.data.canvases.length) return;
								const ctxs  = await Promise.all($e.data.blobs.map(async $blob => {
									const bmp  = await createImageBitmap($blob);
									return bmp;
								}));
								for(let i = 0, n = $e.data.canvases.length; i < n; ++i){
									$e.data.canvases[i].getContext("2d", { willReadFrequently: true }).drawImage(ctxs[i], 0, 0);
								}
								const ssim   = new StillBE_SSIM(...$e.data.canvases);
								const result = await ssim.calc({
									forceSingleThread : true,
									startX            : ${i * interval},
									interval          : ${interval},
								});
								const data = {
									ok   : true,
									data : {
										index : $e.data.index,
										mssim : result.mssim,
										time  : result.times[0],   // [ms]
									},
								}
								self.postMessage(data);
							};
						`;

						const workerScriptBlob = new Blob([workerScriptContent], {type: "text/javascript"});
						const workerScriptSrc  = URL.createObjectURL(workerScriptBlob);

						const worker = new Worker(workerScriptSrc);

						return worker;

					})());

				}

				_this.#receiveWorkerResultBinded = _this.#receiveWorkerResult.bind(_this, resolve);

				Promise.all(generateWorkerPromises)
				.then($workers => {
					$workers.forEach(async ($worker, $index) => {
						const blobs = [
							await _this.#getTargetBlob(_this.original),
							await _this.#getTargetBlob(_this.compare),
						];
						const offscreenCanvases = [
							new OffscreenCanvas(size.width, size.height),
							new OffscreenCanvas(size.width, size.height),
						];
						$worker.addEventListener("message", _this.#receiveWorkerResultBinded, false);
						return $worker.postMessage({
							index    : $index,
							blobs    : blobs,
							canvases : offscreenCanvases,
						}, offscreenCanvases);
					});
				});

			} else {

				const startX   = $params?.startX   || 0;
				const interval = $params?.interval || size.width;

				const startTime = performance.now();
				const mssim     = this.#subSsim( startX, interval, size, r, m, w, c1, c2 );
				const spentTime = performance.now() - startTime;

				resolve({
					times  : [ spentTime ],
					mssim  : mssim,
				});

			}

		});

	}


	/**
	 * Gets the source URL of an image element
	 * @param {HTMLImageElement|HTMLCanvasElement} $imageElement - The image element to get source from
	 * @returns {Promise<string>} The source URL of the image
	 * @private
	 */
	async #getTargetBlob($imageElement){

		if($imageElement instanceof HTMLImageElement){
			const bitmap    = await createImageBitmap($imageElement);
			const offscreen = new OffscreenCanvas(bitmap.width, bitmap.height);
			const ctx       = offscreen.getContext("2d");
			ctx.drawImage(bitmap, 0, 0);
			const blob = await offscreen.convertToBlob({ type: 'image/png' });
			bitmap.close();
			return blob;
		}

		if($imageElement instanceof HTMLCanvasElement){
			return await new Promise((_resolve, _rejct) => $imageElement.toBlob($blob => _resolve($blob)));
		}

		return "";

	}


	/**
	 * Handles worker result messages and aggregates SSIM calculations
	 * @param {Function} $resolve - Promise resolve function
	 * @param {MessageEvent} $e - Worker message event
	 * @private
	 */
	#receiveWorkerResult($resolve, $e){

	//	if($e.origin !== self.location.origin) return;
		if(!$e.data?.ok) return;

		$e.target.removeEventListener("message", this.#receiveWorkerResultBinded, false);
		$e.target.terminate();   // 停止

		this.#workerResults.push($e.data);

		if(this.#workerResults.length === this.constructor.PARALLEL_THREADS){

			const workerCount = this.#workerResults.length;

			const result = this.#workerResults.reduce((_acc, _cur, _idx) => {
				_acc.mssim += (_cur.data.mssim || 0) / workerCount;
				_acc.times.push(_cur.data.time);
				return _acc;
			}, {
				times : [],
				mssim : 0,
			});

			$resolve(result);

		}

	}


	/**
	 * Calculates SSIM for a specific region of the images
	 * @param {number} $startX - Starting X coordinate
	 * @param {number} $intervalX - Width of the region to process
	 * @param {Object} $imageSize - Size of the images
	 * @param {number} $r - Window radius
	 * @param {number} $m - Window size
	 * @param {Object} $w - Window coefficients
	 * @param {number} $c1 - SSIM constant C1
	 * @param {number} $c2 - SSIM constant C2
	 * @returns {number} The calculated SSIM value for the region
	 * @private
	 */
	#subSsim( $startX, $intervalX, $imageSize, $r, $m, $w, $c1, $c2 ){

		const [drwA, drwB] = [this.#drwA, this.#drwB];

		let ssim = 0;
		let n    = 0;

		const startX = Math.floor($startX);
		const endX   = Math.min($startX + $intervalX, $imageSize['width']);

		const d      = $r * 2 + 1;
		let   offset = $r;

	// 正規化済みのため使用しない
	//	const mm = Object.values($w).reduce(($curr, $item) => $curr + Object.values($item).reduce((_curr, _item) => _curr + _item, 0), 0 );

		this.#colorA = drwA.getImageData(startX - $r, - $r, $intervalX + $r * 2, $imageSize.height + $r * 2).data.reduce((_acc, _cur, _idx) => {
			if((_idx - $r) % 4 !== 0) return _acc;
			const i = (_idx - $r) / 4;
			const w = $intervalX + $r * 2;
			const y = ~~(i / w);
			const x = i % w;
			_acc[ x ] = x in _acc ? _acc[ x ] : {};
			_acc[ x ][ y ] = _cur;
			return _acc;
		}, {});

		this.#colorB = drwB.getImageData(startX - $r, - $r, $intervalX + $r * 2, $imageSize.height + $r * 2).data.reduce((_acc, _cur, _idx) => {
			if((_idx - $r) % 4 !== 0) return _acc;
			const i = (_idx - $r) / 4;
			const w = $intervalX + $r * 2;
			const y = ~~(i / w);
			const x = i % w;
			_acc[ x ] = x in _acc ? _acc[ x ] : {};
			_acc[ x ][ y ] = _cur;
			return _acc;
		}, {});

		/**
		 * SSIM の計算負荷を抑えるために X 軸は微小エリアの幅の約半分 ($r + 1) をスキップし、
		 * Y 軸は微小エリアの幅ほど ($d = $r * 2 + 1) スキップする
		 * 
		 * ガウス関数による重み付けで影響が小さくなる微小領域の頂点に近いも網羅されるように
		 * Y 軸は1回ごとに $r だけオフセットさせる
		 * 
		 */
		for(let x = startX; x < endX; x += $r + 1){
			offset = $r - offset;
			for(let y = offset; y < $imageSize.height; y += d ) {

				let ua = 0;
				let ub = 0;
				for(let dx = -$r; dx <= $r; ++dx){
					for(let dy = -$r; dy <= $r; ++dy){
						ua += $w[ dx ][ dy ] * this.#colorA[ x - startX + dx + $r ][ y + dy + $r ];
						ub += $w[ dx ][ dy ] * this.#colorB[ x - startX + dx + $r ][ y + dy + $r ];
					}
				}

				let va  = 0;
				let vb  = 0;
				let sab = 0;
				for(let dx = -$r; dx <= $r; ++dx){
					for(let dy = -$r; dy <= $r; ++dy){
						const da = this.#colorA[ x - startX + dx + $r ][ y + dy + $r ] - ua;
						const db = this.#colorB[ x - startX + dx + $r ][ y + dy + $r ] - ub;
						va  += $w[ dx ][ dy ] * ( da ** 2 );
						vb  += $w[ dx ][ dy ] * ( db ** 2 );
						sab += $w[ dx ][ dy ] * ( da *  db );
					}
				}

				const _ssim = ( 2 * ua * ub + $c1 ) * ( 2 * sab + $c2 ) / ( ( ua ** 2 + ub ** 2 + $c1 ) * ( va + vb + $c2 ) );
				ssim += _ssim;

				++n;

			}
		}

		return ssim / n;

	}


	/**
	 * Prepares grayscale canvas versions of the input images
	 * @param {number} $blurRadius - Optional blur radius to apply
	 * @returns {Object} Object containing grayscale canvases for both images
	 * @private
	 */
	#preparePairGrayscaleCavas($blurRadius = 0){

		if(!(this.original instanceof HTMLImageElement) || !(this.compare instanceof HTMLImageElement)){
			throw new Error("Image not loaded. Please load a valid image.");
		}

		const originalSize = { width: this.original.naturalWidth, height: this.original.naturalHeight };
		const comapreSize  = { width: this.compare.naturalWidth,  height: this.compare.naturalHeight  };

		if(!originalSize.width || originalSize.width !== comapreSize.width || originalSize.height !== comapreSize.height){
			throw new Error("The images to be compared are of different sizes. Please specify images of the same size.");
		}

		const size = originalSize;

		const cvsA = document.createElement("canvas");
		const cvsB = document.createElement("canvas");

		cvsA.width  = size.width;
		cvsB.width  = size.width;
		cvsA.height = size.height;
		cvsB.height = size.height;

		const drwA = cvsA.getContext("2d", { willReadFrequently: true });
		const drwB = cvsB.getContext("2d", { willReadFrequently: true });

		drwA.filter = `grayscale(100%) blur(${parseInt($blurRadius) || 0}px)`;
		drwB.filter = `grayscale(100%) blur(${parseInt($blurRadius) || 0}px)`;

		drwA.drawImage(this.original, 0, 0);
		drwB.drawImage(this.compare,  0, 0);

		return {
			original : cvsA,
			compare  : cvsB,
		};

	}


	/**
	 * Calculates the window coefficients for SSIM calculation
	 * @param {Object} $args - Optional parameters for window calculation
	 * @param {number} $args.radius - Window radius override
	 * @param {number} $args.sigma - Window sigma override
	 * @returns {Object} The calculated window coefficients
	 * @private
	 */
	#calcWindowCoefficient($args = null){

		const radius = parseInt(Math.abs($args?.radius || this.constructor.WINDOW_PARAM_RADIUS ));
		const sigma  =          Math.abs($args?.sigma  || this.constructor.WINDOW_PARAM_SIGMA  );

		const c = 1 / ( Math.sqrt( 2 * Math.PI ) * sigma );
		const t = 2 * ( sigma ** 2 );

		const window    = {};
		let   integrate = 0;

		for(let x = -radius; x <= radius; ++x){
			window[ x ] = {};
			for(let y = -radius; y <= radius; ++y){
				window[ x ][ y ] = this.#calcGaussianDistribution(c, t, x)
				                     * this.#calcGaussianDistribution(c, t, y);
				integrate += window[ x ][ y ];
			}
		}

		for(const x in window){
			for(const y in window[ x ]){
				window[ x ][ y ] /= integrate;
			}
		}

		this.windowCoefficient = window;

		return window;

	}


	/**
	 * Calculates the Gaussian distribution value
	 * @param {number} $c - Normalization constant
	 * @param {number} $t - Variance parameter
	 * @param {number} $d - Distance from center
	 * @returns {number} The Gaussian distribution value
	 * @private
	 */
	#calcGaussianDistribution($c, $t, $d){

		return $c * Math.exp( -( ( $d ** 2 ) / $t ) );

	}


	/**
	 * Converts RGB color to grayscale
	 * @param {Object} $color - RGB color object
	 * @param {number} $color.r - Red component
	 * @param {number} $color.g - Green component
	 * @param {number} $color.b - Blue component
	 * @returns {number} The grayscale value
	 * @private
	 */
	#convertGrayscale( $color ) {

		return 0.2126 * $color.r + 0.7152 * $color.g + 0.0722 * $color.b;

	}


}



if(self.document?.currentScript){
	self.__classScriptSSIM = document.currentScript;
}




// END of the File



