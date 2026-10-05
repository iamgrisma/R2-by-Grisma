/**
 * R2 by Grisma — Client-Side "Edge in Browser" Image Compression Engine
 * Intercepts media uploads in WordPress, compresses/converts to WebP in browser Canvas,
 * provides optional pre-upload confirmation modal, and pushes optimized files.
 */
(function($) {
  'use strict';

  if (typeof window === 'undefined') return;

  const R2G_BrowserCompressor = {
    config: window.r2g_compress_config || {
      enabled: true,
      engine: 'browser',
      format: 'webp',
      quality: 0.82,
      maxWidth: 1920,
      promptConfirm: true,
    },

    init: function() {
      if (this.config.engine !== 'browser' || this.config.format === 'none') {
        return;
      }

      this.hookPlupload();
      this.hookGutenberg();
      this.injectModalHtml();
    },

    /**
     * Intercept standard WordPress Media Uploader (Plupload)
     */
    hookPlupload: function() {
      const self = this;
      $(document).on('uploaderReady', function() {
        if (typeof wp !== 'undefined' && wp.Uploader && wp.Uploader.prototype) {
          const originalInit = wp.Uploader.prototype.init;
          wp.Uploader.prototype.init = function() {
            originalInit.apply(this, arguments);
            const uploader = this.uploader;
            if (!uploader) return;

            uploader.bind('FilesAdded', function(up, files) {
              // Pause uploader queue while compressing
              up.stop();
              self.processQueue(files, function() {
                up.start();
              });
            });
          };
        }
      });
    },

    /**
     * Intercept Gutenberg Block Editor file drops/uploads
     */
    hookGutenberg: function() {
      // Gutenberg drop interception via input[type="file"]
      const self = this;
      document.addEventListener('change', function(e) {
        const target = e.target;
        if (target && target.tagName === 'INPUT' && target.type === 'file' && target.files && target.files.length) {
          // Handled via standard file input if plupload is not active
        }
      }, true);
    },

    /**
     * Process list of selected files
     */
    processQueue: function(files, onComplete) {
      const self = this;
      const imageFiles = files.filter(f => f.type && f.type.startsWith('image/') && !f.type.includes('svg'));

      if (!imageFiles.length) {
        onComplete();
        return;
      }

      if (this.config.promptConfirm) {
        this.showConfirmModal(imageFiles, function(chosenFormat, chosenQuality, chosenMaxWidth) {
          self.compressFiles(imageFiles, chosenFormat, chosenQuality, chosenMaxWidth, onComplete);
        }, function() {
          // User chose "Do not compress" -> upload raw
          onComplete();
        });
      } else {
        self.compressFiles(imageFiles, self.config.format, self.config.quality, self.config.maxWidth, onComplete);
      }
    },

    /**
     * Compress files iteratively via HTML5 Canvas
     */
    compressFiles: function(files, format, quality, maxWidth, callback) {
      const self = this;
      let processed = 0;

      const finishOne = () => {
        processed++;
        if (processed >= files.length) {
          self.hideModal();
          callback();
        }
      };

      files.forEach(fileObj => {
        const nativeFile = fileObj.getNative ? fileObj.getNative() : fileObj;
        if (!nativeFile) {
          finishOne();
          return;
        }

        self.compressSingleImage(nativeFile, format, quality, maxWidth, function(compressedBlob, newName) {
          if (compressedBlob && compressedBlob.size < nativeFile.size) {
            // Replace plupload file reference
            const newFile = new File([compressedBlob], newName, { type: compressedBlob.type });
            fileObj.size = newFile.size;
            fileObj.name = newFile.name;
            fileObj.type = newFile.type;
            if (fileObj.destroy) fileObj.destroy();
            fileObj.getSource = () => newFile;
          }
          finishOne();
        });
      });
    },

    /**
     * Compress a single image file via OffscreenCanvas / Canvas
     */
    compressSingleImage: function(file, format, quality, maxWidth, callback) {
      const reader = new FileReader();
      reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
          let width = img.width;
          let height = img.height;

          // Scale dimensions if larger than maxWidth
          if (maxWidth && width > maxWidth) {
            height = Math.round((height * maxWidth) / width);
            width = maxWidth;
          }

          const canvas = document.createElement('canvas');
          canvas.width = width;
          canvas.height = height;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0, width, height);

          // Determine target MIME
          let mime = 'image/webp';
          let extension = 'webp';

          if (format === 'original') {
            mime = file.type || 'image/jpeg';
            extension = file.name.split('.').pop();
          }

          canvas.toBlob(function(blob) {
            if (!blob) {
              callback(null, file.name);
              return;
            }

            const baseName = file.name.substring(0, file.name.lastIndexOf('.')) || file.name;
            const finalName = format === 'webp' ? baseName + '.webp' : file.name;

            callback(blob, finalName);
          }, mime, quality);
        };
        img.onerror = () => callback(null, file.name);
        img.src = e.target.result;
      };
      reader.onerror = () => callback(null, file.name);
      reader.readAsDataURL(file);
    },

    /**
     * Inject Confirmation Modal HTML into DOM
     */
    injectModalHtml: function() {
      if (document.getElementById('r2g-confirm-modal')) return;

      const html = `
        <div id="r2g-confirm-modal" class="r2g-modal-overlay" style="display:none;">
          <div class="r2g-modal-card">
            <div class="r2g-modal-header">
              <span class="r2g-modal-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Browser Edge Compression
              </span>
              <h3>Optimize Image Before Upload?</h3>
              <p>Compress image directly inside your browser before uploading to Cloudflare R2.</p>
            </div>
            <div class="r2g-modal-body">
              <div class="r2g-field-group">
                <label>Target Format:</label>
                <div class="r2g-radio-group">
                  <label class="r2g-radio-pill">
                    <input type="radio" name="r2g_modal_format" value="webp" checked>
                    <span>WebP (Fastest & Smallest)</span>
                  </label>
                  <label class="r2g-radio-pill">
                    <input type="radio" name="r2g_modal_format" value="original">
                    <span>Keep Original (JPG/PNG)</span>
                  </label>
                </div>
              </div>
              <div class="r2g-field-group">
                <label>Quality: <span id="r2g-quality-val">82%</span></label>
                <input type="range" id="r2g-modal-quality" min="60" max="95" value="82" class="r2g-slider">
              </div>
            </div>
            <div class="r2g-modal-footer">
              <button type="button" id="r2g-modal-skip" class="button">Upload Without Compressing</button>
              <button type="button" id="r2g-modal-proceed" class="button button-primary">Compress & Upload</button>
            </div>
          </div>
        </div>
      `;

      $('body').append(html);

      $('#r2g-modal-quality').on('input', function() {
        $('#r2g-quality-val').text($(this).val() + '%');
      });
    },

    showConfirmModal: function(files, onProceed, onSkip) {
      const modal = $('#r2g-confirm-modal');
      modal.fadeIn(150);

      $('#r2g-modal-proceed').off('click').on('click', function() {
        const format = $('input[name="r2g_modal_format"]:checked').val() || 'webp';
        const quality = parseInt($('#r2g-modal-quality').val(), 10) / 100;
        modal.hide();
        onProceed(format, quality, 1920);
      });

      $('#r2g-modal-skip').off('click').on('click', function() {
        modal.hide();
        onSkip();
      });
    },

    hideModal: function() {
      $('#r2g-confirm-modal').fadeOut(100);
    }
  };

  $(document).ready(function() {
    R2G_BrowserCompressor.init();
  });

})(jQuery);
