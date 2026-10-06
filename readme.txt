=== R2 by Grisma ===
Contributors: grisma
Tags: r2, cloudflare, cloudflare r2, image optimization, webp, s3, offload media
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.25
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise Cloudflare R2 sync with reSmush.it cloud API & Server GD/Imagick optimization, on-site WebP conversion, custom CDN delivery, and zero vendor bloat.

== Description ==

**R2 by Grisma** is a high-performance, lightweight WordPress plugin designed specifically for Cloudflare R2 object storage and intelligent image optimization.

### Key Highlights
* **Zero Vendor SDK Bloat:** Pure PHP AWS SigV4 implementation (<100KB footprint instead of 50MB AWS SDKs).
* **reSmush.it Cloud Optimization (Primary):** Zero-CPU cloud compression via reSmush.it official API (with automatic, seamless fallback to Server GD/Imagick).
* **Native Upload Stability:** 100% native upload flow by default. Eliminates browser canvas freezes and Plupload/Gutenberg queue hangs (0%/3% stuck).
* **Server-Side WebP Conversion:** Converts raster images (JPG, PNG) to lightweight modern WebP format on the server.
* **Upload-Time Control:** Preset defaults in Settings with full freedom to override format (WebP, JPEG, PNG, Original) and destination (Dual, Cloud Only, Local Only) per upload.
* **Safe Bulk Sync & Verified Cleanup:** Bulk Sync NEVER auto-deletes local files. Reclaim server disk space on demand via safe verified cleanup.
* **Universal Custom CDN Routing:** Rewrites URLs and responsive `srcset` for both Headless REST API (Astro, Next.js) and standard WordPress monolithic themes.
* **Minimalist UI:** Clean modern status badges (Cloud, Synced, Local, Missing) with responsive controls and detailed optimization notes.
* **Encrypted Credentials:** AES-256-CBC encryption for secret keys.

== Installation ==
1. Upload the `r2-by-grisma` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Settings -> R2 by Grisma** and enter your Cloudflare R2 credentials.
4. Click **Test Connection & Verify CDN** to confirm connection.

== Changelog ==

= 1.0.25 =
* Security Hardening: Upgraded credential encryption to authenticated AES-256-CBC (encrypt-then-MAC) with fixed-length binary IV extraction and libsodium secretbox fallback (`sodium_crypto_secretbox`), completely eliminating insecure base64 fallback and providing automatic background upgrades for legacy credentials.
* Security Hardening: Transitioned R2 HTTP client from raw cURL to WordPress HTTP API (`wp_remote_request()`), respecting corporate proxies, `WP_HTTP_BLOCK_EXTERNAL`, and WordPress CA certificates.
* Security Hardening: Hardened R2 downloads with directory traversal containment guards, streaming response byte caps, MIME validation, and image binary verification.
* Security Hardening: Parameterized table introspection queries and strictly validated table identifiers with backtick escaping to prevent SQL injection during media import.
* Security Hardening: Protected secret key inputs with `wp_unslash()` to prevent backslash corruption, and validated uploaded preview files with `is_uploaded_file()`.
* Architecture & Standards: Enforced singleton pattern by making constructors private and disabling cloning across all plugin singletons.
* Rate Limiting & Stability: Added reSmush.it client-side request throttling, HTTP 429/503 circuit-breaker backoff, and upload memory headroom guards.
* Lifecycle & Cleanup: Added `uninstall.php` to clean up options, transients, temporary working files, and optional database table/metadata purge.

= 1.0.24 =
* WordPress.org Compliance: Guarded custom GitHub updater behind `R2G_ENABLE_GITHUB_UPDATER` constant; removed forced automatic update override; added explicit Third-Party Services disclosures for Cloudflare R2 and reSmush.it.
* Security Hardening: Enforced strict image extension and MIME validation, randomized temporary file storage, and directory access controls on preview compression AJAX handler.
* Security Hardening: Patched DOM-based XSS in upload interceptor batch carousel preview with secure DOM node creation.
* Security Hardening: Added granular `delete_post` and `edit_post` capability checks to single-item Media Library AJAX handlers.
* Security Hardening: Mitigated SSRF on external optimization downloads by enforcing host validation and safe transport options.
* Functional Fixes: Resolved bulk sync infinite loop by tracking and excluding failed item IDs from subsequent sync queries.
* Functional Fixes: Enhanced CDN URL rewriter and `srcset` generator to fully support custom directory structures (`wp_content`, `uploads_only`, `date_only`, `custom`) and preserve responsive image markup.
* Stability: Added `mbstring` function guards with native WordPress text trimming fallback to prevent fatal errors on minimal PHP environments.

= 1.0.23 =
* Codebase Cleanup: Streamlined code comments across all PHP and JavaScript modules to ensure clean, professional, production-grade documentation.
* Native Upload & Compression Refinements: Polished reSmush.it primary API pipeline and GD/Imagick fallback mechanisms for optimal performance and upload stability.

= 1.0.22 =
* Elimination of Browser Canvas Engine: Completely removed client-side canvas manipulation, blob alteration, and DataTransfer file swapping that caused Plupload multi-file upload, Gutenberg block drag-and-drop, and Media Library uploads to hang at 0% or 3%.
* reSmush.it as Ultimate Cloud Engine: Fixed reSmush.it API endpoint to use official direct-upload endpoint (`https://api.resmush.it/?qlty=...`), generous 60s timeout, HTTP fallback download handling, and pre-resize/EXIF orientation handling for images exceeding 5MB.
* Seamless Server GD/Imagick Fallback: When reSmush.it is unreachable or image is unsupported/optimal, processing automatically and transparently falls back to Server GD/Imagick.
* Transparent Optimization Audit: Stored `_r2g_opt_info` on every attachment recording exact engine used, notes, and bytes saved.
* Safe Native Upload Default: Made Visual Interceptor modal optional (disabled by default) so standard WordPress uploads run with 100% native stability and zero interference.
* Optimized Bulk Sync: Safe batching for reSmush.it with automatic time limit extension to prevent server timeouts.

= 1.0.21 =
* Fixed a Plupload start race: hold image uploads before WordPress starts the request, show the interceptor first, and release the queue only after confirmation.
* Remove cancelled files from the WordPress upload queue so cancelled items do not remain stuck in an Uploading state.
* Support both exported Gutenberg media upload API locations for drag-and-drop interception.

= 1.0.18 =
* Gutenberg Promise & Upload Fix: Wrapped `wp.mediaUtils.uploadMedia` in a standard Promise and preserved `_clientCompressed` state across async REST uploads, resolving post editor queue hangs.
* Universal Gutenberg Drag-and-Drop: Actively synchronized with `core/block-editor` and `core/editor` stores and subscribed to lifecycle updates so dropped images on editor canvas and dropzones are reliably intercepted.
* Browser Uploader Capture: Enabled capture-phase listeners and added hidden `html-upload` field injection on `media-new.php?browser-uploader=1` so single-file browser uploads trigger interceptor and submit cleanly.
* True Multi-Engine Preview: Added `r2g_preview_compression` AJAX endpoint for real Server GD / Imagick and reSmush.it previews with base64 data URIs and exact server byte savings, keeping HTML5 Canvas for browser engine and raw display for lossless.
* Live Bulk Sync Counter: Displayed exact live counts `Synced X / Y media items (Z remaining)` using `count_unsynced()` database index.
* Defer Local File Deletion: When storage mode is set to R2 Only, deferred local disk cleanup to PHP `shutdown` hook so REST API responses never 500 or miss dimensions during upload.

= 1.0.17 =
* Added In-Browser "Compress Preview" Action: Generates instant client-side Canvas blobs in browser memory. Displays live image quality, exact byte sizes, and green percentage savings badge (-XX%).
* Dynamic Action Button Flow: Modal footer buttons intuitively adapt. Displays [Cancel Upload] and [Compress & Upload] initially; transforms to [Cancel Upload] and [✓ Upload Now] once pre-compressed. If settings are tweaked, prompts with [⚡ Re-compress].
* Fixed Unresponsive Controls in Gutenberg: Stopped pointer/mouse event propagation to Gutenberg's canvas, ensuring all format buttons, engine toggles, destination radios, and the quality slider are 100% responsive and draggable.
* Guaranteed Site Settings as Baseline Default: The modal always initializes to the configured site settings (Target Format, Quality, Engine, Destination) saved in WP Admin without stale cookie pollution.
* Full Browser File Form (`media-new.php?browser-uploader`) Interception: Seamlessly intercepts file selection, replaces the file list with the client-compressed blob, injects parameters, and submits the form upon confirmation.
* Enhanced Drag-and-Drop Interception: Eliminates auto-skip flags so every drag-and-drop onto Gutenberg or Media Library cleanly triggers the Interceptor modal.
* Multi-File Batch Wildcard (*): Compact batch carousel with thumbnail selector, total batch size calculation, and wildcard setting to apply chosen format and quality across all batch files.
* Enhanced Modal UX: Scrollable container (`max-height: 90vh`) with custom sleek scrollbar and top-priority z-index (`99999999`) above all WordPress UI layers.

= 1.0.16 =
* Completely removed sticky dropzone toolbar from DOM: eliminates unwanted bars from the UI, keeping the Media Library and editors 100% clean and native.
* Universal Interceptor Trigger on File Selection: opens the visual Interceptor Modal only when an image is chosen or dropped (works for Plupload multi-file upload, browser file uploader form, and Gutenberg editor).
* Full Gutenberg Block Editor Interception: intercepts image uploads from the core Image block, Gallery block, Cover block, Media & Text block, 3rd party plugin blocks, and drag-and-drop before hitting the WordPress server.
* Enhanced Modal Stacking: increased overlay z-index to 999999 to guarantee priority above all Gutenberg modals, dialogs, and media frames.
* Simplified Settings: clean Visual Upload Interceptor toggle replacing redundant workflow options.

= 1.0.15 =
* Fixed unwanted popup modal on page load: rectified modal CSS display rules and Plupload bindings so the modal never displays automatically upon navigating to the dashboard or Media Library.
* Fixed unclosable modal: Cancel button, "X" close icon, Escape key, and backdrop click now cleanly dismiss the dialog and cancel pending uploads.
* Completely eliminated conflicting "Preset" vs "Format" duality: removed redundant preset dropdown from Settings tab, Bulk Sync, and Upload controls. Direct selection of Target Format (WebP, JPG, PNG, Original) and Quality slider (50%–100%) now directly dictate compression without forceful WebP overrides.
* Defaulted upload workflow to interactive dropzone toolbar: non-intrusive toolbar placed above the upload dropzone allows adjusting format and quality directly on-screen without requiring a popup dialog.
* Streamlined Bulk Sync engine: batch processing now accepts Target Format and Quality directly with backward compatibility.

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

== Third-Party Services ==

This plugin integrates with the following external third-party services:

1. **Cloudflare R2 Object Storage**
* Service: Cloudflare R2
* Service URL: https://www.cloudflare.com/products/r2/
* Privacy Policy: https://www.cloudflare.com/privacypolicy/
* Terms of Service: https://www.cloudflare.com/website-terms/
* Purpose: Stores and serves your offloaded WordPress media files via S3-compatible cloud object storage.

2. **reSmush.it Image Optimization API (Optional Engine)**
* Service: reSmush.it
* Service URL: https://resmush.it/
* Terms of Service: https://resmush.it/
* Privacy Policy: https://resmush.it/
* Purpose: When "reSmush.it Free API" is selected as the optimization engine, images are uploaded to the reSmush.it API endpoint (https://api.resmush.it/) to be compressed without consuming web hosting server CPU.
* Data Transmitted: Only the image binary data is transmitted for optimization. No personal, sensitive, or user identifying information is ever sent.
* Control: Users can select "Server: PHP GD / Imagick" or "Raw Offload" in the plugin settings at any time to process all images entirely on their local server without using any third-party optimization service.

== Frequently Asked Questions ==

= Does this plugin require AWS SDK? =
No. R2 by Grisma features a pure PHP AWS SigV4 implementation with zero heavy AWS SDKs or vendor bloat, keeping your site fast and lightweight.

= Are my credentials secure? =
Yes. Cloudflare R2 secret access keys are encrypted with AES-256-CBC using WordPress security salts.

= Does bulk sync delete local files? =
No. Bulk sync never deletes local server copies automatically. Local copies can only be removed after verification via the explicit "Clean Verified Local Files" action.

