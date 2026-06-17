console.log('Registering Google Business Profile Config Handler');
window.ConfigHandlers['google_business_profile'] = {
    getPayload: function () {
        console.log('Executing Google Business Profile getPayload');
        const payload = {
            enabled: document.getElementById('gbp-channel-enabled')?.checked,
            granular_sync: document.getElementById('gbp-granular-sync')?.checked,
            max_workers: document.getElementById('gbp-max-workers')?.value,
            cache_history_range: document.getElementById('gbp-history-range')?.value,
            feature_toggles: {
                cron_recent_hour: document.getElementById('gbp-cron-hour')?.value,
                cron_recent_minute: document.getElementById('gbp-cron-minute')?.value
            },
            assets: {gbp: []}
        };

        document.querySelectorAll('.gbp-location-config-card').forEach(card => {
            const mainToggle = card.querySelector('.gbp-location-main-toggle');
            if (!mainToggle) return;

            const locationId = String(card.dataset.locationId);
            const originalDataStr = card.dataset.rawData;
            const original = originalDataStr ? JSON.parse(originalDataStr) : {};

            const locationData = {
                location_id: locationId,
                platformId: locationId,
                title: original.title || original.name || null,
                enabled: !card.classList.contains('lost-access') && mainToggle.checked,
                lost_access: card.classList.contains('lost-access'),
                data: original.data || {}
            };

            card.querySelectorAll('.gbp-metric-toggle').forEach(metricCb => {
                const metricId = metricCb.dataset.metric;
                if (metricId) {
                    locationData[metricId] = { enabled: metricCb.checked };
                }
            });

            payload.assets.gbp.push(locationData);
        });

        return payload;
    }
};
