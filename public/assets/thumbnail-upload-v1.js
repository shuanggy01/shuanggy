(() => {
    'use strict';

    const input = document.getElementById(
        'vm-thumbnail-file'
    );

    const preview = document.getElementById(
        'vm-thumbnail-preview'
    );

    const empty = document.getElementById(
        'vm-thumbnail-preview-empty'
    );

    const fileName = document.getElementById(
        'vm-thumbnail-file-name'
    );

    if (!input || !preview) {
        return;
    }

    input.addEventListener('change', () => {
        const file = input.files
            && input.files[0]
            ? input.files[0]
            : null;

        if (!file) {
            return;
        }

        if (fileName) {
            fileName.textContent =
                file.name
                + ' • '
                + Math.max(
                    1,
                    Math.round(file.size / 1024)
                )
                + ' KB';
        }

        const objectUrl =
            URL.createObjectURL(file);

        preview.src = objectUrl;
        preview.hidden = false;

        if (empty) {
            empty.hidden = true;
        }

        preview.addEventListener(
            'load',
            () => {
                URL.revokeObjectURL(
                    objectUrl
                );
            },
            { once: true }
        );
    });
})();
