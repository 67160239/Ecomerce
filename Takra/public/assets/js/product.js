/* public/assets/js/product.js - แกลเลอรีรูป และการเปลี่ยนตัวเลือกสินค้าในหน้า product.php */
(function () {
  'use strict';

  /* สลับรูปหลักเมื่อกดรูปย่อ */
  var main = document.getElementById('main-image');
  document.querySelectorAll('.tk-thumb').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (main) { main.src = btn.dataset.src; }
      document.querySelectorAll('.tk-thumb').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
    });
  });

  /* เปลี่ยนตัวเลือก -> อัปเดตราคา สต็อก ปุ่มใส่ตะกร้า */
  var radios = document.querySelectorAll('input[name="variant"]');
  if (!radios.length) { return; }

  var priceEl = document.getElementById('price');
  var stockEl = document.getElementById('stock-text');
  var qtyEl = document.getElementById('qty');
  var addBtn = document.getElementById('add-btn');

  function apply(radio) {
    var price = parseFloat(radio.dataset.price);
    var stock = parseInt(radio.dataset.stock, 10);
    if (priceEl) {
      priceEl.textContent = '฿' + price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    if (stockEl) {
      stockEl.textContent = stock > 0 ? 'คงเหลือ ' + stock.toLocaleString('en-US') + ' ชิ้น' : 'สินค้าหมด';
    }
    if (qtyEl) {
      qtyEl.max = Math.max(1, stock);
      if (parseInt(qtyEl.value, 10) > stock) { qtyEl.value = Math.max(1, stock); }
    }
    if (addBtn) {
      addBtn.dataset.variantId = radio.value;
      addBtn.disabled = stock <= 0;
    }
  }

  radios.forEach(function (r) {
    r.addEventListener('change', function () { apply(r); });
  });
})();