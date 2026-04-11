/**
 * BF10 - Order Form Logic
 */
(function () {
    'use strict';

    const API_URL = 'api/orders.php';
    const form = document.getElementById('pedido-form');
    if (!form) return;

    // Handle Stripe return (?pedido=BF10-XXXX&pago=ok|cancelado)
    (function handleStripeReturn() {
        var params = new URLSearchParams(window.location.search);
        var pedido = params.get('pedido');
        var pago = params.get('pago');
        var banner = document.getElementById('stripe-return-banner');
        if (!banner || !pedido || !pago) return;

        if (pago === 'ok') {
            banner.className = 'pedido__banner pedido__banner--success';
            banner.innerHTML =
                '<strong>✓ Pago recibido</strong><br>' +
                'Tu pedido <strong>' + pedido + '</strong> está confirmado. ' +
                'En breve recibirás un email con la confirmación. Te entregaremos los sacos en 24-48 horas.';
        } else if (pago === 'cancelado') {
            banner.className = 'pedido__banner pedido__banner--warning';
            banner.innerHTML =
                '<strong>Pago cancelado</strong><br>' +
                'No hemos cobrado nada. Tu pedido <strong>' + pedido + '</strong> sigue pendiente. ' +
                'Puedes volver a enviar el formulario para reintentar el pago.';
        }
        banner.style.display = 'block';
        // Clean URL
        if (window.history.replaceState) {
            window.history.replaceState({}, '', window.location.pathname + '#pedido');
        }
    })();

    const packages = {
        10: { name: '10 sacas', price: 450.00 },
        25: { name: '25 sacas', price: 1012.50 },
        50: { name: '50 sacas', price: 1912.50 },
    };

    // Elements
    const summaryPack = document.getElementById('summary-pack');
    const summaryPrice = document.getElementById('summary-price');
    const summaryPayment = document.getElementById('summary-payment');
    const requestInvoice = document.getElementById('request-invoice');
    const successMsg = document.getElementById('pedido-success');
    const errorMsg = document.getElementById('pedido-error');
    const errorText = document.getElementById('pedido-error-text');
    const submitBtn = document.getElementById('pedido-submit');

    // Update summary when pack changes
    form.querySelectorAll('input[name="package_qty"]').forEach(function (radio) {
        radio.addEventListener('change', updateSummary);
    });

    // Hide any legacy Stripe card element (now using Checkout redirect)
    var stripeCardEl = document.getElementById('stripe-card-element');
    if (stripeCardEl) stripeCardEl.style.display = 'none';

    // Update summary when payment method changes
    form.querySelectorAll('input[name="payment_method"]').forEach(function (radio) {
        radio.addEventListener('change', updateSummary);
    });

    function updateSummary() {
        var qty = form.querySelector('input[name="package_qty"]:checked');
        if (qty) {
            var pkg = packages[qty.value];
            summaryPack.textContent = pkg.name;
            summaryPrice.textContent = pkg.price.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
        }

        var method = form.querySelector('input[name="payment_method"]:checked');
        if (method) {
            summaryPayment.textContent = method.value === 'card'
                ? 'Tarjeta de crédito/débito'
                : 'Transferencia bancaria';
        }
    }

    // NIF/CIF validation hint
    var nifInput = document.getElementById('pedido-nif');
    var nifHint = document.getElementById('nif-hint');
    var nifRegex = /^[A-Za-z]\d{7}[A-Za-z0-9]$|^\d{8}[A-Za-z]$/;

    if (nifInput) {
        nifInput.addEventListener('blur', function () {
            var val = this.value.trim();
            if (!val) {
                nifHint.textContent = '';
                return;
            }
            if (nifRegex.test(val)) {
                nifHint.textContent = '✓ NIF/CIF válido';
                nifHint.style.color = '#00A651';
            } else {
                nifHint.textContent = 'Formato no válido (ej: 12345678A o B12345678)';
                nifHint.style.color = '#DA291C';
            }
        });
    }

    // Postal code validation - auto format
    var postalInput = document.getElementById('pedido-postal');
    postalInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 5);
    });

    // Phone validation - auto format
    var phoneInput = document.getElementById('pedido-phone');
    phoneInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9\s\+]/g, '');
    });

    // Address verification hint
    var addressInput = document.getElementById('pedido-address');
    var addressHint = document.getElementById('address-hint');
    var cityInput = document.getElementById('pedido-city');

    addressInput.addEventListener('blur', function () {
        var val = this.value.trim();
        if (val.length > 5) {
            // Basic check: must contain a number (street number)
            if (!/\d/.test(val)) {
                addressHint.textContent = 'Recuerda incluir el número de calle';
                addressHint.style.color = '#DA291C';
            } else {
                addressHint.textContent = '✓ Dirección válida';
                addressHint.style.color = '#00A651';
            }
        } else {
            addressHint.textContent = '';
        }
    });

    // Form validation
    function validateForm() {
        var errors = [];

        var name = document.getElementById('pedido-name').value.trim();
        var nif = document.getElementById('pedido-nif').value.trim();
        var email = document.getElementById('pedido-email').value.trim();
        var phone = document.getElementById('pedido-phone').value.trim();
        var address = document.getElementById('pedido-address').value.trim();
        var city = document.getElementById('pedido-city').value.trim();
        var postal = document.getElementById('pedido-postal').value.trim();
        var terms = document.getElementById('accept-terms').checked;

        if (!name) errors.push('El nombre / razón social es obligatorio');
        if (nif && !nifRegex.test(nif)) errors.push('NIF/CIF no válido');
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.push('Email no válido');
        if (!phone || phone.replace(/\s/g, '').length < 9) errors.push('Teléfono no válido');
        if (!address) errors.push('La dirección es obligatoria');
        if (!city) errors.push('El municipio es obligatorio');
        if (!postal || !/^(28|45)\d{3}$/.test(postal)) errors.push('Código postal no válido para la zona de Madrid');
        if (!terms) errors.push('Debes aceptar las condiciones del servicio');

        // Invoice validation: requires NIF/CIF
        if (requestInvoice && requestInvoice.checked) {
            if (!nif) {
                errors.push('Para solicitar factura debes indicar tu NIF/CIF en datos de entrega');
            } else if (!nifRegex.test(nif)) {
                errors.push('NIF/CIF no válido para emitir factura');
            }
        }

        return errors;
    }

    // Submit form
    form.addEventListener('submit', function (e) {
        e.preventDefault();

        // Validate
        var errors = validateForm();
        if (errors.length > 0) {
            showError(errors.join('. '));
            return;
        }

        // Build data
        var data = {
            package_qty: parseInt(form.querySelector('input[name="package_qty"]:checked').value),
            name: document.getElementById('pedido-name').value.trim(),
            nif: document.getElementById('pedido-nif').value.trim(),
            email: document.getElementById('pedido-email').value.trim(),
            phone: document.getElementById('pedido-phone').value.trim(),
            address: document.getElementById('pedido-address').value.trim(),
            city: document.getElementById('pedido-city').value.trim(),
            postal_code: document.getElementById('pedido-postal').value.trim(),
            observations: document.getElementById('pedido-observations').value.trim(),
            payment_method: form.querySelector('input[name="payment_method"]:checked').value,
            request_invoice: requestInvoice && requestInvoice.checked ? 1 : 0,
        };

        // Disable button
        submitBtn.disabled = true;
        submitBtn.textContent = 'Procesando...';
        hideMessages();

        // Send order. Backend will create Stripe Checkout Session if card
        // and return checkout_url for redirect (handled in sendOrder's .then).
        sendOrder(data);
    });

    function sendOrder(data) {
        fetch(API_URL + '?action=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        })
        .then(function (res) { return res.json(); })
        .then(function (result) {
            if (result.success) {
                // If Stripe Checkout URL returned, redirect user to pay
                if (result.checkout_url) {
                    submitBtn.textContent = 'Redirigiendo a la pasarela de pago...';
                    window.location.href = result.checkout_url;
                    return;
                }
                showSuccess(result);
            } else {
                showError(result.error || 'Error al procesar el pedido');
            }
        })
        .catch(function () {
            showError('Error de conexión. Inténtalo de nuevo o llámanos al 674 78 34 79.');
        })
        .finally(function () {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Confirmar pedido';
        });
    }

    function showSuccess(result) {
        document.getElementById('pedido-code').textContent = result.order.code;

        var bankInfo = document.getElementById('pedido-bank-info');
        if (result.bank) {
            bankInfo.innerHTML =
                '<div class="pedido__bank-details">' +
                '<h4>Datos para la transferencia:</h4>' +
                '<p><strong>IBAN:</strong> ' + result.bank.iban + '</p>' +
                '<p><strong>Concepto:</strong> ' + result.bank.concept + '</p>' +
                '<p><strong>Importe:</strong> ' + result.bank.amount + '</p>' +
                '<p style="margin-top:8px;font-size:13px;color:#666;">Te hemos enviado los datos completos por email, incluyendo el beneficiario de la cuenta.</p>' +
                '</div>';
        } else {
            bankInfo.innerHTML = '';
        }

        form.querySelectorAll('.pedido__step').forEach(function (s) { s.style.display = 'none'; });
        successMsg.style.display = 'block';
        errorMsg.style.display = 'none';

        successMsg.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function showError(msg) {
        errorText.textContent = msg;
        errorMsg.style.display = 'block';
        errorMsg.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function hideMessages() {
        errorMsg.style.display = 'none';
    }

    // Pack preselection from service cards
    document.querySelectorAll('[data-select-pack]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            var qty = this.getAttribute('data-select-pack');
            var radio = form.querySelector('input[name="package_qty"][value="' + qty + '"]');
            if (radio) {
                // Defensive: uncheck all others first
                form.querySelectorAll('input[name="package_qty"]').forEach(function (r) {
                    r.checked = false;
                });
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
                updateSummary();
            }
        });
    });

    // Initial summary
    updateSummary();
})();
