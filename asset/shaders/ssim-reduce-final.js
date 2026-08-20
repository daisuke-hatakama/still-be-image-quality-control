/**
 * StillBE SSIM WebGPU — final reduce pass (WGSL)
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
@group(0) @binding(1) var<storage, read> partial: array<Stats>;
@group(0) @binding(2) var<storage, read_write> out_stats: Stats;

@compute @workgroup_size(1, 1, 1)
fn main() {
	var s: f32 = 0.0;
	var c: f32 = 0.0;
	var mn: f32 = 1.0;
	var mx: f32 = 0.0;
	for (var i = 0u; i < params.partial_count; i++) {
		let p = partial[i];
		if (p.count > 0.0) {
			s = s + p.sum;
			c = c + p.count;
			mn = min(mn, p.min_v);
			mx = max(mx, p.max_v);
		}
	}
	out_stats = Stats(s, c, mn, mx);
}
`;
