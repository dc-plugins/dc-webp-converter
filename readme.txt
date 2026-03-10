=== DC WebP Converter ===
Contributors: dampcig
Tags: webp, image, converter, woocommerce, optimization
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts WooCommerce product images (PNG and JPG) to WebP in background batches via WP-Cron. Saves bandwidth and improves Core Web Vitals.

== Description ==

Dampcig PNG+JPG to WebP Converter automatically converts your WooCommerce product images from PNG and JPG to the modern WebP format. Conversions run silently in the background using WP-Cron so your site stays fast while the queue is processed.

= Key features =

* Batch converts all product-attached PNG and JPG images to WebP.
* Uses WP-Cron — no server timeout issues, no manual steps.
* Intelligent encoding: images with ≤512 unique colours (logos, icons) are always lossless; photos use configurable lossy quality (default 82).
* Requires PHP ImageMagick extension (WEBP write support).
* Progress dashboard with live percentage, queue status, and conversion log.
* System check tool to verify server capability before running.
* Optional footer credit (pre-checked, easily disabled).

= Requirements =

* PHP 8.0+
* PHP ImageMagick extension with WebP write support (most modern hosts)
* WooCommerce (for product image detection)

== Installation ==

1. Upload the `dc-webp-converter` folder to `/wp-content/plugins/`.
2. Activate the plugin from the **Plugins** screen.
3. Go to **Tools → PNG to WebP** and run the System Check.
4. Click **Build Queue** to index all product images.
5. Enable the cron and let it run — check back on the progress bar.

== Frequently Asked Questions ==

= Does it overwrite my original images? =
No. WebP files are saved alongside the originals. WordPress serves the correct format based on browser support.

= What if my server doesn't support WebP? =
Run the System Check on the plugin page. It will tell you exactly what is and isn't available. Most modern shared hosts support WebP via ImageMagick.

= Can I re-run the conversion? =
Yes. Use **Reset Queue** to start over, or **Build Queue** again to pick up new images.

= Is WooCommerce required? =
Yes — the plugin targets product-attached images. It will not run without WooCommerce active.

== Screenshots ==

1. Plugin dashboard with progress bar and queue status.
2. System check results.
3. Settings page with quality slider and cron schedule.

== Changelog ==

= 1.2.0 =
* Intelligent lossless/lossy encoding based on unique colour count.
* System check cached in object cache to avoid repeated DB reads.
* Footer credit strategy cached (object cache → transient) for zero overhead on subsequent pages.
* Cron self-heal: re-schedules automatically if the event disappears while a queue is active.
* JS auto-refresh wrapped in DOMContentLoaded to fix timing bug.

= 1.1.0 =
* Added configurable lossy quality setting.
* Footer credit toggle added to settings.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
Recommended upgrade — fixes a cron stall bug and improves encoding intelligence.
