/**
 * R2 by Grisma — Universal Upload Interceptor & In-Browser Image Optimizer
 * Version: 1.0.20
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
 * 4. Dual-Engine Compress Preview:
 *    - Dedicated "⚡ Preview Compression" button.
 *    - When Browser Canvas engine is selected: generates client-side Canvas blob immediately in browser memory.
 *    - When Server GD or reSmush.it is selected: executes server optimization preview via AJAX and displays real output with exact byte savings.
 *    - When Lossless is selected: displays unaltered source image.
 *    - Action buttons seamlessly adapt:
 *      * Before Preview: [Cancel Upload] and [⚡ Compress & Upload]
 *      * After Preview:  [Cancel Upload] and [✓ Upload Now]
 *      * If settings changed after preview: Preview button shows [⚡ Re-compress], action button returns to [⚡ Compress & Upload].
 * 5. Robust Drag-and-Drop & Multi-File Batch Wildcard (*):
 *    - Global drag-and-drop interception across all editors and library.
 *    - Synchronized with Gutenberg core/block-editor and core/editor stores.
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
      ajax_url: (typeof window !== 'undefined' && window.ajaxurl) ? window.ajaxurl : '/wp-admin/admin-ajax.php',
      nonce: '',
    },

    normalizeFormat: function(fmt) {
      fmt = (fmt || 'webp').toLowerCase();
      if (fmt === 'jpeg') fmt = 'jpg';
      return fmt;
    },

    uploadSettings: function() {
      return {
        r2g_format: this.format,
        r2g_compress: this.engine === 'none' ? 0 : this.compress,
        r2g_quality: this.quality,
        r2g_max_width: this.maxWidth,
        r2g_storage_mode: this.storageMode,
        r2g_engine: this.engine,
      };
    },

    uploadHeaderName: function(key) {
      return 'X-' + key.replace(/^r2g_/, 'R2G-').replace(/_([a-z])/g, function(_, letter) {
        return '-' + letter.toUpperCase();
      });
    },

    setCookie: function(name, val) {
      document.cookie = name + '=' + encodeURIComponent(val) + '; path=/; max-age=86400; SameSite=Lax';
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
      this.maxWidth = Number.isFinite(parseInt(this.config.maxWidth, 10)) ? parseInt(this.config.maxWidth, 10) : 1920;
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
        Object.assign(this.currentUploader.settings.multipart_params, this.uploadSettings());
      }
    },

    markPreviewDirty: function() {
      this.hasCompressedPreview = false;
      this.previewBlob = null;
      this._previewFormat = null;
      this._previewEngine = null;

      if (this.currentFiles) {
        this.currentFiles.forEach(function(f) {
          const original = f._r2g_original_native || (f.getNative ? f.getNative() : (f.getSource ? f.getSource() : f));
          delete f._r2g_compressed;
          delete f._r2g_compressed_format;
          delete f._r2g_compressed_engine;
          if (original) {
            delete original._r2g_compressed;
            delete original._r2g_compressed_format;
            delete original._r2g_compressed_engine;
          }
        });
      }

      const $previewBtn = $('#r2g-btn-preview-compress');
      $previewBtn.prop('disabled', false).removeClass('r2g-recompress');
      $previewBtn.find('.r2g-preview-btn-text').text('⚡ Preview Compression');

      if ($('#r2g-compress-result').is(':visible')) {
        $('#r2g-stat-dirty-note').text('(Settings modified — click Preview to re-test, or proceed with new settings)').show();
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
          if (nativeFile && nativeFile.type && nativeFile.type.indexOf('image/') === 0) {
            nativeFile._r2g_canvas_processed = true;
          }
          return resolve(nativeFile);
        }

        const img = new Image();
        const objectUrl = URL.createObjectURL(nativeFile);
        let settled = false;
        const finish = function(result) {
          if (settled) return;
          settled = true;
          clearTimeout(timeout);
          URL.revokeObjectURL(objectUrl);
          resolve(result);
        };
        const timeout = setTimeout(function() { finish(nativeFile); }, 30000);

        img.onload = function() {
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
              if (!blob) return finish(nativeFile);

              const actualMimeType = blob.type || mimeType;
              let ext = 'webp';
              if (actualMimeType === 'image/webp') ext = 'webp';
              else if (actualMimeType === 'image/jpeg') ext = 'jpg';
              else if (actualMimeType === 'image/png') ext = 'png';
              else if (actualMimeType === 'image/avif') ext = 'avif';
              else ext = nativeFile.name ? nativeFile.name.split('.').pop() : 'webp';

              const baseName = nativeFile.name ? nativeFile.name.replace(/\.[^/.]+$/, '') : 'image';
              const newName = baseName + '.' + ext;

              let newFile;
              try {
                newFile = new File([blob], newName, { type: actualMimeType, lastModified: Date.now() });
              } catch (e) {
                blob.name = newName;
                blob.lastModifiedDate = new Date();
                newFile = blob;
              }
              newFile._r2g_canvas_processed = actualMimeType === mimeType;

              finish(newFile);
            }, mimeType, q);
          } else {
            finish(nativeFile);
          }
        };

        img.onerror = function() {
          finish(nativeFile);
        };

        img.src = objectUrl;
      });
    },

    /** Return the previewed blob when current settings match, otherwise compress once. */
    getUploadFile: function(file) {
      const self = this;
      const nativeFile = file && (file.getNative ? file.getNative() : (file.getSource ? file.getSource() : file));
      if (!nativeFile || !self.isImage(nativeFile)) return Promise.resolve(nativeFile || file);

      if (nativeFile._r2g_canvas_processed) return Promise.resolve(nativeFile);
      if (nativeFile._r2g_compressed &&
          nativeFile._r2g_compressed_format === self.format && nativeFile._r2g_compressed_engine === self.engine) {
        return Promise.resolve(nativeFile._r2g_compressed);
      }
      return self.compressImageCanvas(nativeFile, self.format, self.quality, self.maxWidth).then(function(blob) {
        if (blob && blob !== nativeFile) {
          nativeFile._r2g_compressed = blob;
          nativeFile._r2g_compressed_format = self.format;
          nativeFile._r2g_compressed_engine = self.engine;
        }
        return blob;
      });
    },

    needsBrowserCompression: function(file) {
      const nativeFile = file && (file.getNative ? file.getNative() : (file.getSource ? file.getSource() : file));
      return !!(nativeFile && this.isImage(nativeFile) && !nativeFile._r2g_canvas_processed &&
        !(nativeFile._r2g_compressed && nativeFile._r2g_compressed_format === this.format && nativeFile._r2g_compressed_engine === this.engine));
    },

    /** Compress once and adapt the result for the original WordPress uploader. */
    compressBatch: function(imageFiles) {
      const self = this;
      return Promise.all(imageFiles.map(function(file) {
        return self.getUploadFile(file).then(function(blob) {
          if (blob && blob !== file && file && typeof file.getNative === 'function') {
            file._r2g_original_native = file._r2g_original_native || file.getNative();
            file.getSource = function() { return blob; };
            file.getNative = function() { return blob; };
            file.size = blob.size;
            file.name = blob.name;
            file._r2g_client_compressed = !!blob._r2g_canvas_processed;
          }
          return blob;
        });
      }));
    },

    /** Return the processed File list expected by Gutenberg's uploadMedia. */
    compressFileList: function(files) {
      const self = this;
      return Promise.all(files.map(function(file) {
        return self.getUploadFile(file);
      }));
    },

    applySettings: function(target) {
      Object.assign(target, this.uploadSettings());
      return target;
    },

    setPreviewBusy: function(isBusy) {
      this.previewInProgress = isBusy;
      $('#r2g-btn-preview-compress').prop('disabled', isBusy);
      $('#r2g-btn-modal-proceed').prop('disabled', isBusy);
      $('#r2g-btn-modal-close, #r2g-btn-modal-cancel').prop('disabled', isBusy);
      $('.r2g-format-btn, .r2g-engine-btn, .r2g-storage-btn, .r2g-quality-slider').prop('disabled', isBusy);
      $('#r2g-batch-thumbs').css('pointer-events', isBusy ? 'none' : '');
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
     * Run preview compression on the current active image file
     * - Browser Canvas: processes HTML5 Canvas locally in client memory
     * - Server GD / reSmush.it: requests server preview via AJAX to obtain exact base64 and real byte savings
     * - Lossless: shows unaltered image data
     */
    runPreviewCompression: function(nativeFile) {
      const self = this;
      const $btn = $('#r2g-btn-preview-compress');
      const $label = $btn.find('.r2g-preview-btn-text');

      self.setPreviewBusy(true);

      if (self.engine === 'browser') {
        $label.text('Compressing in browser...');
        self.compressImageCanvas(nativeFile, self.format, self.quality, self.maxWidth).then(function(newBlob) {
          self.setPreviewBusy(false);
          if (!newBlob) {
            $label.text('⚡ Preview Compression');
            return;
          }
          if (!newBlob._r2g_canvas_processed) {
            $label.text('⚡ Preview Compression');
            $('#r2g-stat-dirty-note').text('Browser could not create the selected format. Choose another engine or format.').css({ color: '#b45309' }).show();
            return;
          }

          self.previewBlob = newBlob;
          self._previewFormat = self.format;
          self._previewEngine = self.engine;
          self.hasCompressedPreview = true;

          nativeFile._r2g_compressed = newBlob;
          nativeFile._r2g_compressed_format = self.format;
          nativeFile._r2g_compressed_engine = self.engine;

          const compUrl = URL.createObjectURL(newBlob);
          $('#r2g-preview-img').attr('src', compUrl);
          $('#r2g-preview-tag').text('PREVIEW (BROWSER CANVAS: ' + self.format.toUpperCase() + ')').css({ background: '#0284c7' });

          self.updateSavingsDisplay(nativeFile.size || 1, newBlob.size || 1);
          self.adaptButtonsAfterPreview();
        }).catch(function() {
          self.setPreviewBusy(false);
          $label.text('⚡ Preview Compression');
        });
      } else if (self.engine === 'server' || self.engine === 'resmush') {
        const engTitle = (self.engine === 'resmush') ? 'reSmush.it API' : 'Server GD';
        $label.text('Optimizing with ' + engTitle + '...');

        const formData = new FormData();
        formData.append('action', 'r2g_preview_compression');
        formData.append('nonce', (self.config && self.config.nonce) ? self.config.nonce : '');
        formData.append('format', self.format);
        formData.append('quality', self.quality);
        formData.append('max_width', self.maxWidth);
        formData.append('engine', self.engine);
        formData.append('image', nativeFile);

        const ajaxUrl = (self.config && self.config.ajax_url) ? self.config.ajax_url : (window.ajaxurl || '/wp-admin/admin-ajax.php');

        $.ajax({
          url: ajaxUrl,
          type: 'POST',
          timeout: 45000,
          data: formData,
          processData: false,
          contentType: false,
          dataType: 'json',
          success: function(res) {
            if (res.success && res.data && res.data.data_url) {
              self.hasCompressedPreview = true;
              self.previewBlob = null; // Processed by PHP upon upload
              self._previewFormat = self.format;
              self._previewEngine = self.engine;
              delete nativeFile._r2g_compressed;

              $('#r2g-preview-img').attr('src', res.data.data_url);

              if (self.engine === 'resmush') {
                if (res.data.engine_status === 'success') {
                  $('#r2g-preview-tag').text('PREVIEW (RESMUSH.IT API: ' + self.format.toUpperCase() + ')').css({ background: '#7c3aed' });
                  $('#r2g-stat-dirty-note').text('✓ ' + (res.data.engine_message || 'Optimized via reSmush.it API')).css({ color: '#059669' }).show();
                } else {
                  $('#r2g-preview-tag').text('PREVIEW (SERVER GD FALLBACK: ' + self.format.toUpperCase() + ')').css({ background: '#d97706' });
                  $('#r2g-stat-dirty-note').text('⚠️ ' + (res.data.engine_message || 'reSmush fallback to Server GD')).css({ color: '#b45309' }).show();
                }
              } else {
                $('#r2g-preview-tag').text('PREVIEW (SERVER GD: ' + self.format.toUpperCase() + ')').css({ background: '#0284c7' });
              }

              self.updateSavingsDisplay(res.data.orig_size, res.data.comp_size);
              self.adaptButtonsAfterPreview();
            } else {
              alert('Optimization preview failed: ' + (res.data?.message || 'Server error'));
              $label.text('⚡ Preview Compression');
            }
          },
          error: function(xhr, status, error) {
            alert('Preview request failed: ' + error);
            $label.text('⚡ Preview Compression');
          },
          complete: function() {
            self.setPreviewBusy(false);
          }
        });
      } else if (self.engine === 'none') {
        self.setPreviewBusy(false);
        self.hasCompressedPreview = true;
        self.previewBlob = null;
        self._previewFormat = self.format;
        self._previewEngine = self.engine;
        delete nativeFile._r2g_compressed;

        if (nativeFile instanceof Blob) {
          $('#r2g-preview-img').attr('src', URL.createObjectURL(nativeFile));
        }
        $('#r2g-preview-tag').text('PREVIEW (RAW LOSSLESS)').css({ background: '#475569' });
        self.updateSavingsDisplay(nativeFile.size || 1, nativeFile.size || 1);
        self.adaptButtonsAfterPreview();
      }
    },

    updateSavingsDisplay: function(origSize, compSize) {
      const self = this;
      origSize = origSize || 1;
      compSize = compSize || 1;
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
    },

    adaptButtonsAfterPreview: function() {
      const $btn = $('#r2g-btn-preview-compress');
      $btn.prop('disabled', false);
      $btn.find('.r2g-preview-btn-text').text('✓ Re-compress');
      $btn.addClass('r2g-recompress');

      $('#r2g-btn-modal-proceed').addClass('r2g-ready-upload');
      $('#r2g-proceed-label').text('✓ Upload Now');
    },

    /**
     * Open Upload Interceptor Modal when files are added to upload
     */
    showInterceptorModal: function(uploader, files, onProceed, onCancel) {
      const self = this;
      self.ensureModalHtml();

      const $modal = $('#r2g-interceptor-modal');
      let activeIndex = 0;
      self.currentFiles = files;
      self.hasCompressedPreview = false;
      self.previewBlob = null;
      self._previewFormat = null;
      self._previewEngine = null;

      // Always reload default site settings when a new modal opens
      self.format = self.normalizeFormat(self.config.format || 'webp');
      self.quality = parseInt(self.config.quality, 10) || 82;
      self.compress = (self.config.compress !== undefined) ? parseInt(self.config.compress, 10) : 1;
      self.maxWidth = Number.isFinite(parseInt(self.config.maxWidth, 10)) ? parseInt(self.config.maxWidth, 10) : 1920;
      self.engine = self.config.engine || 'server';
      self.storageMode = self.config.storageMode || 'both';

      self.setPreviewBusy(false);

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

        $('#r2g-btn-preview-compress').prop('disabled', false).removeClass('r2g-recompress');
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
      $modal.find('.r2g-format-btn').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        self.setFormat($(this).data('format'));
      });

      // Bind engine buttons directly on modal
      $modal.find('.r2g-engine-btn').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        self.setEngine($(this).data('engine'));
      });

      // Bind storage destination buttons directly on modal
      $modal.find('.r2g-storage-btn').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        self.setStorageMode($(this).data('storage'));
      });

      // Bind quality slider directly on modal (stops Gutenberg pointer theft)
      $modal.find('.r2g-quality-slider')
        .off('pointerdown mousedown touchstart input change')
        .on('pointerdown mousedown touchstart', function(e) {
          e.stopPropagation();
        })
        .on('input change', function(e) {
          e.stopPropagation();
          self.setQuality($(this).val(), this);
        });

      // Bind batch thumbnail clicks
      $modal.find('.r2g-batch-thumb-item').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const idx = parseInt($(this).data('idx'), 10) || 0;
        updateFileDisplay(idx);
      });

      // Bind Compress Preview button
      $modal.find('#r2g-btn-preview-compress').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const currentFile = files[activeIndex];
        const nativeFile = currentFile.getNative ? currentFile.getNative() : (currentFile.getSource ? currentFile.getSource() : currentFile);
        self.runPreviewCompression(nativeFile);
      });

      const closeModal = function() {
        if (self.previewInProgress) return false;
        $modal.removeClass('r2g-modal-active');
        $(document).off('keydown.r2gmodal');
        return true;
      };

      // Close handlers: Cancel button & Close X
      $modal.find('#r2g-btn-modal-close, #r2g-btn-modal-cancel').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (self.previewInProgress) return;
        closeModal();
        if (typeof onCancel === 'function') onCancel();
      });

      // Close handler: Backdrop click outside the card
      $modal.off('click').on('click', function(e) {
        if ($(e.target).is('#r2g-interceptor-modal')) {
          if (self.previewInProgress) return;
          e.preventDefault();
          closeModal();
          if (typeof onCancel === 'function') onCancel();
        }
      });

      // Close handler: Escape key
      $(document).on('keydown.r2gmodal', function(e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
          if (self.previewInProgress) return;
          closeModal();
          if (typeof onCancel === 'function') onCancel();
        }
      });

      // Proceed handler: Start upload
      $modal.find('#r2g-btn-modal-proceed').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (self.previewInProgress) return;

        const $btn = $(this);
        $btn.prop('disabled', true);
        $('#r2g-proceed-label').text('Preparing upload...');

        const startUpload = function() {
          self.setPreviewBusy(false);
          closeModal();
          $btn.prop('disabled', false);
          if (typeof onProceed === 'function') onProceed();
        };

        if (self.engine === 'browser' && files.some(function(file) { return self.needsBrowserCompression(file); })) {
          self.setPreviewBusy(true);
          $('#r2g-proceed-label').text('Compressing in browser...');
          const isPluploadBatch = !!(uploader && files[0] && typeof files[0].getNative === 'function');
          const processing = isPluploadBatch ? self.compressBatch(files) : self.compressFileList(files);
          processing.then(function(processedFiles) {
            if (processedFiles.some(function(file) { return self.isImage(file) && !file._r2g_canvas_processed; })) {
              throw new Error('One or more images could not be processed in the browser.');
            }
            startUpload();
          }).catch(function(error) {
            self.setPreviewBusy(false);
            $('#r2g-proceed-label').text('⚡ Compress & Upload');
            $('#r2g-stat-dirty-note').text(error.message || 'Browser compression failed. Choose another engine or retry.').css({ color: '#b45309' }).show();
            $btn.removeClass('r2g-ready-upload');
          });
          return;
        }

        startUpload();
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

        // Prevent Plupload from racing ahead before user interacts with interceptor modal
        uploader.settings.autostart = false;

        const applyParams = function(up) {
          up.settings.multipart_params = up.settings.multipart_params || {};
          self.applySettings(up.settings.multipart_params);
          self.syncCookies();
        };

        uploader.bind('FilesAdded', function(up, files) {
          const imageFiles = files.filter(function(f) {
            return self.isImage(f);
          });

          // Non-image files proceed directly without modal
          if (imageFiles.length === 0) {
            applyParams(up);
            up.start();
            return;
          }

          if (self.config.interceptor === 0) {
            applyParams(up);
            if (self.engine === 'browser') {
              up.stop();
              self.compressBatch(imageFiles).then(function() {
                applyParams(up);
                up.start();
              });
            } else {
              up.start();
            }
            return;
          }

          // STOP Plupload immediately before sending bytes to server!
          try { up.stop(); } catch (e) {}
          setTimeout(function() {
            try { up.stop(); } catch (e) {}
          }, 1);

          self.showInterceptorModal(
            up,
            imageFiles,
            // onProceed:
            function() {
              applyParams(up);
              const queuedStatus = (typeof plupload !== 'undefined' && plupload.QUEUED) ? plupload.QUEUED : 1;
              files.forEach(function(f) {
                f.status = queuedStatus;
                f.percent = 0;
                f.loaded = 0;
              });

              if (self.hasCompressedPreview && self.previewBlob && imageFiles.length === 1 && self.engine === 'browser') {
                // Single image pre-compressed in browser
                const f = imageFiles[0];
                f.getSource = function() { return self.previewBlob; };
                f.getNative = function() { return self.previewBlob; };
                f.size = self.previewBlob.size;
                f.name = self.previewBlob.name;
                f._r2g_client_compressed = !!self.previewBlob._r2g_canvas_processed;
                applyParams(up);
                try { up.trigger('QueueChanged'); up.refresh(); } catch (e) {}
                up.start();
              } else if (self.engine === 'browser') {
                self.compressBatch(imageFiles).then(function() {
                  applyParams(up);
                  files.forEach(function(f) {
                    f.status = queuedStatus;
                  });
                  try { up.trigger('QueueChanged'); up.refresh(); } catch (e) {}
                  up.start();
                });
              } else {
                delete up.settings.multipart_params['r2g_client_compressed'];
                applyParams(up);
                try { up.trigger('QueueChanged'); up.refresh(); } catch (e) {}
                up.start();
              }
            },
            // onCancel:
            function() {
              files.forEach(function(f) {
                try { up.removeFile(f); } catch (e) {}
              });
              try { up.trigger('QueueChanged'); up.refresh(); } catch (e) {}
            }
          );
        });

        uploader.bind('BeforeUpload', function(up, file) {
          applyParams(up);
          if (self.engine === 'browser' && file._r2g_client_compressed) {
            up.settings.multipart_params['r2g_client_compressed'] = 1;
          } else {
            delete up.settings.multipart_params['r2g_client_compressed'];
          }
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
          const uploadSettings = self.uploadSettings();
          Object.keys(uploadSettings).forEach(function(key) {
            xhr.setRequestHeader(self.uploadHeaderName(key), uploadSettings[key]);
          });
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
            const pathOrUrl = options ? (options.path || options.url || '') : '';
            if (pathOrUrl.indexOf('/wp/v2/media') !== -1) {
              const setHeader = function(name, val) {
                if (!options.headers) {
                  options.headers = {};
                }
                if (typeof options.headers.set === 'function') {
                  options.headers.set(name, val);
                } else {
                  options.headers[name] = val;
                }
              };
              const settings = self.uploadSettings();
              Object.keys(settings).forEach(function(key) {
                setHeader(self.uploadHeaderName(key), String(settings[key]));
              });
              if (self._clientCompressed) {
                setHeader('X-R2G-Client-Compressed', '1');
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

        const wrappedUploadMedia = function(options) {
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
            options.additionalData = options.additionalData || {};
            self.applySettings(options.additionalData);

            if (self.engine === 'browser') {
              return self.compressFileList(files).then(function(newFiles) {
                options.filesList = newFiles;
                options.additionalData['r2g_client_compressed'] = newFiles.every(function(file) { return !self.isImage(file) || !!file._r2g_canvas_processed; }) ? 1 : 0;
                self._clientCompressed = !!options.additionalData['r2g_client_compressed'];
                const res = originalUploadMedia.call(wp.mediaUtils, options);
                if (res && typeof res.finally === 'function') {
                  res.finally(function() { self._clientCompressed = false; });
                } else {
                  setTimeout(function() { self._clientCompressed = false; }, 5000);
                }
                return res;
              });
            }
            return originalUploadMedia.call(wp.mediaUtils, options);
          }

          // MUST return a Promise so Gutenberg promise chains never break or hang!
          return new Promise(function(resolve, reject) {
            self.showInterceptorModal(
              null,
              imageFiles,
              // onProceed:
              function() {
                self.syncCookies();
                options.additionalData = options.additionalData || {};
                self.applySettings(options.additionalData);

                if (self.hasCompressedPreview && self.previewBlob && imageFiles.length === 1 && self.engine === 'browser') {
                  // Pre-compressed in browser preview
                  options.filesList = [self.previewBlob];
                  options.additionalData['r2g_client_compressed'] = self.previewBlob._r2g_canvas_processed ? 1 : 0;
                  self._clientCompressed = !!options.additionalData['r2g_client_compressed'];
                  const res = originalUploadMedia.call(wp.mediaUtils, options);
                  if (res && typeof res.then === 'function') {
                    res.then(resolve).catch(reject).finally(function() {
                      self._clientCompressed = false;
                    });
                  } else {
                    resolve(res);
                    setTimeout(function() { self._clientCompressed = false; }, 5000);
                  }
                } else if (self.engine === 'browser') {
                  self.compressFileList(files).then(function(newFiles) {
                    options.filesList = newFiles;
                    options.additionalData['r2g_client_compressed'] = newFiles.every(function(file) { return !self.isImage(file) || !!file._r2g_canvas_processed; }) ? 1 : 0;
                    self._clientCompressed = !!options.additionalData['r2g_client_compressed'];
                    const res = originalUploadMedia.call(wp.mediaUtils, options);
                    if (res && typeof res.then === 'function') {
                      res.then(resolve).catch(reject).finally(function() {
                        self._clientCompressed = false;
                      });
                    } else {
                      resolve(res);
                      setTimeout(function() { self._clientCompressed = false; }, 5000);
                    }
                  }).catch(function(err) {
                    console.warn('[R2G] Browser compression fallback in Gutenberg:', err);
                    delete options.additionalData['r2g_client_compressed'];
                    self._clientCompressed = false;
                    const res = originalUploadMedia.call(wp.mediaUtils, options);
                    if (res && typeof res.then === 'function') {
                      res.then(resolve).catch(reject);
                    } else {
                      resolve(res);
                    }
                  });
                } else {
                  // Server GD, reSmush.it, or Raw Lossless
                  delete options.additionalData['r2g_client_compressed'];
                  self._clientCompressed = false;
                  const res = originalUploadMedia.call(wp.mediaUtils, options);
                  if (res && typeof res.then === 'function') {
                    res.then(resolve).catch(reject);
                  } else {
                    resolve(res);
                  }
                }
              },
              // onCancel:
              function() {
                if (typeof options.onError === 'function') {
                  options.onError(new Error('Upload cancelled.'));
                }
                reject(new Error('Upload cancelled.'));
              }
            );
          });
        };

        wp.mediaUtils.uploadMedia = wrappedUploadMedia;

        // Synchronize wrapped function with core/block-editor and core/editor settings
        const syncEditorStores = function() {
          if (typeof window.wp === 'undefined' || !wp.data || !wp.data.dispatch) return;
          try {
            const be = wp.data.dispatch('core/block-editor');
            if (be && typeof be.updateSettings === 'function') {
              be.updateSettings({ mediaUpload: wrappedUploadMedia });
            }
          } catch (e) {}
          try {
            const ed = wp.data.dispatch('core/editor');
            if (ed) {
              if (typeof ed.updateEditorSettings === 'function') {
                ed.updateEditorSettings({ mediaUpload: wrappedUploadMedia });
              } else if (typeof ed.updateSettings === 'function') {
                ed.updateSettings({ mediaUpload: wrappedUploadMedia });
              }
            }
          } catch (e) {}
        };

        syncEditorStores();

        // Subscribe to store updates to keep mediaUpload wrapped if Gutenberg resets settings
        if (typeof window.wp !== 'undefined' && wp.data && wp.data.subscribe) {
          wp.data.subscribe(function() {
            try {
              let needsUpdate = false;
              if (wp.data.select && wp.data.select('core/block-editor')) {
                const bs = wp.data.select('core/block-editor').getSettings();
                if (bs && bs.mediaUpload && bs.mediaUpload !== wrappedUploadMedia) {
                  needsUpdate = true;
                }
              }
              if (wp.data.select && wp.data.select('core/editor')) {
                const es = wp.data.select('core/editor').getEditorSettings();
                if (es && es.mediaUpload && es.mediaUpload !== wrappedUploadMedia) {
                  needsUpdate = true;
                }
              }
              if (needsUpdate) {
                syncEditorStores();
              }
            } catch (e) {}
          });
        }

        return true;
      };

      if (!wrapGutenberg()) {
        const gTimer = setInterval(function() {
          if (wrapGutenberg()) clearInterval(gTimer);
        }, 150);
        setTimeout(function() { clearInterval(gTimer); }, 20000);
      }
    },

    /**
     * Intercept browser single-file uploader form (media-new.php?browser-uploader)
     * Handles both file selection and submit clicks with native capture-phase listeners
     */
    hookBrowserForm: function() {
      const self = this;

      const handleFileInput = function(input) {
        if (!input || !input.files || input.files.length === 0) return;
        if (input._r2g_processing) return;

        const files = Array.from(input.files);
        const imageFiles = files.filter(function(f) {
          return self.isImage(f);
        });
        if (imageFiles.length === 0 || self.config.interceptor === 0) return;

        const form = input.form || $(input).closest('form')[0];
        input._r2g_processing = true;

        self.showInterceptorModal(
          null,
          imageFiles,
          function() {
            self.syncCookies();
            input._r2g_confirmed = true;
            input._r2g_processing = false;

            const submitForm = function() {
              if (!form) return;

              // Ensure html-upload hidden field is present so WordPress media-new.php processes the upload!
              let htmlUploadInput = form.querySelector('input[type="hidden"][name="html-upload"]');
              if (!htmlUploadInput) {
                htmlUploadInput = document.createElement('input');
                htmlUploadInput.type = 'hidden';
                htmlUploadInput.name = 'html-upload';
                htmlUploadInput.value = 'Upload';
                form.appendChild(htmlUploadInput);
              }

              const fields = self.uploadSettings();

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

              let clientInp = form.querySelector('input[name="r2g_client_compressed"]');
              if (self.engine === 'browser' && input._r2g_client_compressed) {
                if (!clientInp) {
                  clientInp = document.createElement('input');
                  clientInp.type = 'hidden';
                  clientInp.name = 'r2g_client_compressed';
                  form.appendChild(clientInp);
                }
                clientInp.value = '1';
              } else if (clientInp) {
                clientInp.remove();
              }

              const submitBtn = form.querySelector('input[type="submit"][name="html-upload"]');
              if (submitBtn) {
                submitBtn.click();
              } else {
                form.submit();
              }
            };

            // If compressed in browser, replace input.files via DataTransfer
            if (self.hasCompressedPreview && self.previewBlob && self.engine === 'browser' && window.DataTransfer) {
              try {
                const dt = new DataTransfer();
                dt.items.add(self.previewBlob);
                input.files = dt.files;
                input._r2g_client_compressed = input.files.length === 1 && input.files[0].name === self.previewBlob.name && !!self.previewBlob._r2g_canvas_processed;
              } catch (e) {
                input._r2g_client_compressed = false;
              }
              submitForm();
            } else if (self.engine === 'browser' && window.DataTransfer) {
              self.getUploadFile(input.files[0]).then(function(newBlob) {
                try {
                  const dt = new DataTransfer();
                  dt.items.add(newBlob);
                  input.files = dt.files;
                  input._r2g_client_compressed = input.files.length === 1 && input.files[0].name === newBlob.name && !!newBlob._r2g_canvas_processed;
                } catch (e) {
                  input._r2g_client_compressed = false;
                }
                submitForm();
              }).catch(function() {
                input._r2g_client_compressed = false;
                submitForm();
              });
            } else {
              input._r2g_client_compressed = false;
              submitForm();
            }
          },
          function() {
            input.value = '';
            input._r2g_confirmed = false;
            input._r2g_processing = false;
          }
        );
      };

      // Native capture-phase listener for change on file inputs
      document.addEventListener('change', function(e) {
        const target = e.target;
        if (target && target.tagName === 'INPUT' && target.type === 'file') {
          if (target.form && target.form.id === 'file-form') {
            handleFileInput(target);
          }
        }
      }, true);

      // Intercept submit button click on browser upload form
      $(document).on('click', '#html-upload, input[type="submit"][name="html-upload"]', function(e) {
        const form = this.form || $(this).closest('form')[0];
        if (!form) return;
        const fileInput = form.querySelector('input[type="file"]');
        if (fileInput && fileInput.files && fileInput.files.length > 0 && !fileInput._r2g_confirmed && self.config.interceptor !== 0) {
          const imageFiles = Array.from(fileInput.files).filter(function(f) {
            return self.isImage(f);
          });
          if (imageFiles.length > 0) {
            e.preventDefault();
            e.stopPropagation();
            handleFileInput(fileInput);
          }
        }
      });

      // Submit listener fallback
      document.addEventListener('submit', function(e) {
        const form = e.target;
        if (form && form.id === 'file-form') {
          const fileInput = form.querySelector('input[type="file"]');
          if (fileInput && fileInput.files && fileInput.files.length > 0 && !fileInput._r2g_confirmed && self.config.interceptor !== 0) {
            const imageFiles = Array.from(fileInput.files).filter(function(f) {
              return self.isImage(f);
            });
            if (imageFiles.length > 0) {
              e.preventDefault();
              e.stopPropagation();
              handleFileInput(fileInput);
            }
          }
        }
      }, true);
    }
  };

  $(document).ready(function() {
    R2G_UploadManager.init();
  });

})(jQuery);
