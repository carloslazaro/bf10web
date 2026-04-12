/**
 * Servisaco Admin Panel v2
 * Tabs: Pedidos / Facturas emitidas / Email log
 * Features: search, filters, source badges, invoice column,
 *           internal notes, events timeline, CSV export, issue-with-send modal.
 */
(function () {
    'use strict';

    var API = '../api/';
    var loginScreen = document.getElementById('login-screen');
    var dashboard = document.getElementById('dashboard');

    var filters = { status: '', source: '', brand: '', q: '', from: '', to: '' };

    var currentOrder = null;

    // ---------- session bootstrap ----------
    fetch(API + 'auth.php?action=me')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.user && (data.user.role === 'manager' || data.user.role === 'ceo')) showDashboard(data.user);
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

    var currentUserRole = 'manager';
    function showDashboard(user) {
        currentUserRole = user.role || 'manager';
        loginScreen.style.display = 'none';
        dashboard.style.display = 'block';
        document.getElementById('user-name').textContent = user.name;
        loadStats();
        loadAlbaranes();
    }

    // ---------- tabs ----------
    document.querySelectorAll('.tab').forEach(function (t) {
        t.addEventListener('click', function () {
            var name = this.dataset.tab;
            document.querySelectorAll('.tab').forEach(function (x) { x.classList.remove('tab--active'); });
            document.querySelectorAll('.tab-panel').forEach(function (x) { x.classList.remove('tab-panel--active'); });
            this.classList.add('tab--active');
            document.getElementById('tab-' + name).classList.add('tab-panel--active');
            if (name === 'emails')    loadEmailLog();
            if (name === 'customers') loadCustomers();
            if (name === 'stock')     { loadStockSummary(); loadStockMovements(); }
            if (name === 'pedidos')   { loadPedidos(); }
            if (name === 'albaranes') { loadAlbaranes(); }
        });
    });

    // ---------- Otros dropdown ----------
    var otrosBtn = document.getElementById('tab-otros-btn');
    var otrosDropdown = otrosBtn.closest('.tab-dropdown');
    otrosBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        otrosDropdown.classList.toggle('open');
    });
    document.addEventListener('click', function () {
        otrosDropdown.classList.remove('open');
    });
    document.querySelectorAll('.tab-dropdown__item').forEach(function (item) {
        item.addEventListener('click', function () {
            var name = this.dataset.tab;
            document.querySelectorAll('.tab').forEach(function (x) { x.classList.remove('tab--active'); });
            document.querySelectorAll('.tab-panel').forEach(function (x) { x.classList.remove('tab-panel--active'); });
            document.getElementById('tab-' + name).classList.add('tab-panel--active');
            otrosDropdown.classList.remove('open');
            if (name === 'emails') loadEmailLog();
            if (name === 'users') loadUsers();
            if (name === 'camiones') { loadCamiones(); loadAssignments(); }
            if (name === 'ranking') { loadRanking(); }
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

        var statusLabel = { confirmado: 'Confirmado', pendiente_pago: 'Pendiente pago', enviado: 'Enviado', recogida: 'Recogida' };

        tbody.innerHTML = orders.map(function (o) {
            var d = fmtDate(o.created_at);
            var src = o.source || 'web';
            var requestedInvoice = o.request_invoice == 1 || o.request_invoice === '1';
            var invCell = requestedInvoice
                ? '<span class="badge badge--yes">Sí</span>'
                : '<span class="badge badge--no">No</span>';

            return '<tr>' +
                '<td><strong>' + esc(o.order_code) + '</strong></td>' +
                '<td>' + d + '</td>' +
                '<td><span class="badge badge--' + src + '">' + src + '</span></td>' +
                '<td>' + esc(o.name) + '<br><small>' + esc(o.email) + '</small></td>' +
                '<td>' + esc(o.package_name) + '</td>' +
                '<td>' + money(o.package_price) + '</td>' +
                '<td>' + paymentLabel(o.payment_method) + '</td>' +
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
        if (o.status !== 'recogida')       html += '<option value="recogida">Marcar recogida ♻️</option>';
        if (o.status !== 'pendiente_pago') html += '<option value="pendiente_pago">Pendiente pago</option>';
        html += '</select>';
        // Certificate flag indicator
        if (parseInt(o.certificate_requested, 10) === 1) {
            var issued = !!o.certificate_issued_at;
            html += '<br><small class="cert-flag ' + (issued ? 'cert-flag--issued' : 'cert-flag--pending') + '">'
                  + (issued ? '📜 Certificado emitido' : '📜 Certificado solicitado')
                  + '</small>';
        }
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
            if (data.success) {
                if (data.certificate && data.certificate.issued) {
                    alert('✓ Estado actualizado.\n📜 Certificado RCD ' + (data.certificate.number || '') + ' emitido y enviado por email automáticamente.');
                }
                loadStats(); loadOrders();
            }
            else alert(data.error || 'Error al actualizar');
        });
    };

    window.issueCertificate = function (orderId) {
        if (!confirm('¿Emitir el certificado de gestión de residuos para este pedido?')) return;
        fetch(API + 'admin.php?action=issue-certificate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                alert('✓ Certificado ' + (data.certificate_number || '') + (data.newly_issued ? ' emitido' : ' (ya estaba emitido)') + ' y enviado por email.');
                loadOrders();
                document.getElementById('order-modal').style.display = 'none';
            } else {
                alert(data.error || 'Error al emitir el certificado');
            }
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
        var date = fmtDateTime(order.created_at);
        var statusLabel = { confirmado: 'Confirmado', pendiente_pago: 'Pendiente de pago', enviado: 'Enviado', recogida: 'Recogida (residuo en planta)' };
        var wantsInvoice = order.request_invoice == 1 || order.request_invoice === '1';
        var paidStr = order.paid_at ? fmtDateTime(order.paid_at) : '';

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
                        '<p><strong>Pago:</strong> ' + paymentLabel(order.payment_method) + '</p>' +
                        (order.brand ? '<p><strong>Marca:</strong> ' + esc(order.brand) + '</p>' : '') +
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
                            (order.invoice_number
                                ? '<span class="badge badge--yes" style="font-size:13px">✓ Factura ' + esc(order.invoice_number) + '</span>'
                                : '<button class="btn-action btn-primary" onclick="openIssueModal(\'' + esc(order.order_code) + '\')">🧾 Emitir factura</button>') +
                            '<a class="btn-action" href="' + API + 'invoices.php?action=download&code=' + encodeURIComponent(order.order_code) + '" target="_blank">📄 Ver factura PDF</a>' +
                            '<a class="btn-action" href="' + API + 'invoices.php?action=download-receipt&code=' + encodeURIComponent(order.order_code) + '" target="_blank">📑 Recibo PDF</a>' +
                            '<button class="btn-action" onclick="resendOrderEmail(\'' + esc(order.order_code) + '\')">✉️ Reenviar confirmación</button>' +
                        '</p>' +
                        '<hr style="border:none;border-top:1px solid #eee;margin:14px 0;">' +
                        '<h4 style="margin:0 0 8px 0;">Certificado RCD</h4>' +
                        (parseInt(order.certificate_requested, 10) === 1
                            ? '<p>✓ Cliente solicitó certificado' + (order.certificate_requested_at ? ' (' + fmtDate(order.certificate_requested_at) + ')' : '') + '</p>'
                            : '<p><em>No solicitado por el cliente.</em></p>') +
                        (order.certificate_issued_at
                            ? '<p>📜 Emitido <strong>' + esc(order.certificate_number || '') + '</strong> el ' + fmtDate(order.certificate_issued_at) + '</p>'
                            : '') +
                        '<p style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">' +
                            (order.status === 'recogida'
                                ? '<button class="btn-action btn-primary" onclick="issueCertificate(' + order.id + ')">📜 ' + (order.certificate_issued_at ? 'Reemitir' : 'Emitir') + ' certificado</button>'
                                : '<small style="color:#888">Disponible cuando el pedido pase a estado <em>recogida</em>.</small>') +
                            (order.certificate_issued_at
                                ? '<a class="btn-action" href="' + API + 'admin.php?action=download-certificate&code=' + encodeURIComponent(order.order_code) + '" target="_blank">📄 Ver certificado PDF</a>'
                                : '') +
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
                        '<div class="timeline-item__time">' + fmtDateTime(e.created_at) + '</div>' +
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
                        '<td>' + fmtDateTime(e.created_at) + '</td>' +
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
        filters.brand  = document.getElementById('filter-brand').value;
        filters.from   = document.getElementById('filter-from').value;
        filters.to     = document.getElementById('filter-to').value;
        loadOrders();
    });
    document.getElementById('btn-clear-filters').addEventListener('click', function () {
        document.getElementById('filter-q').value = '';
        document.getElementById('filter-source').value = '';
        document.getElementById('filter-brand').value = '';
        document.getElementById('filter-from').value = '';
        document.getElementById('filter-to').value = '';
        filters = { status: filters.status, source: '', brand: '', q: '', from: '', to: '' };
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

    // ---------- customers tab ----------
    function loadCustomers() {
        var q = (document.getElementById('cust-q').value || '').trim();
        var url = API + 'admin.php?action=customers' + (q ? '&q=' + encodeURIComponent(q) : '');
        fetch(url).then(function (r) { return r.json(); }).then(function (data) {
            var rows = data.customers || [];
            var tbody = document.getElementById('customers-body');
            var empty = document.getElementById('customers-empty');
            if (!rows.length) { tbody.innerHTML = ''; empty.style.display = 'block'; return; }
            empty.style.display = 'none';
            tbody.innerHTML = rows.map(function (c) {
                var last = c.last_order_at ? fmtDate(c.last_order_at) : '—';
                return '<tr>' +
                    '<td><strong>' + esc(c.name) + '</strong></td>' +
                    '<td>' + esc(c.phone) + '</td>' +
                    '<td>' + esc(c.email || '—') + '</td>' +
                    '<td>' + esc(c.nif || '—') + '</td>' +
                    '<td>' + esc(c.city || '—') + '</td>' +
                    '<td>' + (c.orders_count || 0) + '</td>' +
                    '<td>' + money(c.total_spent) + '</td>' +
                    '<td>' + last + '</td>' +
                    '<td>' +
                        '<button class="btn-action btn-view" onclick="viewCustomer(' + c.id + ')">Ver</button> ' +
                        '<button class="btn-action btn-edit" onclick="editCustomer(' + c.id + ',this)">Editar</button> ' +
                        '<button class="btn-action btn-danger" onclick="deleteCustomer(' + c.id + ',this)">Eliminar</button>' +
                    '</td>' +
                '</tr>';
            }).join('');
        });
    }

    document.getElementById('btn-cust-search').addEventListener('click', loadCustomers);
    document.getElementById('btn-cust-clear').addEventListener('click', function () {
        document.getElementById('cust-q').value = '';
        loadCustomers();
    });
    document.getElementById('cust-q').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') loadCustomers();
    });

    window.viewCustomer = function (id) {
        fetch(API + 'admin.php?action=customer-detail&id=' + id)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.customer) { alert(data.error || 'Cliente no encontrado'); return; }
                showCustomerModal(data.customer, data.orders || [], data.albaranes || []);
            });
    };

    window.editCustomer = function (id) {
        fetch(API + 'admin.php?action=customer-detail&id=' + id)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.customer) { alert(data.error || 'Cliente no encontrado'); return; }
                showCustomerEditModal(data.customer);
            });
    };

    window.deleteCustomer = function (id) {
        if (!window.confirm('¿Eliminar este cliente? Se moverá a la papelera.')) return;
        fetch(API + 'admin.php?action=customer-delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.success) {
                window.alert('Cliente eliminado');
                loadCustomers();
            } else {
                window.alert(data.error || 'Error al eliminar');
            }
        });
    };

    function showCustomerEditModal(c) {
        var modal = document.getElementById('customer-modal');
        var body  = document.getElementById('customer-body');
        var titleEl = document.getElementById('customer-modal-title');
        if (titleEl) titleEl.textContent = 'Editar cliente';
        body.innerHTML =
            '<form id="customer-edit-form">' +
                '<div class="form-row"><label>Nombre *<input type="text" name="name" value="' + esc(c.name || '') + '" required></label></div>' +
                '<div class="form-row">' +
                    '<label>NIF/CIF<input type="text" name="nif" value="' + esc(c.nif || '') + '"></label>' +
                    '<label>Teléfono<input type="tel" name="phone" value="' + esc(c.phone || '') + '"></label>' +
                '</div>' +
                '<div class="form-row"><label>Email<input type="email" name="email" value="' + esc(c.email || '') + '"></label></div>' +
                '<div class="form-row"><label>Dirección<input type="text" name="address" value="' + esc(c.address || '') + '"></label></div>' +
                '<div class="form-row">' +
                    '<label>Ciudad<input type="text" name="city" value="' + esc(c.city || '') + '"></label>' +
                    '<label>C.P.<input type="text" name="postal_code" value="' + esc(c.postal_code || '') + '"></label>' +
                '</div>' +
                '<div class="form-actions">' +
                    '<button type="button" class="btn-modal-cancel" onclick="document.getElementById(\'customer-modal\').style.display=\'none\'">Cancelar</button>' +
                    '<button type="submit" class="btn-modal-save">Guardar</button>' +
                '</div>' +
            '</form>';

        // Google Places autocomplete on customer address field
        if (typeof initAddressAutocomplete === 'function') {
            initAddressAutocomplete(document.querySelector('#customer-edit-form input[name="address"]'));
        }

        document.getElementById('customer-edit-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(e.target);
            var payload = { id: c.id };
            fd.forEach(function (v, k) { payload[k] = v; });
            fetch(API + 'admin.php?action=customer-update', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data.success) {
                    window.alert('Cliente actualizado');
                    modal.style.display = 'none';
                    loadCustomers();
                } else {
                    window.alert(data.error || 'Error al guardar');
                }
            });
        });

        modal.style.display = 'flex';
    }

    function showCustomerModal(c, orders, albaranes) {
        var modal = document.getElementById('customer-modal');
        var body  = document.getElementById('customer-body');
        var titleEl = document.getElementById('customer-modal-title');
        if (titleEl) titleEl.textContent = 'Ficha del cliente';
        var statusLabel = { confirmado: 'Confirmado', pendiente_pago: 'Pendiente', enviado: 'Enviado', recogida: 'Recogida' };
        var pagoLabel = { pendiente: 'Pendiente', efectivo: 'Efectivo', tarjeta: 'Tarjeta', transferencia: 'Transferencia' };

        // Albaranes table
        var albaranesHtml = (albaranes && albaranes.length)
            ? '<table class="dash-table"><thead><tr>' +
                '<th>Código</th><th>Fecha</th><th>Marca</th><th>Sacas</th><th>Importe</th>' +
                '<th>Pago</th><th>Pagado</th><th>Factura</th><th>Comercial</th>' +
              '</tr></thead><tbody>' +
              albaranes.map(function (a) {
                  return '<tr>' +
                    '<td><strong>' + esc(a.albaran_code) + '</strong></td>' +
                    '<td>' + fmtDate(a.fecha_entrega) + '</td>' +
                    '<td>' + esc(a.marca) + '</td>' +
                    '<td>' + a.num_sacas + '</td>' +
                    '<td>' + money(a.importe) + '</td>' +
                    '<td>' + (pagoLabel[a.forma_pago] || a.forma_pago) + '</td>' +
                    '<td>' + (a.pagado ? '<span class="badge badge--yes">Sí</span>' : '<span class="badge badge--no">No</span>') + '</td>' +
                    '<td>' + (a.invoice_number ? esc(a.invoice_number) : '—') + '</td>' +
                    '<td>' + esc(a.comercial_name || '—') + '</td>' +
                  '</tr>';
              }).join('') +
              '</tbody></table>'
            : '<p style="color:#94a3b8;font-size:0.9rem"><em>Sin albaranes registrados.</em></p>';

        // Web orders table
        var ordersHtml = orders.length
            ? '<table class="dash-table"><thead><tr>' +
                '<th>Código</th><th>Fecha</th><th>Marca</th><th>Pack</th><th>Importe</th>' +
                '<th>Pago</th><th>Estado</th><th>Factura</th><th></th>' +
              '</tr></thead><tbody>' +
              orders.map(function (o) {
                  return '<tr>' +
                    '<td><strong>' + esc(o.order_code) + '</strong></td>' +
                    '<td>' + fmtDate(o.created_at) + '</td>' +
                    '<td>' + esc(o.brand || 'BF10') + '</td>' +
                    '<td>' + esc(o.package_name) + '</td>' +
                    '<td>' + money(o.package_price) + '</td>' +
                    '<td>' + paymentLabel(o.payment_method) + '</td>' +
                    '<td><span class="status status--' + (o.status||'').replace('_','-') + '">' + (statusLabel[o.status]||o.status) + '</span></td>' +
                    '<td>' + (o.invoice_number ? esc(o.invoice_number) : '—') + '</td>' +
                    '<td><button class="btn-action btn-view" onclick="viewOrder(\'' + esc(o.order_code) + '\')">Ver</button></td>' +
                  '</tr>';
              }).join('') +
              '</tbody></table>'
            : '<p style="color:#94a3b8;font-size:0.9rem"><em>Sin pedidos web registrados.</em></p>';

        body.innerHTML =
            '<div class="modal-grid">' +
                '<div class="modal-section">' +
                    '<h3>Datos</h3>' +
                    '<p><strong>Nombre:</strong> ' + esc(c.name) + '</p>' +
                    (c.nif ? '<p><strong>NIF/CIF:</strong> ' + esc(c.nif) + '</p>' : '') +
                    '<p><strong>Teléfono:</strong> <a href="tel:' + esc(c.phone) + '">' + esc(c.phone) + '</a></p>' +
                    (c.email ? '<p><strong>Email:</strong> <a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a></p>' : '') +
                    (c.address ? '<p><strong>Dirección:</strong> ' + esc(c.address) + '</p>' : '') +
                    (c.city ? '<p>' + esc(c.postal_code || '') + ' ' + esc(c.city) + '</p>' : '') +
                '</div>' +
                '<div class="modal-section">' +
                    '<h3>Resumen</h3>' +
                    '<p><strong>Pedidos web:</strong> ' + (c.orders_count || 0) + '</p>' +
                    '<p><strong>Albaranes:</strong> ' + (albaranes ? albaranes.length : 0) + '</p>' +
                    '<p><strong>Gasto total (web):</strong> ' + money(c.total_spent) + '</p>' +
                    (c.first_order_at ? '<p><strong>Primer pedido:</strong> ' + fmtDate(c.first_order_at) + '</p>' : '') +
                    (c.last_order_at  ? '<p><strong>Último pedido:</strong> ' + fmtDate(c.last_order_at) + '</p>' : '') +
                '</div>' +
            '</div>' +
            '<h3 style="margin-top:24px">Albaranes</h3>' +
            albaranesHtml +
            '<h3 style="margin-top:24px">Pedidos web</h3>' +
            ordersHtml;

        modal.style.display = 'flex';
    }

    document.getElementById('customer-close').addEventListener('click', function () {
        document.getElementById('customer-modal').style.display = 'none';
    });
    document.getElementById('customer-overlay').addEventListener('click', function () {
        document.getElementById('customer-modal').style.display = 'none';
    });

    // ---------- create order modal ----------
    var createModal = document.getElementById('create-modal');
    document.getElementById('btn-create-order').addEventListener('click', function () {
        document.getElementById('create-form').reset();
        document.getElementById('create-error').style.display = 'none';
        createModal.style.display = 'flex';
    });
    function closeCreateModal() { createModal.style.display = 'none'; }
    document.getElementById('create-close').addEventListener('click', closeCreateModal);
    document.getElementById('create-overlay').addEventListener('click', closeCreateModal);
    document.getElementById('create-cancel').addEventListener('click', closeCreateModal);

    document.getElementById('create-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var form = e.target;
        var data = {};
        Array.prototype.slice.call(form.elements).forEach(function (el) {
            if (!el.name) return;
            if (el.type === 'checkbox') data[el.name] = el.checked ? 1 : 0;
            else data[el.name] = el.value;
        });

        var btn = document.getElementById('create-submit');
        var errEl = document.getElementById('create-error');
        errEl.style.display = 'none';
        btn.disabled = true; btn.textContent = 'Creando...';

        fetch(API + 'admin.php?action=create-order', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            btn.disabled = false; btn.textContent = 'Crear pedido';
            if (!resp.success) {
                errEl.textContent = resp.error || 'Error al crear el pedido';
                errEl.style.display = 'block';
                return;
            }
            var msg = '✓ Pedido ' + resp.order.code + ' creado (' + resp.order.brand + ').';
            if (resp.checkout_url) msg += '\n\nEnlace de pago Stripe:\n' + resp.checkout_url;
            alert(msg);
            closeCreateModal();
            loadStats();
            loadOrders();
        })
        .catch(function () {
            btn.disabled = false; btn.textContent = 'Crear pedido';
            errEl.textContent = 'Error de conexión';
            errEl.style.display = 'block';
        });
    });

    // ---------- helpers ----------
    function paymentLabel(m) {
        if (m === 'card')     return 'Tarjeta';
        if (m === 'transfer') return 'Transferencia';
        if (m === 'cash')     return 'Efectivo';
        return m || '—';
    }
    function fmtDate(s) {
        if (!s) return '—';
        var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? m[3] + '/' + m[2] + '/' + m[1] : s;
    }
    function fmtDateTime(s) {
        if (!s) return '—';
        var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
        return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : s;
    }
    function esc(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }
    function money(n) {
        return parseFloat(n || 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    // ============================================================
    // STOCK MANAGEMENT
    // ============================================================

    var brandColors = { BF10: '#DA291C', SERVISACO: '#1B5E20', ATUSACO: '#1565C0', ATUSACO_LUISFER: '#0D47A1', ATUSACO_HERREROCON: '#283593', ATUSACO_COSASCASA: '#4527A0', ECOSACO: '#2E7D32', SACAS_BLANCAS: '#795548' };
    var stockTrashMode = false;
    var stockEditId = null; // null = create, number = edit

    function loadStockSummary() {
        fetch(API + 'stock.php?action=summary')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var container = document.getElementById('stock-summary');
                if (!data.summary) return;
                container.innerHTML = data.summary.map(function (s) {
                    var color = brandColors[s.marca] || '#333';
                    return '<div class="dash-stat" style="border-top:3px solid ' + color + '">' +
                        '<span class="dash-stat__value">' + s.stock + '</span>' +
                        '<span class="dash-stat__label">' + esc(s.marca) + '</span>' +
                        '<span style="font-size:11px;color:#888">E: ' + s.entradas + ' | S: ' + s.salidas + '</span>' +
                        '</div>';
                }).join('');
            });
    }

    function loadStockMovements() {
        if (stockTrashMode) {
            fetch(API + 'stock.php?action=trash')
                .then(function (r) { return r.json(); })
                .then(function (data) { renderStockTable(data.movimientos || [], true); });
            return;
        }

        var marca  = document.getElementById('stock-marca').value;
        var tipo   = document.getElementById('stock-tipo').value;
        var motivo = document.getElementById('stock-motivo').value;
        var desde  = document.getElementById('stock-desde').value;
        var hasta  = document.getElementById('stock-hasta').value;

        var qs = 'action=list';
        if (marca)  qs += '&marca=' + marca;
        if (tipo)   qs += '&tipo=' + tipo;
        if (motivo) qs += '&motivo=' + motivo;
        if (desde)  qs += '&desde=' + desde;
        if (hasta)  qs += '&hasta=' + hasta;

        fetch(API + 'stock.php?' + qs)
            .then(function (r) { return r.json(); })
            .then(function (data) { renderStockTable(data.movimientos || [], false); });
    }

    function renderStockTable(rows, isTrash) {
        var tbody = document.getElementById('stock-body');
        var empty = document.getElementById('stock-empty');
        if (!rows.length) {
            tbody.innerHTML = '';
            empty.style.display = 'block';
            empty.textContent = isTrash ? 'Papelera vacía' : 'Sin movimientos de stock';
            return;
        }
        empty.style.display = 'none';

        var tipoLabels = { entrada: '🟢 Entrada', salida: '🔴 Salida' };
        var motivoLabels = { compra: 'Compra', devolucion: 'Devolución', venta_albaran: 'Venta (albarán)', ajuste: 'Ajuste', otro: 'Otro' };

        tbody.innerHTML = rows.map(function (m) {
            var fecha = fmtDateTime(m.created_at);
            var num = '';
            if (m.numeracion_inicial && m.numeracion_final) num = m.numeracion_inicial + ' - ' + m.numeracion_final;
            else if (m.numeracion_inicial) num = m.numeracion_inicial + '';
            var alb = m.albaran_code ? esc(m.albaran_code) : '—';
            var canEdit = !m.albaran_id;
            var actions = '';

            if (isTrash) {
                actions = '<button class="btn-action btn-sm" data-restore-stock="' + m.id + '" style="color:#2e7d32">Restaurar</button>';
            } else {
                if (canEdit) {
                    actions = '<button class="btn-action btn-sm" data-edit-stock="' + m.id + '">Editar</button> ' +
                              '<button class="btn-action btn-sm" data-delete-stock="' + m.id + '" style="color:#c00">Eliminar</button>';
                }
            }

            return '<tr>' +
                '<td>' + esc(fecha) + '</td>' +
                '<td><span style="color:' + (brandColors[m.marca] || '#333') + ';font-weight:600">' + esc(m.marca) + '</span></td>' +
                '<td>' + (tipoLabels[m.tipo] || m.tipo) + '</td>' +
                '<td style="font-weight:600">' + m.cantidad + '</td>' +
                '<td>' + (motivoLabels[m.motivo] || m.motivo) + '</td>' +
                '<td>' + esc(num || '—') + '</td>' +
                '<td>' + alb + '</td>' +
                '<td>' + esc(m.user_name || '') + '</td>' +
                '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(m.comentarios || '') + '">' + esc(m.comentarios || '—') + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>';
        }).join('');
    }

    // Stock filters
    document.getElementById('btn-stock-filter').addEventListener('click', loadStockMovements);
    document.getElementById('btn-stock-clear').addEventListener('click', function () {
        document.getElementById('stock-marca').value = '';
        document.getElementById('stock-tipo').value = '';
        document.getElementById('stock-motivo').value = '';
        document.getElementById('stock-desde').value = '';
        document.getElementById('stock-hasta').value = '';
        loadStockMovements();
    });

    // Stock trash toggle
    document.getElementById('btn-stock-trash').addEventListener('click', function () {
        stockTrashMode = !stockTrashMode;
        this.textContent = stockTrashMode ? 'Volver a stock' : 'Papelera';
        this.style.background = stockTrashMode ? '#fce4ec' : '';
        this.style.color = stockTrashMode ? '#c00' : '';
        document.getElementById('btn-stock-add').style.display = stockTrashMode ? 'none' : '';
        loadStockMovements();
    });

    // Stock modal open for CREATE
    document.getElementById('btn-stock-add').addEventListener('click', function () {
        stockEditId = null;
        document.getElementById('stock-modal-title').textContent = 'Registrar movimiento de stock';
        document.getElementById('stock-form').reset();
        document.getElementById('stock-error').style.display = 'none';
        document.getElementById('stock-modal').style.display = 'flex';
    });

    // Stock modal close
    document.getElementById('stock-close').addEventListener('click', function () { document.getElementById('stock-modal').style.display = 'none'; });
    document.getElementById('stock-cancel').addEventListener('click', function () { document.getElementById('stock-modal').style.display = 'none'; });
    document.getElementById('stock-overlay').addEventListener('click', function () { document.getElementById('stock-modal').style.display = 'none'; });

    // Stock form submit (create or update)
    document.getElementById('stock-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var errEl = document.getElementById('stock-error');
        errEl.style.display = 'none';

        var payload = {
            marca: document.getElementById('stock-f-marca').value,
            tipo: document.getElementById('stock-f-tipo').value,
            cantidad: parseInt(document.getElementById('stock-f-cantidad').value) || 0,
            motivo: document.getElementById('stock-f-motivo').value,
            numeracion_inicial: document.getElementById('stock-f-num-ini').value || null,
            numeracion_final: document.getElementById('stock-f-num-fin').value || null,
            comentarios: document.getElementById('stock-f-comentarios').value,
        };

        var actionUrl = 'stock.php?action=create';
        if (stockEditId) {
            payload.id = stockEditId;
            actionUrl = 'stock.php?action=update';
        }

        fetch(API + actionUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                document.getElementById('stock-modal').style.display = 'none';
                loadStockSummary();
                loadStockMovements();
            } else {
                errEl.textContent = data.error || 'Error al guardar';
                errEl.style.display = 'block';
            }
        })
        .catch(function () {
            errEl.textContent = 'Error de conexión';
            errEl.style.display = 'block';
        });
    });

    // ============================================================
    // ALBARANES MANAGEMENT
    // ============================================================

    var albTrashMode = false;
    var allAlbaranes = [];

    function loadAlbaranes() {
        if (albTrashMode) {
            fetch(API + 'albaranes.php?action=trash')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    allAlbaranes = data.albaranes || [];
                    renderAlbaranes();
                    renderAlbStats();
                });
            return;
        }
        var desde = document.getElementById('alb-desde').value;
        var hasta = document.getElementById('alb-hasta').value;
        var pagado = document.getElementById('alb-pagado').value;
        var url = 'albaranes.php?action=list';
        if (desde) url += '&desde=' + desde;
        if (hasta) url += '&hasta=' + hasta;
        if (pagado !== '') url += '&pagado=' + pagado;
        fetch(API + url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                allAlbaranes = data.albaranes || [];
                renderAlbaranes();
                renderAlbStats();
            });
    }

    function renderAlbaranes() {
        var tbody = document.getElementById('alb-body');
        var empty = document.getElementById('alb-empty');
        if (!allAlbaranes.length) {
            tbody.innerHTML = '';
            empty.style.display = 'block';
            empty.textContent = albTrashMode ? 'Papelera vacía' : 'No hay albaranes';
            return;
        }
        empty.style.display = 'none';
        var pagoLabels = { efectivo: 'Efectivo', tarjeta: 'Tarjeta', transferencia: 'Transfer.', pendiente: 'Pendiente' };
        tbody.innerHTML = allAlbaranes.map(function (a) {
            var actions = '';
            if (albTrashMode) {
                actions = '<button class="btn-action btn-sm" data-alb-restore="' + a.id + '" style="color:#2e7d32">Restaurar</button>';
            } else {
                var invoiceBtn = a.invoice_number
                    ? '<button class="btn-action btn-sm" data-alb-view-invoice="' + a.id + '" style="color:#2e7d32;border-color:#2e7d32" title="' + esc(a.invoice_number) + '">✓ ' + esc(a.invoice_number) + '</button>'
                    : '<button class="btn-action btn-sm" data-alb-invoice="' + a.id + '" style="color:#1565C0">Facturar</button>';
                actions = '<button class="btn-action btn-sm btn-view" data-alb-view="' + a.id + '">Ver</button> ' +
                          '<button class="btn-action btn-sm" data-alb-edit="' + a.id + '">Editar</button> ' +
                          invoiceBtn + ' ' +
                          '<button class="btn-action btn-sm" data-alb-del="' + a.id + '" style="color:#c00">Eliminar</button>';
            }
            var dir = esc(a.direccion_envio || '');
            var dirCell = dir || '—';
            if (dir) {
                var mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(a.direccion_envio);
                dirCell = dir + ' <a href="' + mapsUrl + '" target="_blank" title="Ver en Google Maps" style="text-decoration:none;font-size:14px">📍</a>';
            }
            return '<tr>' +
                '<td><strong>' + esc(a.albaran_code) + '</strong></td>' +
                '<td>' + (a.fecha_entrega || '') + '</td>' +
                '<td>' + esc(a.cliente) + '</td>' +
                '<td>' + dirCell + '</td>' +
                '<td><span style="color:' + (brandColors[a.marca] || '#333') + ';font-weight:600">' + esc(a.marca) + '</span></td>' +
                '<td>' + a.num_sacas + '</td>' +
                '<td>' + Number(a.importe).toFixed(2) + ' €</td>' +
                '<td>' + (pagoLabels[a.forma_pago] || a.forma_pago) + '</td>' +
                '<td><span class="badge badge--' + (a.pagado == 1 ? 'yes' : 'no') + '">' + (a.pagado == 1 ? 'Sí' : 'No') + '</span></td>' +
                '<td>' + esc(a.comercial_name || '') + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>';
        }).join('');
    }

    function renderAlbStats() {
        var total = allAlbaranes.length;
        var totalImporte = allAlbaranes.reduce(function (s, a) { return s + Number(a.importe); }, 0);
        var pagados = allAlbaranes.filter(function (a) { return a.pagado == 1; }).length;
        var totalSacas = allAlbaranes.reduce(function (s, a) { return s + Number(a.num_sacas); }, 0);
        document.getElementById('alb-stats').innerHTML =
            '<div class="dash-stat"><span class="dash-stat__value">' + total + '</span><span class="dash-stat__label">Albaranes</span></div>' +
            '<div class="dash-stat"><span class="dash-stat__value">' + totalSacas + '</span><span class="dash-stat__label">Total sacas</span></div>' +
            '<div class="dash-stat"><span class="dash-stat__value">' + totalImporte.toFixed(2) + ' €</span><span class="dash-stat__label">Importe total</span></div>' +
            '<div class="dash-stat"><span class="dash-stat__value">' + pagados + ' / ' + total + '</span><span class="dash-stat__label">Pagados</span></div>';
    }

    // Alb filters
    document.getElementById('btn-alb-filter').addEventListener('click', loadAlbaranes);
    document.getElementById('btn-alb-clear').addEventListener('click', function () {
        document.getElementById('alb-desde').value = '';
        document.getElementById('alb-hasta').value = '';
        document.getElementById('alb-pagado').value = '';
        loadAlbaranes();
    });

    // Alb trash toggle
    document.getElementById('btn-alb-trash').addEventListener('click', function () {
        albTrashMode = !albTrashMode;
        this.textContent = albTrashMode ? 'Volver a albaranes' : 'Papelera';
        this.style.background = albTrashMode ? '#fce4ec' : '';
        this.style.color = albTrashMode ? '#c00' : '';
        document.getElementById('btn-alb-new').style.display = albTrashMode ? 'none' : '';
        loadAlbaranes();
    });

    // Alb modal
    function openAlbModal(data) {
        document.getElementById('alb-error').style.display = 'none';
        document.getElementById('alb-modal-title').textContent = data ? 'Editar albarán' : 'Nuevo albarán';
        document.getElementById('alb-f-id').value = data ? data.id : '';
        document.getElementById('alb-f-cliente').value = data ? data.cliente : '';
        document.getElementById('alb-f-cliente-id').value = data ? (data.customer_id || '') : '';
        document.getElementById('alb-client-dropdown').style.display = 'none';
        document.getElementById('alb-f-direccion').value = data ? (data.direccion_envio || '') : '';
        document.getElementById('alb-f-fecha').value = data ? data.fecha_entrega : new Date().toISOString().split('T')[0];
        document.getElementById('alb-f-marca').value = data ? data.marca : 'BF10';
        document.getElementById('alb-f-sacas').value = data ? data.num_sacas : '';
        document.getElementById('alb-f-num-ini').value = data ? (data.numeracion_inicial || '') : '';
        document.getElementById('alb-f-num-fin').value = data ? (data.numeracion_final || '') : '';
        document.getElementById('alb-f-precio').value = data ? (data.precio || '') : '';
        document.getElementById('alb-f-pago').value = data ? data.forma_pago : 'pendiente';
        document.getElementById('alb-f-importe').value = data ? data.importe : '';
        document.getElementById('alb-f-pagado').checked = data ? data.pagado == 1 : false;
        document.getElementById('alb-f-comentarios').value = data ? (data.comentarios || '') : '';
        document.getElementById('alb-modal').style.display = 'flex';
    }

    function closeAlbModal() { document.getElementById('alb-modal').style.display = 'none'; }

    // ---------- Client search autocomplete ----------
    var clientSearchTimer = null;
    var clientInput = document.getElementById('alb-f-cliente');
    var clientIdInput = document.getElementById('alb-f-cliente-id');
    var clientDropdown = document.getElementById('alb-client-dropdown');

    clientInput.addEventListener('input', function () {
        clearTimeout(clientSearchTimer);
        clientIdInput.value = '';
        var q = this.value.trim();
        if (q.length < 2) { clientDropdown.style.display = 'none'; return; }
        clientSearchTimer = setTimeout(function () {
            fetch(API + 'admin.php?action=customers&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var customers = data.customers || [];
                    var html = '';
                    customers.slice(0, 8).forEach(function (c) {
                        var detail = [c.phone, c.email, c.nif].filter(Boolean).join(' · ');
                        html += '<div class="client-dropdown-item" data-id="' + c.id + '" data-name="' + esc(c.name) + '">' +
                            esc(c.name) + (detail ? '<small>' + esc(detail) + '</small>' : '') + '</div>';
                    });
                    html += '<div class="client-dropdown-item client-dropdown-item--new" id="alb-client-new">+ Dar de alta nuevo cliente</div>';
                    clientDropdown.innerHTML = html;
                    clientDropdown.style.display = '';
                });
        }, 250);
    });

    clientDropdown.addEventListener('click', function (e) {
        var item = e.target.closest('.client-dropdown-item');
        if (!item) return;
        if (item.id === 'alb-client-new') {
            var nombre = prompt('Nombre del nuevo cliente:');
            if (!nombre || !nombre.trim()) return;
            fetch(API + 'admin.php?action=customer-create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: nombre.trim() }),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    clientInput.value = data.name;
                    clientIdInput.value = data.id;
                    clientDropdown.style.display = 'none';
                } else { alert(data.error || 'Error al crear cliente'); }
            });
            return;
        }
        clientInput.value = item.dataset.name;
        clientIdInput.value = item.dataset.id;
        clientDropdown.style.display = 'none';
    });

    // Close dropdown on click outside
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.client-search-wrap')) clientDropdown.style.display = 'none';
    });

    document.getElementById('btn-alb-new').addEventListener('click', function () { openAlbModal(null); });
    document.getElementById('alb-cancel').addEventListener('click', closeAlbModal);
    document.getElementById('alb-close').addEventListener('click', closeAlbModal);
    document.getElementById('alb-overlay').addEventListener('click', closeAlbModal);

    // Auto-calculate importe = precio × sacas (admin)
    function albCalcImporte() {
        var precio = parseFloat(document.getElementById('alb-f-precio').value) || 0;
        var sacas = parseInt(document.getElementById('alb-f-sacas').value) || 0;
        document.getElementById('alb-f-importe').value = (precio * sacas).toFixed(2);
    }
    document.getElementById('alb-f-precio').addEventListener('input', albCalcImporte);
    document.getElementById('alb-f-sacas').addEventListener('input', albCalcImporte);

    // Alb form submit
    document.getElementById('alb-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var errEl = document.getElementById('alb-error');
        errEl.style.display = 'none';

        var id = document.getElementById('alb-f-id').value;
        var payload = {
            cliente: document.getElementById('alb-f-cliente').value,
            direccion_envio: document.getElementById('alb-f-direccion').value,
            fecha_entrega: document.getElementById('alb-f-fecha').value,
            marca: document.getElementById('alb-f-marca').value,
            num_sacas: parseInt(document.getElementById('alb-f-sacas').value) || 0,
            numeracion_inicial: document.getElementById('alb-f-num-ini').value || null,
            numeracion_final: document.getElementById('alb-f-num-fin').value || null,
            precio: parseFloat(document.getElementById('alb-f-precio').value) || 0,
            forma_pago: document.getElementById('alb-f-pago').value,
            importe: parseFloat(document.getElementById('alb-f-importe').value) || 0,
            pagado: document.getElementById('alb-f-pagado').checked ? 1 : 0,
            comentarios: document.getElementById('alb-f-comentarios').value,
        };

        if (!payload.cliente || !payload.fecha_entrega || !payload.num_sacas) {
            errEl.textContent = 'Completa los campos obligatorios (cliente, fecha, sacas).';
            errEl.style.display = 'block';
            return;
        }

        var action = id ? 'update' : 'create';
        if (id) payload.id = parseInt(id);

        fetch(API + 'albaranes.php?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) { closeAlbModal(); loadAlbaranes(); }
            else {
                errEl.textContent = data.error || 'Error al guardar';
                errEl.style.display = 'block';
            }
        })
        .catch(function () {
            errEl.textContent = 'Error de conexión';
            errEl.style.display = 'block';
        });
    });

    // Alb detail modal
    var currentAlbaran = null;

    function showAlbDetail(albaran) {
        currentAlbaran = albaran;
        var modal = document.getElementById('alb-detail-modal');
        var body = document.getElementById('alb-detail-body');
        document.getElementById('alb-detail-title').textContent = 'Albarán ' + esc(albaran.albaran_code);

        var pagoLabels = { efectivo: 'Efectivo', tarjeta: 'Tarjeta', transferencia: 'Transferencia', pendiente: 'Pendiente' };
        var hasInvoice = !!albaran.invoice_number;

        var precioStr = albaran.precio ? (Number(albaran.precio).toFixed(2) + ' €/saca') : '—';

        body.innerHTML =
            '<div class="modal-tabs">' +
                '<button class="modal-tab modal-tab--active" data-mt="info">Información</button>' +
                '<button class="modal-tab" data-mt="notes">Notas internas</button>' +
            '</div>' +

            '<div class="modal-tab-panel modal-tab-panel--active" data-mt-panel="info">' +
                '<div class="modal-grid">' +
                    '<div class="modal-section">' +
                        '<h3>Albarán</h3>' +
                        '<p><strong>Código:</strong> ' + esc(albaran.albaran_code) + '</p>' +
                        '<p><strong>Fecha entrega:</strong> ' + esc(albaran.fecha_entrega) + '</p>' +
                        '<p><strong>Marca:</strong> <span style="color:' + (brandColors[albaran.marca] || '#333') + ';font-weight:600">' + esc(albaran.marca) + '</span></p>' +
                        '<p><strong>Sacas:</strong> ' + albaran.num_sacas + '</p>' +
                        '<p><strong>Precio:</strong> ' + precioStr + '</p>' +
                        (albaran.numeracion_inicial ? '<p><strong>Numeración:</strong> ' + albaran.numeracion_inicial + ' — ' + (albaran.numeracion_final || '') + '</p>' : '') +
                    '</div>' +
                    '<div class="modal-section">' +
                        '<h3>Cliente</h3>' +
                        '<p><strong>Nombre:</strong> ' + esc(albaran.cliente) + '</p>' +
                    '</div>' +
                    '<div class="modal-section">' +
                        '<h3>Pago</h3>' +
                        '<p><strong>Forma de pago:</strong> ' + (pagoLabels[albaran.forma_pago] || albaran.forma_pago) + '</p>' +
                        '<p><strong>Importe:</strong> ' + Number(albaran.importe).toFixed(2) + ' €</p>' +
                        '<p><strong>Pagado:</strong> <span class="badge badge--' + (albaran.pagado == 1 ? 'yes' : 'no') + '">' + (albaran.pagado == 1 ? 'Sí' : 'No') + '</span></p>' +
                    '</div>' +
                    '<div class="modal-section">' +
                        '<h3>Factura</h3>' +
                        (hasInvoice
                            ? '<p><span class="badge badge--yes" style="font-size:13px">✓ ' + esc(albaran.invoice_number) + '</span></p>' +
                              '<p style="margin-top:8px"><a class="btn-action btn-view" href="' + API + 'invoices.php?action=download&code=' + encodeURIComponent(albaran.albaran_code) + '" target="_blank">📄 Ver factura PDF</a></p>'
                            : '<p><em>Sin factura</em></p>' +
                              '<p style="margin-top:8px"><button class="btn-action btn-primary" onclick="albDetailInvoice(' + albaran.id + ')">🧾 Emitir factura</button></p>') +
                    '</div>' +
                '</div>' +
                '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;padding-top:12px;border-top:1px solid #eee">' +
                    '<p style="color:#888;font-size:.82rem"><strong>Comercial:</strong> ' + esc(albaran.comercial_name || '—') + ' · <strong>Creado:</strong> ' + fmtDateTime(albaran.created_at) + '</p>' +
                '</div>' +
                '<p style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px">' +
                    '<button class="btn-action btn-primary" onclick="albDetailEdit()">✏️ Editar albarán</button>' +
                '</p>' +
            '</div>' +

            '<div class="modal-tab-panel" data-mt-panel="notes">' +
                '<h3>Notas internas</h3>' +
                '<textarea class="notes-textarea" id="alb-notes-text">' + esc(albaran.comentarios || '') + '</textarea>' +
                '<p style="margin-top:10px"><button class="btn-action btn-primary" onclick="saveAlbNotes(' + albaran.id + ')">💾 Guardar notas</button></p>' +
            '</div>';

        // Wire modal tabs
        body.querySelectorAll('.modal-tab').forEach(function (mt) {
            mt.addEventListener('click', function () {
                var name = this.dataset.mt;
                body.querySelectorAll('.modal-tab').forEach(function (x) { x.classList.remove('modal-tab--active'); });
                body.querySelectorAll('.modal-tab-panel').forEach(function (x) { x.classList.remove('modal-tab-panel--active'); });
                this.classList.add('modal-tab--active');
                body.querySelector('[data-mt-panel="' + name + '"]').classList.add('modal-tab-panel--active');
            });
        });

        modal.style.display = 'flex';
    }

    function closeAlbDetail() { document.getElementById('alb-detail-modal').style.display = 'none'; }
    document.getElementById('alb-detail-close').addEventListener('click', closeAlbDetail);
    document.getElementById('alb-detail-overlay').addEventListener('click', closeAlbDetail);

    window.albDetailEdit = function () {
        if (currentAlbaran) {
            closeAlbDetail();
            openAlbModal(currentAlbaran);
        }
    };

    window.albDetailInvoice = function (id) {
        var row = allAlbaranes.find(function (a) { return a.id == id; });
        if (!row) return;
        var msg = 'Emitir factura para:\n\nAlbarán: ' + row.albaran_code +
            '\nCliente: ' + row.cliente +
            '\nImporte: ' + Number(row.importe).toFixed(2) + ' €' +
            '\n\n¿Continuar?';
        if (!window.confirm(msg)) return;
        fetch(API + 'albaranes.php?action=invoice', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                var inv = data.invoice;
                if (data.is_new) {
                    alert('✓ Factura emitida: ' + inv.invoice_number + '\nImporte: ' + Number(inv.total_amount).toFixed(2) + ' €');
                } else {
                    alert('Este albarán ya tiene factura: ' + inv.invoice_number);
                }
                closeAlbDetail();
                loadAlbaranes();
            } else {
                alert(data.error || 'Error al emitir factura');
            }
        });
    };

    window.saveAlbNotes = function (id) {
        var notes = document.getElementById('alb-notes-text').value;
        fetch(API + 'albaranes.php?action=update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, comentarios: notes }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                alert('✓ Notas guardadas');
                if (currentAlbaran) currentAlbaran.comentarios = notes;
                loadAlbaranes();
            } else {
                alert(data.error || 'Error al guardar');
            }
        });
    };

    // Alb table actions (delegated)
    document.getElementById('alb-body').addEventListener('click', function (e) {
        // VIEW detail
        var viewBtn = e.target.closest('[data-alb-view]');
        if (viewBtn) {
            var id = parseInt(viewBtn.dataset.albView);
            var row = allAlbaranes.find(function (a) { return a.id == id; });
            if (row) showAlbDetail(row);
            return;
        }

        var editBtn = e.target.closest('[data-alb-edit]');
        if (editBtn) {
            var id = parseInt(editBtn.dataset.albEdit);
            var row = allAlbaranes.find(function (a) { return a.id == id; });
            if (row) openAlbModal(row);
            return;
        }

        // VIEW invoice detail
        var viewInvBtn = e.target.closest('[data-alb-view-invoice]');
        if (viewInvBtn) {
            var id = parseInt(viewInvBtn.dataset.albViewInvoice);
            var row = allAlbaranes.find(function (a) { return a.id == id; });
            if (row) showAlbDetail(row);
            return;
        }

        var invBtn = e.target.closest('[data-alb-invoice]');
        if (invBtn) {
            e.preventDefault();
            e.stopPropagation();
            var id = parseInt(invBtn.dataset.albInvoice);
            var row = allAlbaranes.find(function (a) { return a.id == id; });
            if (!row) return;
            var msg = 'Emitir factura para:\n\nAlbarán: ' + row.albaran_code +
                '\nCliente: ' + row.cliente +
                '\nImporte: ' + Number(row.importe).toFixed(2) + ' €' +
                '\n\n¿Continuar?';
            if (!window.confirm(msg)) return;
            fetch(API + 'albaranes.php?action=invoice', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id }),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var inv = data.invoice;
                    if (data.is_new) {
                        alert('✓ Factura emitida: ' + inv.invoice_number + '\nImporte: ' + Number(inv.total_amount).toFixed(2) + ' €');
                    } else {
                        alert('Este albarán ya tiene factura: ' + inv.invoice_number);
                    }
                    loadAlbaranes();
                } else {
                    alert(data.error || 'Error al emitir factura');
                }
            });
            return;
        }

        var delBtn = e.target.closest('[data-alb-del]');
        if (delBtn) {
            if (!confirm('¿Mover este albarán a la papelera?')) return;
            fetch(API + 'albaranes.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(delBtn.dataset.albDel) }),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) loadAlbaranes();
                else alert(data.error || 'Error al eliminar');
            });
            return;
        }

        var restBtn = e.target.closest('[data-alb-restore]');
        if (restBtn) {
            if (!confirm('¿Restaurar este albarán?')) return;
            fetch(API + 'albaranes.php?action=restore', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(restBtn.dataset.albRestore) }),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) loadAlbaranes();
                else alert(data.error || 'Error al restaurar');
            });
            return;
        }
    });

    // Stock table actions (delegated): edit, delete, restore
    document.getElementById('stock-body').addEventListener('click', function (e) {
        // EDIT
        var editBtn = e.target.closest('[data-edit-stock]');
        if (editBtn) {
            var id = parseInt(editBtn.dataset.editStock);
            fetch(API + 'stock.php?action=detail&id=' + id)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.movimiento) return;
                    var m = data.movimiento;
                    stockEditId = m.id;
                    document.getElementById('stock-modal-title').textContent = 'Editar movimiento de stock';
                    document.getElementById('stock-f-marca').value = m.marca || 'BF10';
                    document.getElementById('stock-f-tipo').value = m.tipo || 'entrada';
                    document.getElementById('stock-f-cantidad').value = m.cantidad || '';
                    document.getElementById('stock-f-motivo').value = m.motivo || 'otro';
                    document.getElementById('stock-f-num-ini').value = m.numeracion_inicial || '';
                    document.getElementById('stock-f-num-fin').value = m.numeracion_final || '';
                    document.getElementById('stock-f-comentarios').value = m.comentarios || '';
                    document.getElementById('stock-error').style.display = 'none';
                    document.getElementById('stock-modal').style.display = 'flex';
                });
            return;
        }

        // DELETE (soft)
        var delBtn = e.target.closest('[data-delete-stock]');
        if (delBtn) {
            if (!confirm('¿Mover este movimiento a la papelera?')) return;
            var id = parseInt(delBtn.dataset.deleteStock);
            fetch(API + 'stock.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id }),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) { loadStockSummary(); loadStockMovements(); }
                else alert(data.error || 'Error al eliminar');
            });
            return;
        }

        // RESTORE
        var restBtn = e.target.closest('[data-restore-stock]');
        if (restBtn) {
            if (!confirm('¿Restaurar este movimiento?')) return;
            var id = parseInt(restBtn.dataset.restoreStock);
            fetch(API + 'stock.php?action=restore', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id }),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) { loadStockSummary(); loadStockMovements(); }
                else alert(data.error || 'Error al restaurar');
            });
            return;
        }
    });

    // ============================================================
    // PEDIDOS PROVEEDOR (PURCHASE ORDERS)
    // ============================================================

    var pedTrashMode = false;
    var estadoLabels = {
        borrador: 'Borrador',
        pedido_hecho: 'Pedido hecho',
        en_almacen_proveedor: 'En almacén proveedor',
        recibido: 'Recibido'
    };
    var estadoColors = {
        borrador: '#777',
        pedido_hecho: '#1565c0',
        en_almacen_proveedor: '#e65100',
        recibido: '#2e7d32'
    };

    function loadPedidos() {
        if (pedTrashMode) {
            fetch(API + 'pedidos_proveedor.php?action=trash')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    renderPedTable(data.pedidos || [], true);
                    document.getElementById('ped-recibidos-section').style.display = 'none';
                });
            return;
        }
        var marca = document.getElementById('ped-marca').value;
        var estado = document.getElementById('ped-estado').value;
        var url = 'pedidos_proveedor.php?action=list';
        if (marca) url += '&marca=' + marca;
        if (estado) url += '&estado=' + estado;

        fetch(API + url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderPedTable(data.activos || [], false);
                renderPedRecibidos(data.recibidos || []);
                document.getElementById('ped-recibidos-section').style.display = '';
            });
    }

    function renderPedTable(rows, isTrash) {
        var tbody = document.getElementById('ped-body');
        var empty = document.getElementById('ped-empty');
        if (!rows.length) {
            tbody.innerHTML = '';
            empty.style.display = 'block';
            empty.textContent = isTrash ? 'Papelera vacía' : 'No hay pedidos activos';
            return;
        }
        empty.style.display = 'none';

        tbody.innerHTML = rows.map(function (p) {
            var num = '';
            if (p.numeracion_inicial && p.numeracion_final) num = p.numeracion_inicial + ' - ' + p.numeracion_final;
            else if (p.numeracion_inicial) num = p.numeracion_inicial + '';

            var actions = '';
            if (isTrash) {
                actions = '<button class="btn-action btn-sm" data-ped-restore="' + p.id + '" style="color:#2e7d32">Restaurar</button>';
            } else {
                // State change buttons
                var stateBtn = '<select class="btn-status" data-ped-state="' + p.id + '"><option value="">Cambiar...</option>';
                if (p.estado !== 'borrador') stateBtn += '<option value="borrador">Borrador</option>';
                if (p.estado !== 'pedido_hecho') stateBtn += '<option value="pedido_hecho">Pedido hecho</option>';
                if (p.estado !== 'en_almacen_proveedor') stateBtn += '<option value="en_almacen_proveedor">En almacén prov.</option>';
                stateBtn += '<option value="recibido">✓ Recibido</option>';
                stateBtn += '</select>';

                actions = stateBtn + ' ' +
                    '<button class="btn-action btn-sm" data-ped-edit="' + p.id + '">Editar</button> ' +
                    '<button class="btn-action btn-sm" data-ped-del="' + p.id + '" style="color:#c00">Eliminar</button>';
            }

            var eColor = estadoColors[p.estado] || '#333';

            return '<tr>' +
                '<td><strong>' + esc(p.codigo) + '</strong></td>' +
                '<td><span style="color:' + eColor + ';font-weight:600">' + (estadoLabels[p.estado] || p.estado) + '</span></td>' +
                '<td><span style="color:' + (brandColors[p.marca] || '#333') + ';font-weight:600">' + esc(p.marca) + '</span></td>' +
                '<td style="font-weight:600">' + p.cantidad + '</td>' +
                '<td>' + esc(num || '—') + '</td>' +
                '<td>' + esc(p.proveedor || '—') + '</td>' +
                '<td>' + fmtDate(p.fecha_pedido) + '</td>' +
                '<td>' + fmtDate(p.fecha_prevista_entrega) + '</td>' +
                '<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(p.comentarios || '') + '">' + esc(p.comentarios || '—') + '</td>' +
                '<td style="white-space:nowrap">' + actions + '</td>' +
                '</tr>';
        }).join('');
    }

    function renderPedRecibidos(rows) {
        var tbody = document.getElementById('ped-rec-body');
        var empty = document.getElementById('ped-rec-empty');
        if (!rows.length) {
            tbody.innerHTML = '';
            empty.style.display = 'block';
            return;
        }
        empty.style.display = 'none';

        tbody.innerHTML = rows.map(function (p) {
            var num = '';
            if (p.numeracion_inicial && p.numeracion_final) num = p.numeracion_inicial + ' - ' + p.numeracion_final;
            else if (p.numeracion_inicial) num = p.numeracion_inicial + '';

            var stateBtn = '<select class="btn-status" data-ped-state="' + p.id + '" style="font-size:11px">' +
                '<option value="">Cambiar...</option>' +
                '<option value="pedido_hecho">Pedido hecho</option>' +
                '<option value="en_almacen_proveedor">En almacén prov.</option>' +
                '</select>';

            var actions = stateBtn + ' ' +
                '<button class="btn-action btn-sm" data-ped-edit="' + p.id + '">Editar</button>';

            return '<tr style="opacity:.7">' +
                '<td><strong>' + esc(p.codigo) + '</strong></td>' +
                '<td><span style="color:' + (brandColors[p.marca] || '#333') + ';font-weight:600">' + esc(p.marca) + '</span></td>' +
                '<td style="font-weight:600">' + p.cantidad + '</td>' +
                '<td>' + esc(num || '—') + '</td>' +
                '<td>' + esc(p.proveedor || '—') + '</td>' +
                '<td>' + fmtDate(p.fecha_pedido) + '</td>' +
                '<td>' + fmtDate(p.fecha_prevista_entrega) + '</td>' +
                '<td><strong>' + fmtDate(p.fecha_real_entrega) + '</strong></td>' +
                '<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(p.comentarios || '') + '">' + esc(p.comentarios || '—') + '</td>' +
                '<td style="white-space:nowrap">' + actions + '</td>' +
                '</tr>';
        }).join('');
    }

    // Ped filters
    document.getElementById('btn-ped-filter').addEventListener('click', loadPedidos);
    document.getElementById('btn-ped-clear').addEventListener('click', function () {
        document.getElementById('ped-marca').value = '';
        document.getElementById('ped-estado').value = '';
        loadPedidos();
    });

    // Ped trash toggle
    document.getElementById('btn-ped-trash').addEventListener('click', function () {
        pedTrashMode = !pedTrashMode;
        this.textContent = pedTrashMode ? 'Volver a pedidos' : 'Papelera';
        this.style.background = pedTrashMode ? '#fce4ec' : '';
        this.style.color = pedTrashMode ? '#c00' : '';
        document.getElementById('btn-ped-new').style.display = pedTrashMode ? 'none' : '';
        loadPedidos();
    });

    // Ped modal
    function openPedModal(data) {
        document.getElementById('ped-error').style.display = 'none';
        document.getElementById('ped-modal-title').textContent = data ? 'Editar pedido a proveedor' : 'Nuevo pedido a proveedor';
        document.getElementById('ped-f-id').value = data ? data.id : '';
        document.getElementById('ped-f-marca').value = data ? data.marca : 'BF10';
        document.getElementById('ped-f-cantidad').value = data ? data.cantidad : '';
        document.getElementById('ped-f-num-ini').value = data ? (data.numeracion_inicial || '') : '';
        document.getElementById('ped-f-num-fin').value = data ? (data.numeracion_final || '') : '';
        document.getElementById('ped-f-proveedor').value = data ? (data.proveedor || '') : '';
        document.getElementById('ped-f-estado').value = data ? data.estado : 'borrador';
        document.getElementById('ped-f-fecha').value = data ? (data.fecha_pedido || '') : new Date().toISOString().split('T')[0];
        document.getElementById('ped-f-prevista').value = data ? (data.fecha_prevista_entrega || '') : '';
        document.getElementById('ped-f-comentarios').value = data ? (data.comentarios || '') : '';
        document.getElementById('ped-modal').style.display = 'flex';
    }

    function closePedModal() { document.getElementById('ped-modal').style.display = 'none'; }

    document.getElementById('btn-ped-new').addEventListener('click', function () { openPedModal(null); });
    document.getElementById('ped-cancel').addEventListener('click', closePedModal);
    document.getElementById('ped-close').addEventListener('click', closePedModal);
    document.getElementById('ped-overlay').addEventListener('click', closePedModal);

    // Ped form submit
    document.getElementById('ped-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var errEl = document.getElementById('ped-error');
        errEl.style.display = 'none';

        var id = document.getElementById('ped-f-id').value;
        var payload = {
            marca: document.getElementById('ped-f-marca').value,
            cantidad: parseInt(document.getElementById('ped-f-cantidad').value) || 0,
            numeracion_inicial: document.getElementById('ped-f-num-ini').value || null,
            numeracion_final: document.getElementById('ped-f-num-fin').value || null,
            proveedor: document.getElementById('ped-f-proveedor').value,
            estado: document.getElementById('ped-f-estado').value,
            fecha_pedido: document.getElementById('ped-f-fecha').value || null,
            fecha_prevista_entrega: document.getElementById('ped-f-prevista').value || null,
            comentarios: document.getElementById('ped-f-comentarios').value,
        };

        if (!payload.marca || !payload.cantidad) {
            errEl.textContent = 'Marca y cantidad son obligatorios.';
            errEl.style.display = 'block';
            return;
        }

        var actionUrl = id ? 'pedidos_proveedor.php?action=update' : 'pedidos_proveedor.php?action=create';
        if (id) payload.id = parseInt(id);

        fetch(API + actionUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) { closePedModal(); loadPedidos(); }
            else {
                errEl.textContent = data.error || 'Error al guardar';
                errEl.style.display = 'block';
            }
        })
        .catch(function () {
            errEl.textContent = 'Error de conexión';
            errEl.style.display = 'block';
        });
    });

    // Ped table actions (delegated)
    document.getElementById('ped-body').addEventListener('click', function (e) {
        var editBtn = e.target.closest('[data-ped-edit]');
        if (editBtn) {
            var id = parseInt(editBtn.dataset.pedEdit);
            fetch(API + 'pedidos_proveedor.php?action=detail&id=' + id)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.pedido) openPedModal(data.pedido);
                });
            return;
        }

        var delBtn = e.target.closest('[data-ped-del]');
        if (delBtn) {
            if (!confirm('¿Mover este pedido a la papelera?')) return;
            fetch(API + 'pedidos_proveedor.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(delBtn.dataset.pedDel) }),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) loadPedidos();
                else alert(data.error || 'Error al eliminar');
            });
            return;
        }

        var restBtn = e.target.closest('[data-ped-restore]');
        if (restBtn) {
            if (!confirm('¿Restaurar este pedido?')) return;
            fetch(API + 'pedidos_proveedor.php?action=restore', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(restBtn.dataset.pedRestore) }),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) loadPedidos();
                else alert(data.error || 'Error al restaurar');
            });
            return;
        }
    });

    // State change via select (both active and received tables)
    function handlePedStateChange(e) {
        var sel = e.target.closest('[data-ped-state]');
        if (!sel || !sel.value) return;
        var id = parseInt(sel.dataset.pedState);
        var newState = sel.value;

        var msg = '¿Cambiar estado a "' + (estadoLabels[newState] || newState) + '"?';
        if (newState === 'recibido') msg += '\n\nSe añadirá automáticamente al stock como entrada.';

        if (!confirm(msg)) { sel.value = ''; return; }

        fetch(API + 'pedidos_proveedor.php?action=change-state', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, estado: newState }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) loadPedidos();
            else alert(data.error || 'Error al cambiar estado');
            sel.value = '';
        });
    }
    document.getElementById('ped-body').addEventListener('change', handlePedStateChange);
    document.getElementById('ped-rec-body').addEventListener('change', handlePedStateChange);

    // Received table actions (edit)
    document.getElementById('ped-rec-body').addEventListener('click', function (e) {
        var editBtn = e.target.closest('[data-ped-edit]');
        if (editBtn) {
            var id = parseInt(editBtn.dataset.pedEdit);
            fetch(API + 'pedidos_proveedor.php?action=detail&id=' + id)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.pedido) openPedModal(data.pedido);
                });
        }
    });

    // ---------- Users management ----------
    function loadUsers() {
        fetch(API + 'admin.php?action=users')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var rows = data.users || [];
                // Hide CEO users unless logged in as CEO
                if (currentUserRole !== 'ceo') {
                    rows = rows.filter(function (u) { return u.role !== 'ceo'; });
                }
                var tbody = document.getElementById('users-body');
                var empty = document.getElementById('users-empty');
                if (!rows.length) { tbody.innerHTML = ''; empty.style.display = 'block'; return; }
                empty.style.display = 'none';
                var roleLabels = { manager: 'Admin', comercial: 'Comercial', rutas: 'Conductor', avisador: 'Avisador', ceo: 'CEO' };
                var roleColors = { manager: '#c62828', comercial: '#1565C0', rutas: '#2e7d32', avisador: '#E65100', ceo: '#6A1B9A' };
                tbody.innerHTML = rows.map(function (u) {
                    var pin = u.comercial_pin || u.conductor_pin || '—';
                    var pw = u.plain_password || '—';
                    return '<tr>' +
                        '<td><strong>' + esc(u.name) + '</strong></td>' +
                        '<td>' + esc(u.email) + '</td>' +
                        '<td><span style="color:' + (roleColors[u.role] || '#333') + ';font-weight:600">' + (roleLabels[u.role] || u.role) + '</span></td>' +
                        '<td><code>' + esc(pin) + '</code></td>' +
                        '<td><code>' + esc(pw) + '</code></td>' +
                        '<td>' +
                            '<button class="btn-action btn-edit" onclick="editUser(' + u.id + ')">Editar</button> ' +
                            '<button class="btn-action btn-danger" onclick="deleteUser(' + u.id + ')">Eliminar</button>' +
                        '</td>' +
                    '</tr>';
                }).join('');
            });
        // Load conductores table
        loadConductoresTable();
    }

    var conductoresFullCache = [];

    function loadConductoresTable() {
        fetch(API + 'driver.php?action=conductores_full')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                conductoresFullCache = data.conductores || [];
                var rows = conductoresFullCache;
                var tbody = document.getElementById('conductores-body');
                var empty = document.getElementById('conductores-empty');
                if (!rows.length) { tbody.innerHTML = ''; empty.style.display = 'block'; return; }
                empty.style.display = 'none';
                tbody.innerHTML = rows.map(function (c) {
                    var activo = parseInt(c.activo);
                    var camionInfo = c.camion_matricula ? esc(c.camion_matricula) + (c.camion_modelo ? ' (' + esc(c.camion_modelo) + ')' : '') : '—';
                    return '<tr style="' + (activo ? '' : 'opacity:.5') + '">' +
                        '<td><strong>' + esc(c.nombre) + '</strong></td>' +
                        '<td><code>' + esc(c.pin || '—') + '</code></td>' +
                        '<td>' + camionInfo + '</td>' +
                        '<td>' + (activo ? '<span style="color:#43A047">Activo</span>' : '<span style="color:#c62828">Inactivo</span>') + '</td>' +
                        '<td>' +
                            '<button class="btn btn--sm" onclick="showConductorModal(' + c.id + ')">Editar</button> ' +
                            '<button class="btn btn--sm" onclick="showZonasModal(\'' + esc(c.nombre).replace(/'/g, "\\'") + '\')">Zonas</button> ' +
                            '<button class="btn btn--sm" onclick="toggleConductorActivo(' + c.id + ')">' + (activo ? 'Desactivar' : 'Activar') + '</button>' +
                        '</td>' +
                    '</tr>';
                }).join('');
            })
            .catch(function () {});
    }

    // Populate conductor_habitual select in camion modal from conductores table
    function populateConductorSelect(selectId, selectedValue) {
        var sel = document.getElementById(selectId);
        sel.innerHTML = '<option value="">— Sin conductor —</option>';
        conductoresFullCache.filter(function (c) { return parseInt(c.activo); }).forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = c.nombre;
            opt.textContent = c.nombre;
            if (c.nombre === selectedValue) opt.selected = true;
            sel.appendChild(opt);
        });
        // Also fetch fresh if cache empty
        if (!conductoresFullCache.length) {
            fetch(API + 'driver.php?action=conductores_full')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    conductoresFullCache = data.conductores || [];
                    sel.innerHTML = '<option value="">— Sin conductor —</option>';
                    conductoresFullCache.filter(function (c) { return parseInt(c.activo); }).forEach(function (c) {
                        var opt = document.createElement('option');
                        opt.value = c.nombre;
                        opt.textContent = c.nombre;
                        if (c.nombre === selectedValue) opt.selected = true;
                        sel.appendChild(opt);
                    });
                });
        }
    }

    window.showConductorModal = function (id) {
        document.getElementById('conductor-f-id').value = '';
        document.getElementById('conductor-f-nombre').value = '';
        document.getElementById('conductor-f-pin').value = '';
        document.getElementById('conductor-f-activo').checked = true;
        document.getElementById('conductor-modal-title').textContent = 'Nuevo Conductor';

        // Populate camion select - fetch fresh from API
        var selCam = document.getElementById('conductor-f-camion');
        selCam.innerHTML = '<option value="">— Sin camión —</option>';
        fetch(API + 'camiones.php?action=list&all=1')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var cams = (d.camiones || []).filter(function (c) { return parseInt(c.activo); });
                selCam.innerHTML = '<option value="">— Sin camión —</option>';
                cams.forEach(function (c) {
                    var opt = document.createElement('option');
                    opt.value = c.matricula;
                    opt.textContent = c.matricula + (c.modelo ? ' (' + c.modelo + ')' : '');
                    selCam.appendChild(opt);
                });
                // Set selected value after options loaded
                if (id) {
                    var con = conductoresFullCache.find(function (x) { return x.id == id; });
                    if (con && con.camion_matricula) selCam.value = con.camion_matricula;
                }
            });

        if (id) {
            var c = conductoresFullCache.find(function (x) { return x.id == id; });
            if (c) {
                document.getElementById('conductor-f-id').value = c.id;
                document.getElementById('conductor-f-nombre').value = c.nombre || '';
                document.getElementById('conductor-f-pin').value = c.pin || '';
                document.getElementById('conductor-f-activo').checked = parseInt(c.activo) === 1;
                document.getElementById('conductor-modal-title').textContent = 'Editar Conductor';
            }
        }
        document.getElementById('conductor-modal').style.display = 'flex';
    };

    document.getElementById('conductor-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var id = document.getElementById('conductor-f-id').value;
        var payload = {
            nombre: document.getElementById('conductor-f-nombre').value.trim(),
            pin: document.getElementById('conductor-f-pin').value.trim(),
            camion_habitual: document.getElementById('conductor-f-camion').value,
            activo: document.getElementById('conductor-f-activo').checked ? 1 : 0
        };
        if (!payload.nombre) { window.alert('El nombre es obligatorio'); return; }

        var action = id ? 'conductor_update' : 'conductor_create';
        if (id) payload.id = parseInt(id);

        fetch(API + 'driver.php?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.success || d.id) {
                document.getElementById('conductor-modal').style.display = 'none';
                loadConductoresTable();
                loadCamiones(); // refresh camion habitual display
            } else {
                window.alert(d.error || 'Error al guardar conductor');
            }
        });
    });

    window.toggleConductorActivo = function (id) {
        fetch(API + 'driver.php?action=conductor_toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        }).then(function (r) { return r.json(); }).then(function () {
            loadConductoresTable();
        });
    };

    window.editUser = function (id) {
        fetch(API + 'admin.php?action=users')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var u = (data.users || []).find(function (x) { return x.id == id; });
                if (!u) return;
                showUserModal(u);
            });
    };

    window.deleteUser = function (id) {
        if (!window.confirm('¿Eliminar este usuario permanentemente?')) return;
        fetch(API + 'admin.php?action=user-delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.success) { window.alert('Usuario eliminado'); loadUsers(); }
            else window.alert(data.error || 'Error');
        });
    };

    function showUserModal(u) {
        var isNew = !u;
        var modal = document.getElementById('customer-modal');
        var titleEl = document.getElementById('customer-modal-title');
        var body = document.getElementById('customer-body');
        titleEl.textContent = isNew ? 'Nuevo usuario' : 'Editar usuario';

        var id = u ? u.id : 0;
        var pin = u ? (u.comercial_pin || u.conductor_pin || '') : '';

        body.innerHTML =
            '<form id="user-edit-form">' +
                '<div class="form-row"><label>Nombre *<input type="text" name="name" value="' + esc(u ? u.name : '') + '" required></label></div>' +
                '<div class="form-row">' +
                    '<label>Email *<input type="email" name="email" value="' + esc(u ? u.email : '') + '" required></label>' +
                    '<label>Rol *' +
                        '<select name="role">' +
                            '<option value="manager"' + (u && u.role === 'manager' ? ' selected' : '') + '>Admin</option>' +
                            (currentUserRole === 'ceo' ? '<option value="ceo"' + (u && u.role === 'ceo' ? ' selected' : '') + '>CEO</option>' : '') +
                            '<option value="comercial"' + (u && u.role === 'comercial' ? ' selected' : '') + '>Comercial</option>' +
                            '<option value="rutas"' + (u && u.role === 'rutas' ? ' selected' : '') + '>Conductor</option>' +
                            '<option value="avisador"' + (u && u.role === 'avisador' ? ' selected' : '') + '>Avisador</option>' +
                        '</select>' +
                    '</label>' +
                '</div>' +
                '<div class="form-row">' +
                    '<label>' + (isNew ? 'Contraseña *' : 'Nueva contraseña (vacío = no cambiar)') + '<input type="text" name="password" value="" placeholder="' + (isNew ? '' : '••••••••') + '"' + (isNew ? ' required' : '') + '></label>' +
                    '<label>PIN (4 dígitos)<input type="text" name="pin" value="' + esc(pin) + '" maxlength="4" pattern="\\d{0,4}" placeholder="1234"></label>' +
                '</div>' +
                '<div class="form-actions">' +
                    '<button type="button" class="btn-modal-cancel" onclick="document.getElementById(\'customer-modal\').style.display=\'none\'">Cancelar</button>' +
                    '<button type="submit" class="btn-modal-save">Guardar</button>' +
                '</div>' +
            '</form>';

        document.getElementById('user-edit-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(e.target);
            var payload = {};
            fd.forEach(function (v, k) { payload[k] = v; });
            // Remove empty password on update (means "don't change")
            if (id && !payload.password) delete payload.password;
            if (id) payload.id = id;

            var action = id ? 'user-update' : 'user-create';
            fetch(API + 'admin.php?action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data.success) {
                    modal.style.display = 'none';
                    loadUsers();
                } else {
                    window.alert(data.error || 'Error');
                }
            });
        });

        modal.style.display = 'flex';
    }

    document.getElementById('btn-user-new').addEventListener('click', function () {
        showUserModal(null);
    });

    // ================================================================
    // CAMIONES
    // ================================================================
    var camionesCache = [];
    var conductoresCache = [];

    window.loadCamiones = function () {
        fetch(API + 'camiones.php?action=list&all=1')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                camionesCache = d.camiones || [];
                var tbody = document.getElementById('camiones-body');
                var empty = document.getElementById('camiones-empty');
                if (!camionesCache.length) {
                    tbody.innerHTML = '';
                    empty.style.display = '';
                    return;
                }
                empty.style.display = 'none';
                tbody.innerHTML = camionesCache.map(function (c) {
                    var activo = parseInt(c.activo);
                    return '<tr style="' + (activo ? '' : 'opacity:.5') + '">' +
                        '<td><strong>' + (c.matricula || '') + '</strong></td>' +
                        '<td>' + (c.modelo || '-') + '</td>' +
                        '<td>' + (c.conductor_habitual || '-') + '</td>' +
                        '<td>' + (c.capacidad_m3 || '-') + '</td>' +
                        '<td>' + (c.capacidad_sacas || '-') + '</td>' +
                        '<td>' + (activo ? '<span style="color:#43A047">Activo</span>' : '<span style="color:#c62828">Inactivo</span>') + '</td>' +
                        '<td>' +
                            '<button class="btn btn--sm" onclick="showCamionModal(' + c.id + ')">Editar</button> ' +
                            '<button class="btn btn--sm" onclick="toggleCamion(' + c.id + ')">' + (activo ? 'Desactivar' : 'Activar') + '</button> ' +
                            '<button class="btn btn--sm" onclick="showCamionHistory(' + c.id + ',\'' + (c.matricula || '').replace(/'/g, "\\'") + '\')">Historial</button>' +
                        '</td></tr>';
                }).join('');
            });
    };

    window.showCamionModal = function (id) {
        document.getElementById('camion-f-id').value = '';
        document.getElementById('camion-f-matricula').value = '';
        document.getElementById('camion-f-modelo').value = '';
        document.getElementById('camion-f-m3').value = '';
        document.getElementById('camion-f-sacas').value = '';
        document.getElementById('camion-modal-title').textContent = 'Nuevo Camion';

        // Populate conductor_habitual select from conductores table
        var selectedConductor = '';
        if (id) {
            var c = camionesCache.find(function (x) { return x.id == id; });
            if (c) {
                document.getElementById('camion-f-id').value = c.id;
                document.getElementById('camion-f-matricula').value = c.matricula || '';
                document.getElementById('camion-f-modelo').value = c.modelo || '';
                document.getElementById('camion-f-m3').value = c.capacidad_m3 || '';
                document.getElementById('camion-f-sacas').value = c.capacidad_sacas || '';
                document.getElementById('camion-modal-title').textContent = 'Editar Camion';
                selectedConductor = c.conductor_habitual || '';
            }
        }
        populateConductorSelect('camion-f-conductor', selectedConductor);
        document.getElementById('camion-modal').style.display = 'flex';
    };

    document.getElementById('camion-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var id = document.getElementById('camion-f-id').value;
        var payload = {
            matricula: document.getElementById('camion-f-matricula').value,
            modelo: document.getElementById('camion-f-modelo').value,
            conductor_habitual: document.getElementById('camion-f-conductor').value,
            capacidad_m3: document.getElementById('camion-f-m3').value,
            capacidad_sacas: document.getElementById('camion-f-sacas').value
        };
        var action = id ? 'update' : 'create';
        if (id) payload.id = parseInt(id);

        fetch(API + 'camiones.php?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.success || d.id) {
                document.getElementById('camion-modal').style.display = 'none';
                loadCamiones();
            } else {
                window.alert(d.error || 'Error');
            }
        });
    });

    window.toggleCamion = function (id) {
        fetch(API + 'camiones.php?action=toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        }).then(function (r) { return r.json(); }).then(function () {
            loadCamiones();
        });
    };

    window.showCamionHistory = function (camionId, matricula) {
        document.getElementById('camion-history-title').textContent = 'Historial — ' + matricula;
        document.getElementById('camion-history-body').innerHTML = '<p>Cargando...</p>';
        document.getElementById('camion-history-modal').style.display = 'flex';

        fetch(API + 'camiones.php?action=history&camion_id=' + camionId)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var rows = d.history || [];
                if (!rows.length) {
                    document.getElementById('camion-history-body').innerHTML = '<p style="color:#777">Sin historial</p>';
                    return;
                }
                var html = '<table class="dash-table"><thead><tr><th>Fecha</th><th>Conductor</th></tr></thead><tbody>';
                rows.forEach(function (r) {
                    html += '<tr><td>' + r.fecha + '</td><td>' + r.conductor_nombre + '</td></tr>';
                });
                html += '</tbody></table>';
                document.getElementById('camion-history-body').innerHTML = html;
            });
    };

    // ── Assignments ──
    window.loadAssignments = function () {
        var fechaInput = document.getElementById('assign-fecha');
        if (!fechaInput.value) fechaInput.value = new Date().toISOString().slice(0, 10);
        var fecha = fechaInput.value;

        fetch(API + 'camiones.php?action=assignments&fecha=' + fecha)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var rows = d.assignments || [];
                var tbody = document.getElementById('assign-body');
                var empty = document.getElementById('assign-empty');
                if (!rows.length) {
                    tbody.innerHTML = '';
                    empty.style.display = '';
                    return;
                }
                empty.style.display = 'none';
                tbody.innerHTML = rows.map(function (a) {
                    return '<tr>' +
                        '<td><strong>' + (a.matricula || '') + '</strong> ' + (a.modelo || '') + '</td>' +
                        '<td>' + a.conductor_nombre + '</td>' +
                        '<td>' + a.fecha + '</td>' +
                        '<td><button class="btn btn--sm btn--danger" onclick="unassign(' + a.id + ')">Quitar</button></td>' +
                        '</tr>';
                }).join('');
            });
    };

    window.showAssignModal = function () {
        var fechaInput = document.getElementById('assign-fecha');
        if (!fechaInput.value) fechaInput.value = new Date().toISOString().slice(0, 10);
        document.getElementById('assign-f-fecha').value = fechaInput.value;

        // Load camiones into select
        var selCam = document.getElementById('assign-f-camion');
        selCam.innerHTML = '<option value="">— Camion —</option>';
        camionesCache.filter(function (c) { return parseInt(c.activo); }).forEach(function (c) {
            selCam.innerHTML += '<option value="' + c.id + '">' + c.matricula + (c.modelo ? ' (' + c.modelo + ')' : '') + '</option>';
        });

        // Load conductores
        var selCon = document.getElementById('assign-f-conductor');
        selCon.innerHTML = '<option value="">— Conductor —</option>';
        if (conductoresCache.length) {
            conductoresCache.forEach(function (n) {
                selCon.innerHTML += '<option value="' + n + '">' + n + '</option>';
            });
        }
        // Fetch fresh list
        fetch(API + 'driver.php?action=conductores')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                conductoresCache = d.conductores || [];
                selCon.innerHTML = '<option value="">— Conductor —</option>';
                conductoresCache.forEach(function (n) {
                    selCon.innerHTML += '<option value="' + n + '">' + n + '</option>';
                });
            });

        document.getElementById('assign-modal').style.display = 'flex';
    };

    document.getElementById('assign-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var payload = {
            camion_id: parseInt(document.getElementById('assign-f-camion').value),
            conductor_nombre: document.getElementById('assign-f-conductor').value,
            fecha: document.getElementById('assign-f-fecha').value
        };
        if (!payload.camion_id || !payload.conductor_nombre) {
            window.alert('Selecciona camion y conductor');
            return;
        }
        fetch(API + 'camiones.php?action=assign', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.success) {
                document.getElementById('assign-modal').style.display = 'none';
                loadAssignments();
            } else {
                window.alert(d.error || 'Error');
            }
        });
    });

    window.unassign = function (id) {
        fetch(API + 'camiones.php?action=unassign', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        }).then(function (r) { return r.json(); }).then(function () {
            loadAssignments();
        });
    };

    // ================================================================
    // RANKING CONDUCTORES
    // ================================================================
    var rankingPeriod = 'day';

    // Period buttons
    document.querySelectorAll('.ranking-period').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.ranking-period').forEach(function (b) { b.classList.remove('ranking-period--active'); });
            this.classList.add('ranking-period--active');
            rankingPeriod = this.dataset.period;
            loadRanking();
        });
    });

    var MEDALS = ['🥇', '🥈', '🥉'];

    window.loadRanking = function () {
        fetch(API + 'ranking.php?action=ranking&periodo=' + rankingPeriod)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var rows = d.ranking || [];
                var podium = document.getElementById('ranking-podium');
                var list = document.getElementById('ranking-list');
                var empty = document.getElementById('ranking-empty');
                var range = document.getElementById('ranking-range');

                range.textContent = 'Acumulado hasta ' + d.hasta;

                if (!rows.length) {
                    podium.innerHTML = '';
                    list.innerHTML = '';
                    empty.style.display = '';
                    return;
                }
                empty.style.display = 'none';

                // Top 3 podium
                var podiumHTML = '';
                var podiumOrder = [1, 0, 2]; // Show 2nd, 1st, 3rd
                podiumOrder.forEach(function (idx) {
                    if (!rows[idx]) return;
                    var r = rows[idx];
                    var cls = 'podium-' + (idx + 1);
                    var height = idx === 0 ? '160px' : (idx === 1 ? '130px' : '110px');
                    podiumHTML += '<div class="podium-card ' + cls + '" style="min-height:' + height + '">' +
                        '<div class="podium-rank">' + MEDALS[idx] + '</div>' +
                        '<div class="podium-name">' + r.conductor + '</div>' +
                        '<div class="podium-stat">' + r.paradas + '</div>' +
                        '<div class="podium-label">paradas</div>' +
                        '<div style="margin-top:4px;font-size:12px;font-weight:600">' + r.total_sacos + ' sacos</div>' +
                    '</div>';
                });
                podium.innerHTML = podiumHTML;

                // Rest of list (from 4th)
                var listHTML = '';
                for (var i = 3; i < rows.length; i++) {
                    var r = rows[i];
                    listHTML += '<div class="rank-row">' +
                        '<div class="rank-pos">#' + r.rank + '</div>' +
                        '<div class="rank-name">' + r.conductor + '</div>' +
                        '<div class="rank-stats">' +
                            '<span><span class="rv">' + r.paradas + '</span><span class="rl">paradas</span></span>' +
                            '<span><span class="rv">' + r.total_sacos + '</span><span class="rl">sacos</span></span>' +
                            '<span><span class="rv">' + r.dias_trabajados + '</span><span class="rl">dias</span></span>' +
                        '</div>' +
                    '</div>';
                }
                list.innerHTML = listHTML;
            });
    };

    // ============ ZONAS HABITUALES ============
    var zonasAllBarrios = [];

    window.showZonasModal = function (conductorNombre) {
        var modal = document.getElementById('zonas-modal');
        var title = document.getElementById('zonas-modal-title');
        var body = document.getElementById('zonas-body');
        title.textContent = 'Zonas habituales — ' + conductorNombre;
        modal.style.display = 'flex';
        modal.dataset.conductor = conductorNombre.toUpperCase();
        body.innerHTML = '<p style="color:#888;text-align:center;padding:20px">Cargando...</p>';

        // Fetch zonas + barrios in parallel
        Promise.all([
            fetch(API + 'zonas_habituales.php?action=list').then(function(r) { return r.json(); }),
            fetch(API + 'zonas_habituales.php?action=barrios').then(function(r) { return r.json(); })
        ]).then(function(results) {
            var zonas = results[0].zonas || {};
            zonasAllBarrios = results[1].barrios || [];
            var cName = conductorNombre.toUpperCase();
            var conductorZonas = zonas[cName] || [];

            renderZonasBody(cName, conductorZonas);
        });
    };

    function renderZonasBody(cName, conductorZonas) {
        var body = document.getElementById('zonas-body');
        var html = '';

        // Active zones
        html += '<div style="margin-bottom:12px"><strong style="font-size:13px">Zonas activas:</strong></div>';
        var activeCount = 0;
        conductorZonas.forEach(function(z) {
            if (!z.activo) return;
            activeCount++;
            html += '<div style="display:inline-flex;align-items:center;gap:6px;background:#E8F5E9;border:1px solid #A5D6A7;border-radius:20px;padding:4px 12px;margin:3px;font-size:12px;font-weight:500">' +
                '<span>' + esc(z.barrio) + '</span>' +
                '<button onclick="toggleZona(\'' + esc(cName) + '\',\'' + esc(z.barrio) + '\',0)" style="background:none;border:none;color:#c62828;cursor:pointer;font-size:14px;font-weight:700;padding:0 2px" title="Desactivar">&times;</button>' +
                '</div>';
        });
        if (!activeCount) html += '<p style="color:#999;font-size:12px;margin:4px 0">Sin zonas activas</p>';

        // Inactive zones
        var inactiveZonas = conductorZonas.filter(function(z) { return !z.activo; });
        if (inactiveZonas.length) {
            html += '<div style="margin:12px 0 8px"><strong style="font-size:13px;color:#999">Desactivadas:</strong></div>';
            inactiveZonas.forEach(function(z) {
                html += '<div style="display:inline-flex;align-items:center;gap:6px;background:#f5f5f5;border:1px solid #ddd;border-radius:20px;padding:4px 12px;margin:3px;font-size:12px;color:#888">' +
                    '<span>' + esc(z.barrio) + '</span>' +
                    '<button onclick="toggleZona(\'' + esc(cName) + '\',\'' + esc(z.barrio) + '\',1)" style="background:none;border:none;color:#43A047;cursor:pointer;font-size:14px;font-weight:700;padding:0 2px" title="Activar">+</button>' +
                    '<button onclick="removeZona(\'' + esc(cName) + '\',\'' + esc(z.barrio) + '\')" style="background:none;border:none;color:#c62828;cursor:pointer;font-size:12px;padding:0 2px" title="Eliminar">🗑</button>' +
                    '</div>';
            });
        }

        // Add new zone
        var existingBarrios = conductorZonas.map(function(z) { return z.barrio; });
        var availableBarrios = zonasAllBarrios.filter(function(b) { return existingBarrios.indexOf(b) === -1; });

        html += '<div style="margin-top:16px;padding-top:12px;border-top:1px solid #eee;display:flex;align-items:center;gap:8px">';
        html += '<select id="zonas-add-select" style="flex:1;padding:6px 10px;border:1px solid #ccc;border-radius:6px;font-size:12px">';
        html += '<option value="">— Añadir zona —</option>';
        availableBarrios.forEach(function(b) {
            html += '<option value="' + esc(b) + '">' + esc(b) + '</option>';
        });
        html += '</select>';
        html += '<button onclick="addZona(\'' + esc(cName) + '\')" class="btn btn--sm btn--primary">Añadir</button>';
        html += '</div>';

        body.innerHTML = html;
    }

    window.toggleZona = function(conductor, barrio, activo) {
        fetch(API + 'zonas_habituales.php?action=toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ conductor: conductor, barrio: barrio, activo: activo })
        }).then(function() { showZonasModal(conductor); });
    };

    window.removeZona = function(conductor, barrio) {
        if (!window.confirm('¿Eliminar zona ' + barrio + '?')) return;
        fetch(API + 'zonas_habituales.php?action=remove', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ conductor: conductor, barrio: barrio })
        }).then(function() { showZonasModal(conductor); });
    };

    window.addZona = function(conductor) {
        var sel = document.getElementById('zonas-add-select');
        var barrio = sel.value;
        if (!barrio) return;
        fetch(API + 'zonas_habituales.php?action=add', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ conductor: conductor, barrio: barrio })
        }).then(function() { showZonasModal(conductor); });
    };

})();
