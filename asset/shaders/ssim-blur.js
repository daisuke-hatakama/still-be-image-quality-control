/**
 * StillBE SSIM WebGPU — separable Gaussian blur pass (WGSL)
 */
export default /* wgsl */`
struct BlurParams {
	width: u32,
	height: u32,
	kernel_len: u32,
	axis: u32,
};
@group(0) @binding(0) var<uniform> params: BlurParams;
@group(0) @binding(1) var<storage, read> kernel: array<f32>;
@group(0) @binding(2) var<storage, read> src: array<f32>;
@group(0) @binding(3) var<storage, read_write> dst: array<f32>;

fn sample_lum(x: i32, y: i32) -> f32 {
	let w = i32(params.width);
	let h = i32(params.height);
	let xx = clamp(x, 0, w - 1);
	let yy = clamp(y, 0, h - 1);
	return src[u32(yy) * params.width + u32(xx)];
}

@compute @workgroup_size(8, 8, 1)
fn main(@builtin(global_invocation_id) gid: vec3<u32>) {
	let x = gid.x;
	let y = gid.y;
	if (x >= params.width || y >= params.height) { return; }
	let r = i32(params.kernel_len / 2u);
	var acc: f32 = 0.0;
	for (var i = 0; i < i32(params.kernel_len); i++) {
		let d = i - r;
		var v: f32;
		if (params.axis == 0u) {
			v = sample_lum(i32(x) + d, i32(y));
		} else {
			v = sample_lum(i32(x), i32(y) + d);
		}
		acc += kernel[u32(i)] * v;
	}
	dst[y * params.width + x] = acc;
}
`;
