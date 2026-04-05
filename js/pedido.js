/**
 * BF10 - Order Form Logic
 */
(function () {
    'use strict';

    const API_URL = 'api/orders.php';
    const form = document.getElementById('pedido-form');
    if (!form) return;

    const packages = {
        5:  { name: '5 sacos',  price: 5.00 },
        25: { name: '25 sacos', price: 25.00 },
        50: { name: '50 sacos', price: 50.00 },
    };

    // Elements
    const summaryPack = document.getElementById('summary-pack');
    const summaryPrice = document.getElementById('summary-price');
    const summaryPayment = document.getElementById('summary-payment');
    const billingSame = document.getElementById('billing-same');
    const billingFields = document.getElementById('billing-fields');
    const successMsg = document.getElementById('pedido-success');
    const errorMsg = document.getElementById('pedido-error');
    const errorText = document.getElementById('pedido-error-text');
    const submitBtn = document.getElementById('pedido-submit');

    // Update summary when pack changes
    form.querySelectorAll('input[name="package_qty"]').forEach(function (radio) {
        radio.addEventListener('change', updateSummary);
    });

    // Update summary when payment method changes
    form.querySelectorAll('input[name="payment_method"]').forEach(function (radio) {
        radio.addEventListener('change', updateSummary);
    });

    function updateSummary() {
        var qty = form.querySelector('input[name="package_qty"]:checked');
        if (qty) {
            var pkg = packages[qty.value];
            summaryPack.textContent = pkg.name;
            summaryPrice.textContent = pkg.price.toFixed(2).replace('.', ',') + ' €';
        }

        var method = form.querySelector('input[name="payment_method"]:checked');
        if (method) {
            summaryPayment.textContent = method.value === 'card'
                ? 'Tarjeta de crédito/débito'
                : 'Transferencia bancaria';
        }
    }

    // Toggle billing fields
    billingSame.addEventListener('change', function () {
        billingFields.style.display = this.checked ? 'none' : 'block';

        if (!this.checked) {
            // Copy delivery data to billing
            var name = document.getElementById('pedido-name').value;
            var address = document.getElementById('pedido-address').value;
            var billingNameField = document.getElementById('billing-name');
            var billingAddressField = document.getElementById('billing-address');

            if (!billingNameField.value) billingNameField.value = name;
            if (!billingAddressField.value) billingAddressField.value = address;
        }
    });

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
        var email = document.getElementById('pedido-email').value.trim();
        var phone = document.getElementById('pedido-phone').value.trim();
        var address = document.getElementById('pedido-address').value.trim();
        var city = document.getElementById('pedido-city').value.trim();
        var postal = document.getElementById('pedido-postal').value.trim();
        var terms = document.getElementById('accept-terms').checked;

        if (!name) errors.push('El nombre es obligatorio');
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.push('Email no válido');
        if (!phone || phone.replace(/\s/g, '').length < 9) errors.push('Teléfono no válido');
        if (!address) errors.push('La dirección es obligatoria');
        if (!city) errors.push('El municipio es obligatorio');
        if (!postal || !/^(28|45)\d{3}$/.test(postal)) errors.push('Código postal no válido para la zona de Madrid');
        if (!terms) errors.push('Debes aceptar las condiciones del servicio');

        // Billing validation if different
        if (!billingSame.checked) {
            var billingName = document.getElementById('billing-name').value.trim();
            var billingCif = document.getElementById('billing-cif').value.trim();
            var billingAddress = document.getElementById('billing-address').value.trim();

            if (!billingName) errors.push('El nombre de facturación es obligatorio');
            if (!billingCif || !/^[A-Za-z]\d{7}[A-Za-z0-9]$|^\d{8}[A-Za-z]$/.test(billingCif)) {
                errors.push('CIF/NIF no válido');
            }
            if (!billingAddress) errors.push('La dirección de facturación es obligatoria');
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
            email: document.getElementById('pedido-email').value.trim(),
            phone: document.getElementById('pedido-phone').value.trim(),
            address: document.getElementById('pedido-address').value.trim(),
            city: document.getElementById('pedido-city').value.trim(),
            postal_code: document.getElementById('pedido-postal').value.trim(),
            observations: document.getElementById('pedido-observations').value.trim(),
            payment_method: form.querySelector('input[name="payment_method"]:checked').value,
            billing_same: billingSame.checked ? 1 : 0,
        };

        if (!billingSame.checked) {
            data.billing_name = document.getElementById('billing-name').value.trim();
            data.billing_company = document.getElementById('billing-company').value.trim();
            data.billing_cif = document.getElementById('billing-cif').value.trim();
            data.billing_address = document.getElementById('billing-address').value.trim();
        }

        // Disable button
        submitBtn.disabled = true;
        submitBtn.textContent = 'Procesando...';
        hideMessages();

        // Send request
        fetch(API_URL + '?action=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        })
        .then(function (res) { return res.json(); })
        .then(function (result) {
            if (result.success) {
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
    });

    function showSuccess(result) {
        document.getElementById('pedido-code').textContent = result.order.code;

        var bankInfo = document.getElementById('pedido-bank-info');
        if (result.bank) {
            bankInfo.innerHTML =
                '<div class="pedido__bank-details">' +
                '<h4>Datos para la transferencia:</h4>' +
                '<p><strong>IBAN:</strong> ' + result.bank.iban + '</p>' +
                '<p><strong>Beneficiario:</strong> ' + result.bank.beneficiary + '</p>' +
                '<p><strong>Concepto:</strong> ' + result.bank.concept + '</p>' +
                '<p><strong>Importe:</strong> ' + result.bank.amount + '</p>' +
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

    // Initial summary
    updateSummary();
})();
