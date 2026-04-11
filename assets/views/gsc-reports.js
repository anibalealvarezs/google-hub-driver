let mainChart = null;
let activeMetrics = {
  clicks: true,
  impressions: true,
  ctr: false,
  position: false,
};

const GSC_COLORS = {
  clicks: "#4285f4",
  impressions: "#7e57c2",
  ctr: "#0097a7",
  position: "#f4511e",
};

let currentTabData = [];
let currentSort = { key: "clicks", direction: "desc" };

const CHART_CONFIG = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: "index", intersect: false },
  scales: {
    yClicks: {
      type: "linear",
      display: true,
      position: "left",
      grid: { color: "rgba(255,255,255,0.05)" },
      ticks: { color: GSC_COLORS.clicks },
    },
    yImpressions: {
      type: "linear",
      display: false,
      position: "left",
      grid: { display: false },
      ticks: { color: GSC_COLORS.impressions },
    },
    yPct: {
      type: "linear",
      display: true,
      position: "right",
      grid: { display: false },
      min: 0,
      ticks: { color: GSC_COLORS.ctr, callback: (v) => v + "%" },
    },
    yPos: {
      type: "linear",
      display: false,
      position: "right",
      grid: { display: false },
      reverse: true,
      min: 1,
      ticks: { color: GSC_COLORS.position },
    },
    x: { grid: { display: false }, ticks: { color: "#8b949e" } },
  },
  plugins: {
    legend: { display: false },
  },
};

async function flushCache() {
  const btn = document.querySelector('[onclick="flushCache()"]');
  const originalIcon = btn.innerHTML;
  btn.innerHTML =
    '<i data-lucide="loader-2" class="animate-spin" style="width: 18px; height: 18px;"></i>';
  lucide.createIcons();

  try {
    const auth = localStorage.getItem("apis_hub_admin_auth");
    const headers = {
      "Content-Type": "application/json",
      ...(auth ? { Authorization: "Bearer " + JSON.parse(auth).token } : {}),
    };

    await fetch("/api/config-manager/flush-cache", {
      method: "POST",
      headers: headers,
      body: JSON.stringify({ channel: "google_search_console" }),
    });

    // Show success briefly
    btn.innerHTML =
      '<i data-lucide="check" style="width: 18px; height: 18px; color: #4ade80;"></i>';
    lucide.createIcons();

    setTimeout(() => {
      btn.innerHTML = originalIcon;
      lucide.createIcons();
      loadReport(); // Reload data
    }, 1000);
  } catch (e) {
    console.error("Flush Cache Error:", e);
    btn.innerHTML = originalIcon;
    lucide.createIcons();
  }
}

document.addEventListener("DOMContentLoaded", () => {
  initPropertySelector();
  initDateRange();
  loadReport();
  lucide.createIcons();
});

function initPropertySelector() {
  const sel = document.getElementById("propertySelector");
  // Ensure we have a token for local API calls if in demo
  const auth = localStorage.getItem("apis_hub_admin_auth");
  const headers = auth
    ? { Authorization: "Bearer " + JSON.parse(auth).token }
    : {};

  // Use /google_search_console/page to list sites linked to GSC
  fetch("/google_search_console/page", { headers })
    .then((res) => res.json())
    .then((res) => {
      if (res.data && res.data.length > 0) {
        sel.innerHTML = "";
        res.data.forEach((p) => {
          const opt = document.createElement("option");
          opt.value = p.id;
          opt.textContent = p.url
            .replace("https://", "")
            .replace("http://", "")
            .replace(/\/$/, "");
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
    dateFormat: "Y-m-d", // flatpickr format (PHP-style)
    defaultDate: [start.format("YYYY-MM-DD"), end.format("YYYY-MM-DD")],
    onChange: (selectedDates) => {
      if (selectedDates.length === 2) loadReport();
    },
  });
}

async function loadReport() {
  const loader = document.getElementById("loader");
  if (loader) loader.style.display = "flex";

  try {
    const pageEl = document.getElementById("propertySelector");
    const rangeEl = document.getElementById("reportRange");

    if (!pageEl || !rangeEl) return;

    const pageId = pageEl.value;
    const range = rangeEl.value.split(" to ");
    if (range.length < 2) return;

    const [start, end] = range;

    // 1. Fetch Summary Totals (Metric aggregate)
    const summary = await fetchAggregation(
      ["clicks", "impressions", "ctr", "position"],
      [],
      { page: pageId },
      start,
      end,
    );

    // Fetch Previous Period for Comparison
    const [prevStart, prevEnd] = calculatePreviousPeriod(start, end);
    const prevSummary = await fetchAggregation(
      ["clicks", "impressions", "ctr", "position"],
      [],
      { page: pageId },
      prevStart,
      prevEnd,
    );

    updateSummaryCards(summary[0] || {}, prevSummary[0] || {});

    // 2. Fetch Chart Data (Daily)
    const dailyData = await fetchAggregation(
      ["clicks", "impressions", "ctr", "position"],
      ["daily"],
      { page: pageId },
      start,
      end,
    );
    renderChart(dailyData);

    // 3. Fetch Tab Data (Queries by default)
    // Initial load: prefer data-tab over textContent
    const activeTabEl = document.querySelector(".tab-gsc.active");
    const activeTab = activeTabEl
      ? activeTabEl.getAttribute("data-tab") || "queries"
      : "queries";
    loadTabContent(activeTab);
  } catch (e) {
    console.error("GSC Load Error:", e);
  } finally {
    if (loader) loader.style.display = "none";
    lucide.createIcons();
  }
}

async function fetchAggregation(metrics, groupBy, filters, start, end) {
  const auth = localStorage.getItem("apis_hub_admin_auth");
  const headers = {
    "Content-Type": "application/json",
    ...(auth ? { Authorization: "Bearer " + JSON.parse(auth).token } : {}),
  };

  const body = {
    aggregations: {},
    groupBy: groupBy,
    filters: { ...filters, channel: 8 },
    startDate: start,
    endDate: end,
  };

  metrics.forEach((m) => (body.aggregations[m] = m));

  // Call /google_search_console/metric/aggregate
  const res = await fetch("/google_search_console/metric/aggregate", {
    method: "POST",
    headers: headers,
    body: JSON.stringify(body),
  });
  const data = await res.json();
  return data.data || [];
}

// Fix for object key ordering if needed
function json_encode_fix(obj) {
  return JSON.stringify(obj);
}

function updateSummaryCards(data, prevData) {
  const metrics = [
    { id: 'clicks', val: parseInt(data.clicks || 0), prev: parseInt(prevData?.clicks || 0), type: 'num' },
    { id: 'impressions', val: parseInt(data.impressions || 0), prev: parseInt(prevData?.impressions || 0), type: 'num' },
    { id: 'ctr', val: parseFloat(data.ctr || 0), prev: parseFloat(prevData?.ctr || 0), type: 'pct' },
    { id: 'position', val: parseFloat(data.position || 0), prev: parseFloat(prevData?.position || 0), type: 'pos' }
  ];

  metrics.forEach(m => {
    const el = document.getElementById(`val-${m.id}`);
    if (el) {
      if (m.type === 'num') el.textContent = m.val.toLocaleString();
      else if (m.type === 'pct') el.textContent = (m.val * 100).toFixed(2) + '%';
      else el.textContent = m.val.toFixed(1);
    }

    const trendEl = document.getElementById(`trend-${m.id}`);
    if (trendEl) {
      if (!prevData || Object.keys(prevData).length === 0) {
        trendEl.textContent = '--';
        trendEl.className = 'card-metric-trend';
        return;
      }

      let diff = 0;
      let pct = 0;
      let isPositive = false;

      if (m.type === 'pos') {
        // For position, lower is better
        diff = m.prev - m.val;
        isPositive = diff > 0;
        pct = m.prev > 0 ? (diff / m.prev) * 100 : 0;
      } else {
        diff = m.val - m.prev;
        isPositive = diff > 0;
        pct = m.prev > 0 ? (diff / m.prev) * 100 : 0;
      }

      const icon = isPositive ? 'arrow-up' : 'arrow-down';
      const color = isPositive ? '#22c55e' : '#ef4444';
      const sign = diff > 0 ? '+' : '';

      trendEl.style.color = color;
      trendEl.innerHTML = `<i data-lucide="${icon}" style="width:12px; height:12px; vertical-align:middle;"></i> ${sign}${pct.toFixed(1)}%`;
    }
  });

  lucide.createIcons();
}

function calculatePreviousPeriod(start, end) {
  const s = dayjs(start);
  const e = dayjs(end);
  const diff = e.diff(s, 'day') + 1;
  
  const prevEnd = s.subtract(1, 'day');
  const prevStart = prevEnd.subtract(diff - 1, 'day');
  
  return [prevStart.format('YYYY-MM-DD'), prevEnd.format('YYYY-MM-DD')];
}

function renderChart(data) {
  const ctx = document.getElementById("mainChart").getContext("2d");
  const labels = data.map((d) => dayjs(d.daily).format("MMM D"));

  const datasets = [
    {
      label: "Clicks",
      data: data.map((d) => d.clicks),
      borderColor: GSC_COLORS.clicks,
      backgroundColor: "rgba(66, 133, 244, 0.1)",
      borderWidth: 2,
      tension: 0.3,
      fill: true,
      yAxisID: "yClicks",
      hidden: !activeMetrics.clicks,
    },
    {
      label: "Impressions",
      data: data.map((d) => d.impressions),
      borderColor: GSC_COLORS.impressions,
      backgroundColor: "rgba(126, 87, 194, 0.1)",
      borderWidth: 2,
      tension: 0.3,
      fill: true,
      yAxisID: "yImpressions",
      hidden: !activeMetrics.impressions,
    },
        {
            label: 'CTR',
            data: data.map(d => (parseFloat(d.ctr || 0) * 100).toFixed(2)),
            borderColor: GSC_COLORS.ctr,
            borderWidth: 2,
            tension: 0.3,
            yAxisID: 'yPct',
            hidden: !activeMetrics.ctr
        },
    {
      label: "Position",
      data: data.map((d) => d.position),
      borderColor: GSC_COLORS.position,
      borderWidth: 2,
      tension: 0.3,
      yAxisID: "yPos",
      hidden: !activeMetrics.position,
    },
  ];

  if (mainChart) mainChart.destroy();

  mainChart = new Chart(ctx, {
    type: "line",
    data: { labels, datasets },
    options: CHART_CONFIG,
  });

  updateChartAxesVisibility();
}

function toggleMetric(metric) {
  activeMetrics[metric] = !activeMetrics[metric];

  // Update Card UI
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

  // Update Chart
  if (mainChart) {
    const ds = mainChart.data.datasets.find(
      (d) => d.label.toLowerCase() === metric,
    );
    if (ds) ds.hidden = !activeMetrics[metric];
    updateChartAxesVisibility();
    mainChart.update();
  }
}

function updateChartAxesVisibility() {
  if (!mainChart) return;
  const scales = mainChart.options.scales;
  scales.yClicks.display = activeMetrics.clicks;
  scales.yImpressions.display = activeMetrics.impressions;
  scales.yPct.display = activeMetrics.ctr;
  scales.yPos.display = activeMetrics.position;
}

async function loadTabContent(tab) {
  const propertyId = document.getElementById("propertySelector").value;
  const rangeEl = document.getElementById("reportRange");
  if (!rangeEl) return;

  const range = rangeEl.value.split(" to ");
  const [start, end] = range;

  // Clean input
  const t = (tab || "queries").trim().toLowerCase();

  const tabConfigs = {
    queries: { groupBy: ["dimensions.query"], label: "Search Query" },
    pages: { groupBy: ["dimensions.page"], label: "Page URL" },
    countries: { groupBy: ["country"], label: "Country" },
    devices: { groupBy: ["device"], label: "Device" },
    appearances: {
      groupBy: ["dimensions.search_appearance"],
      label: "Search Appearance",
    },
  };

  const config = tabConfigs[t] || tabConfigs["queries"];
  const groupBy = config.groupBy;
  const label = config.label;

  const labelEl = document.getElementById("dim-header-label");
  if (labelEl) labelEl.textContent = label;

  // --- NEW: Clear table and show immediate loader ---
  const tbody = document.getElementById("breakdown-body");
  if (tbody) {
    tbody.innerHTML = `
            <tr>
                <td colspan="5" style="text-align:center; padding: 4rem;">
                    <div style="display:flex; flex-direction:column; align-items:center; color:var(--text-dim);">
                        <i data-lucide="loader-2" class="animate-spin" style="width: 32px; height: 32px; margin-bottom: 1rem;"></i>
                        <span>Loading ${label}...</span>
                    </div>
                </td>
            </tr>
        `;
    if (window.lucide) lucide.createIcons();
  }

  try {
    const rows = await fetchAggregation(
      ["clicks", "impressions", "ctr", "position"],
      groupBy,
      { page: propertyId },
      start,
      end,
    );
    currentTabData = rows;
    currentDimKey = groupBy[0];

    // Initial sort by clicks desc
    applySortAndRender();
  } catch (e) {
    console.error("Tab Load Error:", e);
    if (tbody)
      tbody.innerHTML =
        '<tr><td colspan="5" style="text-align:center; padding: 2rem; color: #f87171;">Error loading data</td></tr>';
  }
}

let currentDimKey = "";

function sortTable(key) {
  if (currentSort.key === key) {
    currentSort.direction = currentSort.direction === "asc" ? "desc" : "asc";
  } else {
    currentSort.key = key;
    currentSort.direction = "desc";
  }
  applySortAndRender();
}

function applySortAndRender() {
  const sorted = [...currentTabData].sort((a, b) => {
    const valA = parseFloat(a[currentSort.key] || 0);
    const valB = parseFloat(b[currentSort.key] || 0);
    return currentSort.direction === "asc" ? valA - valB : valB - valA;
  });
  renderTable(sorted, currentDimKey);
}

function renderTable(data, dimKey) {
  const tbody = document.getElementById("breakdown-body");
  if (!tbody) return;
  tbody.innerHTML = "";

  // Update header icons for sorting
  document
    .querySelectorAll("th[onclick] .sort-icon-wrapper")
    .forEach((wrapper) => {
      const th = wrapper.closest("th");
      if (!th) return;
      const keyMatch = th.getAttribute("onclick").match(/'([^']+)'/);
      if (!keyMatch) return;
      const key = keyMatch[1];

      let iconHtml = "";
      if (key === currentSort.key) {
        const iconName =
          currentSort.direction === "asc" ? "arrow-up" : "arrow-down";
        iconHtml = `<i data-lucide="${iconName}" class="sort-icon" style="width:14px; height:14px; color: #fff; opacity: 1;"></i>`;
      } else {
        iconHtml = `<i data-lucide="chevrons-up-down" class="sort-icon" style="width:14px; height:14px; color: rgba(255,255,255,0.2); opacity: 0.5;"></i>`;
      }
      wrapper.innerHTML = iconHtml;
    });

  if (!data || data.length === 0) {
    tbody.innerHTML =
      '<tr><td colspan="5" style="text-align:center; padding: 2rem; color: var(--text-dim);">No breakdown data available for this dimension.</td></tr>';
    return;
  }

  // Pre-calculate max values for bars
  const maxClicks = Math.max(...data.map((d) => d.clicks || 0), 1);
  const maxImps = Math.max(...data.map((d) => d.impressions || 0), 1);

  data.forEach((row) => {
    const dimValue = row[dimKey] || "Unknown";
    const tr = document.createElement("tr");

    const tabConfigs = {
      queries: { groupBy: ["dimensions.query"], label: "Search Query" },
      pages: { groupBy: ["dimensions.page"], label: "Page URL" },
      countries: { groupBy: ["country"], label: "Country" },
      devices: { groupBy: ["device"], label: "Device" },
      appearances: {
        groupBy: ["dimensions.search_appearance"],
        label: "Search Appearance",
      },
    };
    const configKey = Object.keys(tabConfigs).find(
      (k) => tabConfigs[k].groupBy[0] === dimKey
    );

    let dimContent = '';
    if (configKey === "pages") {
        dimContent = `
            <div class="gsc-url-container" style="max-width: 100%; width: 100%;">
                <div class="gsc-url-text" title="${dimValue}">${dimValue}</div>
                <a href="${dimValue}" target="_blank" class="gsc-external-link" style="margin-left: 4px;">
                    <i data-lucide="external-link" style="width: 14px; height: 14px;"></i>
                </a>
            </div>
        `;
    } else {
        dimContent = `<div class="gsc-url-text">${dimValue}</div>`;
    }

    const clickPct = ((row.clicks || 0) / maxClicks) * 100;
    const impPct = ((row.impressions || 0) / maxImps) * 100;

    tr.innerHTML = `
        <td>${dimContent}</td>
        <td class="metric-cell">
            <div class="metric-val-main">${parseInt(
              row.clicks || 0
            ).toLocaleString()}</div>
            <div class="progress-bar-container"><div class="progress-bar-fill" style="width: ${clickPct}%; background: ${
      GSC_COLORS.clicks
    };"></div></div>
        </td>
        <td class="metric-cell">
            <div class="metric-val-main">${parseInt(
              row.impressions || 0
            ).toLocaleString()}</div>
            <div class="progress-bar-container"><div class="progress-bar-fill" style="width: ${impPct}%; background: ${
      GSC_COLORS.impressions
    };"></div></div>
        </td>
        <td class="metric-cell metric-val-main">${(parseFloat(row.ctr || 0) * 100).toFixed(2)}%</td>
        <td class="metric-cell metric-val-main">${parseFloat(
          row.position || 0
        ).toFixed(1)}</td>
    `;
    tbody.appendChild(tr);
  });

  // FINAL RENDER OF ICONS
  if (window.lucide) {
    lucide.createIcons();
  }
}

function switchTab(el, tab) {
  document
    .querySelectorAll(".tab-gsc")
    .forEach((t) => t.classList.remove("active"));
  el.classList.add("active");
  loadTabContent(tab);
}
