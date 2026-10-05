/**
 * R2 by Grisma — Universal Upload-Time Control & In-Browser Image Optimizer
 *
 * Sensible, streamlined image management:
 * 1. Target Format: WebP, JPG, PNG, Original (NO conflicting redundant presets!)
 * 2. Quality Slider: Real-time smooth touch/mouse slider with live % badge
 * 3. Processing Engine: Browser Canvas (0 server CPU), Server (GD/Imagick), reSmush.it, Lossless
 * 4. Storage Destination: Dual (WP + R2), Cloud Only (R2), Local Only (WP)
 * 5. Visual Interceptor Modal: Only shown when files are uploaded in interceptor mode; dismisses instantly on Cancel, X, ESC, or backdrop click
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
      workflow: 'bar',
      format: 'webp',
      compress: 1,
      quality: 82,
      maxWidth: 1920,
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
      const userCustomized = sessionStorage.getItem('r2g_session_customized') === 'true';

      // 1. Initial State Resolution from session or site config
      if (userCustomized && this.getCookie('r2g_format')) {
        this.format = this.getCookie('r2g_format');
        this.quality = parseInt(this.getCookie('r2g_quality'), 10) || 82;
        this.compress = this.getCookie('r2g_compress') !== null ? parseInt(this.getCookie('r2g_compress'), 10) : 1;
        this.maxWidth = parseInt(this.getCookie('r2g_max_width'), 10) || 1920;
        this.engine = this.getCookie('r2g_engine') || this.config.engine || 'server';
        this.storageMode = this.getCookie('r2g_storage_mode') || this.config.storageMode || 'both';
      } else {
        this.format = this.config.format || 'webp';
        this.quality = parseInt(this.config.quality, 10) || 82;
        this.compress = (this.config.compress !== undefined) ? parseInt(this.config.compress, 10) : 1;
        this.maxWidth = parseInt(this.config.maxWidth, 10) || 1920;
        this.engine = this.config.engine || 'server';
        this.storageMode = this.config.storageMode || 'both';
      }

      this.syncCookies();

      // 2. Setup Dropzone Toolbar & Listeners
      this.startDomWatcher();
      this.hookPlupload();
      this.hookGutenberg();
      this.hookBrowserForm();
      this.bindControlEvents();
    },

    syncCookies: function() {
      this.setCookie('r2g_format', this.format);
      this.setCookie('r2g_quality', this.quality);
      this.setCookie('r2g_compress', this.compress);
      this.setCookie('r2g_max_width', this.maxWidth);
      this.setCookie('r2g_engine', this.engine);
      this.setCookie('r2g_storage_mode', this.storageMode);
    },

    setFormat: function(newFormat) {
      this.format = newFormat;
      sessionStorage.setItem('r2g_session_customized', 'true');
      this.syncAllControls();
    },

    setQuality: function(newQuality, sourceEl) {
      this.quality = parseInt(newQuality, 10);
      sessionStorage.setItem('r2g_session_customized', 'true');

      // Live numeric badge update without re-rendering slider or resetting mobile touch tracking
      $('.r2g-quality-badge, .r2g-quality-val').text(this.quality + '%');

      // Sync other sliders without touching sourceEl
      if (sourceEl) {
        $('.r2g-quality-slider').not(sourceEl).val(this.quality);
      } else {
        $('.r2g-quality-slider').val(this.quality);
      }

      this.syncCookies();
      this.updateUploaderParams();
    },

    setEngine: function(newEngine) {
      this.engine = newEngine;
      sessionStorage.setItem('r2g_session_customized', 'true');
      this.syncAllControls();
    },

    setStorageMode: function(newMode) {
      this.storageMode = newMode;
      sessionStorage.setItem('r2g_session_customized', 'true');
      this.syncAllControls();
    },

    syncAllControls: function() {
      this.syncCookies();

      // 1. Format buttons
      $('.r2g-format-btn').removeClass('r2g-btn-active');
      $('.r2g-format-btn[data-format="' + this.format + '"]').addClass('r2g-btn-active');

      // 2. Quality slider and live badges
      $('.r2g-quality-slider').val(this.quality);
      $('.r2g-quality-badge, .r2g-quality-val').text(this.quality + '%');

      if (this.engine === 'none' || (this.format === 'original' && this.compress === 0)) {
        $('.r2g-quality-wrap').hide();
      } else {
        $('.r2g-quality-wrap').show();
      }

      // 3. Engine controls
      $('.r2g-engine-btn').removeClass('r2g-btn-active');
      $('.r2g-engine-btn[data-engine="' + this.engine + '"]').addClass('r2g-btn-active');
      $('select.r2g-control-engine').val(this.engine);

      let engineLabel = 'Server: GD/Imagick';
      if (this.engine === 'browser') engineLabel = 'Browser Edge (Canvas)';
      else if (this.engine === 'resmush') engineLabel = 'reSmush.it API';
      else if (this.engine === 'none') engineLabel = 'Lossless Offload';
      $('.r2g-engine-badge').text(engineLabel);

      // 4. Storage controls
      $('.r2g-storage-btn').removeClass('r2g-btn-active');
      $('.r2g-storage-btn[data-storage="' + this.storageMode + '"]').addClass('r2g-btn-active');
      $('select.r2g-control-storage').val(this.storageMode);

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

    /**
     * Client-side HTML5 Canvas Image Compression and WebP Conversion
     * Compresses directly in browser memory before sending over the wire.
     */
    compressImageCanvas: function(file, targetFormat, quality, maxWidth) {
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

          let mimeType = 'image/webp';
          if (targetFormat === 'jpg' || targetFormat === 'jpeg') {
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
     * Batch compress multiple files with canvas
     */
    compressBatch: function(up, imageFiles) {
      const self = this;
      const promises = imageFiles.map(function(f) {
        return self.compressImageCanvas(f, self.format, self.quality, self.maxWidth).then(function(newBlob) {
          if (newBlob && newBlob !== f) {
            f.getSource = function() { return newBlob; };
            f.getNative = function() { return newBlob; };
            f.size = newBlob.size;
            f.name = newBlob.name;
            up.settings.multipart_params = up.settings.multipart_params || {};
            up.settings.multipart_params['r2g_client_compressed'] = 1;
          }
        });
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
              <!-- File Preview & Metadata Box -->
              <div class="r2g-preview-box">
                <div class="r2g-preview-thumb-wrap">
                  <img id="r2g-preview-img" src="" alt="Upload Preview" />
                </div>
                <div class="r2g-preview-details">
                  <div class="r2g-preview-filename" id="r2g-preview-name">image.jpg</div>
                  <div class="r2g-preview-badges">
                    <span class="r2g-chip" id="r2g-preview-size">0 KB</span>
                    <span class="r2g-chip" id="r2g-preview-dims">-- &times; -- px</span>
                    <span class="r2g-chip r2g-chip-type" id="r2g-preview-type">IMAGE</span>
                  </div>
                  <p class="r2g-preview-notice">
                    Held in browser memory before sending. Zero server bandwidth consumed yet.
                  </p>
                </div>
              </div>

              <!-- Streamlined Settings Controls (Zero Presets / Zero Conflicts) -->
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

              <!-- Remember for session checkbox -->
              <div class="r2g-session-remember-wrap">
                <label class="r2g-remember-label">
                  <input type="checkbox" id="r2g-remember-session" />
                  <span>Remember choice for this session (auto-upload without asking again)</span>
                </label>
              </div>
            </div>

            <div class="r2g-modal-footer">
              <button type="button" class="button r2g-btn-modal-cancel" id="r2g-btn-modal-cancel">Cancel Upload</button>
              <button type="button" class="button button-primary button-large r2g-btn-modal-proceed" id="r2g-btn-modal-proceed">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px; margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>
                <span id="r2g-proceed-label">Upload & Offload to R2</span>
              </button>
            </div>
          </div>
        </div>
      `;

      $('body').append(modalHtml);
    },

    /**
     * DOM Watcher: Injects EXACTLY ONE clean, responsive dropzone toolbar
     */
    startDomWatcher: function() {
      const self = this;

      const checkAndInject = function() {
        if (self.config.workflow === 'automatic') return;

        // If toolbar already exists in DOM, do NOT inject duplicate
        if ($('#r2g-upload-toolbar').length) {
          return;
        }

        const toolbarHtml = `
          <div id="r2g-upload-toolbar" class="r2g-upload-toolbar">
            <div class="r2g-toolbar-header">
              <div class="r2g-toolbar-title">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                <span>Cloudflare R2 Optimization</span>
              </div>
              <span class="r2g-engine-badge">Server: GD/Imagick</span>
            </div>
            <div class="r2g-toolbar-content">
              <div class="r2g-toolbar-col r2g-col-format">
                <label class="r2g-bar-label">Target Format:</label>
                <div class="r2g-format-group">
                  <button type="button" class="r2g-format-btn ${self.format === 'webp' ? 'r2g-btn-active' : ''}" data-format="webp">WebP</button>
                  <button type="button" class="r2g-format-btn ${self.format === 'jpg' ? 'r2g-btn-active' : ''}" data-format="jpg">JPG</button>
                  <button type="button" class="r2g-format-btn ${self.format === 'png' ? 'r2g-btn-active' : ''}" data-format="png">PNG</button>
                  <button type="button" class="r2g-format-btn ${self.format === 'original' ? 'r2g-btn-active' : ''}" data-format="original">Original</button>
                </div>
              </div>
              <div class="r2g-toolbar-col r2g-col-quality r2g-quality-wrap" style="${self.engine === 'none' ? 'display:none;' : ''}">
                <label class="r2g-bar-label">Quality:</label>
                <div class="r2g-slider-box">
                  <input type="range" class="r2g-quality-slider" min="50" max="100" value="${self.quality}">
                  <span class="r2g-quality-badge r2g-quality-val">${self.quality}%</span>
                </div>
              </div>
              <div class="r2g-toolbar-col r2g-col-engine">
                <label class="r2g-bar-label">Engine:</label>
                <div class="r2g-btn-toggle-group">
                  <button type="button" class="r2g-engine-btn ${self.engine === 'browser' ? 'r2g-btn-active' : ''}" data-engine="browser" title="Compress in browser canvas (0 server CPU)">Browser Canvas</button>
                  <button type="button" class="r2g-engine-btn ${self.engine === 'server' ? 'r2g-btn-active' : ''}" data-engine="server" title="Fast native server GD/Imagick">Server GD</button>
                  <button type="button" class="r2g-engine-btn ${self.engine === 'resmush' ? 'r2g-btn-active' : ''}" data-engine="resmush" title="reSmush.it API with fallback">reSmush.it</button>
                  <button type="button" class="r2g-engine-btn ${self.engine === 'none' ? 'r2g-btn-active' : ''}" data-engine="none" title="Raw lossless offload">Lossless</button>
                </div>
              </div>
              <div class="r2g-toolbar-col r2g-col-storage">
                <label class="r2g-bar-label">Storage:</label>
                <div class="r2g-btn-toggle-group">
                  <button type="button" class="r2g-storage-btn ${self.storageMode === 'both' ? 'r2g-btn-active' : ''}" data-storage="both">Dual (WP + R2)</button>
                  <button type="button" class="r2g-storage-btn ${self.storageMode === 'r2_only' ? 'r2g-btn-active' : ''}" data-storage="r2_only">R2 Only</button>
                  <button type="button" class="r2g-storage-btn ${self.storageMode === 'local_only' ? 'r2g-btn-active' : ''}" data-storage="local_only">WP Only</button>
                </div>
              </div>
            </div>
          </div>
        `;

        // 1. In Media Modal: inject inside .uploader-inline .upload-ui above .upload-instructions
        const $uploadUi = $('.media-modal .uploader-inline .upload-ui, .uploader-inline .upload-ui').first();
        if ($uploadUi.length) {
          $uploadUi.find('.upload-instructions').first().before(toolbarHtml);
          self.syncAllControls();
          return;
        }

        // 2. Standalone upload screens (media-new.php or upload.php)
        const $standaloneTarget = $('#drag-drop-area, #async-upload-wrap, .upload-php #wpbody-content .wrap, .media-new-php #wpbody-content .wrap').first();
        if ($standaloneTarget.length) {
          if ($('#drag-drop-area').length) {
            $('#drag-drop-area').before(toolbarHtml);
          } else {
            $standaloneTarget.find('h1').first().after(toolbarHtml);
          }
          self.syncAllControls();
        }
      };

      $(document).ready(checkAndInject);
      $(document).on('uploaderReady', checkAndInject);
      setInterval(checkAndInject, 800);
    },

    /**
     * Bind 2-way event syncing for all controls
     */
    bindControlEvents: function() {
      const self = this;

      // Quick Format buttons
      $(document).on('click', '.r2g-format-btn', function(e) {
        e.preventDefault();
        self.setFormat($(this).data('format'));
      });

      // Engine buttons
      $(document).on('click', '.r2g-engine-btn', function(e) {
        e.preventDefault();
        self.setEngine($(this).data('engine'));
      });

      // Storage buttons
      $(document).on('click', '.r2g-storage-btn', function(e) {
        e.preventDefault();
        self.setStorageMode($(this).data('storage'));
      });

      // Quality sliders: live smooth input event on desktop & mobile touch
      $(document).on('input', '.r2g-quality-slider', function() {
        self.setQuality($(this).val(), this);
      });

      $(document).on('change', '.r2g-quality-slider', function() {
        self.syncCookies();
      });
    },

    /**
     * Open Upload Interceptor Modal ONLY when files are actually added
     */
    showInterceptorModal: function(uploader, files, onProceed, onCancel) {
      const self = this;
      self.ensureModalHtml();

      const $modal = $('#r2g-interceptor-modal');
      const firstFile = files[0];
      const nativeFile = firstFile.getNative ? firstFile.getNative() : (firstFile.getSource ? firstFile.getSource() : firstFile);

      // Populate file details
      const fileName = nativeFile.name || firstFile.name || 'image.jpg';
      const fileSize = nativeFile.size || firstFile.size || 0;
      const fileType = (nativeFile.type || firstFile.type || '').replace('image/', '').toUpperCase() || 'IMAGE';

      $('#r2g-preview-name').text(fileName);
      $('#r2g-preview-size').text(self.formatBytes(fileSize));
      $('#r2g-preview-type').text(fileType);
      $('#r2g-preview-dims').text('-- \u00d7 -- px');

      let objectUrl = '';
      if (nativeFile instanceof Blob) {
        objectUrl = URL.createObjectURL(nativeFile);
        $('#r2g-preview-img').attr('src', objectUrl);

        const img = new Image();
        img.onload = function() {
          $('#r2g-preview-dims').text(img.naturalWidth + ' \u00d7 ' + img.naturalHeight + ' px');
        };
        img.src = objectUrl;
      } else {
        $('#r2g-preview-img').attr('src', '');
      }

      self.syncAllControls();

      // Clean previous bindings
      $('#r2g-btn-modal-close, #r2g-btn-modal-cancel').off('click');
      $('#r2g-btn-modal-proceed').off('click');
      $modal.off('click');
      $(document).off('keydown.r2gmodal');

      const closeModal = function() {
        if (objectUrl) {
          URL.revokeObjectURL(objectUrl);
        }
        $modal.removeClass('r2g-modal-active');
        $(document).off('keydown.r2gmodal');
      };

      // Close handlers: Cancel button or Close X button
      $('#r2g-btn-modal-close, #r2g-btn-modal-cancel').on('click', function(e) {
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
      $('#r2g-btn-modal-proceed').on('click', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const remember = $('#r2g-remember-session').is(':checked');
        if (remember) {
          sessionStorage.setItem('r2g_session_auto_upload', 'true');
        }

        $btn.prop('disabled', true);
        $('#r2g-proceed-label').text('Preparing upload...');

        closeModal();
        $btn.prop('disabled', false);
        $('#r2g-proceed-label').text('Upload & Offload to R2');

        if (typeof onProceed === 'function') onProceed();
      });

      // Make modal visible
      $modal.addClass('r2g-modal-active');
    },

    /**
     * Intercept standard WordPress Media Uploader (Plupload)
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

          // Non-image files proceed directly without interceptor
          if (imageFiles.length === 0) {
            applyParams(up);
            return;
          }

          const isSessionAuto = sessionStorage.getItem('r2g_session_auto_upload') === 'true';
          const workflow = self.config.workflow || 'bar';

          // If workflow is 'bar' (inline dropzone toolbar) or 'automatic' or user checked remember session:
          if (workflow === 'bar' || workflow === 'automatic' || isSessionAuto) {
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

          // Workflow is 'interceptor': Stop uploader and show confirmation dialog
          up.stop();

          self.showInterceptorModal(
            up,
            imageFiles,
            // onProceed:
            function() {
              applyParams(up);
              if (self.engine === 'browser') {
                self.compressBatch(up, imageFiles).then(function() {
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
     */
    hookGutenberg: function() {
      const self = this;

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
            }
            return next(options);
          });
        }
      }
    },

    /**
     * Intercept browser built-in single file uploader form
     */
    hookBrowserForm: function() {
      const self = this;
      $(document).on('submit', '#file-form', function(e) {
        const form = this;
        self.syncCookies();

        const fields = {
          'r2g_format': self.format,
          'r2g_compress': (self.engine === 'none') ? 0 : self.compress,
          'r2g_quality': self.quality,
          'r2g_max_width': self.maxWidth,
          'r2g_storage_mode': self.storageMode,
          'r2g_engine': self.engine,
        };

        for (const [k, v] of Object.entries(fields)) {
          let input = form.querySelector('input[name="' + k + '"]');
          if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = k;
            form.appendChild(input);
          }
          input.value = v;
        }
      });
    }
  };

  $(document).ready(function() {
    R2G_UploadManager.init();
  });

})(jQuery);
