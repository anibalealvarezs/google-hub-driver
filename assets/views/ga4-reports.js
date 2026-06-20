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
};

let currentTrafficData = [];
let currentEventData = [];
let currentAudienceData = [];

let currentSort = {
    traffic: {key: "sessions", direction: "desc"},
    event: {key: "conversions", direction: "desc"},
    audience: {key: "sessions", direction: "desc"}
};

let reportRequestSeq = 0;
let tableRequestSeq = {traffic: 0, event: 0, audience: 0};

let activeReportController = null;
let activeControllers = {traffic: null, event: null, audience: null};

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
        traffic: {containerId: "trafficSection", statusId: "trafficStatus"},
        event: {containerId: "eventSection", statusId: "eventStatus"},
        audience: {containerId: "audienceSection", statusId: "audienceStatus"},
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

    // Load independent sections
    const activeTrafficTab = document.querySelector(".traffic-tab.active")?.getAttribute("data-tab") || "channels";
    const activeAudienceTab = document.querySelector(".audience-tab.active")?.getAttribute("data-tab") || "countries";
    
    loadTrafficSection(activeTrafficTab, {propertyId, start, end});
    loadEventSection({propertyId, start, end});
    loadAudienceSection(activeAudienceTab, {propertyId, start, end});

    const metricList = ["sessions", "activeUsers", "newUsers", "conversions"];

    Promise.all([
        fetchAggregation(metricList, [], {channeledAccount: propertyId}, start, end, {signal}).catch(() => []),
        fetchAggregation(metricList, [], {channeledAccount: propertyId}, prevStart, prevEnd, {signal}).catch(() => []),
        fetchAggregation(metricList, ["daily"], {channeledAccount: propertyId}, start, end, {signal}).catch(() => [])
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

/* -------------------------------------------------------------------------- */
/*                                SECTIONS                                    */
/* -------------------------------------------------------------------------- */

async function loadTrafficSection(tab, options) {
    const tabConfigs = {
        channels: {groupBy: ["dimensions.sessionDefaultChannelGroup"], label: "Channel"},
        campaigns: {groupBy: ["channeledCampaign"], label: "Campaign"},
        pages: {groupBy: ["dimensions.landing_page"], label: "Landing Page"},
    };
    
    const config = tabConfigs[tab] || tabConfigs.channels;
    const reqId = ++tableRequestSeq.traffic;
    abortControllerSafely(activeControllers.traffic);
    activeControllers.traffic = new AbortController();

    renderTableHeaders("traffic", config.label, ["sessions", "newUsers", "conversions"]);
    showTableLoader("traffic", config.label);
    setSectionState("traffic", {loading: true, message: `Loading ${config.label}...`});

    try {
        const rows = await fetchAggregation(["sessions", "newUsers", "conversions"], config.groupBy, 
            {channeledAccount: options.propertyId, 'dimensions.scope': 'traffic_matrix'}, 
            options.start, options.end, {signal: activeControllers.traffic.signal});

        if (tableRequestSeq.traffic !== reqId) return;
        currentTrafficData = rows;
        currentSort.traffic = {key: "sessions", direction: "desc"};
        applySortAndRender("traffic", config.groupBy[0], ["sessions", "newUsers", "conversions"]);
        setSectionState("traffic", {});
    } catch (e) {
        if (!isAbortError(e) && tableRequestSeq.traffic === reqId) {
            setSectionState("traffic", {error: "Failed to load."});
            document.getElementById("traffic-body").innerHTML = `<tr><td colspan="4">Error</td></tr>`;
        }
    }
}

async function loadEventSection(options) {
    const reqId = ++tableRequestSeq.event;
    abortControllerSafely(activeControllers.event);
    activeControllers.event = new AbortController();

    const label = "Event Name";
    const groupBy = ["event"];
    renderTableHeaders("event", label, ["conversions"]);
    showTableLoader("event", label);
    setSectionState("event", {loading: true, message: `Loading Events...`});

    try {
        const rows = await fetchAggregation(["conversions"], groupBy, 
            {channeledAccount: options.propertyId, 'dimensions.scope': 'event_matrix'}, 
            options.start, options.end, {signal: activeControllers.event.signal});

        if (tableRequestSeq.event !== reqId) return;
        currentEventData = rows;
        currentSort.event = {key: "conversions", direction: "desc"};
        applySortAndRender("event", groupBy[0], ["conversions"]);
        setSectionState("event", {});
    } catch (e) {
        if (!isAbortError(e) && tableRequestSeq.event === reqId) {
            setSectionState("event", {error: "Failed to load."});
            document.getElementById("event-body").innerHTML = `<tr><td colspan="2">Error</td></tr>`;
        }
    }
}

async function loadAudienceSection(tab, options) {
    const tabConfigs = {
        countries: {groupBy: ["country"], label: "Country"},
        devices: {groupBy: ["device"], label: "Device"},
    };
    
    const config = tabConfigs[tab] || tabConfigs.countries;
    const reqId = ++tableRequestSeq.audience;
    abortControllerSafely(activeControllers.audience);
    activeControllers.audience = new AbortController();

    renderTableHeaders("audience", config.label, ["sessions", "conversions"]);
    showTableLoader("audience", config.label);
    setSectionState("audience", {loading: true, message: `Loading ${config.label}...`});

    try {
        const rows = await fetchAggregation(["sessions", "conversions"], config.groupBy, 
            {channeledAccount: options.propertyId, 'dimensions.scope': 'traffic_matrix'}, 
            options.start, options.end, {signal: activeControllers.audience.signal});

        if (tableRequestSeq.audience !== reqId) return;
        currentAudienceData = rows;
        currentSort.audience = {key: "sessions", direction: "desc"};
        applySortAndRender("audience", config.groupBy[0], ["sessions", "conversions"]);
        setSectionState("audience", {});
    } catch (e) {
        if (!isAbortError(e) && tableRequestSeq.audience === reqId) {
            setSectionState("audience", {error: "Failed to load."});
            document.getElementById("audience-body").innerHTML = `<tr><td colspan="3">Error</td></tr>`;
        }
    }
}

function switchTrafficTab(el, tab) {
    document.querySelectorAll(".traffic-tab").forEach(t => t.classList.remove("active"));
    el.classList.add("active");
    const p = document.getElementById("propertySelector").value;
    const r = document.getElementById("reportRange").value.split(" to ");
    loadTrafficSection(tab, {propertyId: p, start: r[0], end: r[1]});
}

function switchAudienceTab(el, tab) {
    document.querySelectorAll(".audience-tab").forEach(t => t.classList.remove("active"));
    el.classList.add("active");
    const p = document.getElementById("propertySelector").value;
    const r = document.getElementById("reportRange").value.split(" to ");
    loadAudienceSection(tab, {propertyId: p, start: r[0], end: r[1]});
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
    let dataMap = {traffic: currentTrafficData, event: currentEventData, audience: currentAudienceData};
    let data = dataMap[section] || [];
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
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding: 4rem;"><i data-lucide="loader-2" class="animate-spin" style="width: 32px; height: 32px; margin-bottom: 1rem; color: var(--text-dim);"></i><br><span style="color: var(--text-dim);">Loading ${label}...</span></td></tr>`;
        if (window.lucide) lucide.createIcons();
    }
}

function renderTableBody(section, data, dimKey, cols) {
    const tbody = document.getElementById(`${section}-body`);
    if (!tbody) return;
    tbody.innerHTML = "";

    if (!data || data.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding: 2rem; color: var(--text-dim);">No data available.</td></tr>`;
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
