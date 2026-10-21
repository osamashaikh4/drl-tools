// CCTV viewer: opens over the CCTV page for one camera or NVR channel.
// Live picture (refreshed snapshots) with digital zoom, picture settings for cameras reached
// directly, and the day's recordings for NVR channels. Uses window.CCTV from index.php.
$(function() {
    if (!window.CCTV) return;
    const { DEVICES, info, esc, SPINNER, toast } = window.CCTV;
    const V = { i: null, ch: 1, recorder: false, scale: 1, x: 0, y: 0, timer: null, loading: false };
    const $stage = $('#vStage'), img = document.getElementById('vImg');

    // ---- Open / close ----------------------------------------------------------------------
    function open(i, ch, label) {
        Object.assign(V, { i, ch, recorder: !!(info[i] && info[i].recorder), scale: 1, x: 0, y: 0 });
        $('#vTitle').text(label || DEVICES[i].name);
        img.removeAttribute('src');
        $('#vMsg').html(SPINNER + ' Loading picture…');
        $('#viewer').removeClass('hidden');
        $('body').addClass('overflow-hidden');
        transform();
        // Picture settings live on cameras reached directly; NVR channels show recordings.
        $('.vtab[data-t="settings"]').toggleClass('hidden', V.recorder);
        $('.vtab[data-t="recordings"]').toggleClass('hidden', !V.recorder);
        fillChannels();
        tab(V.recorder ? 'recordings' : 'settings');
        refresh();
        schedule();
    }

    // ---- Channel switcher (NVR channels) -------------------------------------------------------
    const channels = () => (V.recorder && info[V.i] ? info[V.i].channels : []);
    const channelLabel = (c) => `D${c.id} ${c.name}${c.online === false ? ' (offline)' : ''}`;
    function fillChannels() {
        const list = channels();
        $('#vChannels').toggleClass('hidden', list.length < 2);
        $('#vChannel').html(list.map((c) => `<option value="${c.id}" ${c.id === V.ch ? 'selected' : ''}>${esc(channelLabel(c))}</option>`).join(''));
    }
    // Show another channel of the same recorder: new picture, same zoom reset, same side panel.
    function switchTo(ch) {
        const c = channels().find((x) => x.id === ch);
        if (!c || ch === V.ch) return;
        Object.assign(V, { ch, scale: 1, x: 0, y: 0, loading: false });
        $('#vTitle').text(DEVICES[V.i].name + ' · D' + c.id + ' ' + c.name);
        $('#vChannel').val(ch);
        img.removeAttribute('src');
        $('#vMsg').html(c.online === false ? 'This channel is offline' : SPINNER + ' Loading picture…');
        transform();
        refresh();
        if (!$('.vtab[data-t="recordings"]').hasClass('hidden')) loadRecordings($('#vDate').val() || today());
    }
    function step(dir) {
        const list = channels(), at = list.findIndex((x) => x.id === V.ch);
        if (list.length > 1) switchTo(list[(at + dir + list.length) % list.length].id);
    }
    $('#vChannel').on('change', function() { switchTo(+this.value); });
    $('#vPrev').on('click', () => step(-1));
    $('#vNext').on('click', () => step(1));
    // Arrow keys step through channels, unless typing in a field.
    $(document).on('keydown', (e) => {
        if (V.i === null || !V.recorder || $(e.target).is('input, select, textarea')) return;
        if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
        if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
    });
    function close() {
        clearInterval(V.timer);
        V.i = null;
        if (document.fullscreenElement) document.exitFullscreen();
        $('#viewer').addClass('hidden');
        $('body').removeClass('overflow-hidden');
    }
    $('#vClose').on('click', close);
    $(document).on('keydown', (e) => { if (e.key === 'Escape' && V.i !== null) close(); });
    $(document).on('click', '#wall .tile', function() { open(+$(this).data('i'), +$(this).data('ch'), $(this).data('label')); });
    $(document).on('click', '.open-ch', function() { open(+$(this).closest('.dev').data('i'), +$(this).data('ch'), $(this).data('label')); });
    $(document).on('click', '.open-dev', function() { const i = +$(this).closest('.dev').data('i'); open(i, 1, DEVICES[i].name); });

    // ---- Picture ----------------------------------------------------------------------------
    // The new picture replaces the old one only once it has loaded. Zoomed in, the full-resolution
    // main stream is used so details stay sharp.
    function refresh() {
        if (V.i === null || V.loading) return;
        V.loading = true;
        const next = new Image();
        next.onload = () => {
            V.loading = false;
            if (V.i === null) return;
            img.src = next.src;
            $('#vMsg').empty();
            $('#vSave').attr({ href: next.src, download: 'snapshot-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.jpg' });
        };
        next.onerror = () => { V.loading = false; $('#vMsg').text('No picture from this camera'); };
        next.src = `index.php?snap=${V.i}&ch=${V.ch}${V.scale > 1.5 ? '&main=1' : ''}&t=${Date.now()}`;
    }
    function schedule() {
        clearInterval(V.timer);
        const ms = +$('#vRate').val();
        if (ms) V.timer = setInterval(refresh, ms);
    }
    $('#vRate').on('change', schedule);

    // ---- Digital zoom -----------------------------------------------------------------------
    function transform() {
        const w = $stage.width(), h = $stage.height();
        V.scale = Math.min(8, Math.max(1, V.scale));
        V.x = Math.min(0, Math.max(w - w * V.scale, V.x));
        V.y = Math.min(0, Math.max(h - h * V.scale, V.y));
        img.style.transform = `translate(${V.x}px, ${V.y}px) scale(${V.scale})`;
        $('#vZoom').text(Math.round(V.scale * 10) / 10 + '×');
        $stage.css('cursor', V.scale > 1 ? 'grab' : 'default');
    }
    // Zoom by a factor around a point of the stage, keeping that point under the cursor.
    function zoom(factor, cx, cy) {
        const before = V.scale;
        V.scale = Math.min(8, Math.max(1, before * factor));
        V.x = cx - (cx - V.x) * (V.scale / before);
        V.y = cy - (cy - V.y) * (V.scale / before);
        transform();
        if (before <= 1.5 && V.scale > 1.5) refresh(); // switch to the sharper main stream now
    }
    const fit = () => { V.scale = 1; V.x = V.y = 0; transform(); };
    $stage.on('wheel', (e) => {
        e.preventDefault();
        const o = $stage.offset();
        zoom(e.originalEvent.deltaY < 0 ? 1.25 : 0.8, e.pageX - o.left, e.pageY - o.top);
    });
    $('.vz').on('click', function() { zoom(+$(this).data('z') > 0 ? 1.5 : 1 / 1.5, $stage.width() / 2, $stage.height() / 2); });
    $('#vFit').on('click', fit);
    $stage.on('dblclick', fit);
    let drag = null;
    $stage.on('mousedown', (e) => { if (V.scale > 1) { drag = { x: e.pageX - V.x, y: e.pageY - V.y }; $stage.css('cursor', 'grabbing'); } });
    $(document).on('mousemove', (e) => { if (drag) { V.x = e.pageX - drag.x; V.y = e.pageY - drag.y; transform(); } });
    $(document).on('mouseup', () => { if (drag) { drag = null; transform(); } });
    $('#vFull').on('click', () => { document.fullscreenElement ? document.exitFullscreen() : $stage[0].requestFullscreen && $stage[0].requestFullscreen(); });
    $(document).on('fullscreenchange', () => setTimeout(transform, 50));
    $(window).on('resize', () => { if (V.i !== null) transform(); });

    // ---- Side panels ------------------------------------------------------------------------
    function tab(t) {
        $('.vtab').removeClass('bg-slate-900 text-white').addClass('text-slate-600').filter(`[data-t="${t}"]`).addClass('bg-slate-900 text-white').removeClass('text-slate-600');
        if (t === 'settings') loadSettings(); else loadRecordings(today());
    }
    $('.vtab').on('click', function() { tab($(this).data('t')); });
    const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
    const heading = (t) => `<h3 class="mt-5 first:mt-0 mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">${t}</h3>`;

    // Picture settings: each control saves on change (the camera keeps everything else as it was).
    function loadSettings() {
        const i = V.i;
        $('#vPanel').html(SPINNER + ' Reading camera settings…');
        $.getJSON('index.php', { api: 'controls', i }).done((r) => {
            if (i !== V.i) return;
            if (!r.ok) return $('#vPanel').html('<p class="text-red-700">' + esc(r.error) + '</p>');
            const s = r.settings;
            const slider = (key, label) => s[key] === null ? '' : `<label class="block py-1.5">
                <span class="flex justify-between text-xs text-slate-600"><span>${label}</span><span class="val tabular-nums font-semibold text-slate-900">${s[key]}</span></span>
                <input type="range" min="0" max="100" value="${s[key]}" class="setting w-full accent-slate-900" data-key="${key}"></label>`;
            const toggle = (key, label) => s[key] === null ? '' : `<label class="flex items-center justify-between py-1.5 text-sm">
                <span>${label}</span><input type="checkbox" class="setting w-4 h-4 rounded border-slate-300" data-key="${key}" ${s[key] === 'true' ? 'checked' : ''}></label>`;
            let html = heading('Picture') + slider('brightness', 'Brightness') + slider('contrast', 'Contrast') + slider('saturation', 'Saturation') + slider('sharpness', 'Sharpness');
            if (s.dayNight !== null) {
                html += heading('Day / night') + `<select class="setting w-full rounded-lg border border-slate-300 p-2 text-sm" data-key="dayNight">
                    ${[['auto', 'Automatic'], ['day', 'Always colour (day)'], ['night', 'Always black & white (night, IR)']].map(([v, l]) => `<option value="${v}" ${s.dayNight === v ? 'selected' : ''}>${l}</option>`).join('')}</select>`;
            }
            html += heading('On-screen text');
            if (s.name !== null) {
                html += `<div class="flex gap-2 py-1.5"><input class="vName flex-1 min-w-0 rounded-lg border border-slate-300 p-2 text-sm" maxlength="32" value="${esc(s.name)}">
                    <button type="button" class="vNameSave px-3 rounded-lg border border-slate-300 text-xs font-semibold hover:bg-slate-100">Rename</button></div>`;
            }
            html += toggle('osdName', 'Show camera name') + toggle('osdDate', 'Show date and time');
            html += heading('Motion detection') + toggle('motion', 'Motion detection on') + slider('sensitivity', 'Sensitivity');
            html += heading('Streams (read only)') + (r.streams.length ? `<table class="w-full text-xs"><tbody>${r.streams.map((x) => `<tr class="border-t border-slate-100 first:border-t-0">
                <td class="py-1 font-semibold text-slate-700">${esc(x.stream)}</td><td class="py-1">${esc(x.resolution)}</td><td class="py-1">${esc(x.codec)}</td>
                <td class="py-1 tabular-nums">${x.fps ? esc(x.fps) + ' fps' : ''}</td><td class="py-1 tabular-nums text-right">${x.bitrate ? esc(x.bitrate) + ' kbps' : ''}</td></tr>`).join('')}</tbody></table>` : '<p class="text-xs text-slate-400">Not reported.</p>');
            html += '<p class="mt-4 text-xs text-slate-400">Changes are saved to the camera straight away and show in the picture within a few seconds.</p>';
            $('#vPanel').html(html);
        }).fail(() => $('#vPanel').html('<p class="text-red-700">Could not read the camera settings.</p>'));
    }
    function save(key, value, $el) {
        $el.prop('disabled', true);
        $.post('index.php?api=set', { i: V.i, key, value }, null, 'json')
            .done((r) => { toast(r.ok, r.ok ? 'Saved' : r.error); if (r.ok) setTimeout(refresh, 800); })
            .fail(() => toast(false, 'Request failed'))
            .always(() => $el.prop('disabled', false));
    }
    $(document).on('input', '#vPanel input[type=range].setting', function() { $(this).closest('label').find('.val').text(this.value); });
    $(document).on('change', '#vPanel .setting', function() {
        const $el = $(this);
        save($el.data('key'), $el.is(':checkbox') ? String($el.is(':checked')) : $el.val(), $el);
    });
    $(document).on('click', '.vNameSave', function() { save('name', $('.vName').val().trim(), $(this)); });

    // Recordings of the channel on a day, as a 24-hour bar plus the list of clips.
    function loadRecordings(date) {
        const i = V.i, ch = V.ch;
        $('#vPanel').html(`<label class="block text-xs font-semibold text-slate-600 mb-3">Day
            <input type="date" id="vDate" value="${date}" max="${today()}" class="mt-1 block w-full rounded-lg border border-slate-300 p-2 text-sm"></label>
            <div id="vRec">${SPINNER} Searching recordings…</div>`);
        $.getJSON('index.php', { api: 'recordings', i, ch, date }).done((r) => {
            if (i !== V.i || ch !== V.ch) return;
            if (!r.ok) return $('#vRec').html('<p class="text-red-700">' + esc(r.error) + '</p>');
            const secs = (t) => { const [h, m, s] = t.slice(11).split(':').map(Number); return h * 3600 + m * 60 + s; };
            const total = r.clips.reduce((n, c) => n + Math.max(0, secs(c[1]) - secs(c[0])), 0);
            const types = r.clips.reduce((o, c) => (o[c[2]] = (o[c[2]] || 0) + 1, o), {});
            const bar = r.clips.map((c) => {
                const a = secs(c[0]) / 864, w = Math.max(0.15, (secs(c[1]) - secs(c[0])) / 864);
                return `<span class="absolute inset-y-0 ${c[2] === 'Motion' ? 'bg-amber-500' : 'bg-emerald-500'}" style="left:${a}%;width:${w}%"></span>`;
            }).join('');
            const hh = (s) => Math.floor(s / 3600) + ' h ' + Math.floor(s % 3600 / 60) + ' min';
            $('#vRec').html(r.clips.length ? `
                <p class="text-xs text-slate-600 mb-2">${r.clips.length} clips · ${hh(total)} recorded · ${Object.entries(types).map(([t, n]) => esc(t) + ' ' + n).join(', ')}</p>
                <div class="relative h-6 rounded bg-slate-100 overflow-hidden" title="00:00 to 24:00">${bar}</div>
                <div class="flex justify-between text-[10px] text-slate-400 mt-1 tabular-nums"><span>00</span><span>06</span><span>12</span><span>18</span><span>24</span></div>
                <div class="flex gap-3 text-[11px] text-slate-500 mt-1"><span><span class="inline-block w-2 h-2 rounded-sm bg-emerald-500"></span> Continuous</span><span><span class="inline-block w-2 h-2 rounded-sm bg-amber-500"></span> Motion</span></div>
                ${heading('Clips')}
                <div class="border border-slate-200 rounded-lg max-h-72 overflow-y-auto"><table class="w-full text-xs"><tbody>${r.clips.slice().reverse().map((c) => `<tr class="border-t border-slate-100 first:border-t-0">
                    <td class="px-2 py-1 tabular-nums">${esc(c[0].slice(11))} – ${esc(c[1].slice(11))}</td><td class="px-2 py-1 text-slate-500">${esc(c[2])}</td></tr>`).join('')}</tbody></table></div>
                <p class="mt-3 text-xs text-slate-400">Times are the recorder's clock. To play footage, use the NVR's own interface or Hik-Connect.</p>`
                : '<p class="text-slate-500">No recordings on this day.</p>');
        }).fail(() => $('#vRec').html('<p class="text-red-700">Could not search recordings.</p>'));
    }
    $(document).on('change', '#vDate', function() { loadRecordings(this.value); });
});
