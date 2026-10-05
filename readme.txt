=== R2 by Grisma ===
Contributors: grisma
Tags: r2, cloudflare, cloudflare r2, image optimization, webp, s3, offload media
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.14
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise Cloudflare R2 sync with upload-time format & compression controls, server-side WebP conversion, custom CDN delivery, and zero vendor bloat.

== Description ==

**R2 by Grisma** is a high-performance, lightweight WordPress plugin designed specifically for Cloudflare R2 object storage and intelligent image optimization.

### Key Highlights
* **Zero Vendor SDK Bloat:** Pure PHP AWS SigV4 implementation (<100KB footprint instead of 50MB AWS SDKs).
* **Browser Upload Interceptor:** Halts uploads before server bandwidth is consumed; inspect preview, format, and dimensions with 1-click confirmation or override.
* **Client-Side Canvas Compression:** Compresses and converts images to WebP/JPG right in the browser, eliminating 100% server CPU and hosting load.
* **Upload-Time Control:** Preset defaults in Settings with full freedom to override format (WebP, JPEG, PNG, Original) and destination (Dual, Cloud Only, Local Only) per upload.
* **Streamlined Multi-Engine Support:** Choose between Browser Edge Canvas, Server PHP GD/Imagick, reSmush.it Free API (with automatic fallback), or Raw Offload in the exact same flow.
* **Safe Bulk Sync & Verified Cleanup:** Bulk Sync NEVER auto-deletes local files. Reclaim server disk space on demand via safe verified cleanup.
* **Universal Custom CDN Routing:** Rewrites URLs and responsive `srcset` for both Headless REST API (Astro, Next.js) and standard WordPress monolithic themes.
* **Minimalist UI:** Clean modern status badges (Cloud, Synced, Local, Missing) with responsive controls.
* **Encrypted Credentials:** AES-256-CBC encryption for secret keys.

== Installation ==
1. Upload the `r2-by-grisma` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Settings -> R2 by Grisma** and enter your Cloudflare R2 credentials.
4. Click **Test Connection & Verify CDN** to confirm connection.

== Changelog ==

= 1.0.14 =
* Added Visual Browser Upload Interceptor Modal: Pauses image uploads in browser memory before sending bytes over the wire, providing thumbnail preview, file dimensions, file size, preset selector, format pills, live quality slider, and storage destination with 1-click proceed or cancel.
* Implemented In-Browser HTML5 Canvas Image Compression: Client-side compression and WebP/JPG conversion executed in the browser, reducing uploaded file size by up to 80% before transmission to save web hosting bandwidth and 0 server CPU.
* Resolved Preset, Format, and Quality Desynchronization: Ensured preset definitions strictly govern format and quality; format pills dynamically synchronize dropdown and sliders across both desktop and mobile touch.
* Added Bulk Sync Wildcard & Multi-Engine Controls: Choose wildcard preset, target quality, and processing engine (Server, Browser Open Tab, reSmush, Lossless) directly on the Bulk Sync dashboard.
* Added Data Safety Guarantee & Verified Local Storage Cleanup: Ensured Bulk Sync never auto-deletes local files. Added dedicated Verified Local Storage Cleanup tool to safely free hosting disk space only after confirming verified R2 cloud copies.
* Added session persistence: "Remember choices for this browser session" checkbox to streamline uninterrupted multi-image batch uploads.

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
