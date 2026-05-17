document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('table.table-stack').forEach(table => {
        const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
        if (!headers.length) return;
        table.querySelectorAll('tbody tr').forEach(row => {
            let colIndex = 0;
            row.querySelectorAll('td').forEach(td => {
                const span = parseInt(td.getAttribute('colspan') || '1', 10);
                if (span > 1) {
                    colIndex += span;
                    return;
                }
                if (!td.dataset.label) td.dataset.label = headers[colIndex] || '';
                colIndex += 1;
            });
        });
    });
});
