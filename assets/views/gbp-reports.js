let mainChart = null;
let activeMetrics = {
    impressions: true,
    website_clicks: true,
    calls: true,
    directions: true,
    conversations: false,
    bookings: false,
    food_orders: false,
    menu_clicks: false,
};

const GBP_COLORS = {
    impressions: "#4285F4",
    website_clicks: "#7E57C2",
    calls: "#0097A7",
    directions: "#F4511E",
    conversations: "#0F9D58",
    bookings: "#F9AB00",
    food_orders: "#AB47BC",
    menu_clicks: "#26A69A",
};

const GBP_METRIC_ORDER = [
    "impressions",
    "website_clicks",
    "calls",
    "directions",
    "conversations",
    "bookings",
    "food_orders",
    "menu_clicks",
];

let currentSort = {key: "impressions", direction: "desc"};
let reportRequestSeq = 0;
let activeReportController = null;

const CHART_CONFIG = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {mode: "index", intersect: false},
    scales: {
        yLeft: {
            type: "linear",
            display: true,
            position: "left",
            grid: {color: "rgba(255,255,255,0.05)"},
            ticks: {color: "#8B949E"},
            beginAtZero: true,
        },
        x: {grid: {display: false}, ticks: {color: "#8B949E"}},
    },
    plugins: {
        legend: {display: false},
    },
};

document.addEventListener("DOMContentLoaded", () => {
    initLocationSelector();
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

function initLocationSelector() {
    const sel = document.getElementById("locationSelector");
    const headers = getAuthHeaders();

    fetch("/google_business_profile/location", {headers})
        .then((res) => res.json())
        .then((res) => {
            if (res.data && res.data.length > 0) {
                sel.innerHTML = "";
                res.data.forEach((loc) => {
                    const opt = document.createElement("option");
                    opt.value = loc.id;
                    opt.textContent = loc.title || loc.platformId || "Unknown";
                    sel.appendChild(opt);
                });
                loadReport();
            } else {
                sel.innerHTML = '<option value="">No locations found</option>';
            }
        })
        .catch(() => {
            sel.innerHTML = '<option value="">Error loading locations</option>';
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
    try { controller.abort(); } catch (e) { /* ignore */ }
}

function isAbortError(error) {
    return error?.name === "AbortError";
}

function isLatestReportRequest(requestId) {
    return requestId === reportRequestSeq;
}

function setSectionState(section, {loading = false, message = "", error = ""} = {}) {
    const sectionMap = {
        summary: {containerId: "summaryGrid", statusId: "summaryStatus"},
        chart: {containerId: "chartSection", statusId: "chartStatus"},
    };

    const config = sectionMap[section];
    if (!config) return;

    const container = document.getElementById(config.containerId);
    const statusEl = document.getElementById(config.statusId);
    if (container) {
        container.classList.toggle("is-loading", loading);
    }

    if (!statusEl) return;

    const statusMessage = error || message;
    if (!statusMessage) {
        statusEl.classList.remove("is-visible", "is-error");
        statusEl.innerHTML = "";
        return;
    }

    statusEl.classList.add("is-visible");
    statusEl.classList.toggle("is-error", Boolean(error));
    statusEl.innerHTML = error
        ? `<i data-lucide="alert-triangle" style="width: 14px; height: 14px;"></i><span>${error}</span>`
        : `<i data-lucide="loader-2" class="animate-spin" style="width: 14px; height: 14px;"></i><span>${message}</span>`;

    if (window.lucide) {
        lucide.createIcons();
    }
}

async function flushCache() {
    const btn = document.querySelector('[onclick="flushCache()"]');
    const originalIcon = btn.innerHTML;
    btn.innerHTML = '<i data-lucide="loader-2" class="animate-spin" style="width: 18px; height: 18px;"></i>';
    lucide.createIcons();

    try {
        const auth = localStorage.getItem("apis_hub_admin_auth");
        const headers = {
            "Content-Type": "application/json",
            ...(auth ? {Authorization: "Bearer " + JSON.parse(auth).token} : {}),
        };

        const response = await fetch("/api/config-manager/flush-cache", {
            method: "POST",
            headers: headers,
            body: JSON.stringify({channel: "google_business_profile"}),
        });

        let payload;
        try { payload = await response.json(); } catch (parseError) { payload = null; }

        if (!response.ok || payload?.success !== true) {
            throw new Error(payload?.error || payload?.message || `Flush cache failed with status ${response.status}`);
        }

        btn.innerHTML = '<i data-lucide="check" style="width: 18px; height: 18px; color: #4ade80;"></i>';
        lucide.createIcons();

        setTimeout(() => {
            btn.innerHTML = originalIcon;
            lucide.createIcons();
            loadReport();
        }, 1000);
    } catch (e) {
        console.error("Flush Cache Error:", e);
        btn.innerHTML = originalIcon;
        lucide.createIcons();
    }
}

async function loadReport() {
    const loader = document.getElementById("loader");
    if (loader) loader.style.display = "none";

    const locEl = document.getElementById("locationSelector");
    const rangeEl = document.getElementById("reportRange");
    if (!locEl || !rangeEl) return;

    const locationId = locEl.value;
    const range = rangeEl.value.split(" to ");
    if (!locationId || range.length < 2) return;

    const [start, end] = range;
    const [prevStart, prevEnd] = calculatePreviousPeriod(start, end);
    const requestId = ++reportRequestSeq;

    abortControllerSafely(activeReportController);
    activeReportController = new AbortController();
    const signal = activeReportController.signal;

    setSectionState("summary", {loading: true, message: "Updating summary cards..."});
    setSectionState("chart", {loading: true, message: "Updating chart..."});

    const summaryState = {
        current: null,
        previous: null,
        pending: 2,
        hasError: false,
    };

    const finalizeSummaryState = () => {
        if (!isLatestReportRequest(requestId)) return;
        summaryState.pending -= 1;
        if (summaryState.pending <= 0 && !summaryState.hasError) {
            setSectionState("summary", {});
        }
    };

    const metrics = GBP_METRIC_ORDER;

    const summaryPromise = fetchAggregation(
        metrics,
        [],
        {location: locationId},
        start,
        end,
        {signal},
    )
        .then((summary) => {
            if (!isLatestReportRequest(requestId)) return;
            summaryState.current = summary[0] || {};
            updateSummaryCards(summaryState.current, summaryState.previous);
        })
        .catch((e) => {
            if (isAbortError(e) || !isLatestReportRequest(requestId)) return;
            summaryState.hasError = true;
            console.error("GBP Summary Load Error:", e);
            setSectionState("summary", {error: "Unable to refresh summary cards."});
        })
        .finally(finalizeSummaryState);

    const previousSummaryPromise = fetchAggregation(
        metrics,
        [],
        {location: locationId},
        prevStart,
        prevEnd,
        {signal},
    )
        .then((summary) => {
            if (!isLatestReportRequest(requestId)) return;
            summaryState.previous = summary[0] || {};
            if (summaryState.current) {
                updateSummaryCards(summaryState.current, summaryState.previous);
            }
        })
        .catch((e) => {
            if (isAbortError(e) || !isLatestReportRequest(requestId)) return;
            summaryState.hasError = true;
            console.error("GBP Previous Summary Load Error:", e);
            setSectionState("summary", {error: "Unable to refresh period comparison."});
        })
        .finally(finalizeSummaryState);

    const chartPromise = fetchAggregation(
        metrics,
        ["daily"],
        {location: locationId},
        start,
        end,
        {signal},
    )
        .then((dailyData) => {
            if (!isLatestReportRequest(requestId)) return;
            renderChart(dailyData);
            setSectionState("chart", {});
        })
        .catch((e) => {
            if (isAbortError(e) || !isLatestReportRequest(requestId)) return;
            console.error("GBP Chart Load Error:", e);
            setSectionState("chart", {error: "Unable to refresh chart."});
        });

    await Promise.allSettled([
        summaryPromise,
        previousSummaryPromise,
        chartPromise,
    ]);

    if (isLatestReportRequest(requestId)) {
        lucide.createIcons();
    }
}

async function fetchAggregation(metrics, groupBy, filters, start, end, options = {}) {
    const headers = getAuthHeaders(true);
    const cleanFilters = {...filters};

    if (cleanFilters.location === "" || cleanFilters.location == null) {
        delete cleanFilters.location;
    }
    delete cleanFilters.channel;

    const body = {
        aggregations: {},
        groupBy: groupBy,
        filters: cleanFilters,
        startDate: start,
        endDate: end,
    };

    metrics.forEach((m) => (body.aggregations[m] = m));

    const res = await fetch("/google_business_profile/metric/aggregate", {
        method: "POST",
        headers: headers,
        body: JSON.stringify(body),
        signal: options.signal,
    });

    const data = await res.json();
    if (!res.ok || (data.status && data.status !== "success")) {
        throw new Error(data.message || `Aggregation request failed with status ${res.status}`);
    }

    return data.data || [];
}

function updateSummaryCards(data, prevData) {
    const metricConfigs = [
        {id: "impressions", val: parseInt(data.impressions || 0), prev: parseInt(prevData?.impressions || 0), type: "num"},
        {id: "website_clicks", val: parseInt(data.website_clicks || 0), prev: parseInt(prevData?.website_clicks || 0), type: "num"},
        {id: "calls", val: parseInt(data.calls || 0), prev: parseInt(prevData?.calls || 0), type: "num"},
        {id: "directions", val: parseInt(data.directions || 0), prev: parseInt(prevData?.directions || 0), type: "num"},
        {id: "conversations", val: parseInt(data.conversations || 0), prev: parseInt(prevData?.conversations || 0), type: "num"},
        {id: "bookings", val: parseInt(data.bookings || 0), prev: parseInt(prevData?.bookings || 0), type: "num"},
        {id: "food_orders", val: parseInt(data.food_orders || 0), prev: parseInt(prevData?.food_orders || 0), type: "num"},
        {id: "menu_clicks", val: parseInt(data.menu_clicks || 0), prev: parseInt(prevData?.menu_clicks || 0), type: "num"},
    ];

    metricConfigs.forEach((m) => {
        const el = document.getElementById(`val-${m.id}`);
        if (el) {
            el.textContent = m.val.toLocaleString();
        }

        const trendEl = document.getElementById(`trend-${m.id}`);
        if (trendEl) {
            if (!prevData || Object.keys(prevData).length === 0) {
                trendEl.textContent = "--";
                trendEl.className = "card-metric-trend";
                return;
            }

            const diff = m.val - m.prev;
            const isPositive = diff > 0;
            const pct = m.prev > 0 ? (diff / m.prev) * 100 : 0;
            const icon = isPositive ? "arrow-up" : "arrow-down";
            const color = isPositive ? "#22C55E" : "#EF4444";
            const sign = diff > 0 ? "+" : "";

            trendEl.style.color = color;
            trendEl.innerHTML = `<i data-lucide="${icon}" style="width:12px; height:12px; vertical-align:middle;"></i> ${sign}${pct.toFixed(1)}%`;
        }
    });

    lucide.createIcons();
}

function calculatePreviousPeriod(start, end) {
    const s = dayjs(start);
    const e = dayjs(end);
    const diff = e.diff(s, "day") + 1;

    const prevEnd = s.subtract(1, "day");
    const prevStart = prevEnd.subtract(diff - 1, "day");

    return [prevStart.format("YYYY-MM-DD"), prevEnd.format("YYYY-MM-DD")];
}

function renderChart(data) {
    const sortedData = [...(data || [])].sort((left, right) => {
        const leftDate = dayjs(left?.daily);
        const rightDate = dayjs(right?.daily);

        if (!leftDate.isValid() && !rightDate.isValid()) return 0;
        if (!leftDate.isValid()) return 1;
        if (!rightDate.isValid()) return -1;

        return leftDate.valueOf() - rightDate.valueOf();
    });

    const ctx = document.getElementById("mainChart").getContext("2d");
    const labels = sortedData.map((d) => dayjs(d.daily).format("MMM D"));

    const visibleMetrics = GBP_METRIC_ORDER.filter((m) => activeMetrics[m]);
    const datasets = visibleMetrics.map((metric) => ({
        label: formatMetricLabel(metric),
        data: sortedData.map((d) => parseFloat(d[metric] || 0)),
        borderColor: GBP_COLORS[metric],
        backgroundColor: hexToRgba(GBP_COLORS[metric], 0.1),
        borderWidth: 2,
        tension: 0.3,
        fill: true,
        yAxisID: "yLeft",
    }));

    if (mainChart) mainChart.destroy();

    mainChart = new Chart(ctx, {
        type: "line",
        data: {labels, datasets},
        options: CHART_CONFIG,
    });
}

function formatMetricLabel(metric) {
    return metric
        .split("_")
        .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
        .join(" ");
}

function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
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
        loadReport();
    }
}
