(function () {
    function openModal(el) { el.classList.add('active'); document.body.style.overflow = 'hidden'; }
    function closeModal(el) { el.classList.remove('active'); document.body.style.overflow = ''; }

    const addModal = document.getElementById('addItemModal');
    const distModal = document.getElementById('distributeModal');
    const categoryModal = document.getElementById('categoryModal');
    const restockModal = document.getElementById('restockModal');
    const deleteCategoryModal = document.getElementById('deleteCategoryModal');
    const deleteCategoryNameText = document.getElementById('deleteCategoryNameText');
    let pendingDeleteCategoryForm = null;

    const openAddBtn = document.getElementById('openAddModalBtn');
    if (openAddBtn && addModal) {
        openAddBtn.addEventListener('click', () => openModal(addModal));
    }

    const openDistBtn = document.getElementById('openDistModalBtn');
    if (openDistBtn && distModal) {
        openDistBtn.addEventListener('click', () => openModal(distModal));
    }

    if (categoryModal) {
        document.querySelectorAll('.open-category-modal').forEach(btn => {
            btn.addEventListener('click', () => openModal(categoryModal));
        });
    }

    if (restockModal) {
        document.querySelectorAll('.btn-restock').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.itemId;
                const name = btn.dataset.itemName || 'Selected item';
                const unit = btn.dataset.itemUnit || '';
                const qty = parseInt(btn.dataset.itemQty || '0', 10);

                const itemIdInput = document.getElementById('restockItemId');
                const itemNameLabel = document.getElementById('restockItemName');
                const currentQtyLabel = document.getElementById('restockCurrentQty');
                const qtyInput = document.getElementById('restockQty');


                if (itemIdInput) itemIdInput.value = id;
                if (itemNameLabel) itemNameLabel.textContent = name;
                if (currentQtyLabel) currentQtyLabel.textContent = qty + (unit ? ' ' + unit : '');
                if (qtyInput) qtyInput.value = '1';


                openModal(restockModal);
            });
        });
    }

    const addModalClose = document.getElementById('addModalClose');
    if (addModalClose && addModal) addModalClose.addEventListener('click', () => closeModal(addModal));
    const addModalCancel = document.getElementById('addModalCancel');
    if (addModalCancel && addModal) addModalCancel.addEventListener('click', () => closeModal(addModal));
    const addBackdrop = document.getElementById('addBackdrop');
    if (addBackdrop && addModal) addBackdrop.addEventListener('click', () => closeModal(addModal));

    const categoryModalClose = document.getElementById('categoryModalClose');
    if (categoryModalClose && categoryModal) categoryModalClose.addEventListener('click', () => closeModal(categoryModal));
    const categoryModalCancel = document.getElementById('categoryModalCancel');
    if (categoryModalCancel && categoryModal) categoryModalCancel.addEventListener('click', () => closeModal(categoryModal));
    const categoryBackdrop = document.getElementById('categoryBackdrop');
    if (categoryBackdrop && categoryModal) categoryBackdrop.addEventListener('click', () => closeModal(categoryModal));

    const deleteCategoryClose = document.getElementById('deleteCategoryClose');
    if (deleteCategoryClose && deleteCategoryModal) {
        deleteCategoryClose.addEventListener('click', () => {
            closeModal(deleteCategoryModal);
            pendingDeleteCategoryForm = null;
        });
    }
    const deleteCategoryCancel = document.getElementById('deleteCategoryCancel');
    if (deleteCategoryCancel && deleteCategoryModal) {
        deleteCategoryCancel.addEventListener('click', () => {
            closeModal(deleteCategoryModal);
            pendingDeleteCategoryForm = null;
        });
    }
    const deleteCategoryBackdrop = document.getElementById('deleteCategoryBackdrop');
    if (deleteCategoryBackdrop && deleteCategoryModal) {
        deleteCategoryBackdrop.addEventListener('click', () => {
            closeModal(deleteCategoryModal);
            pendingDeleteCategoryForm = null;
        });
    }
    const deleteCategoryConfirmBtn = document.getElementById('deleteCategoryConfirmBtn');
    if (deleteCategoryConfirmBtn) {
        deleteCategoryConfirmBtn.addEventListener('click', () => {
            if (!pendingDeleteCategoryForm) return;
            const formToSubmit = pendingDeleteCategoryForm;
            pendingDeleteCategoryForm = null;
            closeModal(deleteCategoryModal);
            if (typeof formToSubmit.requestSubmit === 'function') {
                formToSubmit.requestSubmit();
            } else {
                formToSubmit.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }
        });
    }

    const restockModalClose = document.getElementById('restockModalClose');
    if (restockModalClose && restockModal) restockModalClose.addEventListener('click', () => closeModal(restockModal));
    const restockModalCancel = document.getElementById('restockModalCancel');
    if (restockModalCancel && restockModal) restockModalCancel.addEventListener('click', () => closeModal(restockModal));
    const restockBackdrop = document.getElementById('restockBackdrop');
    if (restockBackdrop && restockModal) restockBackdrop.addEventListener('click', () => closeModal(restockModal));

    const distModalClose = document.getElementById('distModalClose');
    if (distModalClose && distModal) distModalClose.addEventListener('click', () => { closeModal(distModal); clearCart(); });
    const distModalCancel = document.getElementById('distModalCancel');
    if (distModalCancel && distModal) distModalCancel.addEventListener('click', () => { closeModal(distModal); clearCart(); });
    const distBackdrop = document.getElementById('distBackdrop');
    if (distBackdrop && distModal) distBackdrop.addEventListener('click', () => { closeModal(distModal); clearCart(); });

    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (addModal) closeModal(addModal);
        if (categoryModal) closeModal(categoryModal);
        if (distModal) closeModal(distModal);
        if (restockModal) closeModal(restockModal);
        if (deleteCategoryModal) closeModal(deleteCategoryModal);
        pendingDeleteCategoryForm = null;
        clearCart();
    });

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        const trigger = target.closest('.js-open-delete-category-modal');
        if (!(trigger instanceof HTMLElement)) return;
        if (trigger.hasAttribute('disabled')) return;

        const form = trigger.closest('form.js-delete-category-form');
        if (!(form instanceof HTMLFormElement)) return;

        pendingDeleteCategoryForm = form;
        const categoryName = trigger.getAttribute('data-category-name') || 'this category';
        if (deleteCategoryNameText) {
            deleteCategoryNameText.textContent = 'Category: ' + categoryName;
        }
        if (deleteCategoryModal) openModal(deleteCategoryModal);
    });

    // ── "Give Out" single-item quick-add ──
    if (distModal) {
        document.querySelectorAll('.btn-distribute').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.itemId;
                const name = btn.dataset.itemName;
                const qty = parseInt(btn.dataset.itemQty, 10);
                const unit = btn.dataset.itemUnit;
                const cat = btn.dataset.itemCategory;

                const pickerSel = document.getElementById('pickerItemSelect');
                const pickerQty = document.getElementById('pickerQty');
                if (pickerSel && pickerQty) {
                    pickerSel.value = id;
                    pickerQty.value = 1;
                }
                addCartItem(id, name, unit, qty, cat, 1);

                openModal(distModal);
            });
        });
    }

    // ── Cart state ──
    // cart: { itemId -> { name, unit, max, category, qty } }
    const cart = {};

    function clearCart() {
        Object.keys(cart).forEach(k => delete cart[k]);
        renderCart();
    }

    function addCartItem(id, name, unit, max, category, qty) {
        if (cart[id]) {
            // Increment qty if already in cart
            const newQty = cart[id].qty + qty;
            cart[id].qty = Math.min(newQty, max);
        } else {
            cart[id] = { name, unit, max, category, qty: Math.min(qty, max) };
        }
        renderCart();
        updateSubmitBtn();
    }

    function removeCartItem(id) {
        delete cart[id];
        renderCart();
        updateSubmitBtn();
    }

    function renderCart() {
        const body = document.getElementById('cartBody');
        const inputs = document.getElementById('cartInputs');
        const table = document.getElementById('cartTable');
        const emptyMsg = document.getElementById('cartEmpty');
        const summary = document.getElementById('cartSummaryText');

        body.innerHTML = '';
        inputs.innerHTML = '';

        const keys = Object.keys(cart);

        if (keys.length === 0) {
            table.style.display = 'none';
            emptyMsg.style.display = 'block';
            summary.textContent = '';
            return;
        }

        table.style.display = 'table';
        emptyMsg.style.display = 'none';

        keys.forEach((id) => {
            const item = cart[id];
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <div class="cart-item-name">${escHtml(item.name)}</div>
                    <div class="cart-item-cat">${escHtml(item.category)}</div>
                </td>
                <td style="text-align:center;color:#6b7280;font-size:0.82rem;">${item.max} ${escHtml(item.unit)}</td>
                <td style="text-align:center;">
                    <input type="number" class="cart-qty-input" data-id="${id}" min="1" max="${item.max}" value="${item.qty}">
                    <div style="font-size:0.65rem;color:#9ca3af;margin-top:2px;">max ${item.max}</div>
                </td>
                <td style="text-align:center;">
                    <button type="button" class="cart-remove-btn" data-id="${id}">✕ Remove</button>
                </td>`;
            body.appendChild(tr);

            // Qty change listener
            tr.querySelector('.cart-qty-input').addEventListener('input', function () {
                const v = parseInt(this.value, 10);
                if (!isNaN(v) && v >= 1 && v <= item.max) {
                    cart[id].qty = v;
                    this.classList.remove('over');
                } else {
                    this.classList.add('over');
                }
                updateSubmitBtn();
                renderSummary();
            });

            // Remove listener
            tr.querySelector('.cart-remove-btn').addEventListener('click', function () {
                removeCartItem(this.dataset.id);
            });

            // Hidden inputs
            const hi = document.createElement('input');
            hi.type = 'hidden'; hi.name = 'dist_item_ids[]'; hi.value = id;
            inputs.appendChild(hi);

            const hq = document.createElement('input');
            hq.type = 'hidden'; hq.name = 'dist_item_qtys[]'; hq.value = item.qty;
            hq.dataset.id = id;
            inputs.appendChild(hq);
        });

        renderSummary();
    }

    function renderSummary() {
        const keys = Object.keys(cart);
        const summary = document.getElementById('cartSummaryText');
        if (keys.length === 0) { summary.textContent = ''; return; }
        const totalQty = keys.reduce((s, id) => s + (cart[id].qty || 0), 0);
        summary.textContent = keys.length + ' item type' + (keys.length > 1 ? 's' : '') + ', ' + totalQty + ' unit' + (totalQty > 1 ? 's' : '') + ' total';
    }

    function updateSubmitBtn() {
        const btn = document.getElementById('distSubmitBtn');
        const keys = Object.keys(cart);
        const hasOver = keys.some(id => cart[id].qty > cart[id].max || cart[id].qty < 1);
        const valid = keys.length > 0 && !hasOver;
        btn.disabled = !valid;
        btn.style.opacity = valid ? '1' : '0.45';

        // Sync hidden qty inputs
        keys.forEach(id => {
            const inp = document.querySelector(`input[name="dist_item_qtys[]"][data-id="${id}"]`);
            if (inp) inp.value = cart[id].qty;
        });
    }

    // ── Add to cart button ──
    const addToCartBtn = document.getElementById('addToCartBtn');
    if (addToCartBtn) addToCartBtn.addEventListener('click', () => {
        const sel = document.getElementById('pickerItemSelect');
        const qtyInp = document.getElementById('pickerQty');
        const errEl = document.getElementById('pickerError');
        const opt = sel.options[sel.selectedIndex];

        errEl.style.display = 'none';

        if (!sel.value) { errEl.textContent = '⚠️ Please select an item first.'; errEl.style.display = 'block'; return; }

        const id = sel.value;
        const name = opt.dataset.name;
        const unit = opt.dataset.unit;
        const max = parseInt(opt.dataset.max, 10);
        const category = opt.dataset.category;
        const qty = parseInt(qtyInp.value, 10);

        if (isNaN(qty) || qty < 1) { errEl.textContent = '⚠️ Please enter a quantity of at least 1.'; errEl.style.display = 'block'; return; }
        if (qty > max) { errEl.textContent = '⚠️ Only ' + max + ' ' + unit + ' available.'; errEl.style.display = 'block'; return; }
        if (cart[id]) { errEl.textContent = '⚠️ This item is already in the list. Edit the quantity directly in the table below.'; errEl.style.display = 'block'; return; }

        addCartItem(id, name, unit, max, category, qty);
        sel.value = '';
        qtyInp.value = '1';
    });

    function escHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    const openAddModal = document.body.dataset.openAddModal === '1';
    const openCategoryModal = document.body.dataset.openCategoryModal === '1';
    const openDistributeModal = document.body.dataset.openDistributeModal === '1';
    const openRestockModal = document.body.dataset.openRestockModal === '1';

    if (openAddModal && addModal) openModal(addModal);
    if (openCategoryModal && categoryModal) openModal(categoryModal);
    if (openDistributeModal && distModal) openModal(distModal);
    if (openRestockModal && restockModal) openModal(restockModal);

    // ── Search + filters ──
    const invSearch = document.getElementById('invSearch');
    const categoryFilter = document.getElementById('categoryFilter');
    const stockFilter = document.getElementById('stockFilter');
    const expirationFilter = document.getElementById('expirationFilter');
    const availabilityFilter = document.getElementById('availabilityFilter');
    const invRows = document.querySelectorAll('#invBody tr[data-search]');
    const invCount = document.getElementById('invCount');

    function applyFilters() {
        const q = invSearch.value.toLowerCase().trim();
        const cat = categoryFilter ? categoryFilter.value : '';
        const stk = stockFilter.value;
        const exp = expirationFilter ? expirationFilter.value : '';
        const avl = availabilityFilter ? availabilityFilter.value : '';
        let v = 0;

        invRows.forEach(row => {
            const show = (!q || row.dataset.search.includes(q))
                && (!cat || row.dataset.category === cat)
                && (!stk || row.dataset.stock === stk)
                && (!exp || row.dataset.expiration === exp)
                && (!avl || row.dataset.availability === avl);
            row.style.display = show ? '' : 'none';
            if (show) v++;
        });
        invCount.textContent = v + ' item' + (v !== 1 ? 's' : '');
    }

    if (invSearch && stockFilter && invCount) {
        invSearch.addEventListener('input', applyFilters);
        if (categoryFilter) categoryFilter.addEventListener('change', applyFilters);
        stockFilter.addEventListener('change', applyFilters);
        if (expirationFilter) expirationFilter.addEventListener('change', applyFilters);
        if (availabilityFilter) availabilityFilter.addEventListener('change', applyFilters);
    }

    // ── Distribution month filter ──
    const distMonthSelect = document.getElementById('distMonthFilter');
    const distSearch = document.getElementById('distSearch');
    const distRows = document.querySelectorAll('#distBody tr[data-month]');
    const distNoResults = document.getElementById('distNoResults');

    function applyDistFilters() {
        if (!distMonthSelect) return;
        const monthVal = distMonthSelect.value;
        const q = distSearch ? distSearch.value.toLowerCase().trim() : '';
        let visible = 0;

        distRows.forEach(row => {
            const matchMonth = !monthVal || row.dataset.month === monthVal;
            const matchSearch = !q || (row.dataset.search && row.dataset.search.includes(q));
            const show = matchMonth && matchSearch;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (distNoResults) {
            distNoResults.style.display = visible === 0 ? '' : 'none';
        }
    }

    if (distMonthSelect) distMonthSelect.addEventListener('change', applyDistFilters);
    if (distSearch) distSearch.addEventListener('input', applyDistFilters);
    if (distMonthSelect || distSearch) applyDistFilters();

    // ── AJAX Form Submission ──
    document.addEventListener('submit', async (e) => {
        const form = e.target;
        if (!form || form.tagName !== 'FORM') return;

        // Only handle forms explicitly marked for inventory AJAX
        if (!form.dataset.inventoryAjax && form.getAttribute('data-inventory-ajax') !== '1') return;

        // Only handle POST forms
        if (form.method && form.method.toLowerCase() !== 'post') return;

        e.preventDefault();

        // Optional: show loading state on submit button
        const submitBtn = form.querySelector('button[type="submit"]') || (form.id === 'deleteCategoryForm' ? document.getElementById('deleteCategoryConfirmBtn') : null);
        let origText = '';
        if (submitBtn) {
            origText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span style="opacity:0.7">Working...</span>';
            submitBtn.disabled = true;
        }

        try {
            const formData = new FormData(form);
            formData.append('ajax', '1');

            // Use window.location.href directly — form.action is shadowed by
            // the hidden <input name="action"> element, which causes form.action
            // to return an HTMLInputElement object instead of the URL string.
            const postUrl = window.location.href.split('?')[0]; // strip query string
            const res = await fetch(postUrl, {
                method: 'POST',
                body: formData
            });

            // Session expired or access denied
            if (res.status === 401 || res.status === 403) {
                showToast('error', 'Your session may have expired. Please refresh the page or sign in again.');
                if (submitBtn) { submitBtn.innerHTML = origText; submitBtn.disabled = false; }
                return;
            }

            // Make sure the server actually returned JSON before parsing
            const contentType = res.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                showToast('error', 'The server did not return a valid response. Please refresh the page and try again.');
                if (submitBtn) { submitBtn.innerHTML = origText; submitBtn.disabled = false; }
                return;
            }

            const data = await res.json();

            if (data.success) {
                // Show success alert
                showToast('success', data.message || 'Action completed successfully!');

                // Hide modals immediately
                const modal = form.closest('.modal-overlay');
                if (modal) closeModal(modal);

                // Fetch new HTML to update parts of the page
                const pageRes = await fetch(window.location.pathname + '?_inv_refresh=1');
                const html = await pageRes.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');

                // Update dynamic sections
                const sectionsToUpdate = [
                    'summaryGrid',
                    'categoryTableWrap',
                    'distHistoryWrap',
                    'addItemCategorySelect',
                    'addCategoryBadges',
                    'pickerItemSelect'
                ];

                sectionsToUpdate.forEach(id => {
                    const el = document.getElementById(id);
                    const newEl = doc.getElementById(id);
                    if (el && newEl) {
                        el.innerHTML = newEl.innerHTML;
                    }
                });

                // Clear form and cart if applicable
                form.reset();
                if (form.id === 'distributeForm') {
                    clearCart();
                }

                // Re-apply filters for the newly injected history table
                applyDistFilters();

            } else {
                // Show errors in the form
                let errorHtml = '<strong>Please fix the following:</strong><ul>';
                if (Array.isArray(data.errors) && data.errors.length > 0) {
                    data.errors.forEach(err => errorHtml += '<li>' + escHtml(err) + '</li>');
                } else if (data.error) {
                    errorHtml += '<li>' + escHtml(data.error) + '</li>';
                } else if (data.message) {
                    errorHtml += '<li>' + escHtml(data.message) + '</li>';
                } else {
                    errorHtml += '<li>An unknown error occurred. Please try again.</li>';
                }
                errorHtml += '</ul>';

                // Find or create alert container inside modal
                let alertDiv = form.closest('.modal-body')?.querySelector('.modal-alert');
                if (!alertDiv) {
                    alertDiv = document.createElement('div');
                    alertDiv.className = 'modal-alert error';
                    form.parentNode.insertBefore(alertDiv, form);
                }
                alertDiv.innerHTML = errorHtml;
                alertDiv.style.display = 'block';
            }
        } catch (err) {
            console.error('Inventory AJAX Error:', err);
            showToast('error', 'A network error occurred. Please check your connection and try again.');
        } finally {
            if (submitBtn) {
                submitBtn.innerHTML = origText;
                submitBtn.disabled = false;
            }
        }
    });

    function showToast(type, message) {
        const host = document.getElementById('toastContainer');
        if (!host || !message) return;

        const toast = document.createElement('div');
        const isSuccess = type === 'success';
        toast.className = `toast ${isSuccess ? 'toast-success' : 'toast-error'}`;

        const label = document.createElement('span');
        label.className = 'toast-label';
        label.textContent = message;
        toast.appendChild(label);

        host.prepend(toast);

        setTimeout(() => {
            toast.style.transition = 'opacity .25s, transform .25s';
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-6px)';
            setTimeout(() => toast.remove(), 260);
        }, 3800);
    }

    // Show toast on page load if set
    const toastHost = document.getElementById('toastContainer');
    if (toastHost) {
        const successMsg = toastHost.dataset.success || '';
        const errorMsg = toastHost.dataset.error || '';
        if (successMsg) showToast('success', successMsg);
        if (errorMsg) showToast('error', errorMsg);
    }
})();
