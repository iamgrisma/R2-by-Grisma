/**
 * R2 by Grisma — Upload Interceptor & Settings Manager
 * Version: 1.0.22
 *
 * Core Principles:
 * 1. ZERO browser canvas freezing: No client-side image manipulation, no blob alteration,
 *    and no DataTransfer swapping that hangs WordPress Plupload or Gutenberg at 0%/3%.
 * 2. Rock-solid Native Upload Flow: When Visual Interceptor is disabled (default), uploads flow
 *    100% natively through WordPress. Preferences (format, quality, engine, storage mode) are
 *    seamlessly conveyed to PHP via cookies and request headers.
 * 3. Primary Engine: reSmush.it cloud optimization with automatic, seamless fallback to Server GD/Imagick.
 * 4. Optional Visual Interceptor Modal: When enabled by the user in settings, pauses uploads
 *    cleanly before sending bytes, offers real server-side preview via AJAX, and proceeds without
 *    tampering with file streams.
 *
 * @package R2_By_Grisma
 */
(function($) {
  'use strict';

  if (typeof window === 'undefined') return;

  const R2G_UploadManager = {
    config: window.r2g_compress_config || {
      engine: 'resmush',
      storageMode: 'both',
      interceptor: 0,
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

    normalizeEngine: function(eng) {
      eng = (eng || 'resmush').toLowerCase();
      if (eng === 'browser' || eng === '') eng = 'resmush';
      if (eng !== 'resmush' && eng !== 'server' && eng !== 'none') eng = 'resmush';
      return eng;
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
      if (name.match(/\.(jpe?g|png|webp|gif|bmp|tiff?|avif)$/i)) return true;
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
      this.format = this.normalizeFormat(this.config.format || 'webp');
      this.quality = parseInt(this.config.quality, 10) || 82;
      this.compress = (this.config.compress !== undefined) ? parseInt(this.config.compress, 10) : 1;
      this.maxWidth = Number.isFinite(parseInt(this.config.maxWidth, 10)) ? parseInt(this.config.maxWidth, 10) : 1920;
      this.engine = this.normalizeEngine(this.config.engine || 'resmush');
      this.storageMode = this.config.storageMode || 'both';

      this.syncCookies();
      this.bindNetworkHeaders();

      // Only hook upload transport interception if user explicitly enabled visual interceptor
      if (parseInt(this.config.interceptor, 10) === 1) {
        this.hookPlupload();
        this.hookGutenberg();
        this.hookBrowserForm();
      }
    },

    syncCookies: function() {
      this.setCookie('r2g_format', this.format);
      this.setCookie('r2g_quality', this.quality);
      this.setCookie('r2g_compress', (this.engine === 'none') ? 0 : this.compress);
      this.setCookie('r2g_max_width', this.maxWidth);
      this.setCookie('r2g_engine', this.engine);
      this.setCookie('r2g_storage_mode', this.storageMode);
    },

    bindNetworkHeaders: function() {
      const self = this;

      // 1. Hook jQuery AJAX for async-upload.php (used by standard Plupload)
      $(document).ajaxSend(function(event, xhr, settings) {
        if (settings && settings.url && settings.url.indexOf('async-upload.php') !== -1) {
          const uploadSettings = self.uploadSettings();
          Object.keys(uploadSettings).forEach(function(key) {
            xhr.setRequestHeader(self.uploadHeaderName(key), uploadSettings[key]);
          });
        }
      });

      // 2. Hook wp.apiFetch for Gutenberg block editor REST API uploads
      if (typeof window.wp !== 'undefined' && wp.apiFetch && wp.apiFetch.use) {
        if (!wp.apiFetch._r2g_header_hooked) {
          wp.apiFetch._r2g_header_hooked = true;
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
            }
            return next(options);
          });
        }
      }
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
      this.engine = this.normalizeEngine(newEngine);
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
      const $previewBtn = $('#r2g-btn-preview-compress');
      $previewBtn.prop('disabled', false).removeClass('r2g-recompress');
      $previewBtn.find('.r2g-preview-btn-text').text('⚡ Preview Compression');

      if ($('#r2g-compress-result').is(':visible')) {
        $('#r2g-stat-dirty-note').text('(Settings modified — click Preview to re-test, or proceed with new settings)').show();
      }

      const $proceedBtn = $('#r2g-btn-modal-proceed');
      $proceedBtn.removeClass('r2g-ready-upload');
      $('#r2g-proceed-label').text('Upload & Offload to R2');
    },

    setPreviewBusy: function(isBusy) {
      this.previewInProgress = isBusy;
      $('#r2g-btn-preview-compress').prop('disabled', isBusy);
      $('#r2g-btn-modal-proceed').prop('disabled', isBusy);
      $('#r2g-btn-modal-close, #r2g-btn-modal-cancel').prop('disabled', isBusy);
      $('.r2g-format-btn, .r2g-engine-btn, .r2g-storage-btn, .r2g-quality-slider').prop('disabled', isBusy);
      $('#r2g-batch-thumbs').css('pointer-events', isBusy ? 'none' : '');
    },

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
                      Processed securely on the server with reSmush.it API & GD/Imagick fallback.
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
                      <button type="button" class="r2g-engine-btn" data-engine="resmush" title="reSmush.it API with automatic GD/Imagick fallback">reSmush.it (Recommended)</button>
                      <button type="button" class="r2g-engine-btn" data-engine="server" title="Fast native server GD/Imagick">Server GD</button>
                      <button type="button" class="r2g-engine-btn" data-engine="none" title="Raw lossless offload without alteration">Lossless</button>
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
                <span id="r2g-proceed-label">Upload & Offload to R2</span>
              </button>
            </div>
          </div>
        </div>
      `;

      $('body').append(modalHtml);
    },

    runPreviewCompression: function(nativeFile) {
      const self = this;
      const $btn = $('#r2g-btn-preview-compress');
      const $label = $btn.find('.r2g-preview-btn-text');

      self.setPreviewBusy(true);

      if (self.engine === 'none') {
        self.setPreviewBusy(false);
        self.hasCompressedPreview = true;
        if (nativeFile instanceof Blob) {
          $('#r2g-preview-img').attr('src', URL.createObjectURL(nativeFile));
        }
        $('#r2g-preview-tag').text('PREVIEW (RAW LOSSLESS)').css({ background: '#475569' });
        self.updateSavingsDisplay(nativeFile.size || 1, nativeFile.size || 1);
        self.adaptButtonsAfterPreview();
        return;
      }

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
        timeout: 90000,
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
          if (res.success && res.data && res.data.data_url) {
            self.hasCompressedPreview = true;
            $('#r2g-preview-img').attr('src', res.data.data_url);

            if (res.data.engine_status === 'fallback') {
              $('#r2g-preview-tag').text('PREVIEW (SERVER GD FALLBACK: ' + self.format.toUpperCase() + ')').css({ background: '#d97706' });
              $('#r2g-stat-dirty-note').text('⚠️ ' + (res.data.engine_message || 'Fallback to Server GD')).css({ color: '#b45309' }).show();
            } else if (res.data.engine_used === 'resmush') {
              $('#r2g-preview-tag').text('PREVIEW (RESMUSH.IT API: ' + self.format.toUpperCase() + ')').css({ background: '#7c3aed' });
              $('#r2g-stat-dirty-note').text('✓ ' + (res.data.engine_message || 'Optimized via reSmush.it')).css({ color: '#059669' }).show();
            } else {
              $('#r2g-preview-tag').text('PREVIEW (SERVER GD: ' + self.format.toUpperCase() + ')').css({ background: '#0284c7' });
              $('#r2g-stat-dirty-note').text('✓ ' + (res.data.engine_message || 'Optimized via Server GD')).css({ color: '#059669' }).show();
            }

            self.updateSavingsDisplay(res.data.orig_size, res.data.comp_size);
            self.adaptButtonsAfterPreview();
          } else {
            alert('Optimization preview failed: ' + (res.data?.message || 'Server error'));
            $label.text('⚡ Preview Compression');
          }
        },
        error: function(xhr, status, error) {
          alert('Preview request error: ' + error);
          $label.text('⚡ Preview Compression');
        },
        complete: function() {
          self.setPreviewBusy(false);
        }
      });
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

      $('#r2g-compress-result').show();
    },

    adaptButtonsAfterPreview: function() {
      const $btn = $('#r2g-btn-preview-compress');
      $btn.prop('disabled', false);
      $btn.find('.r2g-preview-btn-text').text('✓ Re-test Preview');
      $btn.addClass('r2g-recompress');

      $('#r2g-btn-modal-proceed').addClass('r2g-ready-upload');
      $('#r2g-proceed-label').text('✓ Upload Now');
    },

    showInterceptorModal: function(files, onProceed, onCancel) {
      const self = this;
      self.ensureModalHtml();

      const $modal = $('#r2g-interceptor-modal');
      let activeIndex = 0;
      self.currentFiles = files;
      self.hasCompressedPreview = false;

      // Reset to site defaults
      self.format = self.normalizeFormat(self.config.format || 'webp');
      self.quality = parseInt(self.config.quality, 10) || 82;
      self.compress = (self.config.compress !== undefined) ? parseInt(self.config.compress, 10) : 1;
      self.maxWidth = Number.isFinite(parseInt(self.config.maxWidth, 10)) ? parseInt(self.config.maxWidth, 10) : 1920;
      self.engine = self.normalizeEngine(self.config.engine || 'resmush');
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
        $('#r2g-proceed-label').text('Upload & Offload to R2');

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

      updateFileDisplay(0);
      self.syncAllControls();

      // Clean old event listeners
      $modal.off('click mousedown pointerdown');
      $modal.find('*').off('click mousedown pointerdown touchstart input change');
      $(document).off('keydown.r2gmodal');

      $modal.find('.r2g-modal-card').on('click pointerdown mousedown touchstart', function(e) {
        e.stopPropagation();
      });

      $modal.find('.r2g-format-btn').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        self.setFormat($(this).data('format'));
      });

      $modal.find('.r2g-engine-btn').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        self.setEngine($(this).data('engine'));
      });

      $modal.find('.r2g-storage-btn').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        self.setStorageMode($(this).data('storage'));
      });

      $modal.find('.r2g-quality-slider')
        .off('pointerdown mousedown touchstart input change')
        .on('pointerdown mousedown touchstart', function(e) {
          e.stopPropagation();
        })
        .on('input change', function(e) {
          e.stopPropagation();
          self.setQuality($(this).val(), this);
        });

      $modal.find('.r2g-batch-thumb-item').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const idx = parseInt($(this).data('idx'), 10) || 0;
        updateFileDisplay(idx);
      });

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

      $modal.find('#r2g-btn-modal-close, #r2g-btn-modal-cancel').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (self.previewInProgress) return;
        closeModal();
        if (typeof onCancel === 'function') onCancel();
      });

      $modal.off('click').on('click', function(e) {
        if ($(e.target).is('#r2g-interceptor-modal')) {
          if (self.previewInProgress) return;
          e.preventDefault();
          closeModal();
          if (typeof onCancel === 'function') onCancel();
        }
      });

      $(document).on('keydown.r2gmodal', function(e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
          if (self.previewInProgress) return;
          closeModal();
          if (typeof onCancel === 'function') onCancel();
        }
      });

      $modal.find('#r2g-btn-modal-proceed').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (self.previewInProgress) return;
        self.syncCookies();
        closeModal();
        if (typeof onProceed === 'function') onProceed();
      });

      $modal.addClass('r2g-modal-active');
    },

    hookPlupload: function() {
      const self = this;

      const bindToUploader = function(uploader) {
        if (!uploader || uploader._r2g_bound) return;
        uploader._r2g_bound = true;
        self.currentUploader = uploader;

        const applyParams = function(up) {
          up.settings.multipart_params = up.settings.multipart_params || {};
          Object.assign(up.settings.multipart_params, self.uploadSettings());
          self.syncCookies();
        };

        // When visual interceptor is enabled, gate upload start until modal confirmed
        if (typeof uploader.addFile === 'function' && !uploader._r2g_add_file_wrapped) {
          uploader._r2g_add_file_wrapped = true;
          const originalAddFile = uploader.addFile;
          uploader.addFile = function(file) {
            const incoming = Array.isArray(file) ? file : [file];
            if (incoming.some(function(item) { return self.isImage(item); })) {
              this._r2g_hold_start = true;
            }
            return originalAddFile.apply(this, arguments);
          };
        }

        if (typeof uploader.start === 'function' && !uploader._r2g_start_wrapped) {
          uploader._r2g_start_wrapped = true;
          const originalStart = uploader.start;
          uploader.start = function() {
            if (this._r2g_hold_start && !this._r2g_allow_start) return;
            return originalStart.apply(this, arguments);
          };
        }

        const startQueue = function(up) {
          up._r2g_hold_start = false;
          up._r2g_allow_start = true;
          try {
            up.start();
          } finally {
            up._r2g_allow_start = false;
          }
        };

        uploader.bind('FilesAdded', function(up, files) {
          const imageFiles = files.filter(function(f) {
            return self.isImage(f);
          });

          if (imageFiles.length === 0) {
            applyParams(up);
            up.start();
            return;
          }

          try { up.stop(); } catch (e) {}

          self.showInterceptorModal(
            imageFiles,
            function() {
              applyParams(up);
              const queuedStatus = (typeof plupload !== 'undefined' && plupload.QUEUED) ? plupload.QUEUED : 1;
              files.forEach(function(f) {
                f.status = queuedStatus;
              });
              try { up.trigger('QueueChanged'); up.refresh(); } catch (e) {}
              startQueue(up);
            },
            function() {
              up._r2g_hold_start = false;
              files.forEach(function(f) {
                try { up.removeFile(f); } catch (e) {}
                if (f.attachment && typeof wp !== 'undefined' && wp.Uploader && wp.Uploader.queue) {
                  wp.Uploader.queue.remove(f.attachment);
                }
              });
              try { up.trigger('QueueChanged'); up.refresh(); } catch (e) {}
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

      const checkMediaFrames = function() {
        if (typeof window.wp !== 'undefined' && wp.media && wp.media.frame && wp.media.frame.uploader && wp.media.frame.uploader.uploader) {
          bindToUploader(wp.media.frame.uploader.uploader);
        }
      };
      checkMediaFrames();
      $(document).on('click', '.upload-ui, .media-button, #insert-media-button', function() {
        setTimeout(checkMediaFrames, 300);
      });
    },

    hookGutenberg: function() {
      const self = this;

      const wrapGutenberg = function() {
        if (typeof window.wp === 'undefined' || !wp.mediaUtils) return false;
        const mediaUploadApi = (typeof wp.mediaUtils.uploadMedia === 'function')
          ? wp.mediaUtils
          : (wp.mediaUtils.utils && typeof wp.mediaUtils.utils.uploadMedia === 'function' ? wp.mediaUtils.utils : null);
        if (!mediaUploadApi || mediaUploadApi._r2g_wrapped) return false;
        mediaUploadApi._r2g_wrapped = true;

        const originalUploadMedia = mediaUploadApi.uploadMedia;

        const wrappedUploadMedia = function(options) {
          if (!options || !options.filesList || options.filesList.length === 0) {
            return originalUploadMedia.apply(this, arguments);
          }

          const files = Array.from(options.filesList);
          const imageFiles = files.filter(function(f) {
            return self.isImage(f);
          });

          if (imageFiles.length === 0) {
            return originalUploadMedia.call(wp.mediaUtils, options);
          }

          return new Promise(function(resolve, reject) {
            self.showInterceptorModal(
              imageFiles,
              function() {
                self.syncCookies();
                options.additionalData = options.additionalData || {};
                Object.assign(options.additionalData, self.uploadSettings());
                const res = originalUploadMedia.call(wp.mediaUtils, options);
                if (res && typeof res.then === 'function') {
                  res.then(resolve).catch(reject);
                } else {
                  resolve(res);
                }
              },
              function() {
                if (typeof options.onError === 'function') {
                  options.onError(new Error('Upload cancelled.'));
                }
                reject(new Error('Upload cancelled.'));
              }
            );
          });
        };

        mediaUploadApi.uploadMedia = wrappedUploadMedia;

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

    hookBrowserForm: function() {
      const self = this;

      const handleFileInput = function(input) {
        if (!input || !input.files || input.files.length === 0) return;
        if (input._r2g_processing) return;

        const files = Array.from(input.files);
        const imageFiles = files.filter(function(f) {
          return self.isImage(f);
        });
        if (imageFiles.length === 0) return;

        const form = input.form || $(input).closest('form')[0];
        input._r2g_processing = true;

        self.showInterceptorModal(
          imageFiles,
          function() {
            self.syncCookies();
            input._r2g_confirmed = true;
            input._r2g_processing = false;

            if (!form) return;

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

            const submitBtn = form.querySelector('input[type="submit"][name="html-upload"]');
            if (submitBtn) {
              submitBtn.click();
            } else {
              form.submit();
            }
          },
          function() {
            input.value = '';
            input._r2g_confirmed = false;
            input._r2g_processing = false;
          }
        );
      };

      document.addEventListener('change', function(e) {
        const target = e.target;
        if (target && target.tagName === 'INPUT' && target.type === 'file') {
          if (target.form && target.form.id === 'file-form') {
            handleFileInput(target);
          }
        }
      }, true);

      $(document).on('click', '#html-upload, input[type="submit"][name="html-upload"]', function(e) {
        const form = this.form || $(this).closest('form')[0];
        if (!form) return;
        const fileInput = form.querySelector('input[type="file"]');
        if (fileInput && fileInput.files && fileInput.files.length > 0 && !fileInput._r2g_confirmed) {
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
    }
  };

  $(document).ready(function() {
    R2G_UploadManager.init();
  });

})(jQuery);
