/**
 * R2 by Grisma — Universal Upload-Time Control & Preference Interceptor
 *
 * Provides complete control over image format conversion and compression at upload time:
 * 1. Seamlessly injects interactive R2 Preset & Format controls inside the "Select or Upload Media" modal
 * 2. Injects prominent R2 Configuration Card inside the "Upload files" tab dropzone
 * 3. Injects toolbar on Media Library and Add New screens
 * 4. Dispatches chosen preset, format, and compression via Plupload multipart_params, REST headers, and Cookies
 * 5. Eliminates queue-freezing up.stop() calls so Plupload and Gutenberg uploads never hang
 *
 * @package R2_By_Grisma
 */
(function($) {
  'use strict';

  if (typeof window === 'undefined') return;

  const R2G_UploadManager = {
    config: window.r2g_compress_config || {
      workflow: 'bar',
      preset: 'webp_balanced',
      format: 'webp',
      compress: 1,
      quality: 82,
      maxWidth: 1920,
      presets: {},
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

    init: function() {
      // 1. Initialize active preferences from cookies or config
      this.preset = this.getCookie('r2g_preset') || this.config.preset || 'webp_balanced';
      this.format = this.getCookie('r2g_format') || this.config.format || 'webp';
      this.compress = this.getCookie('r2g_compress') !== null ? parseInt(this.getCookie('r2g_compress'), 10) : (this.config.compress !== undefined ? this.config.compress : 1);
      this.quality = this.getCookie('r2g_quality') ? parseInt(this.getCookie('r2g_quality'), 10) : (this.config.quality || 82);
      this.maxWidth = this.getCookie('r2g_max_width') ? parseInt(this.getCookie('r2g_max_width'), 10) : (this.config.maxWidth || 1920);

      this.syncCookies();

      // 2. Watch and inject UI into media modal & upload screens
      this.startDomWatcher();

      // 3. Hook upload channels
      this.hookPlupload();
      this.hookGutenberg();
      this.hookBrowserForm();
      this.bindControlEvents();
    },

    syncCookies: function() {
      this.setCookie('r2g_preset', this.preset);
      this.setCookie('r2g_format', this.format);
      this.setCookie('r2g_compress', this.compress);
      this.setCookie('r2g_quality', this.quality);
      this.setCookie('r2g_max_width', this.maxWidth);
    },

    applyPreset: function(presetKey) {
      this.preset = presetKey;
      const presets = this.config.presets || {};
      if (presets[presetKey]) {
        const p = presets[presetKey];
        this.format = p.format || 'webp';
        this.compress = (p.compress !== undefined) ? parseInt(p.compress, 10) : 1;
        this.quality = parseInt(p.quality, 10) || 82;
        this.maxWidth = parseInt(p.max_width, 10) || 1920;
      }
      this.syncAllControls();
    },

    syncAllControls: function() {
      this.syncCookies();

      // Sync select dropdowns
      $('.r2g-control-preset').val(this.preset);
      $('.r2g-control-format').val(this.format);
      $('.r2g-control-compress').val(this.compress);

      // Sync radio pills
      $('input[name="r2g_inline_fmt"][value="' + this.format + '"]').prop('checked', true);
      $('input[name="r2g_inline_cmp"][value="' + this.compress + '"]').prop('checked', true);

      // Update active pill classes
      $('.r2g-format-btn').removeClass('r2g-btn-active');
      $('.r2g-format-btn[data-format="' + this.format + '"]').addClass('r2g-btn-active');

      $('input[name="r2g_inline_fmt"]').each(function() {
        $(this).closest('.r2g-radio-pill').toggleClass('r2g-pill-active', $(this).is(':checked'));
      });
      $('input[name="r2g_inline_cmp"]').each(function() {
        $(this).closest('.r2g-radio-pill').toggleClass('r2g-pill-active', $(this).is(':checked'));
      });

      // Update quality sliders and labels
      $('.r2g-quality-slider').val(this.quality);
      $('.r2g-quality-val').text(this.quality + '%');

      if (this.compress === 0) {
        $('.r2g-quality-wrap').hide();
      } else {
        $('.r2g-quality-wrap').show();
      }
    },

    isPromptMode: function() {
      if (this.config.workflow !== 'prompt') return false;
      if (sessionStorage.getItem('r2g_session_remember') === 'true') return false;
      return true;
    },

    /**
     * DOM Watcher: Injects controls into Media Modal tabs and upload dropzone
     */
    startDomWatcher: function() {
      const self = this;

      const checkAndInject = function() {
        // If workflow is set to silent automatic, do not inject upload controls
        if (self.config.workflow === 'automatic') return;

        const presets = self.config.presets || {};
        let presetOptionsHtml = '';
        for (const [key, p] of Object.entries(presets)) {
          const sel = (key === self.preset) ? 'selected' : '';
          presetOptionsHtml += '<option value="' + key + '" ' + sel + '>' + (p.name || key) + '</option>';
        }

        // 1. Inject into "Select or Upload Media" Modal Top Tab Bar (.media-frame-router)
        const $frameRouter = $('.media-frame-router').first();
        if ($frameRouter.length && !$('#r2g-modal-top-bar').length) {
          const topBarHtml = `
            <div id="r2g-modal-top-bar" class="r2g-media-modal-top-bar">
              <span class="r2g-top-bar-badge">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                R2 Preset:
              </span>
              <select class="r2g-control-preset">
                ${presetOptionsHtml}
              </select>
              <div class="r2g-format-group" style="display:inline-flex; gap:3px;">
                <button type="button" class="button button-small r2g-format-btn ${self.format === 'webp' ? 'r2g-btn-active' : ''}" data-format="webp">WebP</button>
                <button type="button" class="button button-small r2g-format-btn ${self.format === 'jpg' ? 'r2g-btn-active' : ''}" data-format="jpg">JPG</button>
                <button type="button" class="button button-small r2g-format-btn ${self.format === 'png' ? 'r2g-btn-active' : ''}" data-format="png">PNG</button>
                <button type="button" class="button button-small r2g-format-btn ${self.format === 'original' ? 'r2g-btn-active' : ''}" data-format="original">Original</button>
              </div>
              <span class="r2g-quality-wrap" style="${self.compress === 0 ? 'display:none;' : ''}">
                <span class="r2g-quality-val" style="font-weight:600; font-size:11px;">${self.quality}%</span>
              </span>
            </div>
          `;
          $frameRouter.append(topBarHtml);
        }

        // 2. Inject prominent Card inside the "Upload files" Tab dropzone (.uploader-inline)
        const $uploadUi = $('.uploader-inline .upload-ui').first();
        if ($uploadUi.length && !$('#r2g-inline-upload-card').length) {
          const cardHtml = `
            <div id="r2g-inline-upload-card" class="r2g-inline-upload-card">
              <div class="r2g-inline-card-header">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                <span>Cloudflare R2 Optimization & Conversion Preset</span>
              </div>
              <div class="r2g-inline-card-body">
                <div class="r2g-inline-field">
                  <label>Preset:</label>
                  <select class="r2g-control-preset r2g-input" style="max-width:280px; font-weight:600;">
                    ${presetOptionsHtml}
                  </select>
                </div>
                <div class="r2g-inline-field">
                  <label>Target Format:</label>
                  <div class="r2g-inline-pills">
                    <label class="r2g-radio-pill ${self.format === 'webp' ? 'r2g-pill-active' : ''}">
                      <input type="radio" name="r2g_inline_fmt" value="webp" ${self.format === 'webp' ? 'checked' : ''}>
                      <span><strong>WebP</strong></span>
                    </label>
                    <label class="r2g-radio-pill ${self.format === 'jpg' ? 'r2g-pill-active' : ''}">
                      <input type="radio" name="r2g_inline_fmt" value="jpg" ${self.format === 'jpg' ? 'checked' : ''}>
                      <span><strong>JPEG / JPG</strong></span>
                    </label>
                    <label class="r2g-radio-pill ${self.format === 'png' ? 'r2g-pill-active' : ''}">
                      <input type="radio" name="r2g_inline_fmt" value="png" ${self.format === 'png' ? 'checked' : ''}>
                      <span>PNG</span>
                    </label>
                    <label class="r2g-radio-pill ${self.format === 'original' ? 'r2g-pill-active' : ''}">
                      <input type="radio" name="r2g_inline_fmt" value="original" ${self.format === 'original' ? 'checked' : ''}>
                      <span>Original</span>
                    </label>
                  </div>
                </div>
                <div class="r2g-inline-field r2g-quality-wrap" style="${self.compress === 0 ? 'display:none;' : ''}">
                  <label>Quality: <span class="r2g-quality-val">${self.quality}%</span></label>
                  <input type="range" class="r2g-quality-slider" min="60" max="100" value="${self.quality}" style="max-width:180px;">
                </div>
              </div>
            </div>
          `;
          $uploadUi.find('.upload-instructions').first().before(cardHtml);
        }

        // 3. Inject Toolbar on media-new.php or upload.php
        const $standaloneTarget = $('#wp-media-grid, .upload-php #wpbody-content .wrap, .media-new-php #wpbody-content .wrap, #async-upload-wrap').first();
        if ($standaloneTarget.length && !$('#r2g-upload-toolbar').length && !$('.media-modal').length) {
          const barHtml = `
            <div id="r2g-upload-toolbar" class="r2g-upload-bar">
              <span class="r2g-upload-bar-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                Cloudflare R2 Upload Settings:
              </span>
              <div class="r2g-upload-bar-item">
                <label>Preset:</label>
                <select class="r2g-control-preset">
                  ${presetOptionsHtml}
                </select>
              </div>
              <div class="r2g-upload-bar-item">
                <div class="r2g-format-group" style="display:inline-flex; gap:3px;">
                  <button type="button" class="button button-small r2g-format-btn ${self.format === 'webp' ? 'r2g-btn-active' : ''}" data-format="webp">WebP</button>
                  <button type="button" class="button button-small r2g-format-btn ${self.format === 'jpg' ? 'r2g-btn-active' : ''}" data-format="jpg">JPG</button>
                  <button type="button" class="button button-small r2g-format-btn ${self.format === 'png' ? 'r2g-btn-active' : ''}" data-format="png">PNG</button>
                  <button type="button" class="button button-small r2g-format-btn ${self.format === 'original' ? 'r2g-btn-active' : ''}" data-format="original">Original</button>
                </div>
              </div>
              <div class="r2g-upload-bar-item r2g-quality-wrap" style="${self.compress === 0 ? 'display:none;' : ''}">
                <label>Quality:</label>
                <input type="range" class="r2g-quality-slider" min="60" max="100" value="${self.quality}" style="width:100px;">
                <span class="r2g-quality-val" style="font-weight:600; font-size:12px;">${self.quality}%</span>
              </div>
            </div>
          `;
          if ($('#drag-drop-area').length) {
            $('#drag-drop-area').before(barHtml);
          } else {
            $standaloneTarget.find('h1').first().after(barHtml);
          }
        }
      };

      $(document).ready(checkAndInject);
      $(document).on('uploaderReady', checkAndInject);

      // Periodically check for modal opening (e.g. user clicks "Add Media")
      setInterval(checkAndInject, 500);
    },

    /**
     * Bind 2-way event syncing for all injected controls
     */
    bindControlEvents: function() {
      const self = this;

      // Preset dropdown change
      $(document).on('change', '.r2g-control-preset', function() {
        self.applyPreset($(this).val());
      });

      // Quick Format buttons
      $(document).on('click', '.r2g-format-btn', function(e) {
        e.preventDefault();
        const fmt = $(this).data('format');
        self.format = fmt;
        self.preset = 'custom';
        self.syncAllControls();
      });

      // Radio pills change
      $(document).on('change', 'input[name="r2g_inline_fmt"]', function() {
        self.format = $(this).val();
        self.preset = 'custom';
        self.syncAllControls();
      });

      $(document).on('change', 'input[name="r2g_inline_cmp"]', function() {
        self.compress = parseInt($(this).val(), 10);
        self.preset = 'custom';
        self.syncAllControls();
      });

      // Quality sliders
      $(document).on('input change', '.r2g-quality-slider', function() {
        self.quality = parseInt($(this).val(), 10);
        self.preset = 'custom';
        self.syncAllControls();
      });
    },

    /**
     * Hook Gutenberg Block Editor media uploads & REST API
     */
    hookGutenberg: function() {
      const self = this;

      // Add apiFetch middleware to attach R2 headers to /wp/v2/media requests
      if (typeof window.wp !== 'undefined' && wp.apiFetch && wp.apiFetch.use) {
        if (!wp.apiFetch._r2g_hooked) {
          wp.apiFetch._r2g_hooked = true;
          wp.apiFetch.use(function(options, next) {
            if (options && options.path && options.path.indexOf('/wp/v2/media') !== -1) {
              options.headers = options.headers || {};
              options.headers['X-R2G-Preset'] = self.preset;
              options.headers['X-R2G-Format'] = self.format;
              options.headers['X-R2G-Quality'] = self.quality;
              options.headers['X-R2G-Compress'] = self.compress;
              options.headers['X-R2G-Max-Width'] = self.maxWidth;
            }
            return next(options);
          });
        }
      }

      const checkAndHook = function() {
        if (typeof wp !== 'undefined' && wp.mediaUtils && wp.mediaUtils.uploadMedia) {
          if (wp.mediaUtils._r2g_hooked) return true;
          wp.mediaUtils._r2g_hooked = true;

          const origUploadMedia = wp.mediaUtils.uploadMedia;
          wp.mediaUtils.uploadMedia = function(options) {
            self.syncCookies();
            return origUploadMedia.apply(this, arguments);
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
     * Attaches params safely on BeforeUpload WITHOUT halting the queue
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

            // Attach latest upload parameters to every request right before upload begins
            uploader.bind('BeforeUpload', function(up, file) {
              up.settings.multipart_params = up.settings.multipart_params || {};
              up.settings.multipart_params['r2g_preset'] = self.preset;
              up.settings.multipart_params['r2g_format'] = self.format;
              up.settings.multipart_params['r2g_compress'] = self.compress;
              up.settings.multipart_params['r2g_quality'] = self.quality;
              up.settings.multipart_params['r2g_max_width'] = self.maxWidth;
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
        self.syncCookies();

        // Add hidden inputs for direct form submit
        const fields = {
          'r2g_preset': self.preset,
          'r2g_format': self.format,
          'r2g_compress': self.compress,
          'r2g_quality': self.quality,
          'r2g_max_width': self.maxWidth,
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
