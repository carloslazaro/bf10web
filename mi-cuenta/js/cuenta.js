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
        loadOrders();
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
            'enviado': 'Enviado'
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
            'enviado': 'Enviado'
        };
        var statusClass = 'cuenta__order-status--' + order.status.replace('_', '-');

        var wantsInvoice = order.request_invoice == 1 || order.request_invoice === '1';
        var paidStr = order.paid_at ? new Date(order.paid_at).toLocaleString('es-ES') : null;

        // Progress tracker: 3 steps
        var step = 1;
        if (order.status === 'confirmado') step = 2;
        if (order.status === 'enviado') step = 3;
        var tracker =
            '<div class="cuenta__tracker">' +
                '<div class="cuenta__tracker-step' + (step >= 1 ? ' is-done' : '') + '"><span>1</span>Pedido recibido</div>' +
                '<div class="cuenta__tracker-step' + (step >= 2 ? ' is-done' : '') + '"><span>2</span>' + (order.payment_method === 'card' ? 'Pago confirmado' : 'Transferencia recibida') + '</div>' +
                '<div class="cuenta__tracker-step' + (step >= 3 ? ' is-done' : '') + '"><span>3</span>Sacos entregados</div>' +
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
                (wantsInvoice ?
                    '<div class="cuenta__detail-section cuenta__detail-section--full">' +
                        '<h3>Factura</h3>' +
                        '<p>Solicitaste factura para este pedido.</p>' +
                        '<a class="cuenta__btn cuenta__btn--primary" href="../api/invoices.php?action=download&code=' + encodeURIComponent(order.order_code) + '" target="_blank">📄 Descargar factura PDF</a>' +
                    '</div>'
                : '') +
            '</div>';

        modal.style.display = 'flex';
    }

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
