=== R2 by Grisma ===
Contributors: grisma
Tags: r2, cloudflare, cloudflare r2, image optimization, webp, s3, offload media
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.13
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise Cloudflare R2 sync with upload-time format & compression controls, server-side WebP conversion, custom CDN delivery, and zero vendor bloat.

== Description ==

**R2 by Grisma** is a high-performance, lightweight WordPress plugin designed specifically for Cloudflare R2 object storage and intelligent image optimization.

### Key Highlights
* **Zero Vendor SDK Bloat:** Pure PHP AWS SigV4 implementation (<100KB footprint instead of 50MB AWS SDKs).
* **Upload-Time Control:** Preset defaults in Settings with full freedom to override format (WebP, JPEG, PNG, Original) and compression per upload.
* **Streamlined Multi-Engine Support:** Choose between Server PHP GD/Imagick, reSmush.it Free API (with automatic GD/Imagick fallback for >5MB files), Browser Edge, or Raw Offload in the exact same flow.
* **Conversion Presets:** Ready-made presets (WebP Balanced, WebP High, JPEG Balanced, JPEG High, Keep Original, Raw Lossless, Custom).
* **Universal Custom CDN Routing:** Rewrites URLs and responsive `srcset` for both Headless REST API (Astro, Next.js) and standard WordPress monolithic themes.
* **Minimalist UI:** Clean modern status badges (Cloud, Synced, Local, Missing) with responsive controls.
* **Encrypted Credentials:** AES-256-CBC encryption for secret keys.

== Installation ==
1. Upload the `r2-by-grisma` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Settings -> R2 by Grisma** and enter your Cloudflare R2 credentials.
4. Click **Test Connection & Verify CDN** to confirm connection.

== Changelog ==

= 1.0.13 =
* Resolved duplicate floating controls in Media Modal: consolidated into a single unified, responsive toolbar in the upload dropzone.
* Fixed real-time quality slider: live percentage badge updates seamlessly on touch/drag across mobile and desktop without thumb freezing.
* Fixed format switching: selecting JPG or presets now strictly sets and preserves the target format during upload, preventing forceful WebP conversion.
* Streamlined multi-engine optimization: unified Server (GD/Imagick), reSmush.it Free API (with auto-fallback for files > 5MB), Browser Edge, and Raw Lossless in the same frictionless upload flow.
* Enhanced settings and floating controls synchronization: elimination of stale cookie collisions with WordPress database defaults.

= 1.0.12 =
* Added conversion & compression presets (WebP Balanced, WebP High, JPEG Balanced, JPEG High, Keep Original, Raw Lossless, Custom).
* Added interactive upload-time format switching (WebP, JPG, PNG, Original) and compression slider directly in Media Modal & Uploader.
* Added non-blocking Plupload & REST API parameter passing to eliminate upload queue stalls.
* Enhanced Cloudflare R2 SigV4 client by suppressing HTTP 100-continue header for robust PUT operations.
* Improved custom CDN URL and responsive srcset rewriting for all upload directory structures.
