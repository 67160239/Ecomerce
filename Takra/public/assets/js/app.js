/* public/assets/js/app.js - สคริปต์กลางที่ทุกหน้าใช้ร่วมกัน */
(function () {
  'use strict';

  /** แสดงข้อความ toast มุมขวาล่าง type: success | danger | info */
  window.tkToast = function (message, type) {
    var box = document.getElementById('tk-toasts');
    if (!box || !window.bootstrap) { alert(message); return; }
    var el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + (type || 'info') + ' border-0';
    el.setAttribute('role', 'status');
    var wrap = document.createElement('div');
    wrap.className = 'd-flex';
    var body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = message;            // ใช้ textContent กัน XSS
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close btn-close-white me-2 m-auto';
    close.setAttribute('data-bs-dismiss', 'toast');
    close.setAttribute('aria-label', 'ปิด');
    wrap.appendChild(body); wrap.appendChild(close); el.appendChild(wrap);
    box.appendChild(el);
    var t = new bootstrap.Toast(el, { delay: 3500 });
    el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    t.show();
  };

  /**
   * ส่ง POST ไป API พร้อม CSRF token แล้วคืนค่า JSON
   * ตัวอย่าง: tkPost(TAKRA.apiUrl + '/cart_add.php', { product_id: 5, quantity: 1 })
   */
  window.tkPost = async function (url, data) {
    var res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': window.TAKRA.csrf,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(data || {})
    });
    var json = {};
    try { json = await res.json(); } catch (e) { /* ไม่ใช่ JSON */ }
    json.status = res.status;
    return json;
  };

  /** อัปเดตตัวเลขบนไอคอนตะกร้า */
  window.tkSetCartCount = function (n) {
    var badge = document.getElementById('cart-count');
    if (!badge) { return; }
    badge.textContent = n;
    badge.classList.toggle('d-none', !(n > 0));
  };

  /* ปุ่ม "ใส่ตะกร้า": <button data-add-to-cart data-product-id="5"> (ตัวเลือกเสริม: data-variant-id, data-qty-input="#qty") */
  document.addEventListener('click', async function (ev) {
    var btn = ev.target.closest('[data-add-to-cart]');
    if (!btn) { return; }
    ev.preventDefault();

    if (!window.TAKRA.loggedIn) {
      window.location.href = window.TAKRA.baseUrl + '/login.php';
      return;
    }

    var qtyEl = btn.dataset.qtyInput ? document.querySelector(btn.dataset.qtyInput) : null;
    var qty = qtyEl ? parseInt(qtyEl.value, 10) : 1;
    if (!(qty > 0)) { qty = 1; }

    btn.disabled = true;
    try {
      var r = await window.tkPost(window.TAKRA.apiUrl + '/cart_add.php', {
        product_id: parseInt(btn.dataset.productId, 10),
        variant_id: btn.dataset.variantId ? parseInt(btn.dataset.variantId, 10) : null,
        quantity: qty
      });
      if (r.ok) {
        window.tkSetCartCount(r.cart_count);
        window.tkToast(r.message || 'เพิ่มลงตะกร้าแล้ว', 'success');
      } else {
        window.tkToast(r.message || 'ไม่สามารถเพิ่มลงตะกร้าได้', 'danger');
      }
    } catch (e) {
      window.tkToast('เชื่อมต่อไม่ได้ กรุณาลองใหม่', 'danger');
    } finally {
      btn.disabled = false;
    }
  });
})();