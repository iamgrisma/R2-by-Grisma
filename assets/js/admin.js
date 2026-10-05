/**
 * R2 by Grisma — Admin JS
 * Handles Test Connection, Media Library Row Actions, and Secret Masking.
 */
(function($) {
  'use strict';

  $(document).ready(function() {
    // 1. Test Connection
    $('#r2g-btn-test-connection').on('click', function(e) {
      e.preventDefault();
      const $btn = $(this);
      const $status = $('#r2g-test-status');

      $btn.prop('disabled', true).text('Testing connection...');
      $status.hide().removeClass('r2g-test-ok r2g-test-err').empty();

      $.ajax({
        url: window.ajaxurl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'r2g_test_connection',
          nonce: window.r2g_admin?.nonce || '',
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
          $btn.prop('disabled', false).text('Test Connection');
        }
      });
    });

    // 2. Secret Key Masking / Editing
    $('#r2g-btn-change-secret').on('click', function(e) {
      e.preventDefault();
      $('#r2g-secret-display').hide();
      $('#r2g-secret-input-wrap').show().find('input').focus();
    });

    // 3. Media Library Row Actions (Push, Pull, Delete Local)
    $(document).on('click', '.r2g-row-action', function(e) {
      e.preventDefault();
      const $link = $(this);
      const action = $link.data('action');
      const id = $link.data('id');
      const confirmMsg = $link.data('confirm');

      if (confirmMsg && !confirm(confirmMsg)) {
        return;
      }

      const originalText = $link.text();
      $link.text('Processing...');

      $.ajax({
        url: window.ajaxurl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: action,
          id: id,
          nonce: window.r2g_admin?.nonce || '',
        },
        success: function(response) {
          if (response.success) {
            window.location.reload();
          } else {
            alert(response.data?.message || 'Action failed.');
            $link.text(originalText);
          }
        },
        error: function() {
          alert('Network request failed.');
          $link.text(originalText);
        }
      });
    });
  });

})(jQuery);
