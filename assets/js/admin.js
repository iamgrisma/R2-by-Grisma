/**
 * R2 by Grisma — Admin JS
 * Handles Live Bucket Discovery, Pre-Save Live Connection Test, Media Actions, Bulk Sync, and Import.
 */
(function($) {
  'use strict';

  $(document).ready(function() {
    const nonce = window.r2g_admin?.nonce || '';
    const ajaxurl = window.r2g_admin?.ajax_url || window.ajaxurl || '/wp-admin/admin-ajax.php';

    // Helper: Get active secret key (typed or empty if unchanged)
    function getSecretKeyVal() {
      if ($('#r2g_secret_key').is(':visible')) {
        return $('#r2g_secret_key').val() || '';
      }
      return '';
    }

    // Helper: Get active bucket name (select or input)
    function getBucketVal() {
      if ($('#r2g_bucket_select').is(':visible') && $('#r2g_bucket_select').val()) {
        return $('#r2g_bucket_select').val();
      }
      return $('#r2g_bucket').val() || '';
    }

    // 1. Live Bucket Discovery
    $('#r2g-btn-fetch-buckets').on('click', function(e) {
      e.preventDefault();
      const $btn = $(this);
      const $status = $('#r2g-fetch-status');
      const accountId = $('#r2g_account_id').val()?.trim();
      const accessKey = $('#r2g_access_key').val()?.trim();
      const secretKey = getSecretKeyVal();

      if (!accountId || !accessKey) {
        $status.css('color', '#dc2626').text('Please enter Account ID and Access Key ID first.');
        return;
      }

      $btn.prop('disabled', true).text('Discovering...');
      $status.css('color', '#64748b').text('Connecting to Cloudflare R2 S3 API...');

      $.ajax({
        url: ajaxurl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'r2g_fetch_buckets',
          account_id: accountId,
          access_key: accessKey,
          secret_key: secretKey,
          nonce: nonce,
        },
        success: function(res) {
          if (res.success && res.data?.buckets && res.data.buckets.length > 0) {
            const buckets = res.data.buckets;
            const $select = $('#r2g_bucket_select');
            const currentBucket = $('#r2g_bucket').val()?.trim();

            $select.empty().append('<option value="">-- Select Discovered Bucket --</option>');
            let matched = false;

            buckets.forEach(function(b) {
              const selected = (b === currentBucket) ? 'selected' : '';
              if (b === currentBucket) matched = true;
              $select.append('<option value="' + b + '" ' + selected + '>' + b + '</option>');
            });

            if (!matched && buckets.length === 1) {
              $select.val(buckets[0]);
              $('#r2g_bucket').val(buckets[0]);
            }

            $('#r2g_bucket').hide();
            $select.show();

            // Auto-suggest custom domain if empty
            const chosen = $select.val() || buckets[0];
            const currentDomain = $('#r2g_custom_domain').val()?.trim();
            if (!currentDomain && chosen === 'topnepali') {
              $('#r2g_custom_domain').val('https://objects.topnepali.com');
              $('#r2g-domain-hint').html('<strong>Auto-suggested:</strong> https://objects.topnepali.com (Connected to bucket ' + chosen + ')');
            }

            $status.css('color', '#059669').html('<strong>✓ ' + res.data.message + '</strong> (Selected: <code>' + (chosen || 'none') + '</code>)');
          } else {
            $status.css('color', '#dc2626').html(res.data?.message || res.data?.error || 'Could not discover buckets. Verify credentials or enter bucket name manually.');
          }
        },
        error: function(xhr, status, error) {
          $status.css('color', '#dc2626').text('Discovery failed: ' + error);
        },
        complete: function() {
          $btn.prop('disabled', false).text('Discover Buckets');
        }
      });
    });

    // Bucket select change sync
    $('#r2g_bucket_select').on('change', function() {
      const val = $(this).val();
      $('#r2g_bucket').val(val);
      const currentDomain = $('#r2g_custom_domain').val()?.trim();
      if (!currentDomain && val === 'topnepali') {
        $('#r2g_custom_domain').val('https://objects.topnepali.com');
      }
    });

    // 2. Pre-Save Live Test Connection
    $('#r2g-btn-test-connection').on('click', function(e) {
      e.preventDefault();
      const $btn = $(this);
      const $status = $('#r2g-test-status');

      const accountId = $('#r2g_account_id').val()?.trim();
      const accessKey = $('#r2g_access_key').val()?.trim();
      const secretKey = getSecretKeyVal();
      const bucket = getBucketVal();
      const customDomain = $('#r2g_custom_domain').val()?.trim();

      if (!accountId || !accessKey) {
        $status.show().addClass('r2g-test-err').html('<strong>Missing:</strong> Please enter Account ID and Access Key ID.');
        return;
      }
      if (!bucket) {
        $status.show().addClass('r2g-test-err').html('<strong>Missing:</strong> Please select or enter an R2 Bucket name.');
        return;
      }

      $btn.prop('disabled', true).text('Testing connection (live)...');
      $status.hide().removeClass('r2g-test-ok r2g-test-err').empty();

      $.ajax({
        url: ajaxurl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'r2g_test_connection',
          account_id: accountId,
          access_key: accessKey,
          secret_key: secretKey,
          bucket: bucket,
          custom_domain: customDomain,
          nonce: nonce,
        },
        success: function(response) {
          $status.show();
          if (response.success) {
            $status.addClass('r2g-test-ok').html('<strong>Connected:</strong> ' + response.data.message);
          } else {
            $status.addClass('r2g-test-err').html('<strong>Connection Failed:</strong> ' + (response.data.message || 'Unknown error'));
          }
        },
        error: function(xhr, status, error) {
          $status.show().addClass('r2g-test-err').html('<strong>Error:</strong> Request failed: ' + error);
        },
        complete: function() {
          $btn.prop('disabled', false).text('Test Connection & Verify CDN (Live)');
        }
      });
    });

    // 3. Secret Key Masking / Editing
    $('#r2g-btn-change-secret').on('click', function(e) {
      e.preventDefault();
      $('#r2g-secret-display').hide();
      $('#r2g-secret-input-wrap').show().find('input').focus();
    });

    // 4. Media Actions (Push, Restore, Delete Local, Delete from R2)
    $(document).on('click', '.r2g-row-action', function(e) {
      e.preventDefault();
      const $btn = $(this);
      const action = $btn.data('action');
      const id = $btn.data('id');
      const confirmMsg = $btn.data('confirm');

      if (confirmMsg && !confirm(confirmMsg)) {
        return;
      }

      const originalText = $btn.text();
      $btn.prop('disabled', true).text('Working...');

      $.ajax({
        url: ajaxurl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: action,
          id: id,
          nonce: nonce,
        },
        success: function(response) {
          if (response.success) {
            if ($btn.closest('.media-modal, .media-frame').length) {
              $btn.text('✓ Done!').css('background', '#059669');
              if (response.data?.url) {
                $btn.closest('.r2g-modal-meta-box').find('input[type="text"]').val(response.data.url);
              }
              setTimeout(function() {
                $btn.prop('disabled', false).text(originalText);
              }, 2500);
            } else {
              window.location.reload();
            }
          } else {
            alert(response.data?.message || 'Action failed.');
            $btn.prop('disabled', false).text(originalText);
          }
        },
        error: function() {
          alert('Network request failed.');
          $btn.prop('disabled', false).text(originalText);
        }
      });
    });

    // 5. One-Click Copy CDN URL
    $(document).on('click', '.r2g-btn-copy-cdn, .r2g-link-copy-cdn', function(e) {
      e.preventDefault();
      const $el = $(this);
      const url = $el.data('url');
      if (!url) return;

      const origText = $el.text();

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
          $el.text('Copied!');
          setTimeout(function() { $el.text(origText); }, 1800);
        });
      } else {
        const $temp = $('<input>');
        $('body').append($temp);
        $temp.val(url).select();
        document.execCommand('copy');
        $temp.remove();
        $el.text('Copied!');
        setTimeout(function() { $el.text(origText); }, 1800);
      }
    });

    // Toggle Re-compress options
    $(document).on('click', '.r2g-link-toggle-recompress', function(e) {
      e.preventDefault();
      $(this).next('.r2g-recompress-opts').slideToggle(120);
    });

    // Re-compress action from Media Library column
    $(document).on('click', '.r2g-btn-recompress', function(e) {
      e.preventDefault();
      const $btn = $(this);
      const id = $btn.data('id');
      const engine = $btn.data('engine') || 'server';
      const $cell = $('#r2g-compress-cell-' + id);

      const origText = $btn.text();
      $btn.prop('disabled', true).text('...');

      $.ajax({
        url: ajaxurl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'r2g_recompress_attachment',
          id: id,
          engine: engine,
          format: 'webp',
          nonce: nonce,
        },
        success: function(res) {
          if (res.success) {
            $cell.html(
              '<span class="r2g-badge r2g-badge-both" style="margin-bottom:4px;">✓ Optimal (' + (res.data.format || 'WEBP') + ')</span>' +
              '<div style="font-size:11px; color:#059669; font-weight:600; margin-top:2px;">' + (res.data.message || 'Optimized!') + '</div>'
            );
          } else {
            alert(res.data?.message || 'Compression failed.');
            $btn.prop('disabled', false).text(origText);
          }
        },
        error: function(xhr, status, error) {
          alert('Network request error: ' + error);
          $btn.prop('disabled', false).text(origText);
        }
      });
    });

    // 6. Bulk Sync Engine
    let syncPaused = false;
    let syncCancelled = false;

    // Live batch option changes for Bulk Sync
    const syncPresetQuality = {
      webp_balanced: 82,
      webp_high: 90,
      jpeg_balanced: 82,
      jpeg_high: 90,
      original_compressed: 82,
      raw_lossless: 100,
    };

    $('#r2g-sync-preset').on('change', function() {
      const preset = $(this).val();
      if (preset === 'keep_current' || preset === 'raw_lossless') {
        $('#r2g-sync-quality-wrap').hide();
      } else {
        $('#r2g-sync-quality-wrap').show();
      }
      const presetQuality = syncPresetQuality[preset];
      if (presetQuality) {
        $('#r2g-sync-quality-slider').val(presetQuality);
        $('#r2g-sync-quality-val').text(presetQuality + '%');
      }
    });

    $('#r2g-sync-quality-slider').on('input', function() {
      $('#r2g-sync-quality-val').text($(this).val() + '%');
    });

    $('#r2g-sync-engine').on('change', function() {
      const eng = $(this).val();
      const $notice = $('#r2g-sync-notice-text');
      if (eng === 'resmush') {
        $notice.text('reSmush.it Engine: Compresses via free reSmush API (auto-fallback to PHP GD/Imagick if >5MB or offline).');
      } else if (eng === 'none') {
        $notice.text('Lossless Engine: Pushes binary files directly to R2 without alteration.');
      } else {
        $notice.text('Server Engine: Media items are processed and pushed to R2 by PHP in safe batches of 5.');
      }
    });

    // Bulk Sync Execution
    $('#r2g-btn-start-sync').on('click', function(e) {
      e.preventDefault();
      if ($(this).is(':disabled')) return;

      syncPaused = false;
      syncCancelled = false;

      const $startBtn = $(this);
      const $pauseBtn = $('#r2g-btn-pause-sync');
      const $cancelBtn = $('#r2g-btn-cancel-sync');
      const $progBox = $('#r2g-sync-progress-box');
      const $progFill = $('#r2g-sync-progress-fill');
      const $progText = $('#r2g-sync-status-text');
      const $progPct = $('#r2g-sync-percentage');

      const syncPreset = $('#r2g-sync-preset').val() || 'keep_current';
      const syncQuality = parseInt($('#r2g-sync-quality-slider').val(), 10) || 82;
      const syncEngine = $('#r2g-sync-engine').val() || 'server';

      $startBtn.hide();
      $pauseBtn.show().text('Pause');
      $cancelBtn.show();
      $progBox.show();

      function processBatch() {
        if (syncPaused || syncCancelled) {
          return;
        }

        $.ajax({
          url: ajaxurl,
          type: 'POST',
          dataType: 'json',
          data: {
            action: 'r2g_bulk_sync_batch',
            batch_size: 5,
            format: 'keep',
            preset: syncPreset,
            quality: syncQuality,
            engine: syncEngine,
            nonce: nonce,
          },
          success: function(res) {
            if (!res.success) {
              $progText.text('Error: ' + (res.data?.message || 'Sync failed'));
              $pauseBtn.hide();
              $startBtn.show().text('Retry Bulk Sync');
              return;
            }

            const stats = res.data.stats || {};
            const total = stats.total_wp || 0;
            const synced = stats.synced || 0;
            const remaining = (typeof res.data.remaining_count !== 'undefined') ? res.data.remaining_count : Math.max(0, total - synced);
            const pct = total > 0 ? Math.min(100, Math.round((synced / total) * 100)) : 100;

            // Update stats cards live
            $('#r2g-stat-synced').text(stats.synced || 0);
            $('#r2g-stat-cloud').text(stats.cloud_only || 0);
            $('#r2g-stat-local').text(stats.local_only || 0);
            if (stats.verified_with_local !== undefined) {
              $('#r2g-verified-count').text(stats.verified_with_local);
              $('#r2g-btn-clean-verified').prop('disabled', stats.verified_with_local === 0);
            }

            $progFill.css('width', pct + '%');
            $progPct.text(pct + '% (' + synced + '/' + total + ')');
            $progText.text('Synced ' + synced + ' / ' + total + ' media items (' + remaining + ' remaining)...');

            if ((res.data.failed_count || 0) > 0 && (res.data.synced_count || 0) === 0 && remaining > 0) {
              $progText.text('Stopped: ' + res.data.failed_count + ' media items failed. Check the R2 connection, permissions, and local files, then retry. The sync will not loop on the same failed files.');
              $pauseBtn.hide();
              $cancelBtn.hide();
              $startBtn.show().text('Retry Bulk Sync');
              return;
            }

            if (res.data.done || !res.data.remaining) {
              $progFill.css('width', '100%');
              $progPct.text('100% (' + total + '/' + total + ')');
              $progText.text('All media successfully synced to Cloudflare R2! (0 remaining). Local copies preserved safely.');
              $pauseBtn.hide();
              $cancelBtn.hide();
              $startBtn.show().text('Sync Finished (Run Again)');
              return;
            }

            // Continue to next batch
            setTimeout(processBatch, 400);
          },
          error: function() {
            $progText.text('Network error during batch sync. Retrying in 3s...');
            setTimeout(processBatch, 3000);
          }
        });
      }

      processBatch();
    });

    $('#r2g-btn-pause-sync').on('click', function(e) {
      e.preventDefault();
      syncPaused = !syncPaused;
      $(this).text(syncPaused ? 'Resume' : 'Pause');
      if (!syncPaused) {
        $('#r2g-btn-start-sync').trigger('click');
      }
    });

    $('#r2g-btn-cancel-sync').on('click', function(e) {
      e.preventDefault();
      syncCancelled = true;
      syncPaused = true;
      $('#r2g-btn-pause-sync').hide();
      $('#r2g-btn-cancel-sync').hide();
      $('#r2g-btn-start-sync').show().text('Resume Bulk Sync');
      $('#r2g-sync-status-text').text('Sync paused by user.');
    });

    // Verified Local Storage Cleanup
    $('#r2g-btn-clean-verified').on('click', function(e) {
      e.preventDefault();
      if ($(this).is(':disabled')) return;
      if (!confirm('Delete local media files after checking each object directly on R2? This leaves R2 as the only copy. Do you have an independent backup, or are you prepared to restore these files from R2 if needed? Cancel if you need to make a backup first.')) {
        return;
      }

      const $btn = $(this);
      const $box = $('#r2g-clean-progress-box');
      const $fill = $('#r2g-clean-progress-fill');
      const $text = $('#r2g-clean-status-text');
      const $pct = $('#r2g-clean-percentage');

      $btn.prop('disabled', true);
      $box.show();
      $fill.css('width', '0%');
      $pct.text('0%');
      $text.text('Cleaning local copies in safe batches...');

      let totalToClean = parseInt($('#r2g-verified-count').text(), 10) || 1;
      let cleanedSoFar = 0;

      function cleanBatch() {
        $.ajax({
          url: ajaxurl,
          type: 'POST',
          dataType: 'json',
          data: {
            action: 'r2g_bulk_clean_verified_local',
            batch_size: 15,
            nonce: nonce,
          },
          success: function(res) {
            if (!res.success) {
              $text.text('Error: ' + (res.data?.message || 'Cleanup failed'));
              $btn.prop('disabled', false);
              return;
            }

            const cleanedBatch = res.data.cleaned_count || 0;
            const failedBatch = res.data.failed_count || 0;
            const remaining = res.data.remaining || 0;
            cleanedSoFar += cleanedBatch;

            const pct = (cleanedSoFar + remaining) > 0 ? Math.min(100, Math.round((cleanedSoFar / (cleanedSoFar + remaining)) * 100)) : 100;
            $fill.css('width', pct + '%');
            $pct.text(pct + '%');
            $text.text('Cleaned ' + cleanedSoFar + ' local files. ' + remaining + ' remaining...');

            if (failedBatch > 0 && cleanedBatch === 0) {
              $text.text('Stopped: R2 could not verify the remaining local files, or the server could not delete them. No further files were removed. Check the R2 connection, sync status, and permissions.');
              $btn.prop('disabled', false);
              return;
            }

            // Update verified count in UI
            $('#r2g-verified-count').text(remaining);

            // Update stats cards live
            if (res.data.stats) {
              $('#r2g-stat-synced').text(res.data.stats.synced || 0);
              $('#r2g-stat-cloud').text(res.data.stats.cloud_only || 0);
              $('#r2g-stat-local').text(res.data.stats.local_only || 0);
            }

            if (res.data.done || remaining === 0) {
              $fill.css('width', '100%');
              $pct.text('100%');
              $text.html('<strong style="color:#059669;">✓ Verified local cleanup complete! Web hosting disk space freed.</strong>');
              $btn.prop('disabled', true);
              return;
            }

            setTimeout(cleanBatch, 300);
          },
          error: function() {
            $text.text('Network glitch. Retrying cleanup in 3s...');
            setTimeout(cleanBatch, 3000);
          }
        });
      }

      cleanBatch();
    });

    // 7. Maintenance & Legacy Import
    $('#r2g-btn-import-legacy').on('click', function(e) {
      e.preventDefault();
      const $btn = $(this);
      const $status = $('#r2g-import-status');

      $btn.prop('disabled', true).text('Scanning & Importing...');
      $status.css('color', '#64748b').text('Scanning Media Cloud Sync (wpmcs_items) & attachments...');

      $.ajax({
        url: ajaxurl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'r2g_import_legacy',
          nonce: nonce,
        },
        success: function(res) {
          if (res.success) {
            $status.css('color', '#059669').html('<strong>✓ ' + res.data.message + '</strong>');
            if (res.data.stats) {
              $('#r2g-stat-synced').text(res.data.stats.synced || 0);
              $('#r2g-stat-cloud').text(res.data.stats.cloud_only || 0);
              $('#r2g-stat-local').text(res.data.stats.local_only || 0);
            }
          } else {
            $status.css('color', '#dc2626').text(res.data?.message || 'Import failed.');
          }
        },
        error: function() {
          $status.css('color', '#dc2626').text('Network request failed.');
        },
        complete: function() {
          $btn.prop('disabled', false).text('Import Existing Offloaded Media');
        }
      });
    });

    $('#r2g-btn-reindex').on('click', function(e) {
      e.preventDefault();
      const $btn = $(this);
      const $status = $('#r2g-reindex-status');

      $btn.prop('disabled', true).text('Scanning...');
      $status.css('color', '#64748b').text('Verifying local files and database index...');

      $.ajax({
        url: ajaxurl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'r2g_reindex_media',
          nonce: nonce,
        },
        success: function(res) {
          if (res.success) {
            $status.css('color', '#059669').html('<strong>✓ ' + res.data.message + '</strong>');
            if (res.data.stats) {
              $('#r2g-stat-synced').text(res.data.stats.synced || 0);
              $('#r2g-stat-cloud').text(res.data.stats.cloud_only || 0);
              $('#r2g-stat-local').text(res.data.stats.local_only || 0);
            }
          } else {
            $status.css('color', '#dc2626').text(res.data?.message || 'Re-index failed.');
          }
        },
        error: function() {
          $status.css('color', '#dc2626').text('Network request failed.');
        },
        complete: function() {
          $btn.prop('disabled', false).text('Scan & Refresh Index');
        }
      });
    });

    // 8. Quality slider and number input 2-way sync
    $('#r2g_compress_quality_slider').on('input', function() {
      $('#r2g_compress_quality').val($(this).val());
    });
    $('#r2g_compress_quality').on('input', function() {
      $('#r2g_compress_quality_slider').val($(this).val());
    });

    // 9. Thumbnail Auto-healing: Fallback from broken thumbnail size to full CDN image
    $('table.media img, .media-icon img').on('error', function() {
      const $img = $(this);
      const src = $img.attr('src');
      if (src && src.match(/-\d+x\d+\.(jpg|jpeg|png|webp|gif)$/i)) {
        const fullSrc = src.replace(/-\d+x\d+(\.[a-z]+)$/i, '$1');
        if (fullSrc !== src) {
          $img.attr('src', fullSrc);
        }
      }
    });

  });
})(jQuery);
