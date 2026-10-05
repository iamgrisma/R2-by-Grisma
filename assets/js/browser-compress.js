/**
 * R2 by Grisma — Universal Upload Interceptor & In-Browser Image Optimizer
 * Version: 1.0.17
 *
 * Core Features:
 * 1. ZERO sticky bars in the DOM on page load.
 * 2. Universal interception:
 *    - WordPress Media Library (Plupload multi-file upload & drag-and-drop)
 *    - Gutenberg Block Editor (Image, Gallery, Cover, etc. & drag-and-drop)
 *    - Native Browser File Uploader Form (media-new.php?browser-uploader)
 * 3. Settings Defaults:
 *    - Modal defaults directly to the plugin settings configured in Settings -> R2 by Grisma.
 *    - Fully customizable on every upload without restriction.
 * 4. In-Browser Compress Preview:
 *    - Dedicated "⚡ Preview Compression" button.
 *    - Generates client-side Canvas blob immediately in browser memory with selected format & quality.
 *    - Shows before vs after preview, exact byte sizes, and green savings percentage badge (-XX%).
 *    - Action buttons seamlessly adapt:
 *      * Before Preview: [Cancel Upload] and [⚡ Compress & Upload]
 *      * After Preview:  [Cancel Upload] and [✓ Upload Now] (already compressed!)
 *      * If settings changed after preview: Preview button shows [⚡ Re-compress], action button returns to [⚡ Compress & Upload].
 * 5. Robust Drag-and-Drop & Multi-File Batch Wildcard (*):
 *    - Global drag-and-drop interception across all editors and library.
 *    - Compact batch carousel with thumbnail switcher and wildcard mode.
 * 6. Bulletproof Event Propagation:
 *    - Direct element event binding with stopPropagation to ensure Gutenberg/React never captures or blocks clicks and slider dragging.
 *
 * @package R2_By_Grisma
 */
(function($) {
  'use strict';

  if (typeof window === 'undefined') return;

  const R2G_UploadManager = {
    config: window.r2g_compress_config || {
      engine: 'server',
      storageMode: 'both',
      interceptor: 1,
      format: 'webp',
      compress: 1,
      quality: 82,
      maxWidth: 1920,
    },

    normalizeFormat: function(fmt) {
      fmt = (fmt || 'webp').toLowerCase();
      if (fmt === 'jpeg') fmt = 'jpg';
      return fmt;
    },

    setCookie: function(name, val) {
      document.cookie = name + '=' + encodeURIComponent(val) + '; path=/; max-age=86400; SameSite=Lax';
    },

    getCookie: function(name) {
      const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
      return match ? decodeURIComponent(match[2]) : null;
    },

    isImage: function(f) {
      if (!f) return false;
      const type = (f.type || '').toLowerCase();
      const name = (f.name || '').toLowerCase();
      if (type.indexOf('image/') === 0 && type.indexOf('svg') === -1) return true;
      if (name.match(/\.(jpe?g|png|webp|gif|bmp|tiff|avif)$/i)) return true;
      return false;
    },

    formatBytes: function(bytes) {
      if (!bytes || bytes <= 0) return '0 B';
      const k = 1024;
      const sizes = ['B', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },

    init: function() {
      // 1. Initial State Resolution: Always default to site plugin settings
      this.format = this.normalizeFormat(this.config.format || 'webp');
      this.quality = parseInt(this.config.quality, 10) || 82;
      this.compress = (this.config.compress !== undefined) ? parseInt(this.config.compress, 10) : 1;
      this.maxWidth = parseInt(this.config.maxWidth, 10) || 1920;
      this.engine = this.config.engine || 'server';
      this.storageMode = this.config.storageMode || 'both';

      this.syncCookies();

      // 2. Setup Upload Interceptors across WordPress (Plupload, Gutenberg, Browser Form)
      this.hookPlupload();
      this.hookGutenberg();
      this.hookBrowserForm();
    },

    syncCookies: function() {
      this.setCookie('r2g_format', this.format);
      this.setCookie('r2g_quality', this.quality);
      this.setCookie('r2g_compress', (this.engine === 'none') ? 0 : this.compress);
      this.setCookie('r2g_max_width', this.maxWidth);
      this.setCookie('r2g_engine', this.engine);
      this.setCookie('r2g_storage_mode', this.storageMode);
    },

    setFormat: function(newFormat) {
      this.format = this.normalizeFormat(newFormat);
      this.syncAllControls();
      this.markPreviewDirty();
    },

    setQuality: function(newQuality, sourceEl) {
      this.quality = Math.max(50, Math.min(100, parseInt(newQuality, 10) || 82));

      $('.r2g-quality-badge, .r2g-quality-val').text(this.quality + '%');

      if (sourceEl) {
        $('.r2g-quality-slider').not(sourceEl).val(this.quality);
      } else {
        $('.r2g-quality-slider').val(this.quality);
      }

      this.syncCookies();
      this.updateUploaderParams();
      this.markPreviewDirty();
    },

    setEngine: function(newEngine) {
      this.engine = newEngine;
      this.syncAllControls();
      this.markPreviewDirty();
    },

    setStorageMode: function(newMode) {
      this.storageMode = newMode;
      this.syncAllControls();
    },

    syncAllControls: function() {
      this.syncCookies();

      // Format buttons
      $('.r2g-format-btn').removeClass('r2g-btn-active');
      $('.r2g-format-btn[data-format="' + this.format + '"]').addClass('r2g-btn-active');

      // Quality slider & live badges
      $('.r2g-quality-slider').val(this.quality);
      $('.r2g-quality-badge, .r2g-quality-val').text(this.quality + '%');

      if (this.engine === 'none' || (this.format === 'original' && this.compress === 0)) {
        $('.r2g-quality-wrap').hide();
      } else {
        $('.r2g-quality-wrap').show();
      }

      // Engine controls
      $('.r2g-engine-btn').removeClass('r2g-btn-active');
      $('.r2g-engine-btn[data-engine="' + this.engine + '"]').addClass('r2g-btn-active');

      // Storage controls
      $('.r2g-storage-btn').removeClass('r2g-btn-active');
      $('.r2g-storage-btn[data-storage="' + this.storageMode + '"]').addClass('r2g-btn-active');

      this.updateUploaderParams();
    },

    updateUploaderParams: function() {
      if (this.currentUploader && this.currentUploader.settings) {
        this.currentUploader.settings.multipart_params = this.currentUploader.settings.multipart_params || {};
        this.currentUploader.settings.multipart_params['r2g_format'] = this.format;
        this.currentUploader.settings.multipart_params['r2g_compress'] = (this.engine === 'none') ? 0 : this.compress;
        this.currentUploader.settings.multipart_params['r2g_quality'] = this.quality;
        this.currentUploader.settings.multipart_params['r2g_max_width'] = this.maxWidth;
        this.currentUploader.settings.multipart_params['r2g_storage_mode'] = this.storageMode;
        this.currentUploader.settings.multipart_params['r2g_engine'] = this.engine;
      }
    },

    markPreviewDirty: function() {
      this.hasCompressedPreview = false;
      const $previewBtn = $('#r2g-btn-preview-compress');
      $previewBtn.removeClass('r2g-recompress');
      $previewBtn.find('.r2g-preview-btn-text').text('⚡ Re-compress');

      if ($('#r2g-compress-result').is(':visible')) {
        $('#r2g-stat-dirty-note').text('(Settings modified — click Re-compress)').show();
      }

      const $proceedBtn = $('#r2g-btn-modal-proceed');
      $proceedBtn.removeClass('r2g-ready-upload');
      if (this.engine === 'browser') {
        $('#r2g-proceed-label').text('⚡ Compress & Upload');
      } else {
        $('#r2g-proceed-label').text('Upload & Offload to R2');
      }
    },

    /**
     * Client-side HTML5 Canvas Image Compression and Format Conversion
     * Compresses directly in browser memory before sending over the wire.
     */
    compressImageCanvas: function(file, targetFormat, quality, maxWidth) {
      const self = this;
      return new Promise(function(resolve) {
        if (!file) return resolve(file);

        const nativeFile = file.getNative ? file.getNative() : (file.getSource ? file.getSource() : file);
        if (!nativeFile || !nativeFile.type || nativeFile.type.indexOf('image/') !== 0 || nativeFile.type.indexOf('svg') !== -1 || nativeFile.type.indexOf('gif') !== -1) {
          return resolve(nativeFile);
        }

        const img = new Image();
        const objectUrl = URL.createObjectURL(nativeFile);

        img.onload = function() {
          URL.revokeObjectURL(objectUrl);

          let width = img.naturalWidth || img.width;
          let height = img.naturalHeight || img.height;

          if (maxWidth && maxWidth > 0 && width > maxWidth) {
            height = Math.round((height * maxWidth) / width);
            width = maxWidth;
          }

          const canvas = document.createElement('canvas');
          canvas.width = width;
          canvas.height = height;
          const ctx = canvas.getContext('2d');
          ctx.imageSmoothingEnabled = true;
          ctx.imageSmoothingQuality = 'high';

          targetFormat = self.normalizeFormat(targetFormat);
          let mimeType = 'image/webp';
          if (targetFormat === 'jpg') {
            mimeType = 'image/jpeg';
            ctx.fillStyle = '#FFFFFF';
            ctx.fillRect(0, 0, width, height);
          } else if (targetFormat === 'png') {
            mimeType = 'image/png';
          } else if (targetFormat === 'original') {
            mimeType = nativeFile.type || 'image/jpeg';
            if (mimeType === 'image/jpeg') {
              ctx.fillStyle = '#FFFFFF';
              ctx.fillRect(0, 0, width, height);
            }
          }

          ctx.drawImage(img, 0, 0, width, height);
          const q = Math.max(0.5, Math.min(1.0, quality / 100));

          if (canvas.toBlob) {
            canvas.toBlob(function(blob) {
              if (!blob) return resolve(nativeFile);

              let ext = 'webp';
              if (mimeType === 'image/jpeg') ext = 'jpg';
              else if (mimeType === 'image/png') ext = 'png';
              else ext = nativeFile.name ? nativeFile.name.split('.').pop() : 'webp';

              const baseName = nativeFile.name ? nativeFile.name.replace(/\.[^/.]+$/, '') : 'image';
              const newName = baseName + '.' + ext;

              let newFile;
              try {
                newFile = new File([blob], newName, { type: mimeType, lastModified: Date.now() });
              } catch (e) {
                blob.name = newName;
                blob.lastModifiedDate = new Date();
                newFile = blob;
              }

              resolve(newFile);
            }, mimeType, q);
          } else {
            resolve(nativeFile);
          }
        };

        img.onerror = function() {
          URL.revokeObjectURL(objectUrl);
          resolve(nativeFile);
        };

        img.src = objectUrl;
      });
    },

    /**
     * Batch compress multiple Plupload file items
     */
    compressBatch: function(up, imageFiles) {
      const self = this;
      const promises = imageFiles.map(function(f) {
        if (f._r2g_compressed) {
          const blob = f._r2g_compressed;
          f.getSource = function() { return blob; };
          f.getNative = function() { return blob; };
          f.size = blob.size;
          f.name = blob.name;
          return Promise.resolve(blob);
        }
        return self.compressImageCanvas(f, self.format, self.quality, self.maxWidth).then(function(newBlob) {
          if (newBlob && newBlob !== f) {
            f.getSource = function() { return newBlob; };
            f.getNative = function() { return newBlob; };
            f.size = newBlob.size;
            f.name = newBlob.name;
          }
        });
      });
      return Promise.all(promises);
    },

    /**
     * Batch compress array of File objects (used by Gutenberg & Browser Form)
     */
    compressFileList: function(files) {
      const self = this;
      const promises = files.map(function(f) {
        if (f._r2g_compressed) {
          return Promise.resolve(f._r2g_compressed);
        }
        if (self.isImage(f)) {
          return self.compressImageCanvas(f, self.format, self.quality, self.maxWidth);
        }
        return Promise.resolve(f);
      });
      return Promise.all(promises);
    },

    /**
     * Ensure the Upload Interceptor Modal markup is in DOM ONLY when needed
     */
    ensureModalHtml: function() {
      if ($('#r2g-interceptor-modal').length) return;

      const modalHtml = `
        <div id="r2g-interceptor-modal" class="r2g-modal-overlay">
          <div class="r2g-modal-card">
            <div class="r2g-modal-header">
              <div class="r2g-modal-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                <span>Cloudflare R2 Upload Interceptor</span>
              </div>
              <button type="button" class="r2g-modal-close" id="r2g-btn-modal-close" title="Cancel upload">&times;</button>
            </div>

            <div class="r2g-modal-body">
              <!-- File Preview & Live Compression Inspector -->
              <div class="r2g-preview-box">
                <div class="r2g-preview-main">
                  <div class="r2g-preview-thumb-wrap">
                    <img id="r2g-preview-img" src="" alt="Upload Preview" />
                    <span class="r2g-preview-tag" id="r2g-preview-tag">ORIGINAL</span>
                  </div>
                  <div class="r2g-preview-details">
                    <div class="r2g-preview-filename" id="r2g-preview-name">image.jpg</div>
                    <div class="r2g-preview-badges">
                      <span class="r2g-chip" id="r2g-preview-size">0 KB</span>
                      <span class="r2g-chip" id="r2g-preview-dims">-- &times; -- px</span>
                      <span class="r2g-chip r2g-chip-type" id="r2g-preview-type">IMAGE</span>
                      <span class="r2g-chip" id="r2g-preview-batch-chip" style="display:none; background:#fef3c7; color:#92400e; font-weight:700;"></span>
                    </div>
                    <p class="r2g-preview-notice">
                      Held in browser memory before sending. Zero server bandwidth or CPU consumed yet.
                    </p>
                  </div>
                </div>

                <!-- Live Compression Action & Savings Box -->
                <div class="r2g-preview-actions">
                  <button type="button" class="button r2g-btn-preview-compress" id="r2g-btn-preview-compress">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    <span class="r2g-preview-btn-text">⚡ Preview Compression</span>
                  </button>
                  <div class="r2g-compress-result" id="r2g-compress-result" style="display:none;">
                    <div class="r2g-compress-stats">
                      <span class="r2g-stat-orig" id="r2g-stat-orig">--</span>
                      <span>&rarr;</span>
                      <span class="r2g-stat-comp" id="r2g-stat-comp">--</span>
                      <span class="r2g-stat-saving" id="r2g-stat-saving">-0%</span>
                    </div>
                    <span class="r2g-stat-dirty-note" id="r2g-stat-dirty-note" style="display:none;"></span>
                  </div>
                </div>

                <!-- Multi-File Batch Strip & Wildcard -->
                <div class="r2g-batch-bar" id="r2g-batch-bar" style="display:none;">
                  <div class="r2g-batch-header">
                    <span class="r2g-batch-title" id="r2g-batch-title">Batch: 0 images</span>
                    <label class="r2g-batch-wildcard-label">
                      <input type="checkbox" id="r2g-wildcard-apply" checked />
                      <span>Wildcard (*) Apply settings to all in batch</span>
                    </label>
                  </div>
                  <div class="r2g-batch-thumbs" id="r2g-batch-thumbs"></div>
                </div>
              </div>

              <!-- Streamlined Settings Controls -->
              <div class="r2g-modal-controls">
                <div class="r2g-control-row">
                  <label class="r2g-control-label">Target Format:</label>
                  <div class="r2g-control-input">
                    <div class="r2g-format-group">
                      <button type="button" class="r2g-format-btn" data-format="webp">WebP</button>
                      <button type="button" class="r2g-format-btn" data-format="jpg">JPG</button>
                      <button type="button" class="r2g-format-btn" data-format="png">PNG</button>
                      <button type="button" class="r2g-format-btn" data-format="original">Original</button>
                    </div>
                  </div>
                </div>

                <div class="r2g-control-row r2g-quality-wrap">
                  <label class="r2g-control-label">Compression Quality:</label>
                  <div class="r2g-control-input">
                    <div class="r2g-slider-box">
                      <input type="range" class="r2g-quality-slider" id="r2g-modal-quality" min="50" max="100" value="82" />
                      <span class="r2g-quality-badge r2g-quality-val">82%</span>
                    </div>
                  </div>
                </div>

                <div class="r2g-control-row">
                  <label class="r2g-control-label">Processing Engine:</label>
                  <div class="r2g-control-input">
                    <div class="r2g-btn-toggle-group">
                      <button type="button" class="r2g-engine-btn" data-engine="browser" title="Compress in browser canvas (0 server CPU)">Browser Canvas</button>
                      <button type="button" class="r2g-engine-btn" data-engine="server" title="Fast native server GD/Imagick">Server GD</button>
                      <button type="button" class="r2g-engine-btn" data-engine="resmush" title="reSmush.it API with GD/Imagick fallback">reSmush.it</button>
                      <button type="button" class="r2g-engine-btn" data-engine="none" title="Raw lossless offload">Lossless</button>
                    </div>
                  </div>
                </div>

                <div class="r2g-control-row">
                  <label class="r2g-control-label">Storage Destination:</label>
                  <div class="r2g-control-input">
                    <div class="r2g-btn-toggle-group">
                      <button type="button" class="r2g-storage-btn" data-storage="both" title="Dual storage: WordPress + Cloudflare R2">Dual (WP + R2)</button>
                      <button type="button" class="r2g-storage-btn" data-storage="r2_only" title="Offload to R2 and delete local copies to free hosting disk">R2 Only (Cloud)</button>
                      <button type="button" class="r2g-storage-btn" data-storage="local_only" title="Keep local on WordPress, skip R2 offload">WP Only (Local)</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="r2g-modal-footer">
              <button type="button" class="button r2g-btn-modal-cancel" id="r2g-btn-modal-cancel">Cancel Upload</button>
              <button type="button" class="button button-primary button-large r2g-btn-modal-proceed" id="r2g-btn-modal-proceed">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px; margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>
                <span id="r2g-proceed-label">⚡ Compress & Upload</span>
              </button>
            </div>
          </div>
        </div>
      `;

      $('body').append(modalHtml);
    },

    /**
     * Run in-browser preview compression on the current active image file
     */
    runPreviewCompression: function(nativeFile) {
      const self = this;
      const $btn = $('#r2g-btn-preview-compress');
      const $label = $btn.find('.r2g-preview-btn-text');

      $btn.prop('disabled', true);
      $label.text('Compressing in browser...');

      self.compressImageCanvas(nativeFile, self.format, self.quality, self.maxWidth).then(function(newBlob) {
        $btn.prop('disabled', false);
        if (!newBlob) {
          $label.text('⚡ Preview Compression');
          return;
        }

        self.previewBlob = newBlob;
        self.hasCompressedPreview = true;
        nativeFile._r2g_compressed = newBlob;

        // Update preview image
        const compUrl = URL.createObjectURL(newBlob);
        $('#r2g-preview-img').attr('src', compUrl);
        $('#r2g-preview-tag').text('COMPRESSED (' + self.format.toUpperCase() + ')').css({ background: '#0284c7' });

        // Calculate size reduction
        const origSize = nativeFile.size || 1;
        const compSize = newBlob.size || 1;
        const pct = Math.round(((origSize - compSize) / origSize) * 100);

        $('#r2g-stat-orig').text(self.formatBytes(origSize));
        $('#r2g-stat-comp').text(self.formatBytes(compSize));

        if (pct > 0) {
          $('#r2g-stat-saving').text('-' + pct + '%').css({ background: '#15803d' });
        } else if (pct < 0) {
          $('#r2g-stat-saving').text('+' + Math.abs(pct) + '%').css({ background: '#475569' });
        } else {
          $('#r2g-stat-saving').text('0%').css({ background: '#475569' });
        }

        $('#r2g-stat-dirty-note').hide();
        $('#r2g-compress-result').show();

        // Button transitions
        $label.text('✓ Re-compress');
        $btn.addClass('r2g-recompress');

        // Modal action button adapts:
        $('#r2g-btn-modal-proceed').addClass('r2g-ready-upload');
        $('#r2g-proceed-label').text('✓ Upload Now');
      });
    },

    /**
     * Open Upload Interceptor Modal when files are added to upload
     */
    showInterceptorModal: function(uploader, files, onProceed, onCancel) {
      const self = this;
      self.ensureModalHtml();

      const $modal = $('#r2g-interceptor-modal');
      let activeIndex = 0;
      self.hasCompressedPreview = false;
      self.previewBlob = null;

      // Always reload default site settings when a new modal opens
      self.format = self.normalizeFormat(self.config.format || 'webp');
      self.quality = parseInt(self.config.quality, 10) || 82;
      self.compress = (self.config.compress !== undefined) ? parseInt(self.config.compress, 10) : 1;
      self.maxWidth = parseInt(self.config.maxWidth, 10) || 1920;
      self.engine = self.config.engine || 'server';
      self.storageMode = self.config.storageMode || 'both';

      const updateFileDisplay = function(idx) {
        activeIndex = idx;
        const file = files[idx];
        const nativeFile = file.getNative ? file.getNative() : (file.getSource ? file.getSource() : file);

        const fileName = nativeFile.name || file.name || 'image.jpg';
        const fileSize = nativeFile.size || file.size || 0;
        const fileType = (nativeFile.type || file.type || '').replace('image/', '').toUpperCase() || 'IMAGE';

        $('#r2g-preview-name').text(fileName);
        $('#r2g-preview-size').text(self.formatBytes(fileSize));
        $('#r2g-preview-type').text(fileType);
        $('#r2g-preview-dims').text('-- \u00d7 -- px');
        $('#r2g-preview-tag').text('ORIGINAL').css({ background: 'rgba(15, 23, 42, 0.75)' });

        $('#r2g-btn-preview-compress').removeClass('r2g-recompress');
        $('#r2g-btn-preview-compress .r2g-preview-btn-text').text('⚡ Preview Compression');
        $('#r2g-compress-result').hide();
        $('#r2g-stat-dirty-note').hide();

        $('#r2g-btn-modal-proceed').removeClass('r2g-ready-upload');
        if (self.engine === 'browser') {
          $('#r2g-proceed-label').text('⚡ Compress & Upload');
        } else {
          $('#r2g-proceed-label').text('Upload & Offload to R2');
        }

        if (nativeFile instanceof Blob) {
          const objectUrl = URL.createObjectURL(nativeFile);
          $('#r2g-preview-img').attr('src', objectUrl);

          const img = new Image();
          img.onload = function() {
            $('#r2g-preview-dims').text(img.naturalWidth + ' \u00d7 ' + img.naturalHeight + ' px');
          };
          img.src = objectUrl;
        } else {
          $('#r2g-preview-img').attr('src', '');
        }

        $('.r2g-batch-thumb-item').removeClass('r2g-batch-active');
        $('.r2g-batch-thumb-item[data-idx="' + idx + '"]').addClass('r2g-batch-active');
      };

      // Multi-file batch management
      if (files.length > 1) {
        let totalBytes = 0;
        let thumbsHtml = '';
        files.forEach(function(f, i) {
          const nf = f.getNative ? f.getNative() : (f.getSource ? f.getSource() : f);
          const sz = nf.size || f.size || 0;
          totalBytes += sz;
          let thumbSrc = '';
          if (nf instanceof Blob) {
            thumbSrc = URL.createObjectURL(nf);
          }
          thumbsHtml += `
            <div class="r2g-batch-thumb-item ${i === 0 ? 'r2g-batch-active' : ''}" data-idx="${i}" title="${nf.name || f.name || 'image'}">
              <img src="${thumbSrc}" alt="thumb" />
            </div>
          `;
        });

        $('#r2g-batch-title').text('Batch: ' + files.length + ' images (' + self.formatBytes(totalBytes) + ' total)');
        $('#r2g-batch-thumbs').html(thumbsHtml);
        $('#r2g-batch-bar').show();
        $('#r2g-preview-batch-chip').text('+ ' + (files.length - 1) + ' in batch').show();
      } else {
        $('#r2g-batch-bar').hide();
        $('#r2g-preview-batch-chip').hide();
      }

      // Initial file display
      updateFileDisplay(0);
      self.syncAllControls();

      // Clean old event listeners on modal
      $modal.off('click mousedown pointerdown');
      $modal.find('*').off('click mousedown pointerdown touchstart input change');
      $(document).off('keydown.r2gmodal');

      // CRITICAL: Stop pointer/mouse propagation inside modal card so Gutenberg never steals focus or blocks dragging
      $modal.find('.r2g-modal-card').on('click pointerdown mousedown touchstart', function(e) {
        e.stopPropagation();
      });

      // Bind format buttons directly on modal
      $modal.find('.r2g-format-btn').on('click pointerup', function(e) {
        e.preventDefault();
        e.stopPropagation();
        self.setFormat($(this).data('format'));
      });

      // Bind engine buttons directly on modal
      $modal.find('.r2g-engine-btn').on('click pointerup', function(e) {
        e.preventDefault();
        e.stopPropagation();
        self.setEngine($(this).data('engine'));
      });

      // Bind storage destination buttons directly on modal
      $modal.find('.r2g-storage-btn').on('click pointerup', function(e) {
        e.preventDefault();
        e.stopPropagation();
        self.setStorageMode($(this).data('storage'));
      });

      // Bind quality slider directly on modal (stops Gutenberg pointer theft)
      $modal.find('.r2g-quality-slider')
        .on('pointerdown mousedown touchstart', function(e) {
          e.stopPropagation();
        })
        .on('input change', function(e) {
          e.stopPropagation();
          self.setQuality($(this).val(), this);
        });

      // Bind batch thumbnail clicks
      $modal.find('.r2g-batch-thumb-item').on('click pointerup', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const idx = parseInt($(this).data('idx'), 10) || 0;
        updateFileDisplay(idx);
      });

      // Bind Compress Preview button
      $modal.find('#r2g-btn-preview-compress').on('click pointerup', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const currentFile = files[activeIndex];
        const nativeFile = currentFile.getNative ? currentFile.getNative() : (currentFile.getSource ? currentFile.getSource() : currentFile);
        self.runPreviewCompression(nativeFile);
      });

      const closeModal = function() {
        $modal.removeClass('r2g-modal-active');
        $(document).off('keydown.r2gmodal');
      };

      // Close handlers: Cancel button & Close X
      $modal.find('#r2g-btn-modal-close, #r2g-btn-modal-cancel').on('click pointerup', function(e) {
        e.preventDefault();
        e.stopPropagation();
        closeModal();
        if (typeof onCancel === 'function') onCancel();
      });

      // Close handler: Backdrop click outside the card
      $modal.on('click', function(e) {
        if ($(e.target).is('#r2g-interceptor-modal')) {
          e.preventDefault();
          closeModal();
          if (typeof onCancel === 'function') onCancel();
        }
      });

      // Close handler: Escape key
      $(document).on('keydown.r2gmodal', function(e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
          closeModal();
          if (typeof onCancel === 'function') onCancel();
        }
      });

      // Proceed handler: Start upload
      $modal.find('#r2g-btn-modal-proceed').on('click pointerup', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $btn = $(this);
        $btn.prop('disabled', true);
        $('#r2g-proceed-label').text('Preparing upload...');

        closeModal();
        $btn.prop('disabled', false);

        if (typeof onProceed === 'function') onProceed();
      });

      // Show modal
      $modal.addClass('r2g-modal-active');
    },

    /**
     * Intercept standard WordPress Media Uploader (Plupload)
     * Used across Media Library, media modal, and drag-and-drop
     */
    hookPlupload: function() {
      const self = this;

      const bindToUploader = function(uploader) {
        if (!uploader || uploader._r2g_bound) return;
        uploader._r2g_bound = true;
        self.currentUploader = uploader;

        const applyParams = function(up) {
          up.settings.multipart_params = up.settings.multipart_params || {};
          up.settings.multipart_params['r2g_format'] = self.format;
          up.settings.multipart_params['r2g_compress'] = (self.engine === 'none') ? 0 : self.compress;
          up.settings.multipart_params['r2g_quality'] = self.quality;
          up.settings.multipart_params['r2g_max_width'] = self.maxWidth;
          up.settings.multipart_params['r2g_storage_mode'] = self.storageMode;
          up.settings.multipart_params['r2g_engine'] = self.engine;
          self.syncCookies();
        };

        uploader.bind('FilesAdded', function(up, files) {
          const imageFiles = files.filter(function(f) {
            return self.isImage(f);
          });

          // Non-image files proceed directly without modal
          if (imageFiles.length === 0) {
            applyParams(up);
            return;
          }

          if (self.config.interceptor === 0) {
            applyParams(up);
            if (self.engine === 'browser') {
              up.stop();
              self.compressBatch(up, imageFiles).then(function() {
                applyParams(up);
                up.start();
              });
            }
            return;
          }

          // STOP Plupload immediately before sending bytes to server!
          up.stop();

          self.showInterceptorModal(
            up,
            imageFiles,
            // onProceed:
            function() {
              applyParams(up);
              if (self.hasCompressedPreview && self.previewBlob && imageFiles.length === 1) {
                // Single image pre-compressed in browser
                const f = imageFiles[0];
                f.getSource = function() { return self.previewBlob; };
                f.getNative = function() { return self.previewBlob; };
                f.size = self.previewBlob.size;
                f.name = self.previewBlob.name;
                up.settings.multipart_params['r2g_client_compressed'] = 1;
                applyParams(up);
                up.start();
              } else if (self.engine === 'browser') {
                self.compressBatch(up, imageFiles).then(function() {
                  up.settings.multipart_params['r2g_client_compressed'] = 1;
                  applyParams(up);
                  up.start();
                });
              } else {
                up.start();
              }
            },
            // onCancel:
            function() {
              files.forEach(function(f) {
                up.removeFile(f);
              });
            }
          );
        });

        uploader.bind('BeforeUpload', function(up, file) {
          applyParams(up);
        });
      };

      if (typeof wp !== 'undefined' && wp.Uploader && wp.Uploader.prototype) {
        if (!wp.Uploader._r2g_patched) {
          wp.Uploader._r2g_patched = true;
          const originalInit = wp.Uploader.prototype.init;
          wp.Uploader.prototype.init = function() {
            originalInit.apply(this, arguments);
            bindToUploader(this.uploader);
          };
        }
      }

      $(document).on('uploaderReady', function(e, up) {
        bindToUploader(up);
      });

      if (typeof window.uploader !== 'undefined' && window.uploader.bind) {
        bindToUploader(window.uploader);
      }

      // Check media frames if opened dynamically
      const checkMediaFrames = function() {
        if (typeof window.wp !== 'undefined' && wp.media && wp.media.frame && wp.media.frame.uploader && wp.media.frame.uploader.uploader) {
          bindToUploader(wp.media.frame.uploader.uploader);
        }
      };
      checkMediaFrames();
      $(document).on('click', '.upload-ui, .media-button, #insert-media-button', function() {
        setTimeout(checkMediaFrames, 300);
      });

      // Global failsafe for async-upload.php
      $(document).ajaxSend(function(event, xhr, settings) {
        if (settings && settings.url && settings.url.indexOf('async-upload.php') !== -1) {
          xhr.setRequestHeader('X-R2G-Format', self.format);
          xhr.setRequestHeader('X-R2G-Quality', self.quality);
          xhr.setRequestHeader('X-R2G-Compress', (self.engine === 'none') ? 0 : self.compress);
          xhr.setRequestHeader('X-R2G-Max-Width', self.maxWidth);
          xhr.setRequestHeader('X-R2G-Storage-Mode', self.storageMode);
          xhr.setRequestHeader('X-R2G-Engine', self.engine);
        }
      });
    },

    /**
     * Hook Gutenberg Block Editor media uploads & REST API
     * Intercepts Image blocks, Gallery blocks, Cover blocks, 3rd party blocks, and drag & drop!
     */
    hookGutenberg: function() {
      const self = this;

      // 1. Hook wp.apiFetch to inject R2G headers on media endpoints
      if (typeof window.wp !== 'undefined' && wp.apiFetch && wp.apiFetch.use) {
        if (!wp.apiFetch._r2g_hooked) {
          wp.apiFetch._r2g_hooked = true;
          wp.apiFetch.use(function(options, next) {
            if (options && options.path && options.path.indexOf('/wp/v2/media') !== -1) {
              options.headers = options.headers || {};
              options.headers['X-R2G-Format'] = self.format;
              options.headers['X-R2G-Quality'] = self.quality;
              options.headers['X-R2G-Compress'] = (self.engine === 'none') ? 0 : self.compress;
              options.headers['X-R2G-Max-Width'] = self.maxWidth;
              options.headers['X-R2G-Storage-Mode'] = self.storageMode;
              options.headers['X-R2G-Engine'] = self.engine;
              if (self._clientCompressed) {
                options.headers['X-R2G-Client-Compressed'] = '1';
              }
            }
            return next(options);
          });
        }
      }

      // 2. Intercept Gutenberg wp.mediaUtils.uploadMedia
      const wrapGutenberg = function() {
        if (typeof window.wp === 'undefined' || !wp.mediaUtils || !wp.mediaUtils.uploadMedia) {
          return false;
        }
        if (wp.mediaUtils._r2g_wrapped) {
          return true;
        }
        wp.mediaUtils._r2g_wrapped = true;

        const originalUploadMedia = wp.mediaUtils.uploadMedia;

        wp.mediaUtils.uploadMedia = function(options) {
          if (!options || !options.filesList || options.filesList.length === 0) {
            return originalUploadMedia.apply(this, arguments);
          }

          const files = Array.from(options.filesList);
          const imageFiles = files.filter(function(f) {
            return self.isImage(f);
          });

          // Non-image files proceed directly
          if (imageFiles.length === 0) {
            return originalUploadMedia.call(wp.mediaUtils, options);
          }

          // If interceptor is disabled in settings
          if (self.config.interceptor === 0) {
            self.syncCookies();
            if (self.engine === 'browser') {
              return self.compressFileList(files).then(function(newFiles) {
                options.filesList = newFiles;
                self._clientCompressed = true;
                const res = originalUploadMedia.call(wp.mediaUtils, options);
                self._clientCompressed = false;
                return res;
              });
            }
            return originalUploadMedia.call(wp.mediaUtils, options);
          }

          // Show Interceptor Modal before Gutenberg uploads to server!
          self.showInterceptorModal(
            null,
            imageFiles,
            // onProceed:
            function() {
              self.syncCookies();
              if (self.hasCompressedPreview && self.previewBlob && imageFiles.length === 1) {
                // Pre-compressed in preview
                options.filesList = [self.previewBlob];
                self._clientCompressed = true;
                originalUploadMedia.call(wp.mediaUtils, options);
                self._clientCompressed = false;
              } else if (self.engine === 'browser') {
                self.compressFileList(files).then(function(newFiles) {
                  options.filesList = newFiles;
                  self._clientCompressed = true;
                  originalUploadMedia.call(wp.mediaUtils, options);
                  self._clientCompressed = false;
                });
              } else {
                originalUploadMedia.call(wp.mediaUtils, options);
              }
            },
            // onCancel:
            function() {
              if (typeof options.onError === 'function') {
                options.onError(new Error('Upload cancelled.'));
              }
            }
          );
        };

        return true;
      };

      if (!wrapGutenberg()) {
        const gTimer = setInterval(function() {
          if (wrapGutenberg()) clearInterval(gTimer);
        }, 200);
        setTimeout(function() { clearInterval(gTimer); }, 15000);
      }
    },

    /**
     * Intercept browser single-file uploader form (media-new.php?browser-uploader)
     */
    hookBrowserForm: function() {
      const self = this;

      const handleFileInput = function(input) {
        if (!input || !input.files || input.files.length === 0) return;

        const files = Array.from(input.files);
        const imageFiles = files.filter(function(f) {
          return self.isImage(f);
        });
        if (imageFiles.length === 0 || self.config.interceptor === 0) return;

        const form = input.form || $(input).closest('form')[0];

        self.showInterceptorModal(
          null,
          imageFiles,
          function() {
            self.syncCookies();
            input._r2g_confirmed = true;

            const submitForm = function() {
              if (!form) return;

              const fields = {
                'r2g_format': self.format,
                'r2g_compress': (self.engine === 'none') ? 0 : self.compress,
                'r2g_quality': self.quality,
                'r2g_max_width': self.maxWidth,
                'r2g_storage_mode': self.storageMode,
                'r2g_engine': self.engine,
              };

              for (const [k, v] of Object.entries(fields)) {
                let inp = form.querySelector('input[name="' + k + '"]');
                if (!inp) {
                  inp = document.createElement('input');
                  inp.type = 'hidden';
                  inp.name = k;
                  form.appendChild(inp);
                }
                inp.value = v;
              }

              form.submit();
            };

            // If compressed in browser, replace input.files via DataTransfer
            if (self.hasCompressedPreview && self.previewBlob && window.DataTransfer) {
              try {
                const dt = new DataTransfer();
                dt.items.add(self.previewBlob);
                input.files = dt.files;
              } catch (e) {}
              submitForm();
            } else if (self.engine === 'browser' && window.DataTransfer) {
              self.compressImageCanvas(input.files[0], self.format, self.quality, self.maxWidth).then(function(newBlob) {
                try {
                  const dt = new DataTransfer();
                  dt.items.add(newBlob);
                  input.files = dt.files;
                } catch (e) {}
                submitForm();
              });
            } else {
              submitForm();
            }
          },
          function() {
            input.value = '';
            input._r2g_confirmed = false;
          }
        );
      };

      // File input change listener
      $(document).on('change', '#async-upload, #file-form input[type="file"], input[type="file"][name="async-upload"]', function() {
        handleFileInput(this);
      });

      // Submit listener
      $(document).on('submit', '#file-form', function(e) {
        const form = this;
        const fileInput = form.querySelector('input[type="file"]');
        if (fileInput && fileInput.files && fileInput.files.length > 0 && !fileInput._r2g_confirmed && self.config.interceptor !== 0) {
          const imageFiles = Array.from(fileInput.files).filter(function(f) {
            return self.isImage(f);
          });
          if (imageFiles.length > 0) {
            e.preventDefault();
            handleFileInput(fileInput);
          }
        }
      });
    }
  };

  $(document).ready(function() {
    R2G_UploadManager.init();
  });

})(jQuery);
