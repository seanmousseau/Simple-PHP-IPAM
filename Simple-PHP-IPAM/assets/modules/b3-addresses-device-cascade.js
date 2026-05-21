// Addresses page — device → interface dropdown cascade (#645 CSP fix, was inline IIFE)
(function () {
  var el = document.getElementById('iface-data');
  if (!el) return; // not on addresses.php or no devices

  var ifaceMap;
  try { ifaceMap = JSON.parse(el.getAttribute('data-ifaces') || '{}'); }
  catch (e) { ifaceMap = {}; }

  document.addEventListener('change', function (e) {
    var sel = e.target;
    if (!sel || !sel.classList.contains('addr-device-select')) return;
    var targetId = sel.getAttribute('data-iface-target');
    var target = targetId ? document.getElementById(targetId) : null;
    if (!target) return;
    var devId = parseInt(sel.value, 10);
    var ifaces = (devId && ifaceMap[devId]) ? ifaceMap[devId] : [];
    target.innerHTML = '<option value="0">(none)</option>';
    ifaces.forEach(function (iface) {
      var opt = document.createElement('option');
      opt.value = iface.id;
      opt.textContent = iface.name;
      target.appendChild(opt);
    });
  });
}());

