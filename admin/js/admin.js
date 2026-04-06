/**
 * BF10 Admin Panel
 */
(function () {
    'use strict';

    var API = '../api/';
    var loginScreen = document.getElementById('login-screen');
    var dashboard = document.getElementById('dashboard');
    var currentFilter = '';

    // Check session on load
    fetch(API + 'auth.php?action=me')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.user && data.user.role === 'manager') {
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
        loadStats();
        loadOrders('');
    }

    // Load stats
    function loadStats() {
        fetch(API + 'admin.php?action=stats')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.stats) {
                    var s = data.stats;
                    document.getElementById('stat-total').textContent = s.total;
                    document.getElementById('stat-pending').textContent = s.by_status.pendiente_pago || 0;
                    document.getElementById('stat-confirmed').textContent = s.by_status.confirmado || 0;
                    document.getElementById('stat-shipped').textContent = s.by_status.enviado || 0;
                    document.getElementById('stat-revenue').textContent = parseFloat(s.revenue).toLocaleString('es-ES', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €';
                }
            });
    }

    // Load orders
    function loadOrders(status) {
        var url = API + 'admin.php?action=orders';
        if (status) url += '&status=' + status;

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderOrders(data.orders || []);
            });
    }

    // Render orders table
    function renderOrders(orders) {
        var tbody = document.getElementById('orders-body');
        var empty = document.getElementById('orders-empty');

        if (orders.length === 0) {
            tbody.innerHTML = '';
            empty.style.display = 'block';
            return;
        }

        empty.style.display = 'none';
        tbody.innerHTML = orders.map(function (o) {
            var date = new Date(o.created_at);
            var dateStr = date.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });
            var statusClass = 'status--' + o.status.replace('_', '-');
            var statusLabel = {
                'confirmado': 'Confirmado',
                'pendiente_pago': 'Pendiente pago',
                'enviado': 'Enviado'
            };
            var paymentLabel = o.payment_method === 'card' ? 'Tarjeta' : 'Transferencia';

            return '<tr>' +
                '<td><strong>' + o.order_code + '</strong></td>' +
                '<td>' + dateStr + '</td>' +
                '<td>' + esc(o.name) + '<br><small>' + esc(o.email) + '</small></td>' +
                '<td>' + esc(o.package_name) + '</td>' +
                '<td>' + parseFloat(o.package_price).toFixed(2).replace('.', ',') + ' €</td>' +
                '<td>' + paymentLabel + '</td>' +
                '<td><span class="status ' + statusClass + '">' + (statusLabel[o.status] || o.status) + '</span></td>' +
                '<td class="actions">' +
                    '<button class="btn-action btn-view" onclick="viewOrder(' + o.id + ', \'' + o.order_code + '\')">Ver</button>' +
                    statusActions(o) +
                '</td>' +
                '</tr>';
        }).join('');
    }

    function statusActions(order) {
        var html = '<select class="btn-status" onchange="changeStatus(' + order.id + ', this.value)">';
        html += '<option value="">Cambiar...</option>';
        if (order.status !== 'confirmado') html += '<option value="confirmado">Confirmar</option>';
        if (order.status !== 'enviado') html += '<option value="enviado">Marcar enviado</option>';
        if (order.status !== 'pendiente_pago') html += '<option value="pendiente_pago">Pendiente pago</option>';
        html += '</select>';
        return html;
    }

    // Change order status
    window.changeStatus = function (orderId, status) {
        if (!status) return;
        if (!confirm('¿Cambiar estado a "' + status.replace('_', ' ') + '"?')) return;

        fetch(API + 'admin.php?action=update-status', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId, status: status }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                loadStats();
                loadOrders(currentFilter);
            } else {
                alert(data.error || 'Error al actualizar');
            }
        });
    };

    // View order detail
    window.viewOrder = function (id, code) {
        fetch(API + 'orders.php?action=detail&code=' + code)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.order) {
                    showModal(data.order);
                }
            });
    };

    function showModal(order) {
        var modal = document.getElementById('order-modal');
        var body = document.getElementById('modal-body');
        var date = new Date(order.created_at);
        var statusLabel = {
            'confirmado': 'Confirmado',
            'pendiente_pago': 'Pendiente de pago',
            'enviado': 'Enviado'
        };

        body.innerHTML =
            '<div class="modal-grid">' +
                '<div class="modal-section">' +
                    '<h3>Pedido</h3>' +
                    '<p><strong>Código:</strong> ' + esc(order.order_code) + '</p>' +
                    '<p><strong>Fecha:</strong> ' + date.toLocaleString('es-ES') + '</p>' +
                    '<p><strong>Pack:</strong> ' + esc(order.package_name) + ' (' + order.package_qty + ' sacos)</p>' +
                    '<p><strong>Precio:</strong> ' + parseFloat(order.package_price).toFixed(2).replace('.', ',') + ' €</p>' +
                    '<p><strong>Pago:</strong> ' + (order.payment_method === 'card' ? 'Tarjeta' : 'Transferencia') + '</p>' +
                    '<p><strong>Estado:</strong> <span class="status status--' + order.status.replace('_', '-') + '">' + (statusLabel[order.status] || order.status) + '</span></p>' +
                '</div>' +
                '<div class="modal-section">' +
                    '<h3>Cliente</h3>' +
                    '<p><strong>Nombre:</strong> ' + esc(order.name) + '</p>' +
                    '<p><strong>Email:</strong> <a href="mailto:' + esc(order.email) + '">' + esc(order.email) + '</a></p>' +
                    '<p><strong>Teléfono:</strong> <a href="tel:' + esc(order.phone) + '">' + esc(order.phone) + '</a></p>' +
                '</div>' +
                '<div class="modal-section">' +
                    '<h3>Entrega</h3>' +
                    '<p>' + esc(order.address) + '</p>' +
                    '<p>' + esc(order.postal_code) + ' ' + esc(order.city) + '</p>' +
                    (order.observations ? '<p><strong>Observaciones:</strong> ' + esc(order.observations) + '</p>' : '') +
                '</div>' +
                (order.billing_same === '0' || order.billing_same === 0 ?
                    '<div class="modal-section">' +
                        '<h3>Facturación</h3>' +
                        '<p><strong>Nombre:</strong> ' + esc(order.billing_name) + '</p>' +
                        (order.billing_company ? '<p><strong>Empresa:</strong> ' + esc(order.billing_company) + '</p>' : '') +
                        '<p><strong>CIF/NIF:</strong> ' + esc(order.billing_cif) + '</p>' +
                        '<p><strong>Dirección:</strong> ' + esc(order.billing_address) + '</p>' +
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

    // Filters
    document.querySelectorAll('.dash-filter').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelector('.dash-filter--active').classList.remove('dash-filter--active');
            this.classList.add('dash-filter--active');
            currentFilter = this.dataset.status;
            loadOrders(currentFilter);
        });
    });

    // Escape HTML
    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
})();
