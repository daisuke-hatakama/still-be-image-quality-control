/**
 * StillBE SSIM WebGPU — partial reduce pass (WGSL)
 */
export default /* wgsl */`
struct ReduceParams {
	capacity: u32,
	partial_count: u32,
	_a: u32,
	_b: u32,
};
struct Stats {
	sum: f32,
	count: f32,
	min_v: f32,
	max_v: f32,
};
@group(0) @binding(0) var<uniform> params: ReduceParams;
@group(0) @binding(1) var<storage, read> values: array<f32>;
@group(0) @binding(2) var<storage, read_write> partial: array<Stats>;

var<workgroup> sh_sum: array<f32, 256>;
var<workgroup> sh_cnt: array<f32, 256>;
var<workgroup> sh_min: array<f32, 256>;
var<workgroup> sh_max: array<f32, 256>;

@compute @workgroup_size(256, 1, 1)
fn main(
	@builtin(global_invocation_id) gid: vec3<u32>,
	@builtin(local_invocation_id) lid: vec3<u32>,
	@builtin(workgroup_id) wid: vec3<u32>
) {
	let i = gid.x;
	var s: f32 = 0.0;
	var c: f32 = 0.0;
	var mn: f32 = 1.0;
	var mx: f32 = 0.0;
	if (i < params.capacity) {
		let v = values[i];
		if (v == v) {
			s = v;
			c = 1.0;
			mn = v;
			mx = v;
		}
	}
	sh_sum[lid.x] = s;
	sh_cnt[lid.x] = c;
	sh_min[lid.x] = mn;
	sh_max[lid.x] = mx;
	workgroupBarrier();

	var stride: u32 = 128u;
	loop {
		if (stride == 0u) { break; }
		if (lid.x < stride) {
			let j = lid.x + stride;
			sh_sum[lid.x] = sh_sum[lid.x] + sh_sum[j];
			sh_cnt[lid.x] = sh_cnt[lid.x] + sh_cnt[j];
			sh_min[lid.x] = min(sh_min[lid.x], sh_min[j]);
			sh_max[lid.x] = max(sh_max[lid.x], sh_max[j]);
		}
		workgroupBarrier();
		stride = stride / 2u;
	}

	if (lid.x == 0u) {
		partial[wid.x] = Stats(sh_sum[0], sh_cnt[0], sh_min[0], sh_max[0]);
	}
}
`;
