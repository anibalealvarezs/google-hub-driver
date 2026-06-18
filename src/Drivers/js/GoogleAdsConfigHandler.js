function getPayload() {
    let payload = {
        enabled: document.getElementById('gads-channel-enabled')?.checked || false,
        granular_sync: document.getElementById('gads-granular-sync')?.checked || false,
        cache_history_range: document.getElementById('gads-history-range')?.value || '2 years',
        max_workers: parseInt(document.getElementById('gads-max-workers')?.value || '2'),
        feature_toggles: {
            cron_recent_hour: parseInt(document.getElementById('gads-cron-hour')?.value || '5'),
            cron_recent_minute: parseInt(document.getElementById('gads-cron-minute')?.value || '30'),
        },
        assets: {
            google_ads: []
        }
    };

    const accountCards = document.querySelectorAll('.gads-account-config-card');
    accountCards.forEach(card => {
        try {
            const raw = JSON.parse(card.dataset.rawData || '{}');
            const isEnabled = card.querySelector('.gads-account-main-toggle')?.checked || false;

            payload.assets.google_ads.push({
                id: card.dataset.platformId,
                name: raw.name || raw.title || '',
                enabled: isEnabled,
                data: raw.data || raw
            });
        } catch (e) {
            console.error("Error parsing Google Ads account data", e);
        }
    });

    return payload;
}
