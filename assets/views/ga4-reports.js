let mainChart = null;
let activeMetrics = {
    sessions: true,
    activeUsers: true,
    newUsers: false,
    conversions: false,
};

const GA4_COLORS = {
    sessions: "#4285F4",
    activeUsers: "#0F9D58",
    newUsers: "#FBBC04",
    conversions: "#EA4335",
    screenPageViews: "#9C27B0",
};

let currentData = {
    campaign: [],
    channel: [],
};

let currentSort = {
    campaign: {key: "sessions", direction: "desc"},
    channel: {key: "sessions", direction: "desc"},
};

let reportRequestSeq = 0;
let tableRequestSeq = {
    campaign: 0,
    channel: 0,
};

let activeReportController = null;
let activeControllers = {
    campaign: null,
    channel: null,
};

const CHART_CONFIG = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {mode: "index", intersect: false},
    scales: {
        ySessions: { type: "linear", display: true, position: "left", grid: {color: "rgba(255,255,255,0.05)"}, ticks: {color: GA4_COLORS.sessions} },
        yActiveUsers: { type: "linear", display: false, position: "left", grid: {display: false}, ticks: {color: GA4_COLORS.activeUsers} },
        yNewUsers: { type: "linear", display: false, position: "right", grid: {display: false}, ticks: {color: GA4_COLORS.newUsers} },
        yConversions: { type: "linear", display: false, position: "right", grid: {display: false}, ticks: {color: GA4_COLORS.conversions} },
        x: {grid: {display: false}, ticks: {color: "#8B949E"}},
    },
    plugins: { legend: {display: false} },
};

async function flushCache() {
    const btn = document.querySelector('[onclick="flushCache()"]');
    const originalIcon = btn.innerHTML;
    btn.innerHTML = '<i data-lucide="loader-2" class="animate-spin" style="width: 18px; height: 18px;"></i>';
    lucide.createIcons();

    try {
        const headers = getAuthHeaders(true);
        const response = await fetch("/api/config-manager/flush-cache", {
            method: "POST",
            headers: headers,
            body: JSON.stringify({channel: "google_analytics"}),
        });

        const payload = await response.json().catch(() => null);
        if (!response.ok || payload?.success !== true) throw new Error("Flush cache failed");

        btn.innerHTML = '<i data-lucide="check" style="width: 18px; height: 18px; color: #4ade80;"></i>';
        lucide.createIcons();

        setTimeout(() => {
            btn.innerHTML = originalIcon;
            lucide.createIcons();
            loadReport(); 
        }, 1000);
    } catch (e) {
        btn.innerHTML = originalIcon;
        lucide.createIcons();
    }
}

document.addEventListener("DOMContentLoaded", () => {
    initPropertySelector();
    initDateRange();
    lucide.createIcons();
});

function getAuthHeaders(includeJsonContentType = false) {
    const auth = localStorage.getItem("apis_hub_admin_auth");
    return {
        ...(includeJsonContentType ? {"Content-Type": "application/json"} : {}),
        ...(auth ? {Authorization: "Bearer " + JSON.parse(auth).token} : {}),
    };
}

function initPropertySelector() {
    const sel = document.getElementById("propertySelector");
    fetch("/google_analytics/channeled_account", {headers: getAuthHeaders()})
        .then((res) => res.json())
        .then((res) => {
            if (res.data && res.data.length > 0) {
                sel.innerHTML = "";
                res.data.forEach((p) => {
                    const opt = document.createElement("option");
                    opt.value = p.id;
                    opt.textContent = p.name || p.title || p.platform_id;
                    sel.appendChild(opt);
                });
                loadReport();
            } else {
                sel.innerHTML = '<option value="">No properties found</option>';
            }
        });
}

function initDateRange() {
    const end = dayjs().subtract(3, "day");
    const start = end.subtract(28, "day");

    flatpickr("#reportRange", {
        mode: "range",
        dateFormat: "Y-m-d", 
        defaultDate: [start.format("YYYY-MM-DD"), end.format("YYYY-MM-DD")],
        onChange: (selectedDates) => {
            if (selectedDates.length === 2) loadReport();
        },
    });
}

function abortControllerSafely(controller) {
    if (!controller) return;
    try { controller.abort(); } catch (e) {}
}

function isAbortError(error) {
    return error?.name === "AbortError";
}

function setSectionState(section, {loading = false, message = "", error = ""} = {}) {
    const sectionMap = {
        summary: {containerId: "summaryGrid", statusId: "summaryStatus"},
        chart: {containerId: "chartSection", statusId: "chartStatus"},
        campaign: {containerId: "campaignSection", statusId: "campaignStatus"},
        channel: {containerId: "channelSection", statusId: "channelStatus"},
    };

    const config = sectionMap[section];
    if (!config) return;

    const container = document.getElementById(config.containerId);
    const statusEl = document.getElementById(config.statusId);
    if (container) container.classList.toggle("is-loading", loading);

    if (!statusEl) return;

    if (!error && !message) {
        statusEl.classList.remove("is-visible", "is-error");
        statusEl.innerHTML = "";
        return;
    }

    statusEl.classList.add("is-visible");
    statusEl.classList.toggle("is-error", Boolean(error));
    statusEl.innerHTML = error
        ? `<i data-lucide="alert-triangle" style="width: 14px; height: 14px;"></i><span>${error}</span>`
        : `<i data-lucide="loader-2" class="animate-spin" style="width: 14px; height: 14px;"></i><span>${message}</span>`;

    if (window.lucide) lucide.createIcons();
}

async function loadReport() {
    const propEl = document.getElementById("propertySelector");
    const rangeEl = document.getElementById("reportRange");
    if (!propEl || !rangeEl) return;

    const propertyId = propEl.value;
    const range = rangeEl.value.split(" to ");
    if (!propertyId || range.length < 2) return;

    const [start, end] = range;
    const [prevStart, prevEnd] = calculatePreviousPeriod(start, end);
    const requestId = ++reportRequestSeq;

    abortControllerSafely(activeReportController);
    activeReportController = new AbortController();
    const signal = activeReportController.signal;

    setSectionState("summary", {loading: true, message: "Updating summary cards..."});
    setSectionState("chart", {loading: true, message: "Updating chart..."});

    // Load cross-matrix sections
    const activeCampaignTab = document.querySelector(".campaign-tab.active")?.getAttribute("data-tab") || "campaigns";
    const activeChannelTab = document.querySelector(".channel-tab.active")?.getAttribute("data-tab") || "channels";
    
    loadCampaignSection(activeCampaignTab, {propertyId, start, end});
    loadChannelSection(activeChannelTab, {propertyId, start, end});

    // Main Summary and Chart: We merge traffic_matrix and acquisition_matrix
    const trafficMetrics = ["sessions", "screenPageViews", "conversions"];
    const acqMetrics = ["newUsers", "activeUsers"];

    const fetchMergeSummary = async (s, e) => {
        const results = await fetchCrossMatrixAggregation([
            {scope: 'traffic_matrix', metrics: trafficMetrics, groupBy: []},
            {scope: 'acquisition_matrix', metrics: acqMetrics, groupBy: []}
        ], {propertyId, start: s, end: e, signal});
        
        let merged = {};
        results.forEach(rows => {
            if (rows && rows[0]) {
                Object.assign(merged, rows[0]);
            }
        });
        return [merged];
    };

    const fetchMergeChart = async (s, e) => {
        const results = await fetchCrossMatrixAggregation([
            {scope: 'traffic_matrix', metrics: trafficMetrics, groupBy: ["daily"]},
            {scope: 'acquisition_matrix', metrics: acqMetrics, groupBy: ["daily"]}
        ], {propertyId, start: s, end: e, signal});
        return mergeMatrixResults(results, ["daily", "daily"]);
    };

    Promise.all([
        fetchMergeSummary(start, end).catch(() => []),
        fetchMergeSummary(prevStart, prevEnd).catch(() => []),
        fetchMergeChart(start, end).catch(() => [])
    ]).then(([summary, prevSummary, dailyData]) => {
        if (requestId !== reportRequestSeq) return;
        
        updateSummaryCards(summary[0] || {}, prevSummary[0] || {});
        setSectionState("summary", {});
        
        renderChart(dailyData);
        setSectionState("chart", {});
    });
}

async function fetchAggregation(metrics, groupBy, filters, start, end, options = {}) {
    const headers = getAuthHeaders(true);
    const cleanFilters = {...filters};
    if (!cleanFilters.channeledAccount) delete cleanFilters.channeledAccount;
    cleanFilters.channel = "google_analytics";

    const body = { aggregations: {}, groupBy, filters: cleanFilters, startDate: start, endDate: end };
    metrics.forEach(m => body.aggregations[m] = m);

    const res = await fetch("/google_analytics/metric/aggregate", {
        method: "POST", headers, body: JSON.stringify(body), signal: options.signal,
    });
    const data = await res.json();
    if (!res.ok || data.status !== "success") throw new Error(data.message);
    return data.data || [];
}

async function fetchCrossMatrixAggregation(queries, options) {
    const promises = queries.map(q => 
        fetchAggregation(q.metrics, q.groupBy, 
            {channeledAccount: options.propertyId, 'dimensions.scope': q.scope}, 
            options.start, options.end, {signal: options.signal})
            .catch(() => []) // Catch errors per query to allow partial success
    );
    
    return await Promise.all(promises);
}

function mergeMatrixResults(results, primaryGroupByKeys) {
    const map = new Map();
    
    results.forEach((rows, queryIndex) => {
        const dimKey = primaryGroupByKeys[queryIndex];
        
        rows.forEach(row => {
            const dimValue = getRowValueByKey(row, dimKey);
            if (!dimValue || dimValue === 'unknown' || dimValue === 'null') return;
            
            const key = String(dimValue).toLowerCase();
            
            if (!map.has(key)) {
                map.set(key, { _dimKey: dimKey, _dimValue: dimValue });
            }
            
            const existing = map.get(key);
            Object.keys(row).forEach(k => {
                // skip dimension keys
                if (k.toLowerCase() === dimKey.toLowerCase()) return;
                
                // if it's a number, assign it
                const val = parseFloat(row[k]);
                if (!isNaN(val)) {
                    existing[k] = (existing[k] || 0) + val;
                } else {
                    existing[k] = row[k];
                }
            });
        });
    });
    
    return Array.from(map.values()).map(r => {
        r[primaryGroupByKeys[0]] = r._dimValue;
        return r;
    });
}

/* -------------------------------------------------------------------------- */
/*                                SECTIONS                                    */
/* -------------------------------------------------------------------------- */

async function loadCampaignSection(tab, options) {
    const tabConfigs = {
        campaigns: {
            label: "Campaign",
            queries: [
                { scope: 'traffic_matrix', metrics: ['sessions', 'screenPageViews', 'conversions'], groupBy: ['channeledCampaign'] },
                { scope: 'acquisition_matrix', metrics: ['newUsers', 'activeUsers'], groupBy: ['channeledCampaign'] }
            ],
            groupByKeys: ['channeledCampaign', 'channeledCampaign']
        },
        adgroups: {
            label: "Ad Group",
            queries: [
                { scope: 'traffic_matrix', metrics: ['sessions', 'screenPageViews', 'conversions'], groupBy: ['dimensions.sessionGoogleAdsAdGroupName'] },
                { scope: 'ad_touchpoint_matrix', metrics: ['activeUsers'], groupBy: ['dimensions.sessionGoogleAdsAdGroupName'] }
            ],
            groupByKeys: ['dimensions.sessionGoogleAdsAdGroupName', 'dimensions.sessionGoogleAdsAdGroupName']
        }
    };
    
    const config = tabConfigs[tab] || tabConfigs.campaigns;
    const reqId = ++tableRequestSeq.campaign;
    abortControllerSafely(activeControllers.campaign);
    activeControllers.campaign = new AbortController();

    const cols = ["sessions", "screenPageViews", "newUsers", "activeUsers", "conversions"];
    renderTableHeaders("campaign", config.label, cols);
    showTableLoader("campaign", config.label);
    setSectionState("campaign", {loading: true, message: `Loading ${config.label}...`});

    try {
        const results = await fetchCrossMatrixAggregation(config.queries, {
            propertyId: options.propertyId, 
            start: options.start, 
            end: options.end, 
            signal: activeControllers.campaign.signal
        });

        if (tableRequestSeq.campaign !== reqId) return;
        
        currentData.campaign = mergeMatrixResults(results, config.groupByKeys);
        currentSort.campaign = {key: "sessions", direction: "desc"};
        
        applySortAndRender("campaign", config.groupByKeys[0], cols);
        setSectionState("campaign", {});
    } catch (e) {
        if (!isAbortError(e) && tableRequestSeq.campaign === reqId) {
            setSectionState("campaign", {error: "Failed to load."});
            document.getElementById("campaign-body").innerHTML = `<tr><td colspan="6">Error</td></tr>`;
        }
    }
}

async function loadChannelSection(tab, options) {
    const tabConfigs = {
        channels: {
            label: "Channel",
            queries: [
                { scope: 'traffic_matrix', metrics: ['sessions', 'screenPageViews', 'conversions'], groupBy: ['dimensions.sessionDefaultChannelGroup'] },
                { scope: 'acquisition_matrix', metrics: ['newUsers', 'activeUsers'], groupBy: ['dimensions.firstUserDefaultChannelGroup'] }
            ],
            groupByKeys: ['dimensions.sessionDefaultChannelGroup', 'dimensions.firstUserDefaultChannelGroup']
        },
        sources: {
            label: "Source / Medium",
            queries: [
                { scope: 'traffic_matrix', metrics: ['sessions', 'screenPageViews', 'conversions'], groupBy: ['dimensions.sessionSourceMedium'] },
                { scope: 'acquisition_matrix', metrics: ['newUsers', 'activeUsers'], groupBy: ['dimensions.firstUserSourceMedium'] }
            ],
            groupByKeys: ['dimensions.sessionSourceMedium', 'dimensions.firstUserSourceMedium']
        }
    };
    
    const config = tabConfigs[tab] || tabConfigs.channels;
    const reqId = ++tableRequestSeq.channel;
    abortControllerSafely(activeControllers.channel);
    activeControllers.channel = new AbortController();

    const cols = ["sessions", "screenPageViews", "newUsers", "activeUsers", "conversions"];
    renderTableHeaders("channel", config.label, cols);
    showTableLoader("channel", config.label);
    setSectionState("channel", {loading: true, message: `Loading ${config.label}...`});

    try {
        const results = await fetchCrossMatrixAggregation(config.queries, {
            propertyId: options.propertyId, 
            start: options.start, 
            end: options.end, 
            signal: activeControllers.channel.signal
        });

        if (tableRequestSeq.channel !== reqId) return;
        
        currentData.channel = mergeMatrixResults(results, config.groupByKeys);
        currentSort.channel = {key: "sessions", direction: "desc"};
        
        applySortAndRender("channel", config.groupByKeys[0], cols);
        setSectionState("channel", {});
    } catch (e) {
        if (!isAbortError(e) && tableRequestSeq.channel === reqId) {
            setSectionState("channel", {error: "Failed to load."});
            document.getElementById("channel-body").innerHTML = `<tr><td colspan="6">Error</td></tr>`;
        }
    }
}

function switchCampaignTab(el, tab) {
    document.querySelectorAll(".campaign-tab").forEach(t => t.classList.remove("active"));
    el.classList.add("active");
    const p = document.getElementById("propertySelector").value;
    const r = document.getElementById("reportRange").value.split(" to ");
    loadCampaignSection(tab, {propertyId: p, start: r[0], end: r[1]});
}

function switchChannelTab(el, tab) {
    document.querySelectorAll(".channel-tab").forEach(t => t.classList.remove("active"));
    el.classList.add("active");
    const p = document.getElementById("propertySelector").value;
    const r = document.getElementById("reportRange").value.split(" to ");
    loadChannelSection(tab, {propertyId: p, start: r[0], end: r[1]});
}

/* -------------------------------------------------------------------------- */
/*                              TABLE RENDERING                               */
/* -------------------------------------------------------------------------- */

function sortSectionTable(section, key, dimKey, cols) {
    if (currentSort[section].key === key) {
        currentSort[section].direction = currentSort[section].direction === "asc" ? "desc" : "asc";
    } else {
        currentSort[section].key = key;
        currentSort[section].direction = "desc";
    }
    applySortAndRender(section, dimKey, cols);
}

function applySortAndRender(section, dimKey, cols) {
    let data = currentData[section] || [];
    const sortConf = currentSort[section];

    const sorted = [...data].sort((a, b) => {
        const valA = parseFloat(a[sortConf.key] || 0);
        const valB = parseFloat(b[sortConf.key] || 0);
        return sortConf.direction === "asc" ? valA - valB : valB - valA;
    });

    renderTableHeaders(section, document.getElementById(`${section}-table-headers`).firstElementChild.textContent, cols, dimKey);
    renderTableBody(section, sorted, dimKey, cols);
}

function renderTableHeaders(section, label, cols, dimKey = null) {
    const tr = document.getElementById(`${section}-table-headers`);
    if (!tr) return;
    
    let html = `<th>${label}</th>`;
    cols.forEach(col => {
        const colLabel = col.replace(/([A-Z])/g, ' $1').toUpperCase();
        const sortArgs = dimKey ? `'${section}', '${col}', '${dimKey}', ['${cols.join("','")}']` : '';
        html += `<th ${dimKey ? `onclick="sortSectionTable(${sortArgs})"` : ''} style="cursor:pointer;">
            <div style="display:flex; align-items:center; justify-content:flex-end; gap:6px;">
                ${colLabel} <span class="sort-icon-wrapper" data-col="${col}"></span>
            </div>
        </th>`;
    });
    tr.innerHTML = html;

    if (dimKey) {
        const sortConf = currentSort[section];
        tr.querySelectorAll('.sort-icon-wrapper').forEach(wrapper => {
            if (wrapper.dataset.col === sortConf.key) {
                const icon = sortConf.direction === "asc" ? "arrow-up" : "arrow-down";
                wrapper.innerHTML = `<i data-lucide="${icon}" style="width:14px; height:14px; color: #fff;"></i>`;
            } else {
                wrapper.innerHTML = `<i data-lucide="chevrons-up-down" style="width:14px; height:14px; color: rgba(255,255,255,0.2);"></i>`;
            }
        });
        if (window.lucide) lucide.createIcons();
    }
}

function showTableLoader(section, label) {
    const tbody = document.getElementById(`${section}-body`);
    if (tbody) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding: 4rem;"><i data-lucide="loader-2" class="animate-spin" style="width: 32px; height: 32px; margin-bottom: 1rem; color: var(--text-dim);"></i><br><span style="color: var(--text-dim);">Loading ${label}...</span></td></tr>`;
        if (window.lucide) lucide.createIcons();
    }
}

function renderTableBody(section, data, dimKey, cols) {
    const tbody = document.getElementById(`${section}-body`);
    if (!tbody) return;
    tbody.innerHTML = "";

    if (!data || data.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding: 2rem; color: var(--text-dim);">No data available.</td></tr>`;
        return;
    }

    const maxVals = {};
    cols.forEach(c => maxVals[c] = Math.max(...data.map(d => parseFloat(d[c] || 0)), 1));

    data.forEach(row => {
        const dimValue = getRowValueByKey(row, dimKey);
        if (!dimValue || dimValue === 'unknown' || dimValue === 'null') return;

        const tr = document.createElement("tr");
        let dimContent = `<div class="ga4-url-text" title="${dimValue}">${dimValue.length > 80 ? dimValue.slice(0, 79)+'…' : dimValue}</div>`;
        if (dimKey === "dimensions.landing_page") {
            dimContent = `<div class="ga4-url-container"><div class="ga4-url-text" title="${dimValue}">${dimValue}</div><a href="${dimValue}" target="_blank" class="ga4-external-link"><i data-lucide="external-link" style="width:14px; height:14px;"></i></a></div>`;
        }

        let rowHtml = `<td>${dimContent}</td>`;
        cols.forEach(col => {
            const val = parseFloat(row[col] || 0);
            const pct = (val / maxVals[col]) * 100;
            const color = GA4_COLORS[col] || "#ffffff";
            rowHtml += `<td class="metric-cell">
                <div class="metric-val-main">${val.toLocaleString()}</div>
                <div class="progress-bar-container"><div class="progress-bar-fill" style="width: ${pct}%; background: ${color};"></div></div>
            </td>`;
        });
        tr.innerHTML = rowHtml;
        tbody.appendChild(tr);
    });
    if (window.lucide) lucide.createIcons();
}

function getRowValueByKey(row, key) {
    if (!row || !key) return undefined;
    if (Object.prototype.hasOwnProperty.call(row, key)) return row[key];
    const target = key.toLowerCase();
    const matchedKey = Object.keys(row).find(k => k.toLowerCase() === target);
    return matchedKey ? row[matchedKey] : undefined;
}

/* -------------------------------------------------------------------------- */
/*                          CHARTS & CARDS UTILS                              */
/* -------------------------------------------------------------------------- */

function updateSummaryCards(data, prevData) {
    ["sessions", "activeUsers", "newUsers", "conversions"].forEach(id => {
        const val = parseInt(data[id] || 0);
        const prev = parseInt(prevData[id] || 0);
        
        const el = document.getElementById(`val-${id}`);
        if (el) el.textContent = val.toLocaleString();

        const trendEl = document.getElementById(`trend-${id}`);
        if (trendEl) {
            if (!prevData || Object.keys(prevData).length === 0) {
                trendEl.textContent = '--';
                return;
            }
            const diff = val - prev;
            const pct = prev > 0 ? (diff / prev) * 100 : 0;
            const icon = diff > 0 ? 'arrow-up' : 'arrow-down';
            trendEl.style.color = diff > 0 ? '#22C55E' : '#EF4444';
            trendEl.innerHTML = `<i data-lucide="${icon}" style="width:12px; height:12px; vertical-align:middle;"></i> ${diff>0?'+':''}${pct.toFixed(1)}%`;
        }
    });
    if (window.lucide) lucide.createIcons();
}

function calculatePreviousPeriod(start, end) {
    const diff = dayjs(end).diff(dayjs(start), 'day') + 1;
    const prevEnd = dayjs(start).subtract(1, 'day');
    const prevStart = prevEnd.subtract(diff - 1, 'day');
    return [prevStart.format('YYYY-MM-DD'), prevEnd.format('YYYY-MM-DD')];
}

function renderChart(data) {
    const sorted = [...(data||[])].sort((a, b) => dayjs(a.daily).valueOf() - dayjs(b.daily).valueOf());
    const labels = sorted.map(d => dayjs(d.daily).format("MMM D"));
    const datasets = ["sessions", "activeUsers", "newUsers", "conversions"].map(m => ({
        label: m, data: sorted.map(d => d[m]),
        borderColor: GA4_COLORS[m], backgroundColor: `${GA4_COLORS[m]}1A`,
        borderWidth: 2, tension: 0.3, fill: true,
        yAxisID: `y${m.charAt(0).toUpperCase() + m.slice(1)}`,
        hidden: !activeMetrics[m]
    }));

    if (mainChart) mainChart.destroy();
    mainChart = new Chart(document.getElementById("mainChart").getContext("2d"), {
        type: "line", data: {labels, datasets}, options: CHART_CONFIG
    });
}

function toggleMetric(metric) {
    activeMetrics[metric] = !activeMetrics[metric];
    const card = document.querySelector(`[data-metric="${metric}"]`);
    if (activeMetrics[metric]) {
        card.classList.add("active");
        card.style.borderBottomColor = card.style.getPropertyValue("--color");
        card.style.opacity = "1";
    } else {
        card.classList.remove("active");
        card.style.borderBottomColor = "transparent";
        card.style.opacity = "0.5";
    }

    if (mainChart) {
        const ds = mainChart.data.datasets.find(d => d.label === metric);
        if (ds) ds.hidden = !activeMetrics[metric];
        const s = mainChart.options.scales[`y${metric.charAt(0).toUpperCase() + metric.slice(1)}`];
        if (s) s.display = activeMetrics[metric];
        mainChart.update();
    }
}
