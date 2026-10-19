/* PejuangLendir Telegram Publisher V1.9.1 - mobile accordion */
(function () {
    'use strict';

    function initMobileSections() {
        if (!window.matchMedia || !window.matchMedia('(max-width: 782px)').matches) {
            return;
        }

        var table = document.querySelector('#pltp-settings-form .pltp-settings-table');
        if (!table || table.dataset.pltpAccordionReady === '1') {
            return;
        }

        var rows = Array.prototype.slice.call(table.querySelectorAll('tr')).filter(function (row) {
            return row.querySelector('th');
        });

        if (!rows.length) {
            return;
        }

        var sections = [
            { key: 'posting', title: 'Posting & Bot', start: 'Bot Token', open: true },
            { key: 'r2', title: 'Cloudflare R2', start: 'Cloudflare R2', open: false },
            { key: 'rotation', title: 'Thumbnail Rotation', start: 'Thumbnail Rotation', open: false },
            { key: 'category', title: 'Auto Category', start: 'Auto Category', open: false },
            { key: 'seo', title: 'Rank Math SEO', start: 'Rank Math SEO', open: false },
            { key: 'webhook', title: 'Webhook', start: 'Webhook URL', open: false }
        ];

        function labelOf(row) {
            var th = row.querySelector('th');
            return th ? th.textContent.replace(/\s+/g, ' ').trim() : '';
        }

        sections.forEach(function (section) {
            section.index = rows.findIndex(function (row) {
                return labelOf(row) === section.start;
            });
        });

        sections = sections.filter(function (section) {
            return section.index >= 0;
        }).sort(function (a, b) {
            return a.index - b.index;
        });

        if (!sections.length) {
            return;
        }

        function setOpen(section, button, sectionRows, open) {
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            var icon = button.querySelector('.pltp-section-toggle-icon');
            if (icon) {
                icon.textContent = open ? '▾' : '▸';
            }
            sectionRows.forEach(function (row) {
                row.classList.toggle('pltp-section-collapsed', !open);
            });
            try {
                window.sessionStorage.setItem('pltp-section-' + section.key, open ? '1' : '0');
            } catch (e) {
                // Storage is optional; the accordion still works without it.
            }
        }

        sections.forEach(function (section, sectionIndex) {
            var nextIndex = sectionIndex + 1 < sections.length ? sections[sectionIndex + 1].index : rows.length;
            var sectionRows = rows.slice(section.index, nextIndex);
            var firstRow = sectionRows[0];
            var container = firstRow.parentNode;

            sectionRows.forEach(function (row) {
                row.classList.add('pltp-section-row');
                row.dataset.pltpSection = section.key;
            });

            var headerRow = document.createElement('tr');
            headerRow.className = 'pltp-section-toggle-row';

            var cell = document.createElement('td');
            cell.colSpan = 2;

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'pltp-section-toggle';
            button.innerHTML = '<span></span><span class="pltp-section-toggle-icon" aria-hidden="true"></span>';
            button.querySelector('span').textContent = section.title;

            cell.appendChild(button);
            headerRow.appendChild(cell);
            container.insertBefore(headerRow, firstRow);

            var open = section.open;
            try {
                var saved = window.sessionStorage.getItem('pltp-section-' + section.key);
                if (saved === '1' || saved === '0') {
                    open = saved === '1';
                }
            } catch (e) {
                // Ignore storage failures.
            }

            setOpen(section, button, sectionRows, open);

            button.addEventListener('click', function () {
                var isOpen = button.getAttribute('aria-expanded') === 'true';
                setOpen(section, button, sectionRows, !isOpen);
            });
        });

        table.dataset.pltpAccordionReady = '1';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMobileSections);
    } else {
        initMobileSections();
    }
}());
