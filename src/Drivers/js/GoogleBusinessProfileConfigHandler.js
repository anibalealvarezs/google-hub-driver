console.log('Registering Google Business Profile Config Handler');
window.ConfigHandlers['google_business_profile'] = {
    getPayload: function () {
        console.log('Executing Google Business Profile getPayload');
        const payload = {
            enabled: document.getElementById('google_business_profile-channel-enabled')?.checked,
            granular_sync: document.getElementById('google_business_profile-granular-sync')?.checked,
            max_workers: document.getElementById('google_business_profile-max-workers')?.value,
            cache_history_range: document.getElementById('google_business_profile-history-range')?.value,
            feature_toggles: {
                cron_recent_hour: document.getElementById('google_business_profile-cron-hour')?.value,
                cron_recent_minute: document.getElementById('google_business_profile-cron-minute')?.value
            },
            assets: {gbp: []}
        };

        document.querySelectorAll('.gbp-location-config-card').forEach(card => {
            const mainToggle = card.querySelector('.gbp-location-main-toggle');
            if (!mainToggle) return;

            const locationId = String(mainToggle.dataset.id);
            const original = availableAssetsMaps.locations[locationId] || {};

            const locationData = {
                location_id: locationId,
                platformId: locationId,
                title: original.title || original.name || null,
                enabled: !card.classList.contains('lost-access') && mainToggle.checked,
                lost_access: card.classList.contains('lost-access'),
                data: original.data || {}
            };

            payload.assets.gbp.push(locationData);
        });

        return payload;
    }
};
