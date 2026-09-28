/* shive – dashboard tile refresh (loaded by ShiveDashboard.page) */
(function () {
  var icons = { ok: 'fa-check-circle', warning: 'fa-exclamation-triangle', error: 'fa-times-circle' };
  function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
  function ago(t) { if (!t) return '–'; var d = (Date.now() - new Date(t)) / 1000; if (d < 60) return Math.round(d) + 's ago'; if (d < 3600) return Math.round(d / 60) + 'm ago'; if (d < 86400) return Math.round(d / 3600) + 'h ago'; return Math.round(d / 86400) + 'd ago'; }
  function render(st) {
    $('#shive-tile-health').attr('class', 'shive-health-' + st.health).html('<i class="fa ' + icons[st.health] + '"></i>');
    if (!st.schedules.length) { $('#shive-tile-body').html('<span class="shive-meta">No schedules yet – <a href="/Settings/Shive">create one</a>.</span>'); return; }
    var h = '';
    st.schedules.forEach(function (s) {
      var l = s.last, m = [];
      if (s.running) m.push('running: ' + s.running.phase);
      else if (l) {
        m.push(l.status + ' ' + ago(l.finished));
        $.each(l.sends || {}, function (k, v) { m.push(k + ': ' + (v.status === 'ok' ? 'ok ' + ago(v.finished) : v.status)); });
        if (l.resume_failed && l.resume_failed.length) m.push('DOWN: ' + l.resume_failed.join(','));
        else if (s.docker_aware && l.containers && l.containers.length) m.push(l.containers.length + ' containers');
      } else m.push('never ran');
      h += '<div class="shive-row"><span><i class="fa ' + icons[s.health] + ' shive-health-' + s.health + '"></i> <a href="/Settings/Shive">' + esc(s.name) + '</a>' + (s.enabled ? '' : ' <span class="shive-meta">(disabled)</span>') + '</span><span class="shive-meta">' + esc(m.join(' · ')) + '</span></div>';
    });
    $('#shive-tile-body').html(h);
  }
  function refresh() { $.getJSON('/plugins/shive/include/api.php?op=status', render).fail(function () { $('#shive-tile-body').html('<span class="shive-meta">status unavailable</span>'); }); }
  $(function () { refresh(); setInterval(refresh, 30000); });
})();
