// TOTP enrollment QR code — runs after qrcode.min.js is loaded inline in the view
(function () {
  var qrEl = document.getElementById("totp-qr");
  if (!qrEl || typeof QRCode === "undefined") return;
  var uri = qrEl.getAttribute("data-uri");
  if (!uri) return;
  new QRCode(qrEl, {
    text: uri,
    width: 200,
    height: 200,
    colorDark: "#000000",
    colorLight: "#ffffff",
    correctLevel: QRCode.CorrectLevel.M
  });
})();

