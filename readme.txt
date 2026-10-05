=== R2 by Grisma ===
Contributors: grisma
Tags: r2, cloudflare, cloudflare r2, image optimization, webp, s3, offload media
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise Cloudflare R2 sync with client-side Browser Edge compression, on-site WebP conversion, custom CDN delivery, and zero vendor bloat.

== Description ==

**R2 by Grisma** is a high-performance, lightweight WordPress plugin designed specifically for Cloudflare R2 object storage and intelligent image optimization.

### Key Highlights
* **Zero Vendor SDK Bloat:** Pure PHP AWS SigV4 implementation (<100KB footprint instead of 50MB AWS SDKs).
* **Browser Edge Image Compression:** Compresses images and converts to WebP directly inside the editor's browser before upload.
* **On-Site Server Processing:** Native WebP conversion and JPEG/PNG optimization using WordPress's built-in GD/Imagick editors.
* **Async Background reSmush.it Integration:** Immediate upload with non-blocking background compression.
* **Universal Custom CDN Routing:** Rewrites URLs and responsive `srcset` for both Headless REST API (Astro, Next.js) and standard WordPress monolithic themes.
* **Minimalist UI:** Clean modern status badges (Cloud, Synced, Local, Missing) with zero cheesy emojis.
* **Encrypted Credentials:** AES-256-CBC encryption for secret keys.

== Installation ==
1. Upload the `r2-by-grisma` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Settings -> R2 by Grisma** and enter your Cloudflare R2 credentials.
4. Click **Test Connection & Verify CDN** to confirm connection.
