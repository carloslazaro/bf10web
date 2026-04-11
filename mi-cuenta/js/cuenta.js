/**
 * BF10 - Client Area (Mi Cuenta)
 */
(function () {
    'use strict';

    var API = '../api/';
    var loginScreen = document.getElementById('login-screen');
    var dashboard = document.getElementById('dashboard');

    // Check session on load
    fetch(API + 'auth.php?action=me')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.user && data.user.role === 'user') {
                showDashboard(data.user);
            }
        })
        .catch(function () {});

    // Login
    document.getElementById('login-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var email = document.getElementById('login-email').value;
        var pass = document.getElementById('login-pass').value;
        var btn = document.getElementById('login-btn');
        var err = document.getElementById('login-error');

        btn.disabled = true;
        btn.textContent = 'Entrando...';
        err.textContent = '';

        fetch(API + 'auth.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: email, password: pass }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                showDashboard(data.user);
            } else {
                err.textContent = data.error || 'Error de inicio de sesión';
            }
        })
        .catch(function () {
            err.textContent = 'Error de conexión';
        })
        .finally(function () {
            btn.disabled = false;
            btn.textContent = 'Entrar';
        });
    });

    // Logout
    document.getElementById('logout-btn').addEventListener('click', function () {
        fetch(API + 'auth.php?action=logout', { method: 'POST' })
            .then(function () {
                loginScreen.style.display = 'flex';
                dashboard.style.display = 'none';
            });
    });

    // Show dashboard
    function showDashboard(user) {
        loginScreen.style.display = 'none';
        dashboard.style.display = 'block';
        document.getElementById('user-name').textContent = user.name;
        document.getElementById('welcome-name').textContent = user.name.split(' ')[0];
        loadStats();
        loadOrders();
    }

    function loadStats() {
        fetch(API + 'orders.php?action=my-stats')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.stats) return;
                var s = data.stats;
                document.getElementById('stat-total').textContent      = s.total;
                document.getElementById('stat-pagados').textContent    = s.pagados;
                document.getElementById('stat-pendientes').textContent = s.pendientes;
                document.getElementById('stat-enviados').textContent   = s.enviados;
                document.getElementById('stat-gasto').textContent =
                    parseFloat(s.gasto_total).toLocaleString('es-ES', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €';
            })
            .catch(function () {});
    }

    // Load orders
    function loadOrders() {
        fetch(API + 'orders.php?action=my-orders')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderOrders(data.orders || []);
            });
    }

    // Render orders
    function renderOrders(orders) {
        var list = document.getElementById('orders-list');
        var empty = document.getElementById('orders-empty');

        if (orders.length === 0) {
            list.innerHTML = '';
            empty.style.display = 'block';
            return;
        }

        empty.style.display = 'none';
        var statusLabels = {
            'pendiente_pago': 'Pendiente de pago',
            'confirmado': 'Confirmado',
            'enviado': 'Enviado',
            'recogida': 'Recogido ♻️'
        };

        list.innerHTML = orders.map(function (o) {
            var date = new Date(o.created_at);
            var dateStr = date.toLocaleDateString('es-ES', { day: '2-digit', month: 'long', year: 'numeric' });
            var statusClass = 'cuenta__order-status--' + o.status.replace('_', '-');
            var paymentLabel = o.payment_method === 'card' ? 'Tarjeta' : 'Transferencia';

            return '<div class="cuenta__order-card" data-code="' + esc(o.order_code) + '">' +
                '<div class="cuenta__order-info">' +
                    '<div class="cuenta__order-code">' + esc(o.order_code) + '</div>' +
                    '<div class="cuenta__order-meta">' + dateStr + ' · ' + esc(o.package_name) + ' · ' + paymentLabel + '</div>' +
                '</div>' +
                '<div class="cuenta__order-right">' +
                    '<div class="cuenta__order-price">' + parseFloat(o.package_price).toLocaleString('es-ES', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €</div>' +
                    '<span class="cuenta__order-status ' + statusClass + '">' + (statusLabels[o.status] || o.status) + '</span>' +
                '</div>' +
            '</div>';
        }).join('');

        // Click to view detail
        list.querySelectorAll('.cuenta__order-card').forEach(function (card) {
            card.addEventListener('click', function () {
                viewOrder(this.getAttribute('data-code'));
            });
        });
    }

    // View order detail
    function viewOrder(code) {
        fetch(API + 'orders.php?action=detail&code=' + encodeURIComponent(code))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.order) {
                    showModal(data.order);
                }
            });
    }

    function showModal(order) {
        var modal = document.getElementById('order-modal');
        var body = document.getElementById('modal-body');
        var date = new Date(order.created_at);
        var statusLabels = {
            'pendiente_pago': 'Pendiente de pago',
            'confirmado': 'Confirmado',
            'enviado': 'Enviado',
            'recogida': 'Recogido ♻️'
        };
        var statusClass = 'cuenta__order-status--' + order.status.replace('_', '-');

        var wantsInvoice = order.request_invoice == 1 || order.request_invoice === '1';
        var paidStr = order.paid_at ? new Date(order.paid_at).toLocaleString('es-ES') : null;

        // Progress tracker: 4 steps
        var step = 1;
        if (order.status === 'confirmado') step = 2;
        if (order.status === 'enviado') step = 3;
        if (order.status === 'recogida') step = 4;
        var tracker =
            '<div class="cuenta__tracker">' +
                '<div class="cuenta__tracker-step' + (step >= 1 ? ' is-done' : '') + '"><span>1</span>Pedido recibido</div>' +
                '<div class="cuenta__tracker-step' + (step >= 2 ? ' is-done' : '') + '"><span>2</span>' + (order.payment_method === 'card' ? 'Pago confirmado' : 'Transferencia recibida') + '</div>' +
                '<div class="cuenta__tracker-step' + (step >= 3 ? ' is-done' : '') + '"><span>3</span>Sacas entregadas</div>' +
                '<div class="cuenta__tracker-step' + (step >= 4 ? ' is-done' : '') + '"><span>4</span>Recogida y gestión</div>' +
            '</div>';

        body.innerHTML =
            tracker +
            '<div class="cuenta__detail-grid">' +
                '<div class="cuenta__detail-section">' +
                    '<h3>Pedido</h3>' +
                    '<p><strong>Código:</strong> ' + esc(order.order_code) + '</p>' +
                    '<p><strong>Fecha:</strong> ' + date.toLocaleString('es-ES') + '</p>' +
                    '<p><strong>Pack:</strong> ' + esc(order.package_name) + ' (' + order.package_qty + ' sacos)</p>' +
                    '<p><strong>Precio:</strong> ' + parseFloat(order.package_price).toLocaleString('es-ES', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €</p>' +
                    '<p><strong>Pago:</strong> ' + (order.payment_method === 'card' ? 'Tarjeta' : 'Transferencia') + '</p>' +
                    '<p><strong>Estado:</strong> <span class="cuenta__order-status ' + statusClass + '">' + (statusLabels[order.status] || order.status) + '</span></p>' +
                    (paidStr ? '<p><strong>Pagado el:</strong> ' + paidStr + '</p>' : '') +
                '</div>' +
                '<div class="cuenta__detail-section">' +
                    '<h3>Entrega</h3>' +
                    '<p>' + esc(order.address) + '</p>' +
                    '<p>' + esc(order.postal_code) + ' ' + esc(order.city) + '</p>' +
                    (order.observations ? '<p><strong>Notas:</strong> ' + esc(order.observations) + '</p>' : '') +
                '</div>' +
                '<div class="cuenta__detail-section cuenta__detail-section--full">' +
                    '<h3>Documentos</h3>' +
                    (wantsInvoice
                        ? '<p>Solicitaste factura para este pedido.</p>'
                        : '<p>Si necesitas factura con NIF/CIF, puedes emitirla aquí.</p>') +
                    '<div class="cuenta__detail-actions">' +
                        '<a class="cuenta__btn cuenta__btn--secondary" href="../api/invoices.php?action=download-receipt&code=' + encodeURIComponent(order.order_code) + '" target="_blank">📑 Descargar recibo</a>' +
                        '<button class="cuenta__btn cuenta__btn--primary" onclick="bf10IssueAndDownload(\'' + encodeURIComponent(order.order_code) + '\')">📄 Descargar factura</button>' +
                    '</div>' +
                '</div>' +
                '<div class="cuenta__detail-section cuenta__detail-section--full">' +
                    '<h3>Certificado de gestión de residuos (RCD)</h3>' +
                    (order.certificate_issued_at
                        ? '<p>📜 Tu certificado <strong>' + esc(order.certificate_number || '') + '</strong> ha sido emitido el ' + new Date(order.certificate_issued_at).toLocaleDateString('es-ES') + '. Te lo enviamos por email y puedes descargarlo aquí.</p>'
                        : (parseInt(order.certificate_requested, 10) === 1
                            ? (order.status === 'recogida'
                                ? '<p>Estamos generando tu certificado. Estará disponible en unos minutos.</p>'
                                : '<p>✓ Has solicitado el certificado. Lo emitiremos automáticamente cuando recojamos las sacas y te lo enviaremos por email, sin coste adicional.</p>')
                            : '<p>Solicita el certificado oficial de gestión de residuos. Es <strong>gratis</strong> y lo necesitarás como justificante frente al ayuntamiento o cualquier inspección. Se emite automáticamente cuando recogemos las sacas.</p>')) +
                    '<div class="cuenta__detail-actions">' +
                        (parseInt(order.certificate_requested, 10) !== 1
                            ? '<button class="cuenta__btn cuenta__btn--primary" onclick="bf10RequestCertificate(\'' + encodeURIComponent(order.order_code) + '\')">📜 Solicitar certificado gratis</button>'
                            : '') +
                        (order.certificate_issued_at
                            ? '<a class="cuenta__btn cuenta__btn--primary" href="' + API + 'orders.php?action=download-certificate&code=' + encodeURIComponent(order.order_code) + '" target="_blank">📄 Descargar certificado</a>'
                            : '') +
                    '</div>' +
                '</div>' +
            '</div>';

        modal.style.display = 'flex';
    }

    // Request certificate of waste management
    window.bf10RequestCertificate = function (encodedCode) {
        var code = decodeURIComponent(encodedCode);
        if (!confirm('¿Solicitar el certificado de gestión de residuos?\n\nEs gratis. Lo emitiremos automáticamente cuando recojamos las sacas y te lo enviaremos por email.')) return;
        fetch(API + 'orders.php?action=request-certificate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: code }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                alert(data.message || '✓ Solicitud registrada');
                // Reload order detail
                viewOrder(code);
                loadOrders();
            } else {
                alert(data.error || 'No se pudo registrar la solicitud');
            }
        })
        .catch(function () { alert('Error de conexión'); });
    };

    // Issue invoice (if missing) then download
    window.bf10IssueAndDownload = function (encodedCode) {
        var code = decodeURIComponent(encodedCode);
        fetch(API + 'invoices.php?action=issue', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: code, send: false }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success || data.invoice) {
                window.open(API + 'invoices.php?action=download&code=' + encodeURIComponent(code), '_blank');
            } else {
                alert(data.error || 'No se pudo emitir la factura. Llámanos al 674 78 34 79.');
            }
        })
        .catch(function () { alert('Error de conexión'); });
    };

    // Close modal
    document.getElementById('modal-close').addEventListener('click', closeModal);
    document.getElementById('modal-overlay').addEventListener('click', closeModal);

    function closeModal() {
        document.getElementById('order-modal').style.display = 'none';
    }

    // Escape HTML
    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
})();
