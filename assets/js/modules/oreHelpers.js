/**
 * oreHelpers.js - Utility condivise per le pagine "Gestione Ore"
 * Usato da: dashboard_ore.js, ore_business_unit.js, ore_dettaglio_utente.js
 *
 * Espone: window.oreHelpers
 *
 * NOTA: Usa window.escapeHtml e window.showToast dal core (main_core.js) quando disponibili.
 */

(function (global) {
    'use strict';

    /**
     * Shorthand per document.getElementById
     * Nome esplicito per evitare collisioni con jQuery/$
     * @param {string} id - ID elemento
     * @returns {HTMLElement|null}
     */
    function getEl(id) {
        return document.getElementById(id);
    }

    /**
     * Shorthand per document.querySelectorAll
     * Nome esplicito per evitare collisioni
     * @param {string} sel - Selettore CSS
     * @returns {NodeList}
     */
    function getEls(sel) {
        return document.querySelectorAll(sel);
    }

    /**
     * Formatta un numero con locale italiano
     * @param {number|string} n - Numero da formattare
     * @param {number} dec - Decimali (default 0)
     * @returns {string}
     */
    function formatNum(n, dec) {
        if (dec === undefined) dec = 0;
        if (n == null || isNaN(n)) return '0';
        var num = parseFloat(n) || 0;
        return num.toLocaleString('it-IT', {
            minimumFractionDigits: dec,
            maximumFractionDigits: dec
        });
    }

    /**
     * Formatta un delta con segno e unità
     * @param {number|string} val - Valore delta
     * @param {string} unit - Unità (default '%')
     * @returns {string}
     */
    function formatDelta(val, unit) {
        if (unit === undefined) unit = '%';
        var n = parseFloat(val) || 0;
        return (n > 0 ? '+' : '') + n + unit;
    }

    /**
     * Formatta una data in formato ISO (YYYY-MM-DD)
     * NOTA: Diverso da window.formatDate del core che restituisce dd/mm/yyyy
     * @param {Date} d - Oggetto Date
     * @returns {string}
     */
    function formatDateISO(d) {
        return d.toISOString().slice(0, 10);
    }

    /**
     * Escape HTML per prevenire XSS
     * Usa window.escapeHtml del core se disponibile, altrimenti fallback locale
     * @param {string} str - Stringa da escapare
     * @returns {string}
     */
    function htmlEsc(str) {
        // Usa core se disponibile
        if (typeof global.escapeHtml === 'function') {
            return global.escapeHtml(str);
        }
        // Fallback locale
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Colori standard per pie charts (8 colori)
     */
    var PIE_COLORS = [
        '#2563eb', '#10b981', '#f59e0b', '#ef4444',
        '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16'
    ];

    /**
     * Converte coordinate polari in cartesiane (per SVG pie)
     * @param {number} cx - Centro X
     * @param {number} cy - Centro Y
     * @param {number} r - Raggio
     * @param {number} deg - Angolo in gradi
     * @returns {{x: number, y: number}}
     */
    function polarToCartesian(cx, cy, r, deg) {
        var rad = (deg - 90) * Math.PI / 180;
        return {
            x: cx + r * Math.cos(rad),
            y: cy + r * Math.sin(rad)
        };
    }

    /**
     * Genera path SVG per arco (slice di pie)
     * @param {number} cx - Centro X
     * @param {number} cy - Centro Y
     * @param {number} r - Raggio
     * @param {number} start - Angolo inizio (gradi)
     * @param {number} end - Angolo fine (gradi)
     * @returns {string}
     */
    function describeArc(cx, cy, r, start, end) {
        var s = polarToCartesian(cx, cy, r, start);
        var e = polarToCartesian(cx, cy, r, end);
        var large = (end - start) > 180 ? 1 : 0;
        return [
            'M', cx, cy,
            'L', s.x, s.y,
            'A', r, r, 0, large, 1, e.x, e.y,
            'Z'
        ].join(' ');
    }

    /**
     * Renderizza un pie chart SVG
     * @param {string} svgId - ID elemento SVG
     * @param {string} legendId - ID elemento legenda
     * @param {Array} slices - Array di {label, value, color?}
     * @param {Object} options - Opzioni {cx, cy, r}
     */
    function renderPie(svgId, legendId, slices, options) {
        var svg = getEl(svgId);
        var legend = getEl(legendId);
        if (!svg || !slices || slices.length === 0) {
            if (svg) svg.innerHTML = '';
            if (legend) legend.innerHTML = '<span class="muted">Nessun dato</span>';
            return;
        }

        var opts = options || {};
        var cx = opts.cx || 100;
        var cy = opts.cy || 100;
        var r = opts.r || 80;

        var total = slices.reduce(function (s, d) { return s + (d.value || 0); }, 0);
        if (total === 0) {
            svg.innerHTML = '';
            if (legend) legend.innerHTML = '<span class="muted">Nessun dato</span>';
            return;
        }

        var paths = '';
        var angle = 0;
        slices.forEach(function (slice, i) {
            var pct = slice.value / total;
            var sweep = pct * 360;
            if (sweep < 0.5) return; // skip slices troppo piccole
            var color = slice.color || PIE_COLORS[i % PIE_COLORS.length];
            var endAngle = angle + sweep;
            // Evita arco completo (360°) che non renderizza
            if (sweep >= 359.9) endAngle = angle + 359.9;
            paths += '<path d="' + describeArc(cx, cy, r, angle, endAngle) + '" fill="' + color + '" />';
            angle = endAngle;
        });
        svg.innerHTML = paths;

        // Legenda
        if (legend) {
            legend.innerHTML = slices.map(function (slice, i) {
                var color = slice.color || PIE_COLORS[i % PIE_COLORS.length];
                var pct = total > 0 ? Math.round(slice.value / total * 100) : 0;
                return '<div class="orebu-leg-row">' +
                    '<span class="orebu-leg-dot" style="background:' + color + '"></span>' +
                    '<span class="orebu-leg-label">' + htmlEsc(slice.label) + '</span>' +
                    '<span class="orebu-leg-val">' + formatNum(slice.value) + 'h (' + pct + '%)</span>' +
                    '</div>';
            }).join('');
        }
    }

    /**
     * Genera path SVG per linea (polyline)
     * @param {Array} points - Array di {x, y}
     * @returns {string}
     */
    function linePath(points) {
        if (!points || points.length === 0) return '';
        return points.map(function (p, i) {
            return (i === 0 ? 'M' : 'L') + p.x + ',' + p.y;
        }).join(' ');
    }

    /**
     * Genera path SVG per area (closed polyline)
     * @param {Array} points - Array di {x, y}
     * @param {number} baseY - Y base per chiusura
     * @returns {string}
     */
    function areaPath(points, baseY) {
        if (!points || points.length === 0) return '';
        var d = 'M' + points[0].x + ',' + baseY;
        points.forEach(function (p) {
            d += ' L' + p.x + ',' + p.y;
        });
        d += ' L' + points[points.length - 1].x + ',' + baseY + ' Z';
        return d;
    }

    /**
     * Renderizza un trend chart SVG (linee + aree)
     * @param {string} svgId - ID elemento SVG
     * @param {Array} data - Array di {label, wh, eh}
     * @param {Object} options - Opzioni {width, height, paddingX, paddingY, colors}
     */
    function drawTrendSVG(svgId, data, options) {
        var svg = getEl(svgId);
        if (!svg || !data || data.length === 0) {
            if (svg) svg.innerHTML = '';
            return;
        }

        var opts = options || {};
        var width = opts.width || 800;
        var height = opts.height || 200;
        var padX = opts.paddingX || 40;
        var padY = opts.paddingY || 20;
        var colorWh = (opts.colors && opts.colors.wh) || '#2563eb';
        var colorEh = (opts.colors && opts.colors.eh) || '#10b981';

        var chartW = width - padX * 2;
        var chartH = height - padY * 2;

        // Calcola max Y
        var maxY = 0;
        data.forEach(function (d) {
            if (d.wh > maxY) maxY = d.wh;
            if (d.eh > maxY) maxY = d.eh;
        });
        if (maxY === 0) maxY = 100;
        maxY = Math.ceil(maxY / 50) * 50; // arrotonda a 50

        // Punti
        var stepX = chartW / Math.max(data.length - 1, 1);
        var whPoints = [];
        var ehPoints = [];
        data.forEach(function (d, i) {
            var x = padX + i * stepX;
            whPoints.push({ x: x, y: padY + chartH - (d.wh / maxY) * chartH });
            ehPoints.push({ x: x, y: padY + chartH - (d.eh / maxY) * chartH });
        });

        var baseY = padY + chartH;

        // SVG content
        var content = '';

        // Grid lines
        for (var g = 0; g <= 4; g++) {
            var gy = padY + (chartH / 4) * g;
            var gVal = Math.round(maxY - (maxY / 4) * g);
            content += '<line x1="' + padX + '" y1="' + gy + '" x2="' + (width - padX) + '" y2="' + gy + '" stroke="#e2e8f0" stroke-dasharray="4,4" />';
            content += '<text x="' + (padX - 5) + '" y="' + (gy + 4) + '" text-anchor="end" fill="#94a3b8" font-size="10">' + gVal + '</text>';
        }

        // Areas
        content += '<path d="' + areaPath(whPoints, baseY) + '" fill="' + colorWh + '" fill-opacity="0.1" />';
        content += '<path d="' + areaPath(ehPoints, baseY) + '" fill="' + colorEh + '" fill-opacity="0.1" />';

        // Lines
        content += '<path d="' + linePath(whPoints) + '" stroke="' + colorWh + '" stroke-width="2" fill="none" />';
        content += '<path d="' + linePath(ehPoints) + '" stroke="' + colorEh + '" stroke-width="2" fill="none" />';

        // Dots
        whPoints.forEach(function (p) {
            content += '<circle cx="' + p.x + '" cy="' + p.y + '" r="3" fill="' + colorWh + '" />';
        });
        ehPoints.forEach(function (p) {
            content += '<circle cx="' + p.x + '" cy="' + p.y + '" r="3" fill="' + colorEh + '" />';
        });

        // X labels
        data.forEach(function (d, i) {
            var x = padX + i * stepX;
            content += '<text x="' + x + '" y="' + (height - 5) + '" text-anchor="middle" fill="#94a3b8" font-size="10">' + htmlEsc(d.label) + '</text>';
        });

        svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
        svg.innerHTML = content;
    }

    // Esponi come oggetto globale
    // NOTA: showToast RIMOSSO - usare window.showToast dal core
    global.oreHelpers = {
        getEl: getEl,
        getEls: getEls,
        formatNum: formatNum,
        formatDelta: formatDelta,
        formatDateISO: formatDateISO,
        htmlEsc: htmlEsc,
        PIE_COLORS: PIE_COLORS,
        polarToCartesian: polarToCartesian,
        describeArc: describeArc,
        renderPie: renderPie,
        linePath: linePath,
        areaPath: areaPath,
        drawTrendSVG: drawTrendSVG
    };

})(window);
