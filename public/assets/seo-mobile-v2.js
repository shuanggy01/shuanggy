(() => {
    const title = document.querySelector('[data-seo-title]');
    const description = document.querySelector('[data-seo-description]');
    const image = document.querySelector('[data-seo-image]');

    const titleCount = document.querySelector('[data-title-count]');
    const descriptionCount = document.querySelector('[data-description-count]');

    const previewTitle = document.querySelector('[data-preview-title]');
    const previewDescription = document.querySelector('[data-preview-description]');
    const shareTitle = document.querySelector('[data-share-title]');
    const shareDescription = document.querySelector('[data-share-description]');

    function updateCount(input, output, recommendedMax) {
        if (!input || !output) {
            return;
        }

        const length = input.value.length;

        output.textContent =
            length
            + '/'
            + recommendedMax;

        output.classList.toggle(
            'warn',
            length > recommendedMax
        );
    }

    function updatePreview() {
        if (title) {
            const fallback = title.getAttribute('placeholder') || '';
            const value = title.value.trim() || fallback;

            if (previewTitle) {
                previewTitle.textContent = value;
            }

            if (shareTitle) {
                shareTitle.textContent = value;
            }

            updateCount(title, titleCount, 60);
        }

        if (description) {
            const fallback =
                description.getAttribute('placeholder')
                || '';

            const value =
                description.value.trim()
                || fallback;

            if (previewDescription) {
                previewDescription.textContent = value;
            }

            if (shareDescription) {
                shareDescription.textContent = value;
            }

            updateCount(
                description,
                descriptionCount,
                160
            );
        }

        if (image) {
            const previewImg =
                document.querySelector(
                    '[data-preview-image]'
                );

            const fallback =
                image.getAttribute('placeholder')
                || '';

            const value =
                image.value.trim()
                || fallback;

            if (previewImg && value) {
                previewImg.src = value;
            }
        }
    }

    title?.addEventListener('input', updatePreview);
    description?.addEventListener('input', updatePreview);
    image?.addEventListener('input', updatePreview);

    updatePreview();
})();
