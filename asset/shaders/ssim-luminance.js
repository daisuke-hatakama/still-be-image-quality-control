/**
 * StillBE SSIM WebGPU — luminance pass (WGSL)
 */
export default /* wgsl */`
struct ImageParams {
	width: u32,
	height: u32,
	_a: u32,
	_b: u32,
};
@group(0) @binding(0) var<uniform> params: ImageParams;
@group(0) @binding(1) var src_tex: texture_2d<f32>;
@group(0) @binding(2) var src_smp: sampler;
@group(0) @binding(3) var<storage, read_write> lum: array<f32>;

@compute @workgroup_size(8, 8, 1)
fn main(@builtin(global_invocation_id) gid: vec3<u32>) {
	let x = gid.x;
	let y = gid.y;
	if (x >= params.width || y >= params.height) { return; }
	let uv = (vec2<f32>(f32(x), f32(y)) + vec2<f32>(0.5, 0.5))
		/ vec2<f32>(f32(params.width), f32(params.height));
	let rgba = textureSampleLevel(src_tex, src_smp, uv, 0.0);
	let yv = 0.2126 * rgba.r + 0.7152 * rgba.g + 0.0722 * rgba.b;
	lum[y * params.width + x] = yv * 255.0;
}
`;
