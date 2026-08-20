/**
 * StillBE SSIM WebGPU — local SSIM sample pass (WGSL)
 */
export default /* wgsl */`
struct Params {
	width: u32,
	height: u32,
	radius: u32,
	dense: u32,
	step_x: u32,
	step_y: u32,
	grid_x: u32,
	grid_y: u32,
	c1: f32,
	c2: f32,
	capacity: u32,
	_pad: u32,
};
@group(0) @binding(0) var<uniform> params: Params;
@group(0) @binding(1) var<storage, read> lum_a: array<f32>;
@group(0) @binding(2) var<storage, read> lum_b: array<f32>;
@group(0) @binding(3) var<storage, read> win: array<f32>;
@group(0) @binding(4) var<storage, read_write> results: array<f32>;

fn sample_lum(buf: ptr<storage, array<f32>, read>, x: i32, y: i32) -> f32 {
	let w = i32(params.width);
	let h = i32(params.height);
	if (x < 0 || y < 0 || x >= w || y >= h) { return 0.0; }
	return (*buf)[u32(y) * params.width + u32(x)];
}

@compute @workgroup_size(8, 8, 1)
fn main(@builtin(global_invocation_id) gid: vec3<u32>) {
	let ix = gid.x;
	let iy = gid.y;
	if (ix >= params.grid_x || iy >= params.grid_y) { return; }

	var cx: u32;
	var cy: u32;
	if (params.dense != 0u) {
		cx = ix;
		cy = iy;
	} else {
		cx = ix * params.step_x;
		if (cx >= params.width) { return; }
		let y_offset = select(params.radius, 0u, (ix & 1u) == 0u);
		cy = y_offset + iy * params.step_y;
		if (cy >= params.height) { return; }
	}

	let out_index = iy * params.grid_x + ix;
	if (out_index >= params.capacity) { return; }

	let r = i32(params.radius);
	let cxi = i32(cx);
	let cyi = i32(cy);

	var ua: f32 = 0.0;
	var ub: f32 = 0.0;
	var vi: u32 = 0u;
	for (var dy = -r; dy <= r; dy++) {
		for (var dx = -r; dx <= r; dx++) {
			let wgt = win[vi];
			ua += wgt * sample_lum(&lum_a, cxi + dx, cyi + dy);
			ub += wgt * sample_lum(&lum_b, cxi + dx, cyi + dy);
			vi++;
		}
	}

	var va: f32 = 0.0;
	var vb: f32 = 0.0;
	var sab: f32 = 0.0;
	vi = 0u;
	for (var dy2 = -r; dy2 <= r; dy2++) {
		for (var dx2 = -r; dx2 <= r; dx2++) {
			let wgt2 = win[vi];
			let da = sample_lum(&lum_a, cxi + dx2, cyi + dy2) - ua;
			let db = sample_lum(&lum_b, cxi + dx2, cyi + dy2) - ub;
			va  += wgt2 * (da * da);
			vb  += wgt2 * (db * db);
			sab += wgt2 * (da * db);
			vi++;
		}
	}

	let numer = (2.0 * ua * ub + params.c1) * (2.0 * sab + params.c2);
	let denom = (ua * ua + ub * ub + params.c1) * (va + vb + params.c2);
	results[out_index] = numer / denom;
}
`;
