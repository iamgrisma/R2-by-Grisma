/**
 * R2 by Grisma — Universal Client-Side Image Compression & Format Conversion Engine
 *
 * Intercepts image uploads across ALL WordPress pipelines:
 * 1. Gutenberg Block Editor (drag & drop from PC, image blocks, cover, gallery)
 * 2. Media Library Multi-File Plupload (upload.php, media-new.php, wp.media modal)
 * 3. Browser Built-In Single File Uploader (media-new.php form)
 *
 * Converts to WebP in browser Canvas, applies quality/max-width settings,
 * provides pre-upload confirmation modal with clean Cancel, and pushes optimized files.
 *
 * @package R2_By_Grisma
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
      this.hookBrowserForm();
      this.injectModalHtml();
    },

    /**
     * 1. Intercept Gutenberg Block Editor media uploads (Drag & Drop, Image blocks, Replace)
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

            if (!imageFiles.length || self.config.engine !== 'browser' || self.config.format === 'none') {
              return origUploadMedia.apply(this, arguments);
            }

            const proceed = function(processedFiles) {
              options.filesList = processedFiles;
              return origUploadMedia.call(this, options);
            };

            const cancel = function() {
              self.hideModal();
              if (typeof options.onError === 'function') {
                options.onError('Upload cancelled by user.');
              }
            };

            if (self.config.promptConfirm) {
              self.showConfirmModal(imageFiles, function(chosenFormat, chosenQuality, chosenMaxWidth) {
                self.compressNativeFiles(rawFiles, chosenFormat, chosenQuality, chosenMaxWidth, proceed);
              }, function() {
                // Upload without compressing
                proceed(rawFiles);
              }, cancel);
            } else {
              self.compressNativeFiles(rawFiles, self.config.format, self.config.quality, self.config.maxWidth, proceed);
            }
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
     * 2. Intercept standard WordPress Media Uploader (Plupload - upload.php, media-new.php, modal)
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

            uploader.bind('FilesAdded', function(up, files) {
              up.stop();
              self.processQueue(up, files, function() {
                up.start();
              });
            });
          };
        }
      };

      patchUploader();
      $(document).on('uploaderReady', patchUploader);
    },

    /**
     * 3. Intercept browser built-in single file uploader form (media-new.php?browser-uploader)
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
        if (self.config.engine !== 'browser' || self.config.format === 'none') return;

        e.preventDefault();

        const proceed = function(finalFile) {
          form._r2g_submitting = true;
          if (typeof DataTransfer !== 'undefined') {
            const dt = new DataTransfer();
            dt.items.add(finalFile);
            fileInput.files = dt.files;
          }
          form.submit();
        };

        const cancel = function() {
          self.hideModal();
          fileInput.value = '';
        };

        if (self.config.promptConfirm) {
          self.showConfirmModal([file], function(chosenFormat, chosenQuality, chosenMaxWidth) {
            self.compressSingleImage(file, chosenFormat, chosenQuality, chosenMaxWidth, function(blob, newName) {
              if (blob) {
                const newFile = new File([blob], newName, { type: blob.type });
                proceed(newFile);
              } else {
                proceed(file);
              }
            });
          }, function() {
            proceed(file);
          }, cancel);
        } else {
          self.compressSingleImage(file, self.config.format, self.config.quality, self.config.maxWidth, function(blob, newName) {
            if (blob) {
              const newFile = new File([blob], newName, { type: blob.type });
              proceed(newFile);
            } else {
              proceed(file);
            }
          });
        }
      });
    },

    /**
     * Process list of Plupload selected files
     */
    processQueue: function(up, files, onComplete) {
      const self = this;
      const imageFiles = files.filter(f => f.type && f.type.startsWith('image/') && !f.type.includes('svg'));

      if (!imageFiles.length) {
        onComplete();
        return;
      }

      const onCancel = function() {
        imageFiles.forEach(f => {
          if (up && up.removeFile) up.removeFile(f);
        });
        self.hideModal();
      };

      if (this.config.promptConfirm) {
        this.showConfirmModal(imageFiles, function(chosenFormat, chosenQuality, chosenMaxWidth) {
          self.compressPluploadFiles(imageFiles, chosenFormat, chosenQuality, chosenMaxWidth, onComplete);
        }, function() {
          onComplete();
        }, onCancel);
      } else {
        self.compressPluploadFiles(imageFiles, self.config.format, self.config.quality, self.config.maxWidth, onComplete);
      }
    },

    /**
     * Compress native File objects (used by Gutenberg)
     */
    compressNativeFiles: function(files, format, quality, maxWidth, callback) {
      const self = this;
      const results = [];
      let done = 0;

      files.forEach(function(f, idx) {
        if (!f.type || f.type.indexOf('image/') !== 0 || f.type.indexOf('svg') !== -1) {
          results[idx] = f;
          done++;
          if (done >= files.length) {
            self.hideModal();
            callback(results);
          }
          return;
        }

        self.compressSingleImage(f, format, quality, maxWidth, function(blob, newName) {
          if (blob) {
            results[idx] = new File([blob], newName, { type: blob.type });
          } else {
            results[idx] = f;
          }
          done++;
          if (done >= files.length) {
            self.hideModal();
            callback(results);
          }
        });
      });
    },

    /**
     * Compress Plupload file wrappers
     */
    compressPluploadFiles: function(files, format, quality, maxWidth, callback) {
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
     * Compress a single image file via HTML5 Canvas
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
            const cleanBase = baseName.replace(/-scaled$/i, '');
            const finalName = format === 'webp' ? cleanBase + '.webp' : file.name;

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
        <div id="r2g-confirm-modal" class="r2g-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:9999999;">
          <div class="r2g-modal-card">
            <div class="r2g-modal-header">
              <span class="r2g-modal-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Browser Edge Compression
              </span>
              <h3>Optimize Image Before Upload?</h3>
              <p>Compress image directly inside your browser before uploading to Cloudflare R2.</p>
              <div id="r2g-modal-file-info" style="font-size:12px; color:#475569; margin-top:8px; font-weight:500; word-break:break-all;"></div>
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
              <button type="button" id="r2g-modal-cancel" class="button" style="color:#d63638;">Cancel</button>
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

    showConfirmModal: function(files, onProceed, onSkip, onCancel) {
      this.injectModalHtml();
      const modal = $('#r2g-confirm-modal');

      if (files && files.length) {
        const fileNames = files.map(f => f.name || 'image').join(', ');
        $('#r2g-modal-file-info').text('Files: ' + fileNames);
      }

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
    R2G_BrowserCompressor.init();
  });

})(jQuery);
