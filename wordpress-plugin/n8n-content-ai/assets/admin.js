/* n8n-CI Content AI — admin script */
(function ($) {
  'use strict';

  function nonce() { return $('#ncai-nonce').val(); }

  function post(action, data) {
    return $.ajax({
      url: ajaxurl,
      method: 'POST',
      data: Object.assign({ action: action, nonce: nonce() }, data || {}),
    });
  }

  function badge(ok, text) {
    return '<span class="ncai-badge ' + (ok ? 'ok' : 'bad') + '">' + text + '</span>';
  }

  // ===================== Settings =====================
  $(document).on('click', '#ncai-save', function () {
    var $btn = $(this).prop('disabled', true);
    post('ncai_save_settings', {
      api_url: $('#ncai-api-url').val(),
      api_key: $('#ncai-api-key').val(),
      min_words: $('#ncai-min-words').val(),
      language: $('#ncai-language').val(),
      company_profile: $('#ncai-company').val(),
    }).done(function (res) {
      var st = res.data && res.data.status ? res.data.status : {};
      var api = st.data || {};
      var html = '<p>' + (res.data.message || '') + '</p>';
      if (typeof api.valid !== 'undefined') {
        html += '<p>Koneksi API: ' + badge(api.valid, api.valid ? 'Valid' : 'Gagal') + '</p>';
      }
      if (typeof api.ai_credential_ready !== 'undefined') {
        html += '<p>Credential AI default: ' + badge(api.ai_credential_ready, api.ai_credential_ready ? 'Siap' : 'Belum ada') + '</p>';
      }
      if (api.expires_at) {
        var expired = new Date(api.expires_at.replace(' ', 'T')) < new Date();
        html += '<p>API key berlaku sampai: ' + badge(!expired, api.expires_at) + '</p>';
      }
      if (api.workspace_name) {
        html += '<p>Workspace: <strong>' + $('<i>').text(api.workspace_name).html() + '</strong></p>';
      }
      if (st.message && !st.ok) {
        html += '<p style="color:#a00">' + $('<i>').text(st.message).html() + '</p>';
      }
      $('#ncai-status-box').html(html);
    }).fail(function () {
      alert('Gagal menyimpan pengaturan.');
    }).always(function () { $btn.prop('disabled', false); });
  });

  // Muat status saat buka halaman settings
  if ($('#ncai-app').length) {
    post('ncai_status', {}).done(function (res) {
      var api = res.data && res.data.api ? res.data.api : {};
      var d = api.data || {};
      if (!d.valid && api.message) {
        $('#ncai-status-box').html('<p style="color:#a00">' + $('<i>').text(api.message).html() + '</p>');
      } else if (d.valid) {
        $('#ncai-status-box').html('<p>Koneksi: ' + badge(true, 'OK') + ' — Credential AI: ' +
          badge(!!d.ai_credential_ready, d.ai_credential_ready ? 'Siap' : 'Belum ada default') + '</p>');
      }
    });
  }

  // ===================== Scan =====================
  var scanPosts = [];

  $(document).on('click', '#ncai-scan-btn', function () {
    var $btn = $(this).prop('disabled', true).text('Memuat…');
    post('ncai_scan', {}).done(function (res) {
      scanPosts = (res.data.posts || []).filter(function (p) { return p.type !== 'tag' && p.type !== 'category'; });
      renderScan();
    }).always(function () { $btn.prop('disabled', false).text('Muat Daftar Konten'); });
  });

  function renderScan(filterShortOnly) {
    var rows = '';
    $.each(scanPosts, function (_, p) {
      if (filterShortOnly && !p.short) return;
      rows += '<tr data-id="' + p.id + '">' +
        '<td>' + p.id + '</td><td>' + $('<i>').text(p.title).html() + '</td><td>' + p.type + '</td>' +
        '<td>' + p.words + '</td>' +
        '<td>' + badge(!p.short, p.short ? 'Kurang' : 'Cukup') + '</td>' +
        '<td><button class="button ncai-run" data-id="' + p.id + '">Jalankan</button></td></tr>';
    });
    $('#ncai-table tbody').html(rows);
  }

  function runOne(postId, actionKind, $row, done) {
    $row.find('.ncai-run').prop('disabled', true);
    post('ncai_continue_one', { post_id: postId, action_kind: actionKind })
      .done(function (res) {
        return post('ncai_update_post', { post_id: postId, content: res.data.content });
      })
      .done(function (res) {
        var idx = scanPosts.findIndex(function (p) { return String(p.id) === String(postId); });
        if (idx >= 0) { scanPosts[idx].words = res.data.word_count; scanPosts[idx].short = false; }
        done(true);
      })
      .fail(function () { done(false); });
  }

  // Jalankan Satu
  $(document).on('click', '.ncai-run', function () {
    runOne($(this).data('id'), $('#ncai-action').val(), $(this).closest('tr'), function (ok) {
      renderScan($('#ncai-only-short').is(':checked'));
      if (!ok) alert('Satu item gagal diproses.');
    });
  });

  // Jalankan Bulk: item bertanda "Kurang", dengan delay anti rate-limit
  $(document).on('click', '#ncai-bulk', function () {
    var action = $('#ncai-action').val();
    var targets = scanPosts.filter(function (p) { return p.short; });
    if (!targets.length) { alert('Semua konten sudah memenuhi target kata.'); return; }
    if (!window.confirm('Proses ' + targets.length + ' konten? Ini memakai kuota AI.')) return;

    var i = 0;
    function step() {
      if (i >= targets.length) {
        $('#ncai-progress').text('Selesai.');
        renderScan(false);
        return;
      }
      var t = targets[i++];
      $('#ncai-progress').text('Memproses ' + i + '/' + targets.length + ': ' + t.title);
      var $row = $('#ncai-table tr[data-id="' + t.id + '"]');
      runOne(t.id, action, $row, function (ok) { setTimeout(step, 3000); });
    }
    step();
  });

  // Tambahkan tombol bulk setelah tabel
  $('#ncai-scan h1').after('<p><label><input type="checkbox" id="ncai-only-short" checked /> Hanya tampilkan yang kurang</label> ' +
    '<button class="button" id="ncai-bulk">Jalankan Bulk</button></p>');

  // ===================== Create =====================
  $(document).on('click', '#ncai-generate', function () {
    var $btn = $(this).prop('disabled', true).text('Menghasilkan…');
    post('ncai_generate', {
      topic: $('#ncai-topic').val(),
      content_type: $('#ncai-type').val(),
      min_words: $('#ncai-target').val(),
      instructions: $('#ncai-instructions').val(),
    }).done(function (res) {
      var d = res.data;
      $('#ncai-preview').html(
        '<div class="ncai-preview-box">' + (d.content || '') + '</div>' +
        '<p>Jumlah kata: <strong>' + (d.word_count || 0) + '</strong> · Model: ' + (d.model || '-') + '</p>' +
        '<p><button class="button" id="ncai-draft">Simpan Draft</button> ' +
        '<button class="button button-primary" id="ncai-publish">Publish</button></p>'
      );
    }).fail(function (xhr) {
      alert((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Generate gagal.');
    }).always(function () { $btn.prop('disabled', false).text('Generate Preview'); });
  });

  function saveGenerated(status) {
    post('ncai_publish', {
      title: $('#ncai-topic').val(),
      content: $('.ncai-preview-box').html(),
      post_type: $('#ncai-type').val(),
      status: status,
    }).done(function (res) {
      window.alert('Tersimpan. Edit: ' + res.data.edit_link);
    });
  }
  $(document).on('click', '#ncai-draft', function () { saveGenerated('draft'); });
  $(document).on('click', '#ncai-publish', function () { saveGenerated('publish'); });

  // ===================== Continue =====================
  $(document).on('click', '#ncai-run-one', function () {
    var $btn = $(this).prop('disabled', true);
    var postId = $('#ncai-post-id').val();
    post('ncai_continue_one', { post_id: postId, action_kind: $('#ncai-c-action').val() })
      .done(function (res) {
        $('#ncai-diff').html(
          '<div class="ncai-preview-box">' + (res.data.content || '') + '</div>' +
          '<p>Kata hasil: ' + res.data.word_count + '</p>' +
          '<button class="button button-primary" id="ncai-apply">Terapkan ke Post</button>'
        );
      })
      .fail(function (xhr) {
        alert((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Gagal.');
      })
      .always(function () { $btn.prop('disabled', false); });
  });

  $(document).on('click', '#ncai-apply', function () {
    post('ncai_update_post', { post_id: $('#ncai-post-id').val(), content: $('#ncai-diff .ncai-preview-box').html() })
      .done(function () { window.alert('Post diperbarui.'); });
  });
})(jQuery);
