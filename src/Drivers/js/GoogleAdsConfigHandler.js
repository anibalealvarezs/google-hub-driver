console.log('Registering Google Ads Config Handler');
window.ConfigHandlers['google_ads'] = {
    getPayload: function () {
        console.log('Executing Google Ads getPayload');
        let payload = {
            enabled: document.getElementById('gads-channel-enabled')?.checked || false,
            granular_sync: document.getElementById('gads-granular-sync')?.checked || false,
            cache_history_range: document.getElementById('gads-history-range')?.value || '2 years',
            max_workers: parseInt(document.getElementById('gads-max-workers')?.value || '2'),
            feature_toggles: {
                cron_recent_hour: parseInt(document.getElementById('gads-cron-hour')?.value || '5'),
                cron_recent_minute: parseInt(document.getElementById('gads-cron-minute')?.value || '30'),
                ad_account_metrics: false,
                campaigns: true,
                campaign_metrics: true,
                adgroups: true,
                adgroup_metrics: false,
                ads: true,
                ad_metrics: true
            },
            metrics_strategy: 'default',
            assets: {
                google_ads: []
            }
        };

        payload.metrics_config = {};
        const metricsCard = document.getElementById('google_ads-metrics-card');
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
};
