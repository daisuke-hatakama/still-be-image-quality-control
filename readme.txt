=== Image Quality Control | Still BE ===
Contributors: analogstudio
Donate link: https://donate.stripe.com/aEUg2Q0iKgzbf0Q9AE
Tags: optimize, image, webp, avif, automation
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 2.1.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep image quality while making files smaller for faster pages - just install and you're set, with automatic WebP and AVIF.

== Description ==

WordPress creates many image sizes when you upload a photo. This plugin tunes each size to a suitable quality level so pages load faster, while keeping the pictures looking good.
It works with sensible defaults as soon as you install and activate it. You do not need a long setup to get started.

### Your images never leave your server

All processing stays on your own hosting. There are no cloud conversion fees, usage credits, monthly limits, or third-party watermarks.
When images are rebuilt, the plugin always starts from your original upload — it does not repeatedly compress already optimized files. Even if you change settings and regenerate again and again, quality does not degrade across generations (generational degradation).
That helps both cost and long-term image quality.

### Automatic WebP and AVIF

WebP and AVIF are next-generation formats that keep high image quality at smaller file sizes.
On compatible servers, the plugin builds those versions and serves them to browsers that support them — without rewriting image URLs in your content.

### Adjust settings yourself (optional)

You can leave the defaults as they are, or adjust compression yourself for each size and format (JPEG, PNG, WebP, and AVIF).
This is especially useful on WooCommerce and other product sites, where product thumbnails can be lighter for fast catalog pages while larger product photos stay sharper for detail views.
There is also an optional quality test screen if you want to compare settings with your own eyes.

### Automatic optimization (optional, beta)

After upload, the plugin can keep working in the background to find a smaller file that still looks close to the original.
A second check helps avoid images that look too blocky or soft.
Progress and size savings are recorded per image.

### Protecting your privacy

Uploaded photos often contain EXIF metadata — for example GPS location, camera model, or the time the shot was taken.
By default, the plugin removes EXIF data when images are processed, which helps protect visitors' and photographers' privacy.
You can turn this off in the settings if you need to keep EXIF.

### More options

- Guarantee a Secure Filename (Deny a multibyte filename)
- Autoset Alt from Exif (only JPEG)
- Add a Quality Level Suffix
- Enable Interlace/Progressive
- Enable Index Color (PNG8)
- Optimize "srcset" Attribute
- Force Adding the Query String for Image Cache Clear
- Delete Original Large Images *

\* Available in version 2.0 and later.


### Admin tools

- Add custom image sizes
- Change the big image threshold
- Run re-compression
- Auto re-compression using WP-Cron


### How to use

Just install and activate. Sensible defaults start optimizing new uploads right away, including WebP and AVIF when your server supports them.

1. Install
2. Activate
3. Change settings later if you want finer control
4. Recompress existing images if you want those settings applied to files already on the site

Be sure to make a backup before recompressing.


#### Open the settings

In the WordPress admin, go to Settings → Image Qualities.


== Installation ==

1. Enter "Image Quality Control" in the plugin search field in your admin screen.
2. Once you find this plugin, click "Install Now" to install.  
   (Alternative) Upload "stillbe-image-quality-control.zip" directly to your Plugins -> Add New in your admin screen.  
   (Alternative) Upload an unzipped "stillbe-image-quality-control" directory under the "/wp-content/plugins" directory.
3. Activate the plugin through the Plugins menu in WordPress.
4. Leave image optimization to this plugin. Let's enjoy WordPress!!


== Frequently Asked Questions ==

= Do I need a cloud service or paid credits? =

No.
All processing runs on your own server. There are no conversion fees, usage credits, or third-party watermarks.

= Will it optimize images I already uploaded? =

New uploads are optimized with the current settings as soon as the plugin is active.
Images already in the Media Library keep their current files until you recompress them (or enable automatic re-compression).
Recompression is also the way to generate WebP / AVIF for images that were uploaded before those options were enabled.

= Should I recompress it? =

Not required for day-to-day use.
Recompress when you want existing uploads to follow new quality settings, or when you want WebP / AVIF created for images that do not have them yet.

= What is the difference between recompression and automatic optimization? =

Recompression rebuilds resized images (and optional WebP / AVIF) from the original upload using your quality settings.
Automatic optimization is an optional background process that further lowers quality step by step while watching visual quality (SSIM), and it can raise the level again if images look too blocky or soft.
You can use recompression alone. Automatic optimization is disabled by default.

Both can increase server load when many images are processed at once — especially bulk recompression — so run large jobs in smaller batches on shared hosting.

= What is automatic optimization? =

It lowers the quality level of each resized image until the target visual quality is reached, using SSIM (structural similarity) as an objective quality metric.
When SSIM alone might allow too much compression, a secondary check based on edge detection looks for block noise and similar artifacts and raises the quality level if needed.
This process runs automatically in the background, one image after another. (Disabled by default.)

On shared or low-resource hosts, lower the auto-optimize concurrency if the server becomes busy.

= Does automatic optimization support WebP uploads? =

Yes. When you upload a WebP image, it can be optimized as well.

= What do I need on the server for WebP and AVIF? =

WebP can be generated when your PHP image library supports it (Imagick and/or GD with WebP), or when the optional cwebp-based extension is available.
AVIF needs a library that can encode AVIF (Imagick with AVIF support, and/or GD with `imageavif`).
The plugin settings screen shows whether WebP and AVIF are available on your server.
JPEG and PNG compression work with the usual WordPress image editors even when next-gen formats are unavailable.

= Do I need to rewrite image URLs for WebP or AVIF? =

No.
Content can keep the usual JPEG / PNG URLs.
On compatible servers, the plugin places an `.htaccess` file under `/wp-content/uploads` so browsers that send `Accept: image/avif` or `image/webp` receive those files when they exist. AVIF is preferred over WebP when both are available.

= Will WebP / AVIF delivery work with a CDN or nginx? =

On typical WordPress hosting, Apache serves uploads and honors `.htaccess`, so you usually do not need extra setup. Shared hosting almost always falls into this case.
Many setups that put nginx in front as a reverse proxy still proxy static uploads to Apache, so the same `.htaccess` rules often continue to work.
You mainly need custom rules if the site is pure nginx (no Apache `.htaccess`) or if a CDN caches the JPEG / PNG response without respecting the `Accept` / `Vary` headers. In those environments, configure equivalent content negotiation on the CDN or web server.

= Can I use this with WooCommerce? =

Yes.
You can set different quality levels per image size, so catalog thumbnails can stay light while larger product images remain sharper.

= The image sharpness of WebP is not good. =

Raise the quality level of WebP.
Test the file size and image quality to decide the quality level.

If WebP still looks soft at a practical file size, enable AVIF as well when your server supports it.
AVIF often keeps finer detail at a smaller size than WebP, and supporting browsers will receive AVIF preferentially.

= Does the image lose quality if I change the quality level many times and recompress it? =

No.
When recompressing, the plugin resizes and compresses from the original upload, so quality does not degrade across generations.

= Can EXIF data be deleted individually for each item? =

No.
You can only choose to strip all EXIF data or keep it.

= Can I delete EXIF from uploaded images? =

Yes.
Recompression applies the current EXIF setting.
EXIF that has already been removed cannot be restored.

= Does disabling the plugin stop the automatic delivery of WebP / AVIF? =

Yes. On deactivation, the plugin removes its `.htaccess` rules.
If you keep an equivalent `.htaccess` in `/wp-content/uploads`, delivery can continue after the plugin is deactivated.

= Do this plugin's settings override the 'jpeg_quality' filter? =

Yes. The quality levels set in this plugin take precedence over WordPress's `jpeg_quality` filter.

= How do I uninstall the plugin? =

Deactivate it, then delete it from the Plugins screen.
Deactivation removes the `.htaccess` rules used for WebP / AVIF delivery. Optimized image files remain in your uploads folder.
Plugin settings stored in the database are not removed automatically; use Reset Settings before uninstalling if you want to clear them.

= Recompressing with a higher quality does not improve the image. =

Your browser may still be showing a cached file. Try a hard refresh or clear the browser cache.
This plugin can also append a cache-clearing query string when an image file changes.
If the original upload itself is low quality, recompression cannot make it sharper than that original.

= What does "Delete Original Large Images" do? =

When a very large photo (for example from a smartphone) is uploaded and WordPress creates a `-scaled` image, this option deletes the unused original full-size file.
That original is not referenced anywhere, so removing it helps free server storage.


== Screenshots ==

1. Quality Level Table
2. Test Quality Level
3. Options
4. Recompress the Uploaded Images
5. Generate WebP Image Automatically


== Changelog ==

= 2.1.3 =

Changed EXIF stripping so file permissions are derived from the parent directory.
This corrects incorrect permissions on recompression when an older process had left the wrong mode on the image file.


= 2.1.2 =

Fixed a bug where file permissions were changed when stripping EXIF with the GD editor.

Changed the quality test to allow setting the AVIF quality level to 100.

Added a setting to select the preferred image editor.


= 2.1.1 =

Improved Imagick encoding options for WebP and AVIF.

Limited the AVIF quality level table to 1-99 so that quality 100 remains reserved for lossless encoding.

Fixed some small bugs.


= 2.1.0 =

Added support for generating AVIF alternative images.

Added a secondary check to detect quality degradation caused by block noise or other artifacts, preventing the optimization process from lowering the quality level too much.

Improved the settings for automatic optimization to provide better results.


= 2.0.1 =

Added a setting to limit the number of concurrent automatic optimization jobs.

Fixed bugs related to the automatic optimization process.

Fixed a bug where SSIM values were not displayed.


= 2.0.0 =

Added automatic optimization process using SSIM (structural similarity index measure) index. (Beta function, disabled by default)
The optimization runs in the background via WP-Cron, searching for the lowest quality level that meets the SSIM target for each size (the quality level table is used as the ceiling). The target level (Efficiency / Balance / Quality) can be selected in the settings.
Concurrent auto-optimize jobs are limited (default: 2; configurable in Advanced Settings 2 / filter still-be/image-quality-control/auto-optimize/concurrency).
After re-compression, delivery WebP files are kept by default until automatic optimization replaces them (optional immediate purge in Advanced Settings 2).
Progress and total size reduction (percentage and bytes saved) are stored in attachment metadata and shown in the Media Library list view.
Migrated admin operations from admin-ajax.php to the WordPress REST API (namespace stillbe-iqc/v1) using wp.apiFetch.

Changed the minimum required WordPress version to 5.8.
Added capability checks to all Ajax endpoints.
Fixed a bug that the progressive JPEG toggle setting was not applied.
Changed the safe filename conversion to be applied before saving the uploaded file.
Changed the EXIF stripping to parse JPEG segments correctly.
Improved the cache clear query string to avoid filesystem access on every page load.

Please use version 1.7.4 for the stable release without automatic optimization.


= 1.7.5 =

Fixed required versions of extended plugin.

= 1.7.4 =

Fixed Fatal Error in GD environment.

= 1.7.3 =

Checked that it works with WordPress 6.8.

= 1.7.2 =

Checked that it works with WordPress 6.7.

= 1.7.1 =

Checked that it works with WordPress 6.4.

= 1.7.0 =

Checked that it works with WordPress 6.3.

= 1.6.0 =

Changed Requests_Transport_fsockopen class to WpOrg\Requests\Transport\Fsockopen class.

= 1.5.2 =

Fixed a bug that caused batch recompression to loop without finishing.

= 1.5.1 =

Fixed a bug that failed to set image quality when the size name was changed after an image size was added.
Changed the specification to display only the number of files instead of displaying the attachment id of the targets in the tab of recompression.

Fixed other minor bugs.

Checked that it works with WordPress 6.2.

= 1.5.0 =

Fixed an error when an empty file is passed during EXIF removal.

Checked that it works with WordPress 6.1.

= 1.4.0 =

Added process to strip EXIF data embeded in images.

= 1.3.0 =

Fixed an error that occurred with PHP 7.2 and older.
Checked that it works with WordPress 6.0.

= 1.2.4 =

Fix an issue where resized images were not generated when uploading WebP that WebP was not automatically generated to replace images in conventional format.

= 1.2.3 =

Fix the text domain in some translation functions.

= 1.2.2 =

Change translation function to wp.i18n.
Adjust the tab control of the setting screen.

= 1.2.1 =

Add an option to optimize delay by WP-Cron. cURL in version older than 7.32.0, decimal values cannot be used for timeout.
Chang the method of obtaining the modification date and time of the query string to clear the image cache.
Fix a bug in Imagick that WebP mime-type was not detected correctly.
Fix a bug in the quality test that the quality level of the original image might not be set to the set level.

= 1.2.0 =

Add an option to add a query string to clear the image cache.
Changed the interlace flag to be set separately for JPEG and PNG.

= 1.1,2 =

Add setting of target conditions for batch conversion.
Checked that it works with WordPress 5.9.

= 1.1.1 =

Fix a bug that toggle options could not be displayed when loading by the setting page opening other than the test image tab in the quality level test.
GD changed the initial value of PNG8 enablement to 'false' because transparent colors are not preserved when converted to PNG8 at sites that use the GD library.

= 1.1.0 =

Supports PNG8. Supports changing toggle options in quality level testing.

= 1.0.0 =

Official Release
Change of the quality levels can be set according to the size of the original image.

= 0.10.9 =

Fix a bug that the setting value of this plugin is not set except for 'medium' size when the quality level is set by 'jpeg_quality' hook.

= 0.10.8 =

Fix a bug that the default quality setting values are not used when the quality setting values are not set.
Add the function to display the saved setting values.

= 0.10.7 =

Increase the priority of the quality level setting for this plugin.

= 0.10.6 =

Fix the quality levels in compression informations table.

= 0.10.5 =

Add the quality level table for site icon.
Add single compression button to the Media Library page (list view).
Update translations.

= 0.10.4 =

Add compression informations to the Media Library page (list view).
Fix a bug that occasionally prevented setting the site icon.

= 0.10.3 =

Show the default value for toggle settings.

= 0.10.2 =

Fix because the deletion of 0.10.1 was not successful.

= 0.10.1 =

Delete the old files that remained when dividing into folders under /includes.

= 0.10.0 =

Tab the setting screen.
Fix some bugs.

= 0.9.1 =

Modulated the processes for extension plugin.

= 0.9.0 =

Fix some bugs.
Add the funcitions of extension plugin.

= 0.8.1 =

Fix a bug that the quality level cannot be set in the quality test.
Add the function to regenerate only one image.

= 0.8.0 =

Add the bellow functions;
1. Add Custom Image Sizes
2. Optimize "srcset" Attribute
3. Big Image Threshold
4. Images are automatically regenerated using WP-Cron

= 0.7.5 =

Compatible with WordPress 5.8.1.
Changed the default values.

= 0.7.4 =

Fix a bug that the setting values cannot reset.

= 0.7.3 =

Fix some bugs.

= 0.7.2 =

Changed the test image preview to an image size that takes into account the device pixel ratio.

= 0.7.1 =

Add the function to test quality level.

= 0.6.1 =

Update the description on the setting screen.

= 0.6.0 =

Compatible with WordPress 5.8. Fixed a bug that the original image WebP was not generated.
Updated to interrupt the recompression process.

= 0.5.3 =

First Release on The WordPress Plugin Directory

= 0.5.0 =

Organized items on the setting page and added explanations. Changed the settings to apply.

= 0.4.0 =

Add a Setting Page & Selected the Editor Changed to Prioritize WebP-enabled Library.

= 0.3.1 =

When Deleting an Attachment, Delete WebP at the Same Time.

= 0.3.0 =

Changed WebP Creation Method to 'cwebp' Utility.

= 0.2.0 =

Add Classes that extends the Core Image Editor and Set the Compression Quality when Creating Resized Images with GD / Imagick.

= 0.1.0 =

Overwrites the All Resized Images with GD functions after 'wp_generate_attachment_metadata' Hook.
