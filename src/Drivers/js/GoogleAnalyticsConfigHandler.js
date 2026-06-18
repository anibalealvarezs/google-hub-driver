console.log('Registering Google Analytics Config Handler');
window.ConfigHandlers['google_analytics'] = {
    getPayload: function () {
        console.log('Executing Google Analytics getPayload');
        const payload = {
            enabled: document.getElementById('ga-channel-enabled')?.checked,
            granular_sync: document.getElementById('ga-granular-sync')?.checked,
            max_workers: document.getElementById('ga-max-workers')?.value || 1,
            cache_history_range: document.getElementById('ga-history-range')?.value,
            feature_toggles: {
                cron_recent_hour: document.getElementById('ga-cron-hour')?.value,
                cron_recent_minute: document.getElementById('ga-cron-minute')?.value
            },
            metrics_strategy: 'default',
            assets: {google_analytics: []}
        };

        payload.metrics_config = {};
        const metricsCard = document.getElementById('google_analytics-metrics-card');
        if (metricsCard) {
            metricsCard.querySelectorAll('.metric-config-card').forEach(card => {
                const nameEl = card.querySelector('.metric-name-label');
                if (!nameEl) return;
                const name = nameEl.textContent.toLowerCase().replace(/ /g, '_');
                const enabled = card.querySelector('.metric-enable').checked;
                const sparkline = card.querySelector('.metric-sparkline').checked;
                const format = card.querySelector('.metric-format').value;
                const precision = parseInt(card.querySelector('.metric-precision').value || 0);
                const rules = [];

                card.querySelectorAll('.rule-item-grid').forEach(ri => {
                    const classValue = ri.querySelector('.rule-class').value;
                    rules.push({
                        min: parseFloat(ri.querySelector('.rule-min').value || 0),
                        max: parseFloat(ri.querySelector('.rule-max').value || 0),
                        class: 'badge-' + classValue
                    });
                });

                payload.metrics_config[name] = {
                    enabled,
                    sparkline,
                    sparkline_direction: card.querySelector('.metric-sparkline-direction').value,
                    sparkline_color: card.querySelector('.metric-sparkline-color').value || null,
                    format,
                    precision,
                    conditional: {
                        enabled: rules.length > 0,
                        config: rules
                    }
                };
            });
        }

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
