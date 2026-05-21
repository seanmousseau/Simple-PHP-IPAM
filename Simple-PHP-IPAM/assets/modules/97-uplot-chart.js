// uPlot dashboard growth chart
(function () {
  var el = document.getElementById('growth-chart');
  if (!el || typeof uPlot === 'undefined') return;

  var xs, ys;
  try {
    xs = JSON.parse(el.getAttribute('data-uplot-xs') || '[]');
    ys = JSON.parse(el.getAttribute('data-uplot-ys') || '[]');
  } catch (e) { return; }
  if (!xs.length) return;

  var hasData = ys.some(function(v) { return v !== 0; });
  if (!hasData) {
    /* All content below is hardcoded literal HTML — no user data, no XSS risk */
    var wrap  = document.createElement('div');
    wrap.className = 'chart-empty';
    var svgIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svgIcon.setAttribute('class', 'icon');
    svgIcon.setAttribute('aria-hidden', 'true');
    var useEl = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    useEl.setAttribute('href', 'assets/icons.svg#icon-reports');
    svgIcon.appendChild(useEl);
    var msg = document.createElement('p');
    msg.className = 'chart-empty__msg';
    msg.textContent = 'No address activity in the past 30 days';
    var cta = document.createElement('a');
    cta.className = 'chart-empty__cta';
    cta.href = 'subnets.php';
    cta.textContent = 'Go to Subnets';
    wrap.appendChild(svgIcon);
    wrap.appendChild(msg);
    wrap.appendChild(cta);
    el.appendChild(wrap);
    return;
  }

  var style  = getComputedStyle(document.documentElement);
  var stroke = (style.getPropertyValue('--link') || '#0077cc').trim();
  var fill   = stroke + '22';
  var muted  = (style.getPropertyValue('--muted') || '#6c757d').trim();

  var opts = {
    width:  640,
    height: 180,
    cursor: { drag: { x: false, y: false } },
    select: { show: false },
    legend: { show: false },
    series: [
      {},
      {
        label: 'New addresses',
        stroke: stroke,
        fill:   fill,
        width:  2
      }
    ],
    axes: [
      { gap: 8, size: 28, stroke: muted, ticks: { stroke: muted } },
      {
        gap: 8, size: 40, stroke: muted, ticks: { stroke: muted },
        values: function (_u, vals) {
          return vals.map(function (v) { return v == null ? '' : String(Math.round(v)); });
        }
      }
    ],
    scales: { x: { time: true } }
  };

  var u = new uPlot(opts, [xs, ys], el);

  // Hide stale cursor lines/points after mouse leaves the chart (#648)
  el.addEventListener('mouseleave', function () {
    u.over.dispatchEvent(new MouseEvent('mousemove', { bubbles: false, clientX: -9999, clientY: -9999 }));
  });

  function resizeChart() {
    var w = el.offsetWidth;
    if (w > 0) u.setSize({ width: w, height: 180 });
  }

  requestAnimationFrame(resizeChart);
  window.addEventListener('resize', resizeChart);
  document.addEventListener('ipam:sidebar-toggle', resizeChart);
}());

