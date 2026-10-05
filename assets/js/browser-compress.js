/**
 * R2 by Grisma — Upload-Time Control & Preference Interceptor
 *
 * Provides real-time upload control:
 * 1. Sets upload preferences (format, compression, quality, max width) in cookies & POST params
 * 2. Renders an interactive upload toolbar bar on media pages
 * 3. Shows a pre-upload options modal when "Prompt on Upload" is enabled
 * 4. Passes original native files to server without browser Canvas memory overhead
 *
 * @package R2_By_Grisma
 */
(function($) {
  'use strict';

  if (typeof window === 'undefined') return;

  const R2G_UploadManager = {
    config: window.r2g_compress_config || {
      workflow: 'prompt',
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

    init: function() {
      // 1. Initialize active preferences from cookies or admin presets
      this.format = this.getCookie('r2g_format') || this.config.format || 'webp';
      this.compress = this.getCookie('r2g_compress') !== null ? parseInt(this.getCookie('r2g_compress'), 10) : (this.config.compress !== undefined ? this.config.compress : 1);
      this.quality = this.getCookie('r2g_quality') ? parseInt(this.getCookie('r2g_quality'), 10) : (this.config.quality || 82);
      this.maxWidth = this.getCookie('r2g_max_width') ? parseInt(this.getCookie('r2g_max_width'), 10) : (this.config.maxWidth || 1920);

      this.syncCookies();

      // 2. Inject Upload Settings Toolbar on media screens
      this.injectUploadBar();

      // 3. Hook upload channels
      this.hookPlupload();
      this.hookGutenberg();
      this.hookBrowserForm();
      this.injectModalHtml();
    },

    syncCookies: function() {
      this.setCookie('r2g_format', this.format);
      this.setCookie('r2g_compress', this.compress);
      this.setCookie('r2g_quality', this.quality);
      this.setCookie('r2g_max_width', this.maxWidth);
    },

    isPromptMode: function() {
      if (this.config.workflow !== 'prompt') return false;
      if (sessionStorage.getItem('r2g_session_remember') === 'true') return false;
      return true;
    },

    /**
     * Inject Sleek Toolbar Bar in Media Library / Add New
     */
    injectUploadBar: function() {
      const self = this;
      const renderBar = function() {
        if ($('#r2g-upload-toolbar').length) return;

        const target = $('#wp-media-grid, .upload-php #wpbody-content .wrap, .media-new-php #wpbody-content .wrap, #async-upload-wrap').first();
        if (!target.length) return;

        const barHtml = `
          <div id="r2g-upload-toolbar" class="r2g-upload-bar">
            <span class="r2g-upload-bar-title">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
              Cloudflare R2 Upload Settings:
            </span>
            <div class="r2g-upload-bar-item">
              <label for="r2g-bar-format">Format:</label>
              <select id="r2g-bar-format">
                <option value="webp" ${self.format === 'webp' ? 'selected' : ''}>WebP (Recommended)</option>
                <option value="original" ${self.format === 'original' ? 'selected' : ''}>Preserve Original (Keep JPG/PNG)</option>
              </select>
            </div>
            <div class="r2g-upload-bar-item">
              <label for="r2g-bar-compress">Compression:</label>
              <select id="r2g-bar-compress">
                <option value="1" ${self.compress == 1 ? 'selected' : ''}>Compress & Optimize (${self.quality}%)</option>
                <option value="0" ${self.compress == 0 ? 'selected' : ''}>Raw Original (No Compression)</option>
              </select>
            </div>
            <div class="r2g-upload-bar-item">
              <span style="font-size:11px; color:#64748b;">(Upload-time settings applied instantly)</span>
            </div>
          </div>
        `;

        if ($('#drag-drop-area').length) {
          $('#drag-drop-area').before(barHtml);
        } else {
          target.find('h1').first().after(barHtml);
        }

        $('#r2g-bar-format').on('change', function() {
          self.format = $(this).val();
          self.syncCookies();
        });

        $('#r2g-bar-compress').on('change', function() {
          self.compress = parseInt($(this).val(), 10);
          self.syncCookies();
        });
      };

      $(document).ready(renderBar);
      $(document).on('uploaderReady', renderBar);
    },

    /**
     * Intercept Gutenberg Block Editor media uploads
     */
    hookGutenberg: function() {
      const self = this;
      const checkAndHook = function() {
        if (typeof wp !== 'undefined' && wp.mediaUtils && wp.mediaUtils.uploadMedia) {
          if (wp.mediaUtils._r2g_hooked) return true;
          wp.mediaUtils._r2g_hooked = true;

          const origUploadMedia = wp.mediaUtils.uploadMedia;
          wp.mediaUtils.uploadMedia = function(options) {
            if (!options || !options.filesList || !options.filesList.length) {
              return origUploadMedia.apply(this, arguments);
            }

            const rawFiles = Array.from(options.filesList);
            const imageFiles = rawFiles.filter(function(f) {
              return f.type && f.type.indexOf('image/') === 0 && f.type.indexOf('svg') === -1;
            });

            if (!imageFiles.length || !self.isPromptMode()) {
              self.syncCookies();
              return origUploadMedia.apply(this, arguments);
            }

            const proceed = function() {
              self.syncCookies();
              return origUploadMedia.call(this, options);
            };

            const cancel = function() {
              self.hideModal();
              if (typeof options.onError === 'function') {
                options.onError('Upload cancelled by user.');
              }
            };

            self.showConfirmModal(imageFiles, function(chosenFormat, chosenCompress, chosenQuality, chosenMaxWidth) {
              self.format = chosenFormat;
              self.compress = chosenCompress;
              self.quality = chosenQuality;
              self.maxWidth = chosenMaxWidth;
              self.syncCookies();
              proceed();
            }, cancel);
          };
          return true;
        }
        return false;
      };

      if (!checkAndHook()) {
        const timer = setInterval(function() {
          if (checkAndHook()) clearInterval(timer);
        }, 300);
        setTimeout(function() { clearInterval(timer); }, 15000);
      }
    },

    /**
     * Intercept standard WordPress Media Uploader (Plupload)
     */
    hookPlupload: function() {
      const self = this;
      if (this._pluploadHooked) return;
      this._pluploadHooked = true;

      const patchUploader = function() {
        if (typeof wp !== 'undefined' && wp.Uploader && wp.Uploader.prototype) {
          if (wp.Uploader._r2g_patched) return;
          wp.Uploader._r2g_patched = true;

          const originalInit = wp.Uploader.prototype.init;
          wp.Uploader.prototype.init = function() {
            originalInit.apply(this, arguments);
            const uploader = this.uploader;
            if (!uploader || uploader._r2g_bound) return;
            uploader._r2g_bound = true;

            // Attach upload parameters to every request
            uploader.bind('BeforeUpload', function(up, file) {
              up.settings.multipart_params = up.settings.multipart_params || {};
              up.settings.multipart_params['r2g_format'] = self.format;
              up.settings.multipart_params['r2g_compress'] = self.compress;
              up.settings.multipart_params['r2g_quality'] = self.quality;
              up.settings.multipart_params['r2g_max_width'] = self.maxWidth;
            });

            uploader.bind('FilesAdded', function(up, files) {
              const imageFiles = files.filter(f => f.type && f.type.startsWith('image/') && !f.type.includes('svg'));
              if (!imageFiles.length || !self.isPromptMode()) {
                self.syncCookies();
                return;
              }

              // Pause uploader queue to prompt user
              up.stop();

              self.showConfirmModal(imageFiles, function(chosenFormat, chosenCompress, chosenQuality, chosenMaxWidth) {
                self.format = chosenFormat;
                self.compress = chosenCompress;
                self.quality = chosenQuality;
                self.maxWidth = chosenMaxWidth;
                self.syncCookies();

                // Resume upload queue
                up.start();
              }, function() {
                // Cancelled
                imageFiles.forEach(f => {
                  if (up.removeFile) up.removeFile(f);
                });
                self.hideModal();
              });
            });
          };
        }
      };

      patchUploader();
      $(document).on('uploaderReady', patchUploader);
    },

    /**
     * Intercept browser built-in single file uploader form
     */
    hookBrowserForm: function() {
      const self = this;
      $(document).on('submit', '#file-form', function(e) {
        const form = this;
        if (form._r2g_submitting) return;

        const fileInput = form.querySelector('input[type="file"][name="async-upload"], input[type="file"]');
        if (!fileInput || !fileInput.files || !fileInput.files.length) return;

        const file = fileInput.files[0];
        if (!file.type || file.type.indexOf('image/') !== 0 || file.type.indexOf('svg') !== -1) return;
        if (!self.isPromptMode()) {
          self.syncCookies();
          return;
        }

        e.preventDefault();

        self.showConfirmModal([file], function(chosenFormat, chosenCompress, chosenQuality, chosenMaxWidth) {
          self.format = chosenFormat;
          self.compress = chosenCompress;
          self.quality = chosenQuality;
          self.maxWidth = chosenMaxWidth;
          self.syncCookies();

          form._r2g_submitting = true;
          form.submit();
        }, function() {
          self.hideModal();
          fileInput.value = '';
        });
      });
    },

    /**
     * Inject Confirmation Modal HTML into DOM
     */
    injectModalHtml: function() {
      if (document.getElementById('r2g-confirm-modal')) return;

      const html = `
        <div id="r2g-confirm-modal" class="r2g-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:9999999;">
          <div class="r2g-modal-card">
            <div class="r2g-modal-header">
              <span class="r2g-modal-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                Cloudflare R2 Upload
              </span>
              <h3>Cloudflare R2 Upload Settings</h3>
              <p>Choose format conversion and compression options for this upload.</p>
              <div id="r2g-modal-file-info" style="font-size:12px; color:#475569; margin-top:8px; font-weight:500; word-break:break-all;"></div>
            </div>
            <div class="r2g-modal-body">
              <div class="r2g-field-group">
                <label>Format Conversion:</label>
                <div class="r2g-radio-group">
                  <label class="r2g-radio-pill">
                    <input type="radio" name="r2g_modal_format" value="webp" checked>
                    <span><strong>Convert to WebP (Recommended)</strong></span>
                  </label>
                  <label class="r2g-radio-pill">
                    <input type="radio" name="r2g_modal_format" value="original">
                    <span>Preserve Original (Keep JPG/PNG)</span>
                  </label>
                </div>
              </div>
              <div class="r2g-field-group">
                <label>Compression & Resizing:</label>
                <div class="r2g-radio-group">
                  <label class="r2g-radio-pill">
                    <input type="radio" name="r2g_modal_compress" value="1" checked>
                    <span><strong>Compress & Optimize</strong></span>
                  </label>
                  <label class="r2g-radio-pill">
                    <input type="radio" name="r2g_modal_compress" value="0">
                    <span>Raw Original (No Compression)</span>
                  </label>
                </div>
              </div>
              <div class="r2g-field-group" id="r2g-modal-quality-group">
                <label>Compression Quality: <span id="r2g-quality-val">82%</span></label>
                <input type="range" id="r2g-modal-quality" min="60" max="95" value="82" class="r2g-slider">
              </div>
              <div style="margin-top:6px;">
                <label style="font-size:12px; color:#64748b; font-weight:normal; display:flex; align-items:center; gap:6px; cursor:pointer;">
                  <input type="checkbox" id="r2g-modal-remember" value="1">
                  Remember my choice for this session (Do not ask again)
                </label>
              </div>
            </div>
            <div class="r2g-modal-footer">
              <button type="button" id="r2g-modal-cancel" class="button" style="color:#d63638;">Cancel</button>
              <button type="button" id="r2g-modal-proceed" class="button button-primary button-large">Start Upload</button>
            </div>
          </div>
        </div>
      `;

      $('body').append(html);

      $('#r2g-modal-quality').on('input', function() {
        $('#r2g-quality-val').text($(this).val() + '%');
      });

      $('input[name="r2g_modal_compress"]').on('change', function() {
        if ($(this).val() == '0') {
          $('#r2g-modal-quality-group').slideUp(150);
        } else {
          $('#r2g-modal-quality-group').slideDown(150);
        }
      });
    },

    showConfirmModal: function(files, onProceed, onCancel) {
      this.injectModalHtml();
      const modal = $('#r2g-confirm-modal');

      if (files && files.length) {
        const fileNames = files.map(f => (f.name || 'image')).join(', ');
        $('#r2g-modal-file-info').text('Files: ' + fileNames);
      }

      // Pre-select current choices
      $(`input[name="r2g_modal_format"][value="${this.format}"]`).prop('checked', true);
      $(`input[name="r2g_modal_compress"][value="${this.compress}"]`).prop('checked', true);
      $('#r2g-modal-quality').val(this.quality);
      $('#r2g-quality-val').text(this.quality + '%');

      if (this.compress == 0) {
        $('#r2g-modal-quality-group').hide();
      } else {
        $('#r2g-modal-quality-group').show();
      }

      modal.fadeIn(150);

      $('#r2g-modal-proceed').off('click').on('click', function() {
        const chosenFormat = $('input[name="r2g_modal_format"]:checked').val() || 'webp';
        const chosenCompress = parseInt($('input[name="r2g_modal_compress"]:checked').val() || '1', 10);
        const chosenQuality = parseInt($('#r2g-modal-quality').val(), 10) || 82;
        const remember = $('#r2g-modal-remember').is(':checked');

        if (remember) {
          sessionStorage.setItem('r2g_session_remember', 'true');
        }

        modal.hide();
        onProceed(chosenFormat, chosenCompress, chosenQuality, 1920);
      });

      $('#r2g-modal-cancel').off('click').on('click', function() {
        modal.hide();
        if (typeof onCancel === 'function') onCancel();
      });
    },

    hideModal: function() {
      $('#r2g-confirm-modal').fadeOut(100);
    }
  };

  $(document).ready(function() {
    R2G_UploadManager.init();
  });

})(jQuery);
