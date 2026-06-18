console.log('Registering Google Analytics Config Handler');
window.ConfigHandlers['google_analytics'] = {
    getPayload: function () {
        console.log('Executing Google Analytics getPayload');
        const payload = {
            enabled: document.getElementById('ga-channel-enabled')?.checked,
            granular_sync: document.getElementById('ga-granular-sync')?.checked,
            max_workers: document.getElementById('ga-max-workers')?.value,
            cache_history_range: document.getElementById('ga-history-range')?.value,
            feature_toggles: {
                cron_recent_hour: document.getElementById('ga-cron-hour')?.value,
                cron_recent_minute: document.getElementById('ga-cron-minute')?.value
            },
            assets: {google_analytics: []}
        };

        document.querySelectorAll('.ga-property-config-card').forEach(card => {
            const mainToggle = card.querySelector('.ga-property-main-toggle');
            if (!mainToggle) return;

            const platformId = String(card.dataset.platformId);
            const originalDataStr = card.dataset.rawData;
            const original = originalDataStr ? JSON.parse(originalDataStr) : {};

            const propertyData = {
                platformId: platformId,
                name: original.name || original.title || null,
                enabled: !card.classList.contains('lost-access') && mainToggle.checked,
                lost_access: card.classList.contains('lost-access'),
                data: original.data || {}
            };

            payload.assets.google_analytics.push(propertyData);
        });

        return payload;
    }
};
