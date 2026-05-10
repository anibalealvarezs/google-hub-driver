console.log('Registering Google Search Console Config Handler');
window.ConfigHandlers['google_search_console'] = {
    getPayload: function() {
        console.log('Executing Google Search Console getPayload');
        const payload = {
            enabled: document.getElementById('gsc-channel-enabled')?.checked,
            granular_sync: document.getElementById('gsc-granular-sync')?.checked,
            max_workers: document.getElementById('gsc-max-workers')?.value,
            cache_history_range: document.getElementById('gsc-history-range')?.value,
            feature_toggles: {
                cron_recent_hour: document.getElementById('gsc-cron-hour')?.value,
                cron_recent_minute: document.getElementById('gsc-cron-minute')?.value,
                calculate_synthetics: document.getElementById('gsc-calculate-synthetics')?.checked || false
            },
            assets: { gsc: [] }
        };

        document.querySelectorAll('.asset-item').forEach(item => {
            const cb = item.querySelector('.gsc-asset-sync');
            if (cb && (cb.checked || item.classList.contains('in-config') || item.classList.contains('lost-access'))) {
                const url = cb.value;
                const original = availableAssetsMaps.gsc[url] || {};
                payload.assets.gsc.push({ 
                    url: url, 
                    enabled: cb.checked,
                    target_countries: original.target_countries || [], 
                    target_keywords: original.target_keywords || [],
                    lost_access: item.classList.contains('lost-access'),
                    data: original.data || []
                });
            }
        });

        return payload;
    }
};
