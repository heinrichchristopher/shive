/* shive – WebGUI client. Depends on jQuery + swal (both provided by the Unraid webGUI). */
var Shive = (function () {
  var API = '/plugins/shive/include/api.php';
  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
  function get(op, data) { return $.getJSON(API, $.extend({ op: op }, data || {})); }
  function token() {
    var t = window.SHIVE_CSRF || $('#shive-schedules').data('csrf') || (typeof csrf_token !== 'undefined' ? csrf_token : '');
    if (!t) console.warn('shive: no csrf token available on this page');
    return String(t);
  }
  function post(op, data) {
    return $.ajax({ url: API, type: 'POST', dataType: 'json', headers: { 'X-Shive-Csrf': token() },
      data: $.extend({ op: op, csrf_token: token() }, data || {}) });
  }
  function toast(msg, ok) { swal({ title: ok === false ? 'Error' : 'Shive', text: msg, type: ok === false ? 'error' : 'success', timer: ok === false ? undefined : 2500, showConfirmButton: ok === false }); }
  function confirm(title, text, cb, danger) { swal({ title: title, text: text, type: 'warning', showCancelButton: true, confirmButtonText: danger || 'Proceed', closeOnConfirm: true }, function (ok) { if (ok) cb(); }); }
  function fmtTs(t) { return t ? new Date(t * 1000).toLocaleString() : '–'; }
  function fmtIso(t) { return t ? new Date(t).toLocaleString() : '–'; }
  function bytes(b) { var u = ['B', 'K', 'M', 'G', 'T']; var i = 0; while (b >= 1024 && i < 4) { b /= 1024; i++; } return (Math.round(b * 10) / 10) + u[i]; }
  function badge(s) { return '<span class="shive-badge ' + esc(s) + '">' + esc(s) + '</span>'; }
  var datasets = [], pools = [], schedules = [];

  function loadInventory() {
    return get('datasets').then(function (r) { datasets = r.datasets; pools = r.pools; });
  }

  /* ------------------------------------------------------------ schedules */
  var sched = {
    init: function () { loadInventory().then(function () { sched.fillSelects(); sched.load(); }); },
    fillSelects: function () {
      var opts = datasets.map(function (d) { return '<option value="' + esc(d.name) + '">' + esc(d.name) + (d.encrypted ? ' 🔒' : '') + '</option>'; }).join('');
      $('#f-local-ds-list').html(opts);
      $('#f-datasets').html(datasets.map(function (d) { return '<label data-name="' + esc(d.name) + '"><input type="checkbox" class="f-ds" value="' + esc(d.name) + '" onchange="Shive.sched.dsCount()">' + esc(d.name) + (d.encrypted ? ' 🔒' : '') + '</label>'; }).join(''));
    },
    load: function () {
      var tgtSched = function (t) {
        return t.own_schedule ? '<br><span class="shive-hint">' + esc(t.frequency) + ' · ' + esc(t.cron_expr) + '</span>'
                              : '<br><span class="shive-hint">with snapshot</span>';
      };
      get('schedules').then(function (r) {
        schedules = r.schedules;
        $('#cron-warning').remove();
        if (!r.cron_active) $('#shive-schedules').prepend('<p id="cron-warning" class="shive-err">' +
          'Warning: no Shive entry found in /etc/cron.d/root – scheduled runs will not fire. ' +
          'Run <span class="shive-mono">update_cron</span> on the console, or re-save a schedule to retry.</p>');
        get('status').then(function (st) {
          var byId = {}; st.schedules.forEach(function (s) { byId[s.id] = s; });
          if (!schedules.length) { $('#sched-rows').html('<tr><td colspan="9">No schedules yet.</td></tr>'); return; }
          $('#sched-rows').html(schedules.map(function (s) {
            var x = byId[s.id] || {}, l = x.last, last = x.running ? badge('running') + ' ' + esc(x.running.phase) : (l ? badge(l.status) + ' ' + esc(fmtIso(l.finished)) : '–');
            if (x.messages && x.messages.length) last += '<br><span class="shive-hint">' + esc(x.messages.join(' · ')) + '</span>';
            return '<tr' + (s.enabled ? '' : ' style="opacity:.5"') + '><td><b>' + esc(s.name) + '</b> <span class="shive-mono shive-hint">#' + esc(s.id) + '</span>' + (s.enabled ? '' : '<br><span class="shive-hint">disabled</span>') + '</td>' +
              '<td class="shive-mono">' + esc(s.tag_prefix) + '…</td>' +
              '<td class="shive-mono">' + s.datasets.map(esc).join('<br>') + (s.recursive ? '<br><span class="shive-hint">+children</span>' : '') + '</td>' +
              '<td>' + esc(s.frequency) + '<br><span class="shive-mono shive-hint">' + esc(s.cron_expr) + '</span></td>' +
              '<td>' + (s.docker_aware ? 'yes' : 'no') + '</td>' +
              '<td class="shive-mono">' + (s.local_target.enabled ? esc(s.local_target.dataset) + tgtSched(s.local_target) : '–') + '</td>' +
              '<td class="shive-mono">' + (s.remote_target.enabled ? esc(s.remote_target.host + ':' + s.remote_target.dataset) + tgtSched(s.remote_target) : '–') + '</td>' +
              '<td>' + last + '</td>' +
              '<td><span class="buttons-spaced"><input type="button" value="Run" onclick="Shive.sched.run(\'' + esc(s.id) + '\',0)"> <input type="button" value="Dry run" onclick="Shive.sched.run(\'' + esc(s.id) + '\',1)"> <input type="button" value="Prune preview" onclick="Shive.sched.prunePreview(\'' + esc(s.id) + '\')"> <input type="button" value="Edit" onclick="Shive.sched.edit(\'' + esc(s.id) + '\')"> <input type="button" value="Delete" onclick="Shive.sched.del(\'' + esc(s.id) + '\')"></span></td></tr>';
          }).join(''));
        });
      });
    },
    selectedDs: function () { return $('.f-ds:checked').map(function () { return this.value; }).get(); },
    setDs: function (list) { $('.f-ds').each(function () { this.checked = list.indexOf(this.value) >= 0; }); sched.dsCount(); },
    dsCount: function () { var n = sched.selectedDs().length; $('#f-ds-count').text(n ? n + ' selected: ' + sched.selectedDs().join(', ') : 'none selected'); sched.excludeUI(); },
    // Only children of the selected sources can be excluded - anything else would be a typo the
    // backend rejects anyway, so don't offer it. Keeps ticks that are still valid after a change.
    excludeUI: function () {
      var srcs = sched.selectedDs(), rec = $('#f-recursive').is(':checked');
      var children = datasets.map(function (d) { return d.name; }).filter(function (n) {
        return srcs.some(function (s) { return n !== s && n.indexOf(s + '/') === 0; });
      });
      $('#f-excl-wrap').toggle(rec && children.length > 0);

      var render = function (id, list) {
        var was = $('#' + id + ' input:checked').map(function () { return this.value; }).get();
        $('#' + id).html(list.map(function (n) {
          return '<label><input type="checkbox" value="' + esc(n) + '"' + (was.indexOf(n) >= 0 ? ' checked' : '') +
                 (id === 'f-exclude' ? ' onchange="Shive.sched.excludeUI()"' : '') + '>' + esc(n) + '</label>';
        }).join('') || '<span class="shive-hint">no child datasets</span>');
      };
      render('f-exclude', children);
      // A dataset with no snapshot can't be sent anywhere, so offering it again per target would
      // suggest a choice that doesn't exist - drop those from the target lists entirely.
      var snapExcluded = sched.excluded('f-exclude');
      var sendable = children.filter(function (n) {
        return !snapExcluded.some(function (x) { return n === x || n.indexOf(x + '/') === 0; });
      });
      ['f-local-exclude', 'f-remote-exclude'].forEach(function (id) {
        render(id, sendable);
        if (!sendable.length) $('#' + id).html('<span class="shive-hint">nothing left to skip – every child dataset is already excluded from the snapshot</span>');
      });
    },
    excluded: function (id) { return $('#' + id + ' input:checked').map(function () { return this.value; }).get(); },
    setExcluded: function (id, list) {
      $('#' + id + ' input').each(function () { this.checked = (list || []).indexOf(this.value) >= 0; });
    },
    filterDs: function () { var q = $('#f-ds-filter').val().toLowerCase(); $('#f-datasets label').each(function () { $(this).toggle(!q || $(this).data('name').toLowerCase().indexOf(q) >= 0); }); },
    // show only the fields the selected mode actually uses (backend ignores the others anyway)
    retUI: function (loc) {
      var gfs = $('#f-ret-' + loc + '-mode').val() === 'gfs';
      $('.ret-gfs-' + loc).css('visibility', gfs ? '' : 'hidden');
      $('.ret-age-' + loc).css('visibility', gfs ? 'hidden' : '');
    },
    sendCfg: function (loc) {
      return { own_schedule: $('#f-' + loc + '-own').is(':checked'), frequency: $('#f-' + loc + '-frequency').val(),
               time: $('#f-' + loc + '-time').val(), weekday: $('#f-' + loc + '-weekday').val(),
               monthday: $('#f-' + loc + '-monthday').val(), cron: $('#f-' + loc + '-cron').val() };
    },
    sendUI: function (loc) {
      var on = $('#f-' + loc + '-own').is(':checked'), f = $('#f-' + loc + '-frequency').val();
      $('#f-' + loc + '-sched').toggle(on);
      $('#f-' + loc + '-time-wrap').toggle(f !== 'custom');
      $('#f-' + loc + '-weekday-wrap').toggle(f === 'weekly');
      $('#f-' + loc + '-monthday-wrap').toggle(f === 'monthly');
      $('#f-' + loc + '-cron-wrap').toggle(f === 'custom');
    },
    freqUI: function () {
      var f = $('#f-frequency').val();
      $('#f-time-wrap').toggle(f !== 'custom'); $('#f-weekday-wrap').toggle(f === 'weekly'); $('#f-monthday-wrap').toggle(f === 'monthly'); $('#f-cron-wrap').toggle(f === 'custom');
    },
    edit: function (id) {
      var s = id ? schedules.filter(function (x) { return x.id === id; })[0] : null;
      $('#sched-editor-title').text(s ? 'Edit schedule "' + s.name + '"' : 'New schedule'); $('#sched-errors').text('');
      $('#f-name').val(s ? s.name : ''); $('#sched-editor').data('edit-id', s ? s.id : '');
      $('#f-enabled').prop('checked', s ? s.enabled : true);
      $('#f-ds-filter').val(''); sched.filterDs(); sched.setDs(s ? s.datasets : []); $('#f-recursive').prop('checked', s ? s.recursive : true);
      $('#f-frequency').val(s ? s.frequency : 'daily'); $('#f-time').val(s ? s.time : '03:00'); $('#f-weekday').val(s ? s.weekday : 0); $('#f-monthday').val(s ? s.monthday : 1); $('#f-cron').val(s ? s.cron : '');
      $('#f-docker').prop('checked', s ? s.docker_aware : false); $('#f-linked').text('');
      sched.excludeUI();
      sched.setExcluded('f-exclude', s ? s.exclude_datasets : []);
      sched.excludeUI();   // again: the target lists are filtered by the snapshot exclusions just set
      sched.setExcluded('f-local-exclude', s ? s.local_target.exclude_datasets : []);
      sched.setExcluded('f-remote-exclude', s ? s.remote_target.exclude_datasets : []);
      $('#f-local-on').prop('checked', s ? s.local_target.enabled : false); $('#f-local-ds').val(s ? s.local_target.dataset : '');
      $('#f-remote-on').prop('checked', s ? s.remote_target.enabled : false);
      $('#f-remote-user').val(s ? s.remote_target.user : 'root'); $('#f-remote-host').val(s ? s.remote_target.host : ''); $('#f-remote-port').val(s ? s.remote_target.port : 22); $('#f-remote-ds').val(s ? s.remote_target.dataset : ''); $('#f-remote-result').text('');
      $('#f-local-parent-hint').hide(); $('#f-remote-parent-hint').hide();
      ['local', 'remote'].forEach(function (loc) {
        var t = s ? s[loc + '_target'] : null, d = loc === 'remote' ? 'weekly' : 'daily';
        $('#f-' + loc + '-own').prop('checked', t ? t.own_schedule : false);
        $('#f-' + loc + '-frequency').val(t ? t.frequency : d);
        $('#f-' + loc + '-time').val(t ? t.time : '04:00');
        $('#f-' + loc + '-weekday').val(t ? t.weekday : 0);
        $('#f-' + loc + '-monthday').val(t ? t.monthday : 1);
        $('#f-' + loc + '-cron').val(t ? t.cron : '');
        sched.sendUI(loc);
      });
      ['source', 'local', 'remote'].forEach(function (k) {
        var r = s ? s.retention[k] : { mode: 'gfs', days: 30, hourly: 0, daily: 7, weekly: 4, monthly: 3 };
        ['mode', 'days', 'hourly', 'daily', 'weekly', 'monthly'].forEach(function (f) { $('#f-ret-' + k + '-' + f).val(r[f] == null ? 0 : r[f]); });
        sched.retUI(k);
      });
      $('#f-excl').val(s ? s.exclude_props.join(',') : 'mountpoint,canmount,sharenfs,sharesmb'); $('#f-notify').prop('checked', s ? s.notify_success : true);
      sched.freqUI(); $('#sched-editor').show(); $('#prune-preview').hide();
      $('html,body').animate({ scrollTop: $('#sched-editor').offset().top - 80 }, 200);
    },
    collect: function () {
      var ret = {};
      ['source', 'local', 'remote'].forEach(function (k) { ret[k] = {}; ['mode', 'days', 'hourly', 'daily', 'weekly', 'monthly'].forEach(function (f) { ret[k][f] = $('#f-ret-' + k + '-' + f).val(); }); });
      return {
        id: $('#sched-editor').data('edit-id') || '', exclude_datasets: sched.excluded('f-exclude'),
        name: $('#f-name').val(), enabled: $('#f-enabled').is(':checked'), datasets: sched.selectedDs(), recursive: $('#f-recursive').is(':checked'),
        frequency: $('#f-frequency').val(), time: $('#f-time').val(), weekday: $('#f-weekday').val(), monthday: $('#f-monthday').val(), cron: $('#f-cron').val(),
        docker_aware: $('#f-docker').is(':checked'),
        local_target: $.extend({ enabled: $('#f-local-on').is(':checked'), dataset: $('#f-local-ds').val(), exclude_datasets: sched.excluded('f-local-exclude') }, sched.sendCfg('local')),
        remote_target: $.extend({ enabled: $('#f-remote-on').is(':checked'), user: $('#f-remote-user').val(), host: $('#f-remote-host').val(), port: $('#f-remote-port').val(), dataset: $('#f-remote-ds').val(), exclude_datasets: sched.excluded('f-remote-exclude') }, sched.sendCfg('remote')),
        retention: ret, exclude_props: $('#f-excl').val().split(','), notify_success: $('#f-notify').is(':checked')
      };
    },
    save: function () {
      // the id travels with the payload, so renaming is an ordinary save - no separate call
      post('schedule_save', { schedule: JSON.stringify(sched.collect()) }).done(function (r) {
        if (!r.ok) { $('#sched-errors').html(r.errors.map(esc).join('<br>')); return; }
        $('#sched-editor').hide(); toast('Schedule saved, cron updated.'); sched.load();
      }).fail(function (x) { var r = x.responseJSON || {}; $('#sched-errors').html((r.errors || [r.error || ('save failed: HTTP ' + x.status)]).map(esc).join('<br>')); });
    },
    nameOf: function (id) { var s = schedules.filter(function (x) { return x.id === id; })[0]; return s ? s.name : id; },
    del: function (id) {
      var name = sched.nameOf(id);
      confirm('Delete schedule "' + name + '"?', 'Existing snapshots are NOT deleted; only the schedule, its cron entry and its status.', function () {
        post('schedule_delete', { id: id }).done(function () { toast('Deleted.'); sched.load(); });
      }, 'Delete');
    },
    run: function (id, dry) {
      var go = function () { post('run', { id: id, dry_run: dry }).done(function () { toast((dry ? 'Dry run' : 'Run') + ' started in background – see History & Logs.'); setTimeout(sched.load, 3000); }); };
      if (dry) go(); else confirm('Run "' + sched.nameOf(id) + '" now?', 'Linked containers will be stopped briefly if Docker awareness is enabled.', go, 'Run now');
    },
    showLinked: function () {
      var c = sched.collect();
      if (!c.datasets.length) { $('#f-linked').text('select datasets first'); return; }
      $('#f-linked').text('scanning…');
      // op=linked applies the same docker_linked() rule the job itself uses - deliberately not
      // reimplemented here, so the preview can't disagree with what actually gets stopped
      get('linked', { datasets: JSON.stringify(c.datasets), recursive: c.recursive ? 1 : 0 }).done(function (r) {
        var names = (r.containers || []).map(function (ct) { return ct.name + (ct.running ? '' : ' (stopped)'); });
        $('#f-linked').html(names.length ? 'Linked: ' + names.map(esc).join(', ') : 'No containers found on these datasets – check the Containers tab.');
      }).fail(function () { $('#f-linked').text('could not determine linked containers'); });
    },
    // spec passed to the target_parent_* ops: 'local' target uses the dataset directly,
    // 'remote' builds the ssh spec from whatever is currently typed (mirrors testRemote())
    parentTarget: function (loc) {
      if (loc === 'local') return { target: 'local', dataset: $('#f-local-ds').val().trim() };
      return { target: 'ssh://' + ($('#f-remote-user').val() || 'root') + '@' + $('#f-remote-host').val() + ':' + ($('#f-remote-port').val() || 22) + '/' + $('#f-remote-ds').val().trim(),
               dataset: $('#f-remote-ds').val().trim() };
    },
    checkParent: function (loc) {
      var t = sched.parentTarget(loc), hint = $('#f-' + loc + '-parent-hint');
      if (!t.dataset || (loc === 'remote' && !$('#f-remote-host').val())) { hint.hide(); return; }
      get('target_parent_status', t).done(function (r) {
        if (!r.ok || r.exists) { hint.hide(); return; }
        hint.show().html('root <span class="shive-mono">' + esc(r.root) + '</span> does not exist yet – ' +
          '<a onclick="Shive.sched.createParent(\'' + loc + '\')">create it now</a>');
      }).fail(function () { hint.hide(); });   // e.g. remote unreachable - testRemote already covers that error
    },
    createParent: function (loc) {
      var t = sched.parentTarget(loc), hint = $('#f-' + loc + '-parent-hint');
      hint.text('creating…');
      post('target_parent_create', t).done(function (r) {
        hint.removeClass('shive-err').text('created ' + r.root);
        setTimeout(function () { hint.hide(); }, 3000);
      }).fail(function (x) { hint.addClass('shive-err').text((x.responseJSON || {}).error || 'create failed'); });
    },
    testRemote: function () {
      var spec = 'ssh://' + ($('#f-remote-user').val() || 'root') + '@' + $('#f-remote-host').val() + ':' + ($('#f-remote-port').val() || 22) + '/' + $('#f-remote-ds').val();
      $('#f-remote-result').text('testing…');
      get('test_remote', { spec: spec }).done(function (r) { $('#f-remote-result').html((r.ok ? '<span class="shive-ok">OK</span> ' : '<span class="shive-err">FAILED</span> ') + esc(r.output)); });
    },
    prunePreview: function (id) {
      $('#prune-preview').show().html('computing…');
      get('prune_preview', { id: id }).done(function (r) {
        var h = '<h3>Prune preview for "' + esc(sched.nameOf(id)) + '" (nothing is deleted)</h3>';
        $.each(r.preview, function (loc, p) {
          if (!p) { h += '<p><b>' + esc(loc) + '</b>: unavailable</p>'; return; }
          h += '<p><b>' + esc(loc) + '</b> – ' + esc(p.policy_summary) + ': keep ' + p.keep.length + ', destroy ' + p.destroy.length + '</p><ul class="shive-mono">';
          p.keep.forEach(function (k) { h += '<li class="shive-ok">keep ' + esc(k) + ' <span class="shive-hint">(' + esc(p.reasons[k]) + ')</span></li>'; });
          p.destroy.forEach(function (k) { h += '<li class="shive-err">destroy ' + esc(k) + '</li>'; });
          h += '</ul>';
        });
        $('#prune-preview').html(h + '<input type="button" value="Close" onclick="$(\'#prune-preview\').hide()">');
      });
    }
  };

  /* ------------------------------------------------------------ containers */
  var ctr = {
    load: function (refresh) {
      $('#ctr-msg').text(refresh ? 'scanning…' : '');
      get('containers', { refresh: refresh ? 1 : 0 }).then(function (r) {
        get('mappings').then(function (m) {
          var ov = m.mappings || {};
          var rows = Object.keys(r.containers).map(function (n) {
            var c = r.containers[n], o = ov[n] || {};
            return '<tr><td><b>' + esc(n) + '</b>' + (c.missing ? ' <span class="shive-err">(not found)</span>' : '') + '</td><td>' + (c.running ? 'running' : 'stopped') + '</td>' +
              '<td class="shive-mono">' + (c.override === 'ignore' ? '<i>ignored</i>' : c.datasets.map(esc).join('<br>') || '<span class="shive-err">unresolved</span>') + '</td>' +
              '<td class="shive-mono shive-hint">' + c.mounts.map(esc).join('<br>') + '</td>' +
              '<td><input type="text" class="ov-ds" data-name="' + esc(n) + '" value="' + esc((o.datasets || []).join(',')) + '" style="width:100%"></td>' +
              '<td><input type="checkbox" class="ov-ignore" data-name="' + esc(n) + '"' + (o.ignore ? ' checked' : '') + '></td></tr>';
          });
          $('#ctr-rows').html(rows.join('') || '<tr><td colspan="6">no containers</td></tr>');
          $('#ctr-msg').text('scanned ' + new Date(r.updated * 1000).toLocaleTimeString());
        });
      });
    },
    save: function () {
      var m = {};
      $('.ov-ds').each(function () { var v = $(this).val().split(',').map($.trim).filter(Boolean); if (v.length) m[$(this).data('name')] = { datasets: v }; });
      $('.ov-ignore:checked').each(function () { m[$(this).data('name')] = $.extend(m[$(this).data('name')] || {}, { ignore: true }); });
      post('mappings_save', { mappings: JSON.stringify(m) }).done(function () { toast('Overrides saved.'); ctr.load(true); });
    }
  };

  /* ------------------------------------------------------------ browser / restore */
  var br = {
    cur: { target: 'local', dataset: '', snapshot: '', root: '', rel: '', staged: null, remote: false },
    init: function () {
      loadInventory().then(function () {
        get('schedules').then(function (r) {
          schedules = r.schedules;   // fillDs() needs these to derive <root>/<basename> targets
          var t = '<option value="local">this server</option>';
          r.schedules.forEach(function (s) { if (s.remote_target.enabled) t += '<option value="' + esc(s.remote_target.spec) + '">' + esc(s.remote_target.host) + ' (' + esc(s.name) + ')</option>'; });
          $('#b-target').html(t).on('change', br.fillDs); br.fillDs();
        });
      });
    },
    fillDs: function () {
      var t = $('#b-target').val();
      if (t === 'local') { $('#b-dataset').html(datasets.map(function (d) { return '<option value="' + esc(d.name) + '">' + esc(d.name) + '</option>'; }).join('')); return; }
      // Remote: the spec's path is the backup ROOT - snapshots live in <root>/<basename> per
      // source dataset, so offer those, not the (always empty) root itself.
      var root = t.replace(/^ssh:\/\/[^\/]+\//, ''), opts = [];
      schedules.forEach(function (s) {
        if (!s.remote_target.enabled || s.remote_target.spec !== t) return;
        s.datasets.forEach(function (ds) { opts.push(root + '/' + ds.split('/').pop()); });
      });
      opts = opts.filter(function (v, i, a) { return a.indexOf(v) === i; });
      if (!opts.length) opts = [root];   // fall back to the root (e.g. schedule list not loaded)
      $('#b-dataset').html(opts.map(function (d) { return '<option value="' + esc(d) + '">' + esc(d) + '</option>'; }).join(''));
    },
    snaps: function () {
      br.cur.target = $('#b-target').val(); br.cur.dataset = $('#b-dataset').val();
      $('#b-rows').html('<tr><td colspan="6">loading…</td></tr>');
      get('snapshots', { target: br.cur.target, dataset: br.cur.dataset, all: $('#b-all').is(':checked') ? 1 : 0 }).done(function (r) {
        if (!r.snapshots.length) { $('#b-rows').html('<tr><td colspan="7">no snapshots</td></tr>'); return; }
        var isLocal = br.cur.target === 'local';
        $('#b-rows').html(r.snapshots.map(function (s) {
          var q = "'" + esc(s.full) + "'";
          return '<tr><td><button class="shive-star' + (s.important ? ' on' : '') + '" title="' +
              (s.important ? 'Important – excluded from pruning. Click to unmark.' : 'Mark as important (never pruned)') +
              '" onclick="Shive.br.star(' + q + ',' + (s.important ? 1 : 0) + ')">' + (s.important ? '★' : '☆') + '</button></td>' +
            '<td class="shive-mono">' + esc(s.name) + '</td><td>' + esc(s.schedule || '–') + '</td><td>' + fmtTs(s.creation) + '</td><td>' + bytes(s.used) + '</td><td>' + bytes(s.referenced) + '</td>' +
            '<td><span class="buttons-spaced"><input type="button" value="Browse" onclick="Shive.br.open(' + q + ')">' +
            (isLocal ? ' <input type="button" value="Restore dataset…" onclick="Shive.br.restoreDataset(' + q + ')">' : '') +
            ' <input type="button" value="Receive as new dataset…" onclick="Shive.br.dr(' + q + ')">' +
            ' <input type="button" value="Delete…" onclick="Shive.br.del(' + q + ')"></span></td></tr>';
        }).join(''));
      });
    },
    star: function (full, isOn) {
      post('snapshot_flag', { snapshot: full, target: br.cur.target, unset: isOn ? 1 : 0, recursive: 1 })
        .done(function () { br.snaps(); })
        .fail(function (x) { toast((x.responseJSON || {}).error || 'could not change the flag', false); });
    },
    del: function (full) {
      var rec = window.confirm('Delete ' + full + '\n\nOK = also delete the snapshot of the same name on child datasets (recommended for recursive schedules)\nCancel = only this one dataset - copies on child datasets are then swept by the next retention run');
      confirm('Delete snapshot?', full + (rec ? ' and its children' : '') + ' will be destroyed permanently. This cannot be undone.', function () {
        post('snapshot_delete', { snapshot: full, target: br.cur.target, recursive: rec ? 1 : 0, confirm: 1 })
          .done(function (r) { toast('Snapshot deleted.'); br.snaps(); })
          .fail(function (x) { var r = x.responseJSON || {}; $('#b-out').show(); $('#b-out-pre').text(r.output || r.error || 'delete failed'); toast('Delete failed – see output.', false); });
      }, 'Delete permanently');
    },
    open: function (full) {
      br.closeTree();
      post('stage', { snapshot: full, target: br.cur.target }).done(function (r) {
        if (!r.ok) { toast(r.error, false); return; }
        br.cur.snapshot = full; br.cur.root = r.path; br.cur.rel = ''; br.cur.staged = r.staged_id; br.cur.remote = r.remote;
        $('#b-tree-title').text(full + (r.staged_id ? ' (read-only clone, auto-cleanup)' : '')); $('#b-tree').show(); br.list();
      }).fail(function (x) { toast((x.responseJSON || {}).error || 'stage failed', false); });
    },
    closeTree: function () { if (br.cur.staged) post('unstage', { id: br.cur.staged }); br.cur.staged = null; $('#b-tree').hide(); },
    list: function () {
      var crumbs = '<a onclick="Shive.br.cd(\'\')">/</a>', acc = '';
      br.cur.rel.split('/').filter(Boolean).forEach(function (p) { acc += '/' + p; crumbs += ' / <a onclick="Shive.br.cd(\'' + esc(acc) + '\')">' + esc(p) + '</a>'; });
      $('#b-crumbs').html(crumbs);
      get('browse', { path: br.cur.root + br.cur.rel, target: br.cur.target }).done(function (r) {
        $('#b-tree-rows').html(r.entries.map(function (e) {
          var rel = br.cur.rel + '/' + e.name;
          if (e.child_dataset) return '<tr><td>📦 ' + esc(e.name) + ' <span class="shive-hint">child dataset <span class="shive-mono">' + esc(e.child_dataset) + '</span> – its data is in its own snapshots, not in this one</span></td><td></td><td></td>' +
            '<td><input type="button" value="Open its snapshots" onclick="Shive.br.jump(\'' + esc(e.child_dataset) + '\')"></td></tr>';
          return '<tr><td>' + (e.dir ? '<a onclick="Shive.br.cd(\'' + esc(rel) + '\')">📁 ' + esc(e.name) + '</a>' : '📄 ' + esc(e.name)) + '</td><td>' + (e.dir ? '' : bytes(e.size)) + '</td><td>' + fmtTs(e.mtime) + '</td>' +
            '<td><input type="button" value="Restore…" onclick="Shive.br.restoreDialog(\'' + esc(rel) + '\')"></td></tr>';
        }).join('') || '<tr><td colspan="4">empty</td></tr>');
      }).fail(function (x) { toast((x.responseJSON || {}).error || 'browse failed', false); });
    },
    cd: function (rel) { br.cur.rel = rel; br.list(); },
    jump: function (ds) { br.closeTree(); $('#b-target').val('local'); br.fillDs(); $('#b-dataset').val(ds); br.snaps(); },
    liveDest: function (rel) {
      var d = datasets.filter(function (x) { return x.name === br.cur.snapshot.split('@')[0]; })[0];
      return (d && d.mountpoint !== 'none' ? d.mountpoint : '/mnt/user/…') + rel;
    },
    restoreDialog: function (rel) {
      var dest = prompt('Restore "' + (rel || '/') + '" from ' + br.cur.snapshot + '\n\nDestination path on this server:', br.liveDest(rel));
      if (!dest) return;
      var mode = window.confirm('OK = restore AS COPY next to the destination (safe, "<dest>.shive-restore-<timestamp>")\nCancel = OVERWRITE the destination in place (asks again)') ? 'copy' : 'overwrite';
      var go = function (dry) {
        $('#b-out').show(); $('#b-out-pre').text('running…');
        post('restore_file', { snapshot: br.cur.snapshot, target: br.cur.target, path: rel, dest: dest, mode: mode, confirm: 1, dry_run: dry ? 1 : 0 })
          .done(function (r) { $('#b-out-pre').text(r.output); toast(r.ok ? 'Restore ' + (dry ? 'dry run ' : '') + 'finished.' : 'Restore failed – see output.', r.ok); });
      };
      if (mode === 'overwrite') confirm('OVERWRITE ' + dest + '?', 'Files in the destination will be replaced by the snapshot version (rsync -a, permissions/ownership/timestamps preserved). Run a dry run first?', function () { go(0); }, 'Overwrite now');
      else go(0);
    },
    restoreDataset: function (full) {
      var method = window.confirm('OK = COPY-BACK (rsync --delete from snapshot into the live dataset; keeps all snapshots)\nCancel = ROLLBACK (zfs rollback -r; instant, but DESTROYS every snapshot newer than this one)') ? 'rsync' : 'rollback';
      var typed = prompt('Full restore of\n' + full + '\nmethod: ' + method + '\n\nLinked containers will be stopped and restarted, a pre-restore snapshot is taken first.\nType RESTORE to confirm:');
      if (typed !== 'RESTORE') return;
      $('#b-out').show(); $('#b-out-pre').text('running – this can take a while…');
      post('restore_dataset', { snapshot: full, method: method, confirm: 1 }).done(function (r) { $('#b-out-pre').text(r.output); toast(r.ok ? 'Dataset restored.' : 'Restore failed – see output.', r.ok); });
    },
    dr: function (full) {
      var to = prompt('Receive backup snapshot\n' + full + '\ninto a NEW local dataset (must not exist yet), e.g. pin/appdata-restored:');
      if (!to) return;
      var rec = window.confirm('OK = include child datasets (zfs send -R)\nCancel = only this dataset');
      confirm('Receive into ' + to + '?', 'The live dataset is not touched. Afterwards swap via zfs rename.', function () {
        $('#b-out').show(); $('#b-out-pre').text('receiving…');
        post('restore_dr', { target: br.cur.target, snapshot: full, to: to, recursive: rec ? 1 : 0, confirm: 1 }).done(function (r) { $('#b-out-pre').text(r.output); toast(r.ok ? 'Received.' : 'Failed – see output.', r.ok); });
      }, 'Receive');
    }
  };

  /* ------------------------------------------------------------ logs / history */
  var log = {
    init: function () {
      get('history').done(function (r) {
        var h = '<div class="shive-hist">';
        $.each(r.history, function (id, x) {
          var l = x.last;
          h += '<div><h4>' + esc(x.name || id) + ' <span class="shive-mono shive-hint">#' + esc(id) + '</span> ' + (x.running ? badge('running') + ' ' + esc(x.running.phase) : (l ? badge(l.status) : '')) + '</h4>';
          if (l) {
            h += '<div class="shive-hint">finished ' + esc(fmtIso(l.finished)) + '</div><div class="shive-mono">' + esc(l.snapshot || '–') + '</div>';
            $.each(l.sends || {}, function (k, v) { h += '<div>' + esc(k) + ': ' + esc(v.status) + (v.finished ? ' ' + esc(fmtIso(v.finished)) : v.reason ? ' (' + esc(v.reason) + ')' : '') + '</div>'; });
            if (l.containers && l.containers.length) h += '<div class="shive-hint">containers: ' + l.containers.map(esc).join(', ') + '</div>';
            if (l.resume_failed && l.resume_failed.length) h += '<div class="shive-err">STILL DOWN: ' + l.resume_failed.map(esc).join(', ') + '</div>';
            (l.errors || []).forEach(function (e) { h += '<div class="shive-err">' + esc(e) + '</div>'; });
            (l.warnings || []).forEach(function (e) { h += '<div class="shive-warn">' + esc(e) + '</div>'; });
          } else h += '<div class="shive-hint">never ran</div>';
          h += '</div>';
        });
        $('#log-history').html(h + '</div>');
      });
      get('logs').done(function (r) {
        $('#log-select').html(r.logs.map(function (l) { return '<option value="' + esc(l.schedule) + '|' + esc(l.file) + '">' + esc(l.label || l.schedule) + ' – ' + esc(l.file) + ' (' + bytes(l.size) + ')</option>'; }).join('') || '<option>no logs</option>');
      });
    },
    view: function () {
      var v = ($('#log-select').val() || '').split('|');
      get('log', { schedule: v[0], file: v[1] }).done(function (r) { $('#log-content').text(r.content); }).fail(function () { $('#log-content').text('not found'); });
    }
  };

  /* ------------------------------------------------------------ settings */
  var cfg = {
    keys: ['LOG_DIR', 'NOTIFY_ON_SUCCESS', 'DOCKER_STOP_TIMEOUT', 'SSH_KEY', 'SSH_OPTS', 'CATCHUP_ON_START', 'RESTORE_CLONE_TTL', 'PRERESTORE_KEEP'],
    load: function () { get('config').done(function (r) { cfg.keys.forEach(function (k) { $('#c-' + k).val(r.config[k]); }); }); },
    save: function () {
      var c = {}; cfg.keys.forEach(function (k) { c[k] = $('#c-' + k).val(); });
      $('#cfg-msg').removeClass('shive-err').text('saving…');
      post('config_save', { config: JSON.stringify(c) })
        .done(function () { $('#cfg-msg').removeClass('shive-err').text('saved ' + new Date().toLocaleTimeString()); })
        .fail(function (x) { $('#cfg-msg').addClass('shive-err').text('save failed: ' + ((x.responseJSON || {}).error || ('HTTP ' + x.status))); });
    }
  };

  return { sched: sched, ctr: ctr, br: br, log: log, cfg: cfg };
})();
