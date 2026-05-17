// view_nutrition_modal.js

document.addEventListener('DOMContentLoaded', function() {
    const viewModal         = document.getElementById('viewModal');
    const viewModalBackdrop = document.getElementById('viewModalBackdrop');
    const btnViewClose      = document.getElementById('btnViewClose');
    const viewModalName     = document.getElementById('viewModalName');
    const viewHeight        = document.getElementById('view_height');
    const viewWeight        = document.getElementById('view_weight');
    const viewHFA           = document.getElementById('view_hfa');
    const viewWFA           = document.getElementById('view_wfa');
    const viewWFLH          = document.getElementById('view_wflh');

    function openViewModal() {
        viewModal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closeViewModal() {
        viewModal.classList.add('hidden');
        document.body.style.overflow = '';
    }
    document.querySelectorAll('.btn-open-view').forEach(btn => {
        btn.addEventListener('click', () => {
            viewModalName.textContent = btn.getAttribute('data-fullname') || '';
            viewHeight.value = btn.getAttribute('data-height') || '';
            viewWeight.value = btn.getAttribute('data-weight') || '';
            viewHFA.value = btn.getAttribute('data-hfa') || '';
            viewWFA.value = btn.getAttribute('data-wfa') || '';
            viewWFLH.value = btn.getAttribute('data-wflh') || '';
            openViewModal();
        });
    });
    btnViewClose.addEventListener('click', closeViewModal);
    viewModalBackdrop.addEventListener('click', closeViewModal);
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeViewModal();
    });
});
