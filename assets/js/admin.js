/**
 * R2 by Grisma — Admin JS
 * Handles Test Connection, Copy CDN URL, Media Actions, Bulk Sync Engine, and Thumbnail Auto-healing.
 */
(function($) {
  'use strict';

  $(document).ready(function() {
    const nonce = window.r2g_admin?.nonce || '';
    const ajaxurl = window.r2g_admin?.ajax_url || window.ajaxurl || '/wp-admin/admin-ajax.php';

    // 1. Test Connection
    $('#r2g-btn-test-connection').on('click', function(e) {
      e.preventDefault();
      const $btn = $(this);
      const $status = $('#r2g-test-status');

      $btn.prop('disabled', true).text('Testing connection...');
      $status.hide().removeClass('r2g-test-ok r2g-test-err').empty();

      $.ajax({
        url: ajaxurl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'r2g_test_connection',
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
          $btn.prop('disabled', false).text('Test Connection & Verify CDN');
        }
      });
    });

    // 2. Secret Key Masking / Editing
    $('#r2g-btn-change-secret').on('click', function(e) {
      e.preventDefault();
      $('#r2g-secret-display').hide();
      $('#r2g-secret-input-wrap').show().find('input').focus();
    });

    // 3. Media Actions (Push, Restore, Delete Local, Delete from R2)
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
      $btn.prop('disabled', true).text('...');

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
            window.location.reload();
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

    // 4. One-Click Copy CDN URL
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

    // 5. Bulk Sync Engine
    let syncPaused = false;
    let syncCancelled = false;

    $('#r2g-btn-start-sync').on('click', function(e) {
      e.preventDefault();
      syncPaused = false;
      syncCancelled = false;

      const $startBtn = $(this);
      const $pauseBtn = $('#r2g-btn-pause-sync');
      const $cancelBtn = $('#r2g-btn-cancel-sync');
      const $progBox = $('#r2g-sync-progress-box');
      const $progFill = $('#r2g-sync-progress-fill');
      const $progText = $('#r2g-sync-status-text');
      const $progPct = $('#r2g-sync-percentage');

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
            const pct = total > 0 ? Math.min(100, Math.round((synced / total) * 100)) : 100;

            // Update stats cards live
            $('#r2g-stat-synced').text(stats.synced || 0);
            $('#r2g-stat-cloud').text(stats.cloud_only || 0);
            $('#r2g-stat-local').text(stats.local_only || 0);

            $progFill.css('width', pct + '%');
            $progPct.text(pct + '%');
            $progText.text('Synced ' + synced + ' of ' + total + ' media items...');

            if (res.data.done || !res.data.remaining) {
              $progFill.css('width', '100%');
              $progPct.text('100%');
              $progText.text('All media successfully synced to Cloudflare R2!');
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

    // 6. Maintenance & Legacy Import
    $('#r2g-btn-import-legacy').on('click', function(e) {
      e.preventDefault();
      const $btn = $(this);
      const $status = $('#r2g-import-status');

      $btn.prop('disabled', true).text('Importing...');
      $status.text('Scanning attachments and legacy records...');

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
            $status.css('color', '#059669').text(res.data.message || 'Import successful!');
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
      $status.text('Verifying local files and database index...');

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
            $status.css('color', '#059669').text(res.data.message || 'Index refreshed!');
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

    // 7. Thumbnail Auto-healing: If an image fails in admin list table, fallback to full CDN image
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
