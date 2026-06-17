console.log('Registering Google Business Profile Config Handler');
window.ConfigHandlers['google_business_profile'] = {
    getPayload: function() {
        console.log('Executing Google Business Profile getPayload');
        const payload = {
            enabled: document.getElementById('gbp-enabled')?.checked || false,
            granular_sync: document.getElementById('gbp-granular-sync')?.checked || false,
            assets: { gbp: [] }
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
