# Image Quality Control | Still BE

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/still-be-image-quality-control?label=WordPress.org)](https://wordpress.org/plugins/still-be-image-quality-control/)
[![WordPress Tested](https://img.shields.io/wordpress/plugin/tested/still-be-image-quality-control)](https://wordpress.org/plugins/still-be-image-quality-control/)
[![Required PHP](https://img.shields.io/wordpress/plugin/required-php/still-be-image-quality-control)](https://wordpress.org/plugins/still-be-image-quality-control/)
[![License](https://img.shields.io/github/license/daisuke-hatakama/still-be-image-quality-control)](https://www.gnu.org/licenses/gpl-2.0.html)

Keep image quality while making files smaller for faster pages. Just install and activate; WebP and AVIF are generated automatically when the server supports them.

An optional companion plugin can use the server’s `cwebp` binary for finer WebP encoding options.

- [WordPress.org](https://wordpress.org/plugins/still-be-image-quality-control/)
- [Changelog (`readme.txt`)](stillbe-image-quality-control/readme.txt)

## Features

- **All processing stays on your server** — No cloud conversion fees, usage credits, or third-party watermarks. Images never leave your hosting.
- **No generational degradation** — Rebuilding always starts from the original upload. Changing settings and regenerating again does not stack compression on already optimized files.
- **Automatic WebP / AVIF delivery** — Supporting browsers receive next-gen formats without rewriting image URLs in your content (Apache `.htaccess` content negotiation). AVIF is preferred when both exist.
- **Per-size, per-format quality** — Set JPEG, PNG, WebP, and AVIF independently for thumbnails versus larger images. Useful for WooCommerce catalog speed versus product detail sharpness.
- **Optional automatic optimization (beta)** — Uses SSIM and edge detection to shrink files further while watching visual quality. Disabled by default.
- **Privacy** — EXIF data (such as GPS) is stripped by default. You can turn this off in the settings.

Also included: secure filenames, alt text from Exif, progressive JPEG, PNG8, `srcset` optimization, cache-clear query strings, deletion of unused original large images, custom image sizes, and recompression (including WP-Cron).

## Requirements

| Item | Requirement |
| --- | --- |
| WordPress | 5.8 or later (tested up to 7.0) |
| PHP | 7.4 or later |
| Image library | GD or Imagick (WordPress image editors) |

WebP / AVIF generation depends on the server. The settings screen shows whether they are available. JPEG and PNG compression still work when next-gen formats are not.

## Installation

The plugin lives in [`stillbe-image-quality-control/`](stillbe-image-quality-control/).

1. Search for **Image Quality Control** in the WordPress admin, or copy this repository’s plugin directory into `wp-content/plugins/`.
2. Activate the plugin.
3. New uploads are optimized with the default quality settings right away.

Settings are under **Settings → Image Qualities**. Existing images keep their current files until you recompress them. Back up before recompressing.

See [`readme.txt`](stillbe-image-quality-control/readme.txt) for full instructions and FAQ.

## Optional extension (`cwebp`)

The main plugin can already generate WebP with Imagick or GD. [`still-be-image-quality-control-extends/`](still-be-image-quality-control-extends/) is a separate plugin that calls the `cwebp` utility installed on the server, which gives more encoding options than the PHP image libraries (lossy, lossless, and near-lossless where `cwebp` supports it).

It requires:

- The main plugin (WordPress `Requires Plugins: still-be-image-quality-control`)
- `cwebp` available on the server (Google’s [libwebp](https://developers.google.com/speed/webp/docs/cwebp) tools)

Copy the `still-be-image-quality-control-extends` directory into `wp-content/plugins/` and activate it after the main plugin. The settings screen then shows whether `cwebp` is available, plus the extra WebP options. If `cwebp` is missing, those options are unused.

This extension is not listed on WordPress.org; it is distributed with this repository.

## Repository layout

```
.
├── README.md                                    # GitHub readme (this file)
├── assets/                                      # WordPress.org plugin directory assets
├── stillbe-image-quality-control/               # Main plugin (WordPress.org SVN trunk)
│   ├── stillbe-image-quality-control.php
│   ├── readme.txt                               # WordPress.org readme
│   └── asset/                                   # Admin JS / CSS (plugin assets)
└── still-be-image-quality-control-extends/      # Optional cwebp companion plugin
    └── stillbe-image-quality-control-extends.php
```

## WordPress.org assets

Put banners, icons, and screenshots in [`assets/`](assets/) at the repository root. On SVN they belong in the top-level `assets/` folder (sibling of `trunk` and `tags`), not inside the plugin. This is not the same as the plugin’s `asset/` folder (admin JS / CSS). Do not include `assets/` in the plugin zip.

Official spec: [How Your Plugin Assets Work](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/)

Filenames must be **lowercase**. Use the exact pixel dimensions implied by the name.

### Banners (top of the plugin page)

| File | Size | Notes |
| --- | --- | --- |
| `banner-772x250.png` (or `.jpg`) | 772 × 250 | Required if you show a banner |
| `banner-1544x500.png` (or `.jpg`) | 1544 × 500 | Retina; used only when the 772×250 banner exists |

Maximum 4 MB; smaller is better. RTL variants use names such as `banner-772x250-rtl.png`.

### Icons (search results and wp-admin)

| File | Size | Notes |
| --- | --- | --- |
| `icon-128x128.png` (or `.jpg` / `.gif`) | 128 × 128 | Standard |
| `icon-256x256.png` (or `.jpg` / `.gif`) | 256 × 256 | Retina |
| `icon.svg` | Vector | Optional; a PNG fallback is still required |

Maximum 1 MB. WordPress.org generates an icon if none is provided.

### Screenshots

Keep a **one-to-one** match with `== Screenshots ==` in `stillbe-image-quality-control/readme.txt`. Numbers must match the captions.

| File | Caption in `readme.txt` |
| --- | --- |
| `screenshot-1.png` (or `.jpg`) | Quality Level Table |
| `screenshot-2.png` (or `.jpg`) | Test Quality Level |
| `screenshot-3.png` (or `.jpg`) | Options |
| `screenshot-4.png` (or `.jpg`) | Recompress the Uploaded Images |
| `screenshot-5.png` (or `.jpg`) | Generate WebP Image Automatically |

Maximum 10 MB. External URLs are not supported. Locale-specific files look like `screenshot-1-ja.png`.

When you add or remove captions, update this list and the numbered image files as well.

WordPress.org caches assets aggressively, so replacements can take a while to appear.

## Development notes

- Plugin slug: `still-be-image-quality-control`
- Text domain: `still-be-image-quality-control`
- REST API namespace: `stillbe-iqc/v1`
- Extension plugin folder: `still-be-image-quality-control-extends` (required extension version: 2.0.0)
- Description, FAQ, and changelog for WordPress.org live in `stillbe-image-quality-control/readme.txt`. Keep that file in sync when you change public-facing copy.

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)

## Author

[Daisuke Yamamoto](https://web.analogstd.com/) ([analogstudio](https://profiles.wordpress.org/analogstudio/))

Support the project via [Donate](https://donate.stripe.com/aEUg2Q0iKgzbf0Q9AE).
