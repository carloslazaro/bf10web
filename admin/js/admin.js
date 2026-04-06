/**
 * BF10 Admin Panel v2
 * Tabs: Pedidos / Facturas emitidas / Email log
 * Features: search, filters, source badges, invoice column,
 *           internal notes, events timeline, CSV export, issue-with-send modal.
 */
(function () {
    'use strict';

    var API = '../api/';
    var loginScreen = document.getElementById('login-screen');
    var dashboard = document.getElementById('dashboard');

    var filters = { status: '', source: '', q: '', from: '', to: '' };
    var invoiceFilter = '';
    var currentOrder = null;

    // ---------- session bootstrap ----------
    fetch(API + 'auth.php?action=me')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.user && data.user.role === 'manager') showDashboard(data.user);
        })
        .catch(function () {});

    document.getElementById('login-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var email = document.getElementById('login-email').value;
        var pass  = document.getElementById('login-pass').value;
        var btn = document.getElementById('login-btn');
        var err = document.getElementById('login-error');
        btn.disabled = true; btn.textContent = 'Entrando...'; err.textContent = '';

        fetch(API + 'auth.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: email, password: pass }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) showDashboard(data.user);
            else err.textContent = data.error || 'Error de inicio de sesión';
        })
        .catch(function () { err.textContent = 'Error de conexión'; })
        .finally(function () { btn.disabled = false; btn.textContent = 'Entrar'; });
    });

    document.getElementById('logout-btn').addEventListener('click', function () {
        fetch(API + 'auth.php?action=logout', { method: 'POST' })
            .then(function () {
                loginScreen.style.display = 'flex';
                dashboard.style.display = 'none';
            });
    });

    function showDashboard(user) {
        loginScreen.style.display = 'none';
        dashboard.style.display = 'block';
        document.getElementById('user-name').textContent = user.name;
        loadStats();
        loadOrders();
    }

    // ---------- tabs ----------
    document.querySelectorAll('.tab').forEach(function (t) {
        t.addEventListener('click', function () {
            var name = this.dataset.tab;
            document.querySelectorAll('.tab').forEach(function (x) { x.classList.remove('tab--active'); });
            document.querySelectorAll('.tab-panel').forEach(function (x) { x.classList.remove('tab-panel--active'); });
            this.classList.add('tab--active');
            document.getElementById('tab-' + name).classList.add('tab-panel--active');
            if (name === 'invoices') loadInvoices();
            if (name === 'emails')   loadEmailLog();
        });
    });

    // ---------- stats ----------
    function loadStats() {
        fetch(API + 'admin.php?action=stats')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.stats) return;
                var s = data.stats;
                document.getElementById('stat-total').textContent     = s.total;
                document.getElementById('stat-pending').textContent   = s.by_status.pendiente_pago || 0;
                document.getElementById('stat-confirmed').textContent = s.by_status.confirmado || 0;
                document.getElementById('stat-shipped').textContent   = s.by_status.enviado || 0;
                document.getElementById('stat-revenue').textContent   = money(s.revenue);
                document.getElementById('stat-month').textContent     = s.month || 0;

                var invTotal = s.invoices_total || 0;
                var invSent  = s.invoices_sent || 0;
                document.getElementById('stat-inv-total').textContent   = invTotal;
                document.getElementById('stat-inv-sent').textContent    = invSent;
                document.getElementById('stat-inv-pending').textContent = invTotal - invSent;
            });
    }

    // ---------- orders ----------
    function loadOrders() {
        var qs = [];
        Object.keys(filters).forEach(function (k) {
            if (filters[k]) qs.push(k + '=' + encodeURIComponent(filters[k]));
        });
        var url = API + 'admin.php?action=orders' + (qs.length ? '&' + qs.join('&') : '');
        fetch(url).then(function (r) { return r.json(); }).then(function (data) {
            renderOrders(data.orders || []);
        });
    }

    function renderOrders(orders) {
        var tbody = document.getElementById('orders-body');
        var empty = document.getElementById('orders-empty');
        if (!orders.length) { tbody.innerHTML = ''; empty.style.display = 'block'; return; }
        empty.style.display = 'none';

        var statusLabel = { confirmado: 'Confirmado', pendiente_pago: 'Pendiente pago', enviado: 'Enviado' };

        tbody.innerHTML = orders.map(function (o) {
            var d = new Date(o.created_at).toLocaleDateString('es-ES');
            var src = o.source || 'web';
            var hasInvoice = !!o.invoice_number;
            var invCell = hasInvoice
                ? '<span class="badge badge--yes" title="' + esc(o.invoice_number) + '">Sí</span>'
                : '<span class="badge badge--no">No</span>';

            return '<tr>' +
                '<td><strong>' + esc(o.order_code) + '</strong></td>' +
                '<td>' + d + '</td>' +
                '<td><span class="badge badge--' + src + '">' + src + '</span></td>' +
                '<td>' + esc(o.name) + '<br><small>' + esc(o.email) + '</small></td>' +
                '<td>' + esc(o.package_name) + '</td>' +
                '<td>' + money(o.package_price) + '</td>' +
                '<td>' + (o.payment_method === 'card' ? 'Tarjeta' : 'Transferencia') + '</td>' +
                '<td><span class="status status--' + o.status.replace('_', '-') + '">' + (statusLabel[o.status] || o.status) + '</span></td>' +
                '<td>' + invCell + '</td>' +
                '<td class="actions">' +
                    '<button class="btn-action btn-view" onclick="viewOrder(\'' + esc(o.order_code) + '\')">Ver</button>' +
                    statusActions(o) +
                '</td>' +
            '</tr>';
        }).join('');
    }

    function statusActions(o) {
        var html = '<select class="btn-status" onchange="changeStatus(' + o.id + ', this.value)">';
        html += '<option value="">Cambiar...</option>';
        if (o.status !== 'confirmado')     html += '<option value="confirmado">Confirmar</option>';
        if (o.status !== 'enviado')        html += '<option value="enviado">Marcar enviado</option>';
        if (o.status !== 'pendiente_pago') html += '<option value="pendiente_pago">Pendiente pago</option>';
        html += '</select>';
        return html;
    }

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
            if (data.success) { loadStats(); loadOrders(); }
            else alert(data.error || 'Error al actualizar');
        });
    };

    // ---------- order detail modal ----------
    window.viewOrder = function (code) {
        fetch(API + 'orders.php?action=detail&code=' + encodeURIComponent(code))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.order) { currentOrder = data.order; showModal(data.order); }
            });
    };

    function showModal(order) {
        var modal = document.getElementById('order-modal');
        var body = document.getElementById('modal-body');
        var date = new Date(order.created_at).toLocaleString('es-ES');
        var statusLabel = { confirmado: 'Confirmado', pendiente_pago: 'Pendiente de pago', enviado: 'Enviado' };
        var wantsInvoice = order.request_invoice == 1 || order.request_invoice === '1';
        var paidStr = order.paid_at ? new Date(order.paid_at).toLocaleString('es-ES') : '';

        body.innerHTML =
            '<div class="modal-tabs">' +
                '<button class="modal-tab modal-tab--active" data-mt="info">Información</button>' +
                '<button class="modal-tab" data-mt="notes">Notas internas</button>' +
                '<button class="modal-tab" data-mt="timeline">Historial</button>' +
            '</div>' +

            '<div class="modal-tab-panel modal-tab-panel--active" data-mt-panel="info">' +
                '<div class="modal-grid">' +
                    '<div class="modal-section">' +
                        '<h3>Pedido</h3>' +
                        '<p><strong>Código:</strong> ' + esc(order.order_code) + '</p>' +
                        '<p><strong>Fecha:</strong> ' + date + '</p>' +
                        '<p><strong>Pack:</strong> ' + esc(order.package_name) + ' (' + order.package_qty + ' sacos)</p>' +
                        '<p><strong>Precio:</strong> ' + money(order.package_price) + '</p>' +
                        '<p><strong>Pago:</strong> ' + (order.payment_method === 'card' ? 'Tarjeta' : 'Transferencia') + '</p>' +
                        '<p><strong>Estado:</strong> <span class="status status--' + order.status.replace('_', '-') + '">' + (statusLabel[order.status] || order.status) + '</span></p>' +
                        (paidStr ? '<p><strong>Pagado:</strong> ' + paidStr + '</p>' : '') +
                    '</div>' +
                    '<div class="modal-section">' +
                        '<h3>Cliente</h3>' +
                        '<p><strong>Nombre:</strong> ' + esc(order.name) + '</p>' +
                        (order.nif ? '<p><strong>NIF/CIF:</strong> ' + esc(order.nif) + '</p>' : '') +
                        '<p><strong>Email:</strong> <a href="mailto:' + esc(order.email) + '">' + esc(order.email) + '</a></p>' +
                        '<p><strong>Tel:</strong> <a href="tel:' + esc(order.phone) + '">' + esc(order.phone) + '</a></p>' +
                    '</div>' +
                    '<div class="modal-section">' +
                        '<h3>Entrega</h3>' +
                        '<p>' + esc(order.address) + '</p>' +
                        '<p>' + esc(order.postal_code) + ' ' + esc(order.city) + '</p>' +
                        (order.observations ? '<p><strong>Observaciones:</strong> ' + esc(order.observations) + '</p>' : '') +
                    '</div>' +
                    '<div class="modal-section">' +
                        '<h3>Documentos</h3>' +
                        (wantsInvoice
                            ? '<p>✓ Cliente solicitó factura</p>'
                            : '<p><em>El cliente no solicitó factura.</em></p>') +
                        '<p style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">' +
                            '<button class="btn-action btn-primary" onclick="openIssueModal(\'' + esc(order.order_code) + '\')">🧾 Emitir factura</button>' +
                            '<a class="btn-action" href="' + API + 'invoices.php?action=download&code=' + encodeURIComponent(order.order_code) + '" target="_blank">📄 Ver factura PDF</a>' +
                            '<a class="btn-action" href="' + API + 'invoices.php?action=download-receipt&code=' + encodeURIComponent(order.order_code) + '" target="_blank">📑 Recibo PDF</a>' +
                            '<button class="btn-action" onclick="resendOrderEmail(\'' + esc(order.order_code) + '\')">✉️ Reenviar confirmación</button>' +
                        '</p>' +
                    '</div>' +
                '</div>' +
            '</div>' +

            '<div class="modal-tab-panel" data-mt-panel="notes">' +
                '<h3>Notas internas (no visibles para el cliente)</h3>' +
                '<textarea class="notes-textarea" id="notes-text">' + esc(order.internal_notes || '') + '</textarea>' +
                '<p style="margin-top:10px"><button class="btn-action btn-primary" onclick="saveNotes(' + order.id + ')">💾 Guardar notas</button></p>' +
            '</div>' +

            '<div class="modal-tab-panel" data-mt-panel="timeline">' +
                '<h3>Historial del pedido</h3>' +
                '<div id="timeline-container"><em>Cargando…</em></div>' +
            '</div>';

        // Wire modal tabs
        body.querySelectorAll('.modal-tab').forEach(function (mt) {
            mt.addEventListener('click', function () {
                var name = this.dataset.mt;
                body.querySelectorAll('.modal-tab').forEach(function (x) { x.classList.remove('modal-tab--active'); });
                body.querySelectorAll('.modal-tab-panel').forEach(function (x) { x.classList.remove('modal-tab-panel--active'); });
                this.classList.add('modal-tab--active');
                body.querySelector('[data-mt-panel="' + name + '"]').classList.add('modal-tab-panel--active');
                if (name === 'timeline') loadTimeline(order.id);
            });
        });

        modal.style.display = 'flex';
    }

    function loadTimeline(orderId) {
        fetch(API + 'admin.php?action=events&order_id=' + orderId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var c = document.getElementById('timeline-container');
                if (!c) return;
                var events = data.events || [];
                if (!events.length) { c.innerHTML = '<p><em>Sin eventos registrados.</em></p>'; return; }
                c.innerHTML = '<div class="timeline">' + events.map(function (e) {
                    return '<div class="timeline-item">' +
                        '<div class="timeline-item__time">' + new Date(e.created_at).toLocaleString('es-ES') + '</div>' +
                        '<div class="timeline-item__desc"><strong>' + esc(e.event_type) + '</strong> — ' + esc(e.description || '') + '</div>' +
                        '<div class="timeline-item__actor">' + esc(e.actor || 'sistema') + '</div>' +
                    '</div>';
                }).join('') + '</div>';
            });
    }

    window.saveNotes = function (orderId) {
        var notes = document.getElementById('notes-text').value;
        fetch(API + 'admin.php?action=update-notes', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId, internal_notes: notes }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) { alert(data.success ? '✓ Notas guardadas' : (data.error || 'Error')); });
    };

    window.resendOrderEmail = function (code) {
        if (!confirm('¿Reenviar email de confirmación al cliente?')) return;
        fetch(API + 'invoices.php?action=resend-confirmation', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: code }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) { alert(data.success ? '✓ Email reenviado' : (data.error || 'Error')); });
    };

    // ---------- issue invoice modal ----------
    window.openIssueModal = function (code) {
        document.getElementById('issue-code').textContent = code;
        document.getElementById('issue-send').checked = false;
        document.getElementById('issue-modal').style.display = 'flex';
        document.getElementById('issue-modal').dataset.code = code;
    };
    function closeIssueModal() { document.getElementById('issue-modal').style.display = 'none'; }
    document.getElementById('issue-close').addEventListener('click', closeIssueModal);
    document.getElementById('issue-overlay').addEventListener('click', closeIssueModal);
    document.getElementById('issue-cancel').addEventListener('click', closeIssueModal);

    document.getElementById('issue-confirm').addEventListener('click', function () {
        var code = document.getElementById('issue-modal').dataset.code;
        var send = document.getElementById('issue-send').checked;
        var btn = this;
        btn.disabled = true; btn.textContent = 'Emitiendo...';

        fetch(API + 'invoices.php?action=issue', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: code, send: send }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false; btn.textContent = 'Emitir factura';
            if (!data.success) { alert(data.error || 'Error'); return; }
            var msg = data.is_new ? '✓ Factura emitida: ' + data.invoice.invoice_number : 'ℹ️ Factura ya existente: ' + data.invoice.invoice_number;
            if (send) msg += data.sent ? '\n✓ Enviada por email.' : '\n⚠ No se pudo enviar el email: ' + (data.send_error || '');
            alert(msg);
            closeIssueModal();
            loadStats();
            loadOrders();
            if (currentOrder && currentOrder.order_code === code) viewOrder(code);
        })
        .catch(function () { btn.disabled = false; btn.textContent = 'Emitir factura'; alert('Error de conexión'); });
    });

    // ---------- invoices tab ----------
    function loadInvoices() {
        var url = API + 'admin.php?action=invoices' + (invoiceFilter ? '&sent=' + invoiceFilter : '');
        fetch(url).then(function (r) { return r.json(); }).then(function (data) {
            var rows = data.invoices || [];
            var tbody = document.getElementById('invoices-body');
            var empty = document.getElementById('invoices-empty');
            if (!rows.length) { tbody.innerHTML = ''; empty.style.display = 'block'; return; }
            empty.style.display = 'none';
            tbody.innerHTML = rows.map(function (i) {
                var sent = i.sent_at
                    ? '<span class="badge badge--sent">' + new Date(i.sent_at).toLocaleDateString('es-ES') + '</span>'
                    : '<span class="badge badge--unsent">Sin enviar</span>';
                return '<tr>' +
                    '<td><strong>' + esc(i.invoice_number) + '</strong></td>' +
                    '<td>' + new Date(i.issued_at).toLocaleDateString('es-ES') + '</td>' +
                    '<td>' + esc(i.order_code) + '</td>' +
                    '<td>' + esc(i.name) + '<br><small>' + esc(i.email) + '</small></td>' +
                    '<td>' + esc(i.nif || '—') + '</td>' +
                    '<td>' + money(i.total_amount) + '</td>' +
                    '<td>' + sent + '</td>' +
                    '<td class="actions">' +
                        '<a class="btn-action btn-view" href="' + API + 'invoices.php?action=download&code=' + encodeURIComponent(i.order_code) + '" target="_blank">PDF</a>' +
                        '<button class="btn-action" onclick="resendInvoice(\'' + esc(i.order_code) + '\')">Reenviar</button>' +
                    '</td>' +
                '</tr>';
            }).join('');
        });
    }

    window.resendInvoice = function (code) {
        if (!confirm('¿Reenviar la factura por email al cliente?')) return;
        fetch(API + 'invoices.php?action=send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: code }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            alert(data.success ? '✓ Factura enviada' : (data.error || 'Error'));
            if (data.success) loadInvoices();
        });
    };

    document.querySelectorAll('#tab-invoices .dash-filter').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#tab-invoices .dash-filter').forEach(function (b) { b.classList.remove('dash-filter--active'); });
            this.classList.add('dash-filter--active');
            invoiceFilter = this.dataset.sent;
            loadInvoices();
        });
    });

    // ---------- email log tab ----------
    function loadEmailLog() {
        fetch(API + 'admin.php?action=email-log')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var rows = data.log || [];
                var tbody = document.getElementById('emails-body');
                var empty = document.getElementById('emails-empty');
                if (!rows.length) { tbody.innerHTML = ''; empty.style.display = 'block'; return; }
                empty.style.display = 'none';
                tbody.innerHTML = rows.map(function (e) {
                    var statusBadge = e.status === 'sent'
                        ? '<span class="badge badge--sent">enviado</span>'
                        : '<span class="badge badge--no">' + esc(e.status) + '</span>';
                    return '<tr>' +
                        '<td>' + new Date(e.created_at).toLocaleString('es-ES') + '</td>' +
                        '<td>' + (e.order_id || '—') + '</td>' +
                        '<td>' + esc(e.to_email) + '</td>' +
                        '<td>' + esc(e.subject) + '</td>' +
                        '<td>' + esc(e.type || '—') + '</td>' +
                        '<td>' + statusBadge + '</td>' +
                        '<td><small>' + esc(e.error || '') + '</small></td>' +
                    '</tr>';
                }).join('');
            });
    }

    // ---------- order filters ----------
    document.querySelectorAll('#tab-orders .dash-filter').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#tab-orders .dash-filter').forEach(function (b) { b.classList.remove('dash-filter--active'); });
            this.classList.add('dash-filter--active');
            filters.status = this.dataset.status;
            loadOrders();
        });
    });

    document.getElementById('btn-apply-filters').addEventListener('click', function () {
        filters.q      = document.getElementById('filter-q').value.trim();
        filters.source = document.getElementById('filter-source').value;
        filters.from   = document.getElementById('filter-from').value;
        filters.to     = document.getElementById('filter-to').value;
        loadOrders();
    });
    document.getElementById('btn-clear-filters').addEventListener('click', function () {
        document.getElementById('filter-q').value = '';
        document.getElementById('filter-source').value = '';
        document.getElementById('filter-from').value = '';
        document.getElementById('filter-to').value = '';
        filters = { status: filters.status, source: '', q: '', from: '', to: '' };
        loadOrders();
    });
    document.getElementById('filter-q').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') document.getElementById('btn-apply-filters').click();
    });
    document.getElementById('btn-export-csv').addEventListener('click', function () {
        var qs = [];
        Object.keys(filters).forEach(function (k) {
            if (filters[k]) qs.push(k + '=' + encodeURIComponent(filters[k]));
        });
        window.open(API + 'admin.php?action=export-csv' + (qs.length ? '&' + qs.join('&') : ''), '_blank');
    });

    // ---------- modal close ----------
    document.getElementById('modal-close').addEventListener('click', closeModal);
    document.getElementById('modal-overlay').addEventListener('click', closeModal);
    function closeModal() { document.getElementById('order-modal').style.display = 'none'; }

    // ---------- helpers ----------
    function esc(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }
    function money(n) {
        return parseFloat(n || 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }
})();
