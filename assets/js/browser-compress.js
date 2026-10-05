/**
 * R2 by Grisma — Universal Upload-Time Control & Preference Interceptor
 *
 * Provides streamlined control over image format conversion and compression:
 * 1. Injects ONE unified, responsive toolbar in Media Modal and upload screens
 * 2. 2-way real-time synchronization between Presets, Format pills, and Quality slider
 * 3. Responsive live percentage badge on mobile and desktop without touch drag freezing
 * 4. Dispatches chosen preset, format, and compression via Plupload multipart_params, REST headers, and Cookies
 * 5. Eliminates queue-freezing calls so Plupload and Gutenberg uploads never hang
 *
 * @package R2_By_Grisma
 */
(function($) {
  'use strict';

  if (typeof window === 'undefined') return;

  const R2G_UploadManager = {
    config: window.r2g_compress_config || {
      engine: 'server',
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
      // 1. Initialize active preferences
      // If user customized within this active browser session, respect their session choices
      const userCustomized = sessionStorage.getItem('r2g_session_customized') === 'true';

      if (userCustomized && this.getCookie('r2g_preset')) {
        this.preset = this.getCookie('r2g_preset');
        this.format = this.getCookie('r2g_format') || 'webp';
        this.compress = this.getCookie('r2g_compress') !== null ? parseInt(this.getCookie('r2g_compress'), 10) : 1;
        this.quality = this.getCookie('r2g_quality') ? parseInt(this.getCookie('r2g_quality'), 10) : 82;
        this.maxWidth = this.getCookie('r2g_max_width') ? parseInt(this.getCookie('r2g_max_width'), 10) : 1920;
      } else {
        // Initial load: strictly use WordPress database config
        this.preset = this.config.preset || 'webp_balanced';
        const presets = this.config.presets || {};
        if (presets[this.preset]) {
          const p = presets[this.preset];
          this.format = p.format || 'webp';
          this.compress = (p.compress !== undefined) ? parseInt(p.compress, 10) : 1;
          this.quality = parseInt(p.quality, 10) || 82;
          this.maxWidth = parseInt(p.max_width, 10) || 1920;
        } else {
          this.format = this.config.format || 'webp';
          this.compress = (this.config.compress !== undefined) ? this.config.compress : 1;
          this.quality = this.config.quality || 82;
          this.maxWidth = this.config.maxWidth || 1920;
        }
      }

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
      sessionStorage.setItem('r2g_session_customized', 'true');

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

    setFormat: function(newFormat) {
      this.format = newFormat;
      sessionStorage.setItem('r2g_session_customized', 'true');

      // Check if format matches an existing preset
      const presets = this.config.presets || {};
      let matched = 'custom';
      for (const [key, p] of Object.entries(presets)) {
        if (key === 'custom') continue;
        if (p.format === newFormat && parseInt(p.quality, 10) === this.quality) {
          matched = key;
          break;
        }
      }
      if (matched === 'custom') {
        if (newFormat === 'webp') matched = 'webp_balanced';
        else if (newFormat === 'jpg') matched = 'jpeg_balanced';
        else if (newFormat === 'original') matched = (this.compress === 0 ? 'raw_lossless' : 'original_compressed');
      }

      this.preset = matched;
      if (presets[matched] && matched !== 'custom') {
        this.quality = parseInt(presets[matched].quality, 10) || 82;
        this.compress = (presets[matched].compress !== undefined) ? parseInt(presets[matched].compress, 10) : 1;
      }

      this.syncAllControls();
    },

    setQuality: function(newQuality, sourceEl) {
      this.quality = parseInt(newQuality, 10);
      sessionStorage.setItem('r2g_session_customized', 'true');
      this.preset = 'custom';

      // Update badge text instantly without interrupting touch drag
      $('.r2g-quality-badge, .r2g-quality-val').text(this.quality + '%');

      // Sync other slider elements if any
      $('.r2g-quality-slider').not(sourceEl).val(this.quality);
      $('.r2g-control-preset').val('custom');

      this.syncCookies();
      this.updateUploaderParams();
    },

    syncAllControls: function() {
      this.syncCookies();

      // Sync select dropdowns
      $('.r2g-control-preset').val(this.preset);

      // Update active format buttons
      $('.r2g-format-btn').removeClass('r2g-btn-active');
      $('.r2g-format-btn[data-format="' + this.format + '"]').addClass('r2g-btn-active');

      // Update quality sliders and badges
      $('.r2g-quality-slider').val(this.quality);
      $('.r2g-quality-badge, .r2g-quality-val').text(this.quality + '%');

      if (this.compress === 0) {
        $('.r2g-quality-wrap').hide();
      } else {
        $('.r2g-quality-wrap').show();
      }

      this.updateUploaderParams();
    },

    updateUploaderParams: function() {
      if (this.currentUploader && this.currentUploader.settings) {
        this.currentUploader.settings.multipart_params = this.currentUploader.settings.multipart_params || {};
        this.currentUploader.settings.multipart_params['r2g_preset'] = this.preset;
        this.currentUploader.settings.multipart_params['r2g_format'] = this.format;
        this.currentUploader.settings.multipart_params['r2g_compress'] = this.compress;
        this.currentUploader.settings.multipart_params['r2g_quality'] = this.quality;
        this.currentUploader.settings.multipart_params['r2g_max_width'] = this.maxWidth;
      }
    },

    /**
     * DOM Watcher: Injects EXACTLY ONE clean, responsive toolbar
     */
    startDomWatcher: function() {
      const self = this;

      const checkAndInject = function() {
        if (self.config.workflow === 'automatic') return;

        // If toolbar already exists in DOM, do NOT inject another one!
        if ($('#r2g-upload-toolbar').length) {
          return;
        }

        const presets = self.config.presets || {};
        let presetOptionsHtml = '';
        for (const [key, p] of Object.entries(presets)) {
          const sel = (key === self.preset) ? 'selected' : '';
          presetOptionsHtml += '<option value="' + key + '" ' + sel + '>' + (p.name || key) + '</option>';
        }

        const engine = self.config.engine || 'server';
        let engineLabel = 'PHP GD/Imagick';
        if (engine === 'resmush') engineLabel = 'reSmush.it (auto-fallback)';
        else if (engine === 'browser') engineLabel = 'Browser Canvas';
        else if (engine === 'none') engineLabel = 'Lossless Offload';

        const toolbarHtml = `
          <div id="r2g-upload-toolbar" class="r2g-upload-toolbar">
            <div class="r2g-toolbar-header">
              <div class="r2g-toolbar-title">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                <span>Cloudflare R2 Optimization</span>
              </div>
              <span class="r2g-engine-badge">${engineLabel}</span>
            </div>
            <div class="r2g-toolbar-content">
              <div class="r2g-toolbar-col r2g-col-preset">
                <label class="r2g-bar-label">Preset:</label>
                <select class="r2g-control-preset r2g-select">
                  ${presetOptionsHtml}
                </select>
              </div>
              <div class="r2g-toolbar-col r2g-col-format">
                <label class="r2g-bar-label">Target Format:</label>
                <div class="r2g-format-group">
                  <button type="button" class="r2g-format-btn ${self.format === 'webp' ? 'r2g-btn-active' : ''}" data-format="webp">WebP</button>
                  <button type="button" class="r2g-format-btn ${self.format === 'jpg' ? 'r2g-btn-active' : ''}" data-format="jpg">JPG</button>
                  <button type="button" class="r2g-format-btn ${self.format === 'png' ? 'r2g-btn-active' : ''}" data-format="png">PNG</button>
                  <button type="button" class="r2g-format-btn ${self.format === 'original' ? 'r2g-btn-active' : ''}" data-format="original">Original</button>
                </div>
              </div>
              <div class="r2g-toolbar-col r2g-col-quality r2g-quality-wrap" style="${self.compress === 0 ? 'display:none;' : ''}">
                <label class="r2g-bar-label">Quality:</label>
                <div class="r2g-slider-box">
                  <input type="range" class="r2g-quality-slider" min="50" max="100" value="${self.quality}">
                  <span class="r2g-quality-badge r2g-quality-val">${self.quality}%</span>
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
      setInterval(checkAndInject, 600);
    },

    /**
     * Bind 2-way event syncing for controls
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
        self.setFormat(fmt);
      });

      // Quality sliders: input fires live during drag without interrupting touch tracking
      $(document).on('input', '.r2g-quality-slider', function() {
        self.setQuality($(this).val(), this);
      });

      $(document).on('change', '.r2g-quality-slider', function() {
        self.syncCookies();
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
     * Attaches params safely on FilesAdded and BeforeUpload WITHOUT halting the queue
     */
    hookPlupload: function() {
      const self = this;

      const bindToUploader = function(uploader) {
        if (!uploader || uploader._r2g_bound) return;
        uploader._r2g_bound = true;
        self.currentUploader = uploader;

        const setParams = function(up) {
          up.settings.multipart_params = up.settings.multipart_params || {};
          up.settings.multipart_params['r2g_preset'] = self.preset;
          up.settings.multipart_params['r2g_format'] = self.format;
          up.settings.multipart_params['r2g_compress'] = self.compress;
          up.settings.multipart_params['r2g_quality'] = self.quality;
          up.settings.multipart_params['r2g_max_width'] = self.maxWidth;
          self.syncCookies();
        };

        uploader.bind('FilesAdded', setParams);
        uploader.bind('BeforeUpload', setParams);
      };

      const patchUploader = function() {
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
        if (typeof window.wp !== 'undefined' && wp.media && wp.media.featuredImage && wp.media.featuredImage.frame) {
          const frame = wp.media.featuredImage.frame();
          if (frame && frame.uploader && frame.uploader.uploader) {
            bindToUploader(frame.uploader.uploader);
          }
        }
        if (typeof window.uploader !== 'undefined' && window.uploader.bind) {
          bindToUploader(window.uploader);
        }
      };

      patchUploader();
      $(document).on('uploaderReady', function(e, up) {
        bindToUploader(up);
      });
      setInterval(patchUploader, 1000);

      // Global failsafe for async-upload.php
      $(document).ajaxSend(function(event, xhr, settings) {
        if (settings && settings.url && settings.url.indexOf('async-upload.php') !== -1) {
          xhr.setRequestHeader('X-R2G-Preset', self.preset);
          xhr.setRequestHeader('X-R2G-Format', self.format);
          xhr.setRequestHeader('X-R2G-Quality', self.quality);
          xhr.setRequestHeader('X-R2G-Compress', self.compress);
          xhr.setRequestHeader('X-R2G-Max-Width', self.maxWidth);
        }
      });
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
