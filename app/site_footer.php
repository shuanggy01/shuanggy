<?php

declare(strict_types=1);

function site_footer_render(): string
{
    $year = date('Y');

    if (!function_exists('ad_render')) {
        require_once __DIR__ . '/ads.php';
    }

    ob_start();
    ?>
<?= ad_render('footer') ?>
<footer class="site-legal-footer">
    <div class="site-legal-footer-inner">

        <div class="site-legal-brand">
            <img
                src="/assets/brand-mark.png"
                alt="AsupanLendir"
                loading="lazy"
            >

            <div>
                <strong>AsupanLendir</strong>
                <span>
                    © <?= htmlspecialchars(
                        $year,
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    ) ?> AsupanLendir. All rights reserved.
                </span>
            </div>
        </div>

        <nav class="site-legal-links" aria-label="Legal">
            <a href="/privacy-policy">Privacy</a>
            <a href="/terms">Terms</a>
            <a href="/copyright">Copyright</a>
            <a href="/disclaimer">Disclaimer</a>
            <a href="/contact">Contact</a>
        </nav>

        <div class="site-legal-note">
            <span>Dukungan:</span>
            <a href="mailto:ebus@asupanlendir.sbs">
                ebus@asupanlendir.sbs
            </a>
        </div>

    </div>
</footer>
    <?php

    return (string) ob_get_clean();
}
