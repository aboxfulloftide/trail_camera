const app = document.querySelector(".app");
const API = app.dataset.apiBase;
const VIEW = app.dataset.view || "trail";

const state = {
  settings: {},
  schema: [],
  busy: new Set(),
  onboardStatus: null,
  onboardMedia: [],
  onboardServerMedia: [],
  selectedOnboardServerFilenames: new Set(),
  cameraActivity: {
    label: "Idle",
    message: "No camera action running.",
    detail: "Ready",
    last: "Last result: none",
    startedAt: null,
    timer: null,
  },
  cameraMonitorTimer: null,
  liveFrameTimer: null,
  liveHealthTimer: null,
  onboardPreviewUrl: null,
  pageRefreshTimer: null,
  mediaJobPolling: false,
  selectedMediaIds: new Set(),
  selectedOnboardIds: new Set(),
};

const $ = (id) => document.getElementById(id);

function stamp() {
  return new Date().toLocaleTimeString();
}

function log(message, details) {
  const line = `[${stamp()}] ${message}`;
  const output = $("logOutput");
  const suffix = details ? `\n${JSON.stringify(details, null, 2)}` : "";
  output.textContent = `${line}${suffix}\n${output.textContent}`.slice(0, 12000);
}

function updateCameraActivity(next) {
  state.cameraActivity = { ...state.cameraActivity, ...next };
  $("cameraActivityState").textContent = state.cameraActivity.label || "Idle";
  $("cameraActivityMessage").textContent = state.cameraActivity.message || "No camera action running.";
  $("cameraActivityDetail").textContent = state.cameraActivity.detail || "Ready";
  $("cameraActivityLast").textContent = state.cameraActivity.last || "Last result: none";
  if (state.cameraActivity.startedAt) {
    const elapsedMs = Date.now() - state.cameraActivity.startedAt;
    $("cameraActivityTimer").textContent = `${(elapsedMs / 1000).toFixed(elapsedMs >= 10000 ? 0 : 1)}s`;
  } else {
    $("cameraActivityTimer").textContent = "0.0s";
  }
}

function progressText(progress) {
  if (!progress || typeof progress !== "object") return null;
  const processed = Number(progress.processed || 0);
  const total = progress.total === null || progress.total === undefined ? null : Number(progress.total);
  const stage = progress.stage ? String(progress.stage).replace(/_/g, " ") : null;
  const counts = total ? `${processed}/${total}` : `${processed}`;
  return stage ? `${stage} ${counts}` : counts;
}

function durableMediaProgressText(job) {
  // Firmware 0.2.9 exposes durable-job counters at the top level, unlike
  // the older server-side jobs which use a nested `progress` object.
  const progress = job?.progress || job?.result?.progress || job || {};
  const total = Number(progress.total || 0);
  const downloaded = Number(progress.downloaded || 0);
  const skipped = Number(progress.skipped || 0);
  const deleted = Number(progress.deleted || 0);
  const stage = progress.stage ? String(progress.stage).replace(/_/g, " ") : "";
  if (total > 0) {
    let message = `${downloaded} of ${total} downloaded`;
    if (skipped > 0) message += `, ${skipped} already saved`;
    if (deleted > 0) message += `, ${deleted} deleted`;
    return message;
  }
  return stage || null;
}

function startCameraActivity(label, message = null) {
  const startedAt = Date.now();
  updateCameraActivity({
    label,
    message: message || `${label} in progress...`,
    detail: "Waiting for camera response",
    startedAt,
    last: `Started ${label.toLowerCase()}`,
  });
  if (state.cameraActivity.timer) {
    clearInterval(state.cameraActivity.timer);
  }
  state.cameraActivity.timer = setInterval(() => {
    if (!state.cameraActivity.startedAt) return;
    const elapsedMs = Date.now() - state.cameraActivity.startedAt;
    $("cameraActivityTimer").textContent = `${(elapsedMs / 1000).toFixed(elapsedMs >= 10000 ? 0 : 1)}s`;
  }, 250);
}

function finishCameraActivity(label, result, error = null) {
  if (state.cameraActivity.timer) {
    clearInterval(state.cameraActivity.timer);
    state.cameraActivity.timer = null;
  }
  updateCameraActivity({
    label: error ? `${label} failed` : `${label} complete`,
    message: error ? `${label} failed` : `${label} complete`,
    detail: error ? (error.message || "Camera action failed") : "Camera responded",
    last: error ? `Last result: ${error.message || "failed"}` : `Last result: ${label} complete`,
    startedAt: null,
  });
  if (!error && result) {
    setTimeout(() => {
      if (!state.cameraActivity.startedAt) {
        updateCameraActivity({
          label: "Idle",
          message: "No camera action running.",
          detail: "Ready",
        });
      }
    }, 5000);
  }
}

function clearCameraMonitor() {
  if (state.cameraMonitorTimer) {
    clearInterval(state.cameraMonitorTimer);
    state.cameraMonitorTimer = null;
  }
}

function clearLiveFrameTimer() {
  if (state.liveFrameTimer) {
    clearInterval(state.liveFrameTimer);
    state.liveFrameTimer = null;
  }
}

function clearLiveHealthTimer() {
  if (state.liveHealthTimer) {
    clearTimeout(state.liveHealthTimer);
    state.liveHealthTimer = null;
  }
}

function clearPageRefreshTimer() {
  if (state.pageRefreshTimer) {
    clearInterval(state.pageRefreshTimer);
    state.pageRefreshTimer = null;
  }
}

function revokeOnboardPreviewUrl() {
  if (state.onboardPreviewUrl) {
    URL.revokeObjectURL(state.onboardPreviewUrl);
    state.onboardPreviewUrl = null;
  }
}

function describeCameraPhase(status, fallback) {
  if (status?.control_error) return `Error: ${String(status.control_error).replace(/_/g, " ")}`;
  if (status?.control_progress_text) return status.control_progress_text;
  const raw = status?.raw || {};
  if (!raw || !Object.keys(raw).length) return fallback;
  if (raw.control_busy) {
    const action = String(raw.control_action || raw.control_last_action || "camera action").replace(/_/g, " ");
    if (action.includes("bringup")) return "Waiting for camera bringup";
    if (action.includes("stream")) return "Waiting for stream start";
    return `Waiting on ${action}`;
  }
  if (raw.stream_active) return "Stream is active";
  if (raw.wifi_connected === false) return "Waiting for WiFi connection";
  return fallback;
}

function updateActivityFromStatus(label, status, fallbackMessage) {
  const detail = status?.control_error
    ? `Error: ${status.control_error}`
    : status?.control_progress_text
    ? status.control_progress_text
    : status?.control_message
    ? status.control_message
    : status?.control_last_message
    ? `Last bridge message: ${status.control_last_message}`
    : fallbackMessage;
  const last = status?.control_error
    ? `Error: ${String(status.control_error).replace(/_/g, " ")}`
    : status?.control_message || status?.control_last_message
    ? `Last result: ${status.control_message || status.control_last_message}`
    : "Last result: pending";
  updateCameraActivity({
    label,
    message: describeCameraPhase(status, fallbackMessage),
    detail,
    last,
  });
}

async function withCameraActivity(label, work, options = {}) {
  const message = options.message || null;
  startCameraActivity(label, message);
  try {
    const result = await work();
    finishCameraActivity(label, result);
    return result;
  } catch (error) {
    finishCameraActivity(label, null, error);
    throw error;
  }
}

function setBusy(key, busy) {
  if (busy) state.busy.add(key);
  else state.busy.delete(key);
  document.querySelectorAll("button").forEach((button) => {
    button.disabled = state.busy.size > 0 && !button.classList.contains("text-button");
  });
}

async function api(path, options = {}) {
  const init = {
    method: options.method || "GET",
    headers: options.body ? { "Content-Type": "application/json" } : {},
    body: options.body ? JSON.stringify(options.body) : undefined,
  };
  const response = await fetch(`${API}${path}`, init);
  const contentType = response.headers.get("content-type") || "";
  const payload = contentType.includes("application/json") ? await response.json() : await response.text();
  if (!response.ok || (payload && payload.ok === false)) {
    const err = payload?.error || { code: "request_failed", message: response.statusText };
    throw Object.assign(new Error(err.message || "Request failed"), { payload, status: response.status });
  }
  return payload && Object.prototype.hasOwnProperty.call(payload, "data") ? payload.data : payload;
}

function boolText(value) {
  if (value === true) return "Yes";
  if (value === false) return "No";
  if (value === null || value === undefined || value === "") return "-";
  return String(value);
}

function sizeText(bytes) {
  const value = Number(bytes || 0);
  if (!value) return "-";
  const units = ["B", "KB", "MB", "GB"];
  let size = value;
  let unit = 0;
  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024;
    unit += 1;
  }
  return `${size.toFixed(unit ? 1 : 0)} ${units[unit]}`;
}

function durationText(ms) {
  const value = Number(ms || 0);
  if (!value) return "-";
  if (value < 1000) return `${value} ms`;
  const seconds = value / 1000;
  if (seconds < 60) return `${seconds.toFixed(seconds >= 10 ? 0 : 1)} s`;
  const minutes = seconds / 60;
  if (minutes < 60) return `${minutes.toFixed(minutes >= 10 ? 0 : 1)} min`;
  const hours = minutes / 60;
  return `${hours.toFixed(hours >= 10 ? 0 : 1)} hr`;
}

function numberText(value, digits = 1) {
  if (value === null || value === undefined || value === "" || Number.isNaN(Number(value))) return "-";
  return Number(value).toFixed(digits);
}

function setPill(ok, text) {
  const pill = $("bridgePill");
  pill.classList.toggle("good", ok === true);
  pill.classList.toggle("bad", ok === false);
  pill.textContent = text;
}

function applyLiveSnapshot(status) {
  if (!status) return;
  const raw = status.raw || {};
  const streamActive = Boolean(status.live_view_active);
  const tunnelConnected = Boolean(raw.tunnel_connected);
  $("streamTitle").textContent = streamActive ? "Stream active" : "Stream inactive";
  $("streamMeta").textContent = tunnelConnected ? "Tunnel connected" : "Tunnel not connected";
  $("liveState").textContent = streamActive ? "Active" : "Stopped";
}

function applyLiveHealth(data) {
  const health = data?.live_health;
  if (!health) return false;
  const packets = health.tunnel_packets_sent === null || health.tunnel_packets_sent === undefined
    ? "-"
    : String(health.tunnel_packets_sent);
  const age = health.udp_primary_last_packet_age_ms === null || health.udp_primary_last_packet_age_ms === undefined
    ? "-"
    : durationText(health.udp_primary_last_packet_age_ms);
  const labels = {
    receiving_live_media: "Live media receiving",
    receiving_degraded_media: "Live media degraded",
    preview_unavailable: "Live preview unavailable",
    recovering: "Recovering live stream",
    camera_stream_local_only: "Camera stream is local only",
    camera_rtp_stalled: "Camera RTP stalled",
    relay_not_moving_media: "Relay is not moving media",
    waiting_for_camera_rtp: "Waiting for camera RTP",
    control_error: "Live start failed",
    board_state_unknown: "Bridge state unknown",
    inactive: "Stream inactive",
  };
  $("streamTitle").textContent = labels[health.state] || "Live stream status unknown";
  const quality = health.rtp_quality_ok === false
    ? ` RTP loss: ${numberText(health.rtp_loss_pct, 1)}%; queue drops: ${health.forward_queue_dropped ?? "-"}; UDP overruns: ${health.udp_receive_overruns ?? "-"}.`
    : "";
  $("streamMeta").textContent = `${health.message || "-"} RTP age: ${age}; tunnel packets: ${packets}.${quality}`;
  return health.media_receiving === true && health.preview_ready !== false;
}

function scheduleLiveHealthPoll(active) {
  clearLiveHealthTimer();
  if (!active) return;
  state.liveHealthTimer = setTimeout(() => refreshLive(), 3000);
}

function syncLivePreview(active) {
  const frame = $("liveFrame");
  const fallback = $("liveFallback");
  if (!frame || !fallback) return;
  clearLiveFrameTimer();
  if (!active) {
    frame.removeAttribute("src");
    frame.hidden = true;
    fallback.hidden = false;
    return;
  }

  const connectFrame = () => {
    const source = `${API}/live/mjpeg?ts=${Date.now()}`;
    frame.onload = () => {
      frame.hidden = false;
      fallback.hidden = true;
    };
    frame.onerror = () => {
      frame.hidden = true;
      fallback.hidden = false;
      $("streamTitle").textContent = "Stream active";
      $("streamMeta").textContent = "Waiting for live relay…";
      clearLiveFrameTimer();
      state.liveFrameTimer = setTimeout(connectFrame, 2000);
    };
    frame.src = source;
  };

  connectFrame();
}

async function refreshTelemetry() {
  try {
    const data = await api("/board/telemetry/latest");
    const telemetry = data.telemetry || null;
    if (!telemetry) {
      $("tempState").textContent = "-";
      $("humidityState").textContent = "-";
      return null;
    }
    if (telemetry.temperature_f !== null && telemetry.temperature_f !== undefined) {
      $("tempState").textContent = `${numberText(telemetry.temperature_f)} F`;
    } else if (telemetry.temperature_c !== null && telemetry.temperature_c !== undefined) {
      $("tempState").textContent = `${numberText(telemetry.temperature_c)} C`;
    } else {
      $("tempState").textContent = "-";
    }
    $("humidityState").textContent = telemetry.humidity_percent === null || telemetry.humidity_percent === undefined
      ? "-"
      : `${numberText(telemetry.humidity_percent, 0)}%`;
    return telemetry;
  } catch (error) {
    $("tempState").textContent = "-";
    $("humidityState").textContent = "-";
    log("Telemetry failed", error.payload || error.message);
    return null;
  }
}

async function refreshBoardStatus() {
  try {
    const data = await api("/board/status/latest");
    const status = data.status || null;
    if (!status) {
      $("boardRecordedAt").textContent = "-";
      $("boardHost").textContent = "-";
      $("boardBattery").textContent = "-";
      $("boardBleStage").textContent = "-";
      $("boardBringupMs").textContent = "-";
      $("boardLeaseState").textContent = "-";
      $("boardStatusRaw").textContent = "No board snapshot stored.";
      return null;
    }
    $("boardRecordedAt").textContent = status.recorded_at || "-";
    $("boardHost").textContent = status.scanner_host || "-";
    const hasBattery = status.battery_est_v !== null && status.battery_est_v !== undefined;
    $("boardBattery").textContent = hasBattery ? `${numberText(status.battery_est_v, 3)} V` : "-";
    // The desktop bridge has no battery; hide the card instead of showing "-".
    const batteryCard = $("boardBattery").closest(".summary-card");
    if (batteryCard) batteryCard.hidden = !hasBattery;
    $("boardBleStage").textContent = status.ble_stage || "-";
    $("boardBringupMs").textContent = status.last_bringup_elapsed_ms === null || status.last_bringup_elapsed_ms === undefined
      ? "-"
      : `${Number(status.last_bringup_elapsed_ms)} ms`;
    $("boardLeaseState").textContent = status.lease_active ? "Active" : "Inactive";
    $("boardStatusRaw").textContent = JSON.stringify(status.payload || {}, null, 2);
    return status;
  } catch (error) {
    $("boardStatusRaw").textContent = `Board snapshot failed\n${JSON.stringify(error.payload || error.message, null, 2)}`;
    log("Board status failed", error.payload || error.message);
    return null;
  }
}

function setCheckboxValue(id, value) {
  const el = $(id);
  if (el) el.checked = Boolean(value);
}

function setInputValue(id, value, fallback = "") {
  const el = $(id);
  if (!el) return;
  el.value = value === null || value === undefined ? fallback : String(value);
}

function onboardErrorText(error) {
  const payload = error?.payload || {};
  const details = payload?.error?.details || {};
  const bridge = details?.bridge || {};
  return bridge?.error || payload?.error?.message || error?.message || "Onboard request failed";
}

async function syncOnboardPreview(force = false) {
  const frame = $("onboardLatestFrame");
  const fallback = $("onboardLatestFallback");
  const title = $("onboardPreviewTitle");
  const meta = $("onboardPreviewMeta");
  const status = state.onboardStatus || {};
  if (!frame || !fallback || !title || !meta) return;
  const hasImage = Number(status.latest_bytes || 0) > 0;
  if (!hasImage && !force) {
    revokeOnboardPreviewUrl();
    frame.removeAttribute("src");
    frame.hidden = true;
    fallback.hidden = false;
    title.textContent = "No onboard image";
    meta.textContent = "Capture a frame to populate the latest JPEG.";
    return;
  }
  revokeOnboardPreviewUrl();
  frame.hidden = true;
  fallback.hidden = false;
  title.textContent = "Loading onboard image";
  meta.textContent = "Requesting latest JPEG from the board";

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 10000);

  try {
    const response = await fetch(`${API}/onboard/latest.jpg?ts=${Date.now()}`, {
      signal: controller.signal,
      cache: "no-store",
    });
    if (!response.ok) {
      throw new Error(`Image request failed (${response.status})`);
    }
    const blob = await response.blob();
    if (!blob.size) {
      throw new Error("Image response was empty");
    }
    const objectUrl = URL.createObjectURL(blob);
    state.onboardPreviewUrl = objectUrl;
    frame.onload = () => {
      frame.hidden = false;
      fallback.hidden = true;
      title.textContent = "Latest onboard image";
      meta.textContent = `${sizeText(blob.size)} loaded`;
    };
    frame.onerror = () => {
      revokeOnboardPreviewUrl();
      frame.hidden = true;
      fallback.hidden = false;
      title.textContent = "Image unavailable";
      meta.textContent = "The browser could not render the JPEG.";
    };
    frame.src = objectUrl;
  } catch (error) {
    frame.hidden = true;
    fallback.hidden = false;
    title.textContent = "Image unavailable";
    meta.textContent = error.name === "AbortError"
      ? "Timed out waiting for the board image"
      : (error.message || "The board did not return a latest JPEG.");
    log("Onboard preview failed", error.message || error);
  } finally {
    clearTimeout(timeoutId);
  }
}

function populateOnboardForm(status) {
  setCheckboxValue("onboardEnabledInput", status.enabled);
  setInputValue("onboardIntervalInput", status.interval_ms);
  setInputValue("onboardStartInput", status.window_start);
  setInputValue("onboardEndInput", status.window_end);
  setInputValue("onboardTzOffsetInput", status.tz_offset_min);
  setInputValue("onboardFrameSizeInput", status.framesize || "UXGA");
  setInputValue("onboardJpegQualityInput", status.jpeg_quality);
  setInputValue("onboardBrightnessInput", status.brightness);
  setInputValue("onboardContrastInput", status.contrast);
  setInputValue("onboardSaturationInput", status.saturation);
  setInputValue("onboardSharpnessInput", status.sharpness);
  setCheckboxValue("onboardVflipInput", status.vflip);
  setCheckboxValue("onboardHmirrorInput", status.hmirror);
  setCheckboxValue("onboardAwbInput", status.awb);
  setCheckboxValue("onboardAwbGainInput", status.awb_gain);
  setInputValue("onboardWbModeInput", status.wb_mode);
  setCheckboxValue("onboardAecInput", status.aec);
  setCheckboxValue("onboardAec2Input", status.aec2);
  setInputValue("onboardAeLevelInput", status.ae_level);
  setInputValue("onboardAecValueInput", status.aec_value);
  setCheckboxValue("onboardAgcInput", status.agc);
  setInputValue("onboardAgcGainInput", status.agc_gain);
  setInputValue("onboardEffectInput", status.special_effect);
}

function renderOnboardStatus(status) {
  state.onboardStatus = status || null;
  if (!status) {
    $("onboardReady").textContent = "-";
    $("onboardEnabled").textContent = "-";
    $("onboardFrameSize").textContent = "-";
    $("onboardJpegQuality").textContent = "-";
    $("onboardWindow").textContent = "-";
    $("onboardClock").textContent = "-";
    $("onboardCaptures").textContent = "-";
    $("onboardFailures").textContent = "-";
    $("onboardLatestBytes").textContent = "-";
    $("onboardTimelapseState").textContent = "-";
    $("onboardTimelapseMeta").textContent = "Idle";
    $("onboardStatusRaw").textContent = "No onboard status loaded.";
    void syncOnboardPreview(false);
    return;
  }

  $("onboardReady").textContent = boolText(status.ready);
  $("onboardEnabled").textContent = boolText(status.enabled);
  $("onboardFrameSize").textContent = status.framesize || "-";
  $("onboardJpegQuality").textContent = status.jpeg_quality ?? "-";
  $("onboardWindow").textContent = `${status.window_start || "-"} to ${status.window_end || "-"}`;
  $("onboardClock").textContent = status.clock_valid ? "Valid" : "Unset";
  $("onboardCaptures").textContent = `${status.captures ?? 0}`;
  $("onboardFailures").textContent = `${status.failures ?? 0}`;
  $("onboardLatestBytes").textContent = sizeText(status.latest_bytes);
  $("onboardTimelapseState").textContent = status.timelapse?.active
    ? `Active (${durationText(status.timelapse.remaining_ms)})`
    : (status.timelapse?.state || "Stopped");
  $("onboardTimelapseMeta").textContent = status.timelapse?.active
    ? `Interval ${durationText(status.timelapse.interval_ms)}`
    : "Idle";
  $("onboardStatusRaw").textContent = JSON.stringify(status, null, 2);
  populateOnboardForm(status);
  void syncOnboardPreview(false);
}

function updateDeleteSelectedOnboardBtn() {
  const btn = $("deleteSelectedOnboardMediaBtn");
  if (btn) btn.disabled = state.selectedOnboardIds.size === 0;
}

function updateSelectAllOnboardCheckbox(items) {
  const selectAll = $("selectAllOnboardMedia");
  if (!selectAll) return;
  const total = items.length;
  const selected = items.filter((i) => state.selectedOnboardIds.has(i.id)).length;
  selectAll.checked = total > 0 && selected === total;
  selectAll.indeterminate = selected > 0 && selected < total;
}

function renderOnboardMedia(items) {
  state.onboardMedia = Array.isArray(items) ? items : [];
  const currentIds = new Set(state.onboardMedia.map((i) => i.id));
  for (const id of state.selectedOnboardIds) {
    if (!currentIds.has(id)) state.selectedOnboardIds.delete(id);
  }
  updateDeleteSelectedOnboardBtn();
  const rows = $("onboardMediaRows");
  if (!rows) return;
  rows.innerHTML = "";
  if (!state.onboardMedia.length) {
    rows.innerHTML = '<tr><td colspan="7" class="empty">No onboard photos stored on the board.</td></tr>';
    const selectAll = $("selectAllOnboardMedia");
    if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
    return;
  }
  state.onboardMedia.forEach((item) => {
    const tr = document.createElement("tr");
    const previewSrc = `${API}/onboard/media/${encodeURIComponent(item.id)}`;
    const isChecked = state.selectedOnboardIds.has(item.id) ? " checked" : "";
    tr.innerHTML = `
      <td><input type="checkbox" class="onboard-select" data-id="${item.id}"${isChecked}></td>
      <td><img class="thumb thumb-clickable" src="${previewSrc}" alt="Click to view"></td>
      <td>${item.id || "-"}</td>
      <td>${item.recorded_at || "-"}</td>
      <td>${sizeText(item.bytes)}</td>
      <td>${item.capture_kind || "-"}</td>
      <td><div class="row-actions">
        <a class="button-link" href="${previewSrc}" target="_blank" rel="noreferrer">View</a>
        <a class="button-link" href="${previewSrc}" download="${item.filename || `${item.id}.jpg`}">Download</a>
        <button data-onboard-delete="${item.id}">Delete</button>
      </div></td>
    `;
    tr.querySelector(".thumb-clickable").addEventListener("click", () => openLightbox({ type: "photo", id: item.id, cached: false, server_only: false, _onboard: true, _src: previewSrc }));
    tr.querySelector(".onboard-select").addEventListener("change", (e) => {
      const id = e.target.dataset.id;
      if (e.target.checked) state.selectedOnboardIds.add(id);
      else state.selectedOnboardIds.delete(id);
      updateSelectAllOnboardCheckbox(state.onboardMedia);
      updateDeleteSelectedOnboardBtn();
    });
    rows.appendChild(tr);
  });
  updateSelectAllOnboardCheckbox(state.onboardMedia);
}

function renderOnboardServerMedia(items) {
  state.onboardServerMedia = Array.isArray(items) ? items : [];
  const currentFilenames = new Set(state.onboardServerMedia.map((i) => i.filename));
  for (const filename of state.selectedOnboardServerFilenames) {
    if (!currentFilenames.has(filename)) state.selectedOnboardServerFilenames.delete(filename);
  }
  updateDeleteSelectedOnboardServerMediaBtn();
  const rows = $("onboardServerMediaRows");
  if (!rows) return;
  rows.innerHTML = "";
  if (!state.onboardServerMedia.length) {
    rows.innerHTML = '<tr><td colspan="7" class="empty">No onboard photos stored on this server.</td></tr>';
    const selectAll = $("selectAllOnboardServerMedia");
    if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
    return;
  }
  state.onboardServerMedia.forEach((item) => {
    const tr = document.createElement("tr");
    const source = item.download_path;
    const checked = state.selectedOnboardServerFilenames.has(item.filename) ? " checked" : "";
    tr.innerHTML = `
      <td><input type="checkbox" class="onboard-server-select" data-filename="${item.filename}"${checked}></td>
      <td><img class="thumb thumb-clickable" src="${source}" alt="Click to view"></td>
      <td>${item.id || "-"}</td>
      <td>${item.recorded_at || "-"}</td>
      <td>${sizeText(item.bytes)}</td>
      <td>${item.capture_kind || "server copy"}</td>
      <td><div class="row-actions">
        <a class="button-link" href="${source}" target="_blank" rel="noreferrer">View</a>
        <a class="button-link" href="${source}" download="${item.filename || "onboard.jpg"}">Download</a>
        <button data-onboard-server-delete="${item.filename}">Delete Server Copy</button>
      </div></td>
    `;
    tr.querySelector(".thumb-clickable").addEventListener("click", () =>
      openLightbox({ type: "photo", id: item.id, cached: true, server_only: true, _onboard: true, _src: source })
    );
    tr.querySelector(".onboard-server-select").addEventListener("change", (event) => {
      const { filename } = event.target.dataset;
      if (event.target.checked) state.selectedOnboardServerFilenames.add(filename);
      else state.selectedOnboardServerFilenames.delete(filename);
      updateSelectAllOnboardServerMediaCheckbox();
      updateDeleteSelectedOnboardServerMediaBtn();
    });
    rows.appendChild(tr);
  });
  updateSelectAllOnboardServerMediaCheckbox();
}

function updateDeleteSelectedOnboardServerMediaBtn() {
  const button = $("deleteSelectedOnboardServerMediaBtn");
  if (button) button.disabled = state.selectedOnboardServerFilenames.size === 0;
}

function updateSelectAllOnboardServerMediaCheckbox() {
  const selectAll = $("selectAllOnboardServerMedia");
  if (!selectAll) return;
  const total = state.onboardServerMedia.length;
  const selected = state.onboardServerMedia.filter((item) => state.selectedOnboardServerFilenames.has(item.filename)).length;
  selectAll.checked = total > 0 && selected === total;
  selectAll.indeterminate = selected > 0 && selected < total;
}

async function refreshOnboardServerMedia(options = {}) {
  try {
    const data = await api("/onboard/server-media");
    renderOnboardServerMedia(data.media || []);
    return data;
  } catch (error) {
    const rows = $("onboardServerMediaRows");
    if (rows) rows.innerHTML = `<tr><td colspan="7" class="empty">Server media failed: ${onboardErrorText(error)}</td></tr>`;
    if (!options.silent) log("Onboard server media failed", error.payload || error.message);
    throw error;
  }
}

async function refreshOnboardMedia(options = {}) {
  const wrapped = options.silent
    ? () => api("/onboard/media")
    : () => withCameraActivity("Refresh onboard media", () => api("/onboard/media"), {
      message: "Loading stored onboard photos",
    });
  try {
    const data = await wrapped();
    renderOnboardMedia(data.media || []);
    await refreshOnboardServerMedia({ silent: true }).catch(() => {});
    return data;
  } catch (error) {
    $("onboardMediaRows").innerHTML = `<tr><td colspan="6" class="empty">Onboard media failed: ${onboardErrorText(error)}</td></tr>`;
    log("Onboard media failed", error.payload || error.message);
    throw error;
  }
}

async function refreshOnboardStatus(options = {}) {
  const wrapped = options.silent
    ? () => api("/onboard/status")
    : () => withCameraActivity("Refresh onboard", () => api("/onboard/status"), {
      message: "Refreshing onboard camera status",
    });
  try {
    const data = await wrapped();
    renderOnboardStatus(data);
    return data;
  } catch (error) {
    $("onboardStatusRaw").textContent = `Onboard status failed\n${JSON.stringify(error.payload || error.message, null, 2)}`;
    log("Onboard status failed", error.payload || error.message);
    throw error;
  }
}

function onboardPayloadFromForm(extra = {}) {
  const payload = { ...extra };
  const includeAll = Boolean(extra.includeAll);
  delete payload.includeAll;

  const checkboxValue = (id) => $(id).checked;
  const inputValue = (id) => $(id).value.trim();
  const maybeNumber = (id) => {
    const raw = inputValue(id);
    return raw === "" ? null : Number(raw);
  };
  const maybeString = (id) => {
    const raw = inputValue(id);
    return raw === "" ? null : raw;
  };

  if (includeAll || state.onboardStatus) payload.enabled = checkboxValue("onboardEnabledInput");

  const intervalMs = maybeNumber("onboardIntervalInput");
  if (intervalMs !== null) payload.interval_ms = intervalMs;

  const start = maybeString("onboardStartInput");
  if (start !== null) payload.start = start;

  const end = maybeString("onboardEndInput");
  if (end !== null) payload.end = end;

  const tzOffset = maybeNumber("onboardTzOffsetInput");
  if (tzOffset !== null) payload.tz_offset_min = tzOffset;

  const frameSize = maybeString("onboardFrameSizeInput");
  if (frameSize !== null) payload.framesize = frameSize;

  const jpegQuality = maybeNumber("onboardJpegQualityInput");
  if (jpegQuality !== null) payload.jpeg_quality = jpegQuality;

  const brightness = maybeNumber("onboardBrightnessInput");
  if (brightness !== null) payload.brightness = brightness;

  const contrast = maybeNumber("onboardContrastInput");
  if (contrast !== null) payload.contrast = contrast;

  const saturation = maybeNumber("onboardSaturationInput");
  if (saturation !== null) payload.saturation = saturation;

  const sharpness = maybeNumber("onboardSharpnessInput");
  if (sharpness !== null) payload.sharpness = sharpness;

  if (includeAll || state.onboardStatus) {
    payload.vflip = checkboxValue("onboardVflipInput");
    payload.hmirror = checkboxValue("onboardHmirrorInput");
    payload.awb = checkboxValue("onboardAwbInput");
    payload.awb_gain = checkboxValue("onboardAwbGainInput");
    payload.aec = checkboxValue("onboardAecInput");
    payload.aec2 = checkboxValue("onboardAec2Input");
    payload.agc = checkboxValue("onboardAgcInput");
  }

  const wbMode = maybeNumber("onboardWbModeInput");
  if (wbMode !== null) payload.wb_mode = wbMode;

  const aeLevel = maybeNumber("onboardAeLevelInput");
  if (aeLevel !== null) payload.ae_level = aeLevel;

  const aecValue = maybeNumber("onboardAecValueInput");
  if (aecValue !== null) payload.aec_value = aecValue;

  const agcGain = maybeNumber("onboardAgcGainInput");
  if (agcGain !== null) payload.agc_gain = agcGain;

  const specialEffect = maybeNumber("onboardEffectInput");
  if (specialEffect !== null) payload.special_effect = specialEffect;

  return payload;
}

async function captureOnboard() {
  setBusy("onboard", true);
  try {
    const payload = onboardPayloadFromForm();
    const result = await withCameraActivity("Capture onboard", () =>
      api("/onboard/capture", { method: "POST", body: payload }), {
      message: "Capturing a fresh onboard JPEG",
    });
    const status = result.onboard_camera || result;
    renderOnboardStatus(status);
    await syncOnboardPreview(true);
    await refreshOnboardMedia({ silent: true }).catch(() => {});
    log("Onboard capture complete", result);
  } catch (error) {
    $("onboardPreviewTitle").textContent = "Capture failed";
    $("onboardPreviewMeta").textContent = onboardErrorText(error);
    $("onboardStatusRaw").textContent = JSON.stringify(error.payload || { message: error.message }, null, 2);
    updateCameraActivity({
      label: "Capture onboard failed",
      message: "Onboard capture failed",
      detail: onboardErrorText(error),
      last: `Last result: ${onboardErrorText(error)}`,
      startedAt: null,
    });
    log("Onboard capture failed", error.payload || error.message);
  } finally {
    setBusy("onboard", false);
  }
}

async function deleteOnboardMedia(id) {
  if (!window.confirm(`Delete onboard photo ${id}?`)) return;
  setBusy("onboard-media", true);
  try {
    const result = await withCameraActivity("Delete onboard photo", () =>
      api(`/onboard/media/${encodeURIComponent(id)}`, { method: "DELETE" }), {
      message: `Deleting onboard photo ${id}`,
    });
    log(`Onboard photo ${id} deleted`, result);
    await refreshOnboardMedia({ silent: true });
    await refreshOnboardStatus({ silent: true }).catch(() => {});
  } catch (error) {
    log("Onboard delete failed", error.payload || error.message);
  } finally {
    setBusy("onboard-media", false);
  }
}

async function deleteOnboardServerMedia(filename) {
  if (!window.confirm("Delete this downloaded onboard photo from the server?")) return;
  setBusy("onboard-server-media", true);
  try {
    const result = await api(`/onboard/server-media/${encodeURIComponent(filename)}`, { method: "DELETE" });
    log("Deleted onboard server copy", result);
    await refreshOnboardServerMedia({ silent: true });
  } catch (error) {
    log("Onboard server-copy delete failed", error.payload || error.message);
  } finally {
    setBusy("onboard-server-media", false);
  }
}

async function deleteSelectedOnboardServerMedia() {
  const filenames = [...state.selectedOnboardServerFilenames];
  if (!filenames.length) return;
  const confirmed = window.confirm(
    `Delete ${filenames.length} selected onboard server cop${filenames.length === 1 ? "y" : "ies"}?\n\nThis does not contact the onboard camera.`
  );
  if (!confirmed) return;
  setBusy("onboard-server-media", true);
  try {
    const result = await api("/onboard/server-media/delete-selected", {
      method: "POST",
      body: { confirm: "delete-selected", filenames },
    });
    log(`Deleted ${result.deleted?.length || 0} onboard server copies`, result);
    if ((result.errors || []).length) log("Some onboard server copies could not be deleted", result.errors);
    state.selectedOnboardServerFilenames.clear();
    await refreshOnboardServerMedia({ silent: true });
  } catch (error) {
    log("Onboard server bulk delete failed", error.payload || error.message);
  } finally {
    setBusy("onboard-server-media", false);
  }
}

async function deleteAllOnboardMedia() {
  if (!window.confirm("Delete all onboard photos from the board?")) return;
  setBusy("onboard-media", true);
  try {
    const result = await withCameraActivity("Delete all onboard photos", () =>
      api("/onboard/media/delete_all", { method: "POST", body: {} }), {
      message: "Deleting all onboard photos",
    });
    log("Deleted onboard photos", result);
    await refreshOnboardMedia({ silent: true });
    await refreshOnboardStatus({ silent: true }).catch(() => {});
  } catch (error) {
    log("Delete all onboard photos failed", error.payload || error.message);
  } finally {
    setBusy("onboard-media", false);
  }
}

async function downloadAndDeleteOnboardMedia() {
  const count = state.onboardMedia.length;
  if (!count) {
    log("No onboard media to download");
    return;
  }
  const confirmed = window.confirm(
    `Download and delete ${count} onboard photo${count === 1 ? "" : "s"}?\n\nEach photo is saved to this server before it is deleted from the onboard camera. Photos that fail to save will remain on the board.`
  );
  if (!confirmed) return;
  setBusy("onboard-media", true);
  try {
    const result = await withCameraActivity("Download and delete onboard photos", () =>
      api("/onboard/media/download-and-delete", { method: "POST", body: { confirm: "download-and-delete" } }), {
      message: "Saving onboard photos to the server before deleting them",
    });
    log(`Onboard download and delete complete — downloaded ${result.downloaded || 0}, skipped ${result.skipped || 0}, deleted ${result.deleted || 0}`, result);
    if ((result.errors || []).length) {
      log("Some onboard photos were not deleted", result.errors);
    }
    state.selectedOnboardIds.clear();
    await refreshOnboardMedia({ silent: true });
    await refreshOnboardStatus({ silent: true }).catch(() => {});
  } catch (error) {
    log("Onboard download and delete failed", error.payload || error.message);
  } finally {
    setBusy("onboard-media", false);
  }
}

async function deleteSelectedOnboardMedia() {
  const ids = [...state.selectedOnboardIds];
  if (!ids.length) return;
  if (!window.confirm(`Delete ${ids.length} selected onboard photo${ids.length > 1 ? "s" : ""}?`)) return;
  setBusy("onboard-media", true);
  try {
    const result = await withCameraActivity("Delete selected onboard photos", () =>
      api("/onboard/media/delete-selected", { method: "POST", body: { confirm: "delete-selected", ids } }), {
      message: `Deleting ${ids.length} onboard photo${ids.length > 1 ? "s" : ""}`,
    });
    log(`Deleted ${result.deleted?.length || 0} onboard photos`, result);
    state.selectedOnboardIds.clear();
    await refreshOnboardMedia({ silent: true });
    await refreshOnboardStatus({ silent: true }).catch(() => {});
  } catch (error) {
    log("Delete selected onboard photos failed", error.payload || error.message);
  } finally {
    setBusy("onboard-media", false);
  }
}

async function saveOnboardSettings() {
  setBusy("onboard", true);
  try {
    const payload = onboardPayloadFromForm({ includeAll: true });
    const result = await withCameraActivity("Save onboard settings", () =>
      api("/onboard/config", { method: "POST", body: payload }), {
      message: "Writing onboard camera settings",
    });
    renderOnboardStatus(result);
    log("Onboard settings saved", result);
  } catch (error) {
    $("onboardStatusRaw").textContent = JSON.stringify(error.payload || { message: error.message }, null, 2);
    log("Onboard settings failed", error.payload || error.message);
  } finally {
    setBusy("onboard", false);
  }
}

async function syncOnboardClock() {
  setBusy("onboard", true);
  try {
    const localOffsetMin = -new Date().getTimezoneOffset();
    const epoch = Math.floor(Date.now() / 1000);
    const result = await withCameraActivity("Sync onboard clock", () =>
      api("/onboard/config", { method: "POST", body: { epoch, tz_offset_min: localOffsetMin } }), {
      message: "Syncing onboard clock and timezone offset",
    });
    renderOnboardStatus(result);
    log("Onboard clock synced", { epoch, tz_offset_min: localOffsetMin });
  } catch (error) {
    $("onboardStatusRaw").textContent = JSON.stringify(error.payload || { message: error.message }, null, 2);
    log("Onboard clock sync failed", error.payload || error.message);
  } finally {
    setBusy("onboard", false);
  }
}

async function startOnboardTimelapse() {
  setBusy("onboard", true);
  try {
    const hours = Number($("timelapseHours").value || 0);
    const interval_ms = Number($("timelapseIntervalMs").value || 0);
    const result = await withCameraActivity("Start onboard timelapse", () =>
      api("/onboard/timelapse/start", { method: "POST", body: { hours, interval_ms } }), {
      message: "Starting onboard timelapse",
    });
    renderOnboardStatus(result);
    log("Onboard timelapse started", result);
  } catch (error) {
    $("onboardStatusRaw").textContent = JSON.stringify(error.payload || { message: error.message }, null, 2);
    log("Onboard timelapse start failed", error.payload || error.message);
  } finally {
    setBusy("onboard", false);
  }
}

async function stopOnboardTimelapse() {
  setBusy("onboard", true);
  try {
    const result = await withCameraActivity("Stop onboard timelapse", () =>
      api("/onboard/timelapse/stop", { method: "POST", body: {} }), {
      message: "Stopping onboard timelapse",
    });
    renderOnboardStatus(result);
    log("Onboard timelapse stopped", result);
  } catch (error) {
    $("onboardStatusRaw").textContent = JSON.stringify(error.payload || { message: error.message }, null, 2);
    log("Onboard timelapse stop failed", error.payload || error.message);
  } finally {
    setBusy("onboard", false);
  }
}

async function refreshStatus() {
  try {
    const data = await withCameraActivity("Refresh status", () => api("/status"), {
      message: "Refreshing camera bridge status",
    });
    $("cameraState").textContent = data.camera_reachable ? "Reachable" : "Unavailable";
    $("sessionState").textContent = data.session_open ? "Open" : "Closed";
    $("liveState").textContent = data.live_view_active ? "Active" : "Stopped";
    $("bridgeIp").textContent = data.bridge_ip || "-";
    $("wifiState").textContent = boolText(data.wifi_connected);
    $("halowState").textContent = boolText(data.halow_connected);
    // Desktop bridge: show host/uplink/camera model when reported.
    const raw = data.raw || {};
    const bridgeBits = [raw.hostname, raw.firmware_version, raw.uplink_iface, raw.camera_target_id].filter(Boolean);
    const bridgeInfo = $("bridgeInfo");
    if (bridgeInfo) {
      bridgeInfo.textContent = bridgeBits.join(" · ") || "-";
      bridgeInfo.closest("div").hidden = bridgeBits.length === 0;
    }
    // No HaLow radio on the desktop (halow_rssi is null): hide that row.
    const halowRow = $("halowState").closest("div");
    if (halowRow) halowRow.hidden = Object.prototype.hasOwnProperty.call(raw, "halow_rssi") && raw.halow_rssi === null && raw.bridge === "desktop";
    $("cameraIp").textContent = data.camera_ip || "-";
    $("bleStage").textContent = data.ble_stage || "-";
    $("standbyState").textContent = boolText(data.standby_requested);
    $("lastMessage").textContent =
      data.control_error ||
      data.control_progress_text ||
      data.control_message ||
      data.control_last_message ||
      "-";
    applyLiveSnapshot(data);
    syncLivePreview(Boolean(data.live_view_active));
    setPill(true, "Bridge online");
    return data;
  } catch (error) {
    $("cameraState").textContent = "Unavailable";
    $("sessionState").textContent = "Unknown";
    $("liveState").textContent = "Unknown";
    setPill(false, error.message);
    log("Status failed", error.payload || error.message);
    throw error;
  }
}

async function refreshLive() {
  try {
    const data = await withCameraActivity("Refresh live", () => api("/live/status"), {
      message: "Refreshing live feed status",
    });
    const receiving = applyLiveHealth(data);
    const recovering = data.live_health?.recovering === true;
    $("liveState").textContent = receiving ? "Active" : recovering ? "Recovering" : data.stream_active ? "Active" : "Stopped";
    syncLivePreview(Boolean(receiving && data.mjpeg_relay_active));
    scheduleLiveHealthPoll(Boolean((data.stream_active || recovering) && !data.live_health?.restart_required));
  } catch (error) {
    $("streamTitle").textContent = "Stream unavailable";
    $("streamMeta").textContent = error.message;
    clearLiveFrameTimer();
    clearLiveHealthTimer();
    syncLivePreview(false);
    log("Live status failed", error.payload || error.message);
  }
}

async function refreshCameraTarget() {
  let data;
  try {
    data = await api("/camera/target");
  } catch (error) {
    await new Promise((r) => setTimeout(r, 3000));
    try {
      data = await api("/camera/target");
    } catch (retryError) {
      log("Camera target refresh failed", retryError.message);
      $("cameraTargetActive").textContent = "Unavailable";
      $("cameraTargetSelect").innerHTML = '<option value="">Unavailable — bridge unreachable</option>';
      return;
    }
  }
  const active = data.target?.active;
  $("cameraTargetActive").textContent = active
    ? `${active.wifi_ssid || active.ble_mac}`
    : "No target set";
  const select = $("cameraTargetSelect");
  const profiles = data.target?.profiles || [];
  select.innerHTML = "";
  profiles.forEach((p) => {
    const opt = document.createElement("option");
    opt.value = p.id;
    opt.textContent = p.label || p.id;
    opt.selected = !!p.selected;
    select.appendChild(opt);
  });
  if (!profiles.length) {
    select.innerHTML = '<option value="">No profiles available</option>';
  }
}

async function switchCameraTarget() {
  const id = $("cameraTargetSelect").value;
  if (!id) return;
  setBusy("switchCamera", true);
  try {
    await withCameraActivity("Switch camera target", () =>
      api("/camera/target", { method: "POST", body: { id } }), {
      message: `Switching to camera profile: ${id}`,
    });
    await Promise.all([refreshCameraTarget(), refreshStatus()]);
    log("Camera target switched", id);
  } finally {
    setBusy("switchCamera", false);
  }
}

async function connectCamera() {
  setBusy("connect", true);
  startCameraActivity("Connect camera", "Waking the selected camera over Bluetooth");
  clearCameraMonitor();
  state.cameraMonitorTimer = setInterval(async () => {
    try {
      const status = await api("/status");
      updateActivityFromStatus("Connect camera", status, "Waiting for the camera Wi-Fi hotspot");
    } catch (error) {
      updateCameraActivity({
        label: "Connect camera",
        message: "Polling bridge status...",
        detail: error.message || "Status poll failed",
        last: "Last result: status poll failed",
      });
    }
  }, 1000);
  try {
    const data = await api("/session/open", { method: "POST", body: { timeout_sec: 180, poll_interval_sec: 1 } });
    clearCameraMonitor();
    finishCameraActivity("Connect camera", data);
    log(data.session_ready ? "Session opened" : "Session open returned incomplete", data);
    await refreshStatus();
  } catch (error) {
    clearCameraMonitor();
    finishCameraActivity("Connect camera", null, error);
    const payload = error.payload?.error || {};
    const detail = payload.details?.control_progress_text
      || payload.details?.control_error
      || payload.message
      || error.message
      || "The camera did not wake.";
    updateCameraActivity({
      label: "Connect camera failed",
      message: detail,
      detail: "The bridge could not bring up the selected camera's Wi-Fi hotspot.",
      last: `Error: ${detail}`,
      startedAt: null,
    });
    log("Connect camera failed", error.payload || error.message);
    await refreshStatus().catch(() => {});
  } finally {
    clearCameraMonitor();
    setBusy("connect", false);
  }
}

async function disconnectCamera() {
  setBusy("disconnect", true);
  try {
    const data = await withCameraActivity("Disconnect camera", () =>
      api("/session/close", { method: "POST", body: { timeout_sec: 45, poll_interval_sec: 2, standby: true } }), {
      message: "Closing the camera session",
    });
    log(data.session_closed ? "Session closed" : "Session close returned incomplete", data);
    await refreshStatus();
  } finally {
    setBusy("disconnect", false);
  }
}

async function startLive() {
  setBusy("live", true);
  try {
    startCameraActivity("Start live", "Starting the live feed");
    clearCameraMonitor();
    state.cameraMonitorTimer = setInterval(async () => {
      try {
        const status = await api("/status");
        updateActivityFromStatus("Start live", status, "Starting the live feed");
      } catch (error) {
        updateCameraActivity({
          label: "Start live",
          message: "Polling bridge status...",
          detail: error.message || "Status poll failed",
          last: "Last result: status poll failed",
        });
      }
    }, 1000);
    const data = await api("/live/start", { method: "POST", body: {} });
    clearCameraMonitor();
    updateCameraActivity({
      label: "Start live complete",
      message: data.stream_active ? "Live feed started" : "Live feed starting",
      detail: data.status?.control_error || data.status?.control_progress_text || data.status?.control_message || data.status?.control_last_message || "Waiting for stream activation",
      last: data.status?.control_error ? `Error: ${data.status.control_error}` : (data.status?.control_message || data.status?.control_last_message) ? `Last result: ${data.status.control_message || data.status.control_last_message}` : "Last result: stream start requested",
      startedAt: null,
    });
    $("streamTitle").textContent = data.stream_active ? "Stream active" : "Stream starting";
    const receiving = applyLiveHealth(data);
    if (!data.live_health) {
      $("streamMeta").textContent = data.player_ready ? (data.player_url || "No player target") : "Live receiver not ready";
    }
    if (data.player_url) {
      $("playerLink").href = data.player_url;
    }
    syncLivePreview(Boolean(receiving && data.mjpeg_relay_active));
    scheduleLiveHealthPoll(Boolean((data.stream_active || data.live_health?.recovering) && !data.live_health?.restart_required));
    log("Live started", data);
    await refreshStatus();
    await refreshLive();
  } catch (error) {
    const payload = error.payload?.error || {};
    const detail = payload.details?.control_progress_text
      || payload.details?.control_error
      || payload.message
      || error.message
      || "The live stream could not be started.";
    $("streamTitle").textContent = "Live start failed";
    $("streamMeta").textContent = detail;
    updateCameraActivity({
      label: "Start live failed",
      message: detail,
      detail: "The desktop bridge must reconnect to the selected camera's Wi-Fi hotspot before live video can start.",
      last: `Error: ${detail}`,
      startedAt: null,
    });
    if (error.payload?.error?.code === "live_receiver_unavailable") {
      $("streamTitle").textContent = "Live receiver missing";
      $("streamMeta").textContent = error.payload.error.details?.sdp_path || "No SDP path configured";
    }
    log("Live start failed", error.payload || error.message);
  } finally {
    clearCameraMonitor();
    setBusy("live", false);
  }
}

async function stopLive() {
  setBusy("live", true);
  try {
    const data = await withCameraActivity("Stop live", () => api("/live/stop", { method: "POST", body: {} }), {
      message: "Stopping the live feed",
    });
    $("streamTitle").textContent = "Stream inactive";
    $("streamMeta").textContent = "No player target";
    clearLiveHealthTimer();
    syncLivePreview(false);
    log("Live stopped", data);
    await refreshStatus();
  } finally {
    setBusy("live", false);
  }
}

async function loadSettings() {
  setBusy("settings", true);
  try {
    const [schema, settings] = await withCameraActivity("Load settings", () => Promise.all([
      api("/settings/schema"),
      api("/settings"),
    ]), {
      message: "Reading camera settings",
    });
    state.schema = schema.fields || [];
    state.settings = settings.settings || {};
    renderSettings();
    log("Settings loaded");
  } catch (error) {
    log("Settings load failed", error.payload || error.message);
  } finally {
    setBusy("settings", false);
  }
}

function renderSettings() {
  const form = $("settingsForm");
  form.innerHTML = "";
  if (!state.schema.length) {
    form.innerHTML = '<div class="empty">No editable settings loaded.</div>';
    return;
  }
  state.schema.forEach((field) => {
    const wrap = document.createElement("div");
    wrap.className = "field";
    const label = document.createElement("label");
    label.htmlFor = `setting-${field.key}`;
    label.textContent = field.label || field.key;
    const input = document.createElement("input");
    input.id = `setting-${field.key}`;
    input.name = field.key;
    input.type = field.type === "integer" ? "number" : "text";
    input.value = state.settings[field.key] ?? "";
    wrap.append(label, input);
    form.append(wrap);
  });
}

async function saveSettings() {
  const patch = {};
  const formData = new FormData($("settingsForm"));
  for (const field of state.schema) {
    const raw = formData.get(field.key);
    const original = state.settings[field.key] ?? "";
    const next = field.type === "integer" && raw !== "" ? Number(raw) : raw;
    if (String(next) !== String(original)) {
      patch[field.key] = next;
    }
  }
  if (!Object.keys(patch).length) {
    log("No setting changes");
    return;
  }
  setBusy("settings", true);
  try {
    const result = await withCameraActivity("Save settings", () =>
      api("/settings", { method: "POST", body: { patch } }), {
      message: "Writing camera settings",
    });
    log("Settings saved", result);
    await loadSettings();
  } catch (error) {
    log("Settings save failed", error.payload || error.message);
  } finally {
    setBusy("settings", false);
  }
}

function openLightbox(item) {
  const box = $("mediaLightbox");
  const container = $("lightboxMedia");
  container.innerHTML = "";
  if (item._onboard) {
    const img = document.createElement("img");
    img.src = item._src;
    img.alt = item.id || "";
    container.appendChild(img);
  } else if (item.type === "video") {
    const vid = document.createElement("video");
    vid.src = item.download_path;
    vid.controls = true;
    vid.autoplay = true;
    container.appendChild(vid);
  } else {
    const img = document.createElement("img");
    img.src = item.download_path;
    img.alt = item.timestamp || "";
    container.appendChild(img);
  }
  box.hidden = false;
  document.body.style.overflow = "hidden";
}

function closeLightbox() {
  const box = $("mediaLightbox");
  const container = $("lightboxMedia");
  const vid = container.querySelector("video");
  if (vid) vid.pause();
  container.innerHTML = "";
  box.hidden = true;
  document.body.style.overflow = "";
}

async function refreshMedia() {
  try {
    const data = await api("/media");
    renderMedia(data.items || []);
    log(`Server media loaded: ${(data.items || []).length} items`);
  } catch (error) {
    renderMedia([]);
    log("Media load failed", error.payload || error.message);
  }
}

function updateSelectAllCheckbox(items) {
  const selectAll = $("selectAllMedia");
  if (!selectAll) return;
  const total = items.length;
  const selected = items.filter((i) => state.selectedMediaIds.has(String(i.id))).length;
  selectAll.checked = total > 0 && selected === total;
  selectAll.indeterminate = selected > 0 && selected < total;
}

function updateDeleteSelectedBtn() {
  const btn = $("deleteSelectedMediaBtn");
  if (btn) btn.disabled = state.selectedMediaIds.size === 0;
}

function renderMedia(items) {
  if (state.selectedMediaIds.size > 0) {
    console.log("[renderMedia] called with", state.selectedMediaIds.size, "selected; items:", items.length, new Error().stack);
  }
  // Preserve selection for IDs still present; prune any that disappeared
  const currentIds = new Set(items.map((i) => String(i.id)));
  for (const id of state.selectedMediaIds) {
    if (!currentIds.has(id)) state.selectedMediaIds.delete(id);
  }
  updateDeleteSelectedBtn();
  const rows = $("mediaRows");
  rows.innerHTML = "";
  if (!items.length) {
    rows.innerHTML = '<tr><td colspan="7" class="empty">No media loaded.</td></tr>';
    const selectAll = $("selectAllMedia");
    if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
    return;
  }
  items.forEach((item) => {
    const tr = document.createElement("tr");
    const canPreview = item.cached || item.server_only;
    const thumb = item.type === "photo" || item.type === "video"
      ? `<img class="thumb${canPreview ? " thumb-clickable" : ""}" src="${item.thumbnail_path}" alt="${canPreview ? "Click to view" : ""}">`
      : '<div class="thumb"></div>';
    const statusText = item.server_only ? "server only" : (item.status || "-");
    const cachedBadge = item.server_only
      ? ' <span class="badge-cached" title="File is on this server — no longer on camera">Server</span>'
      : item.cached
      ? ' <span class="badge-cached" title="Saved on server">Server</span>'
      : '';
    const isChecked = state.selectedMediaIds.has(String(item.id)) ? " checked" : "";
    tr.dataset.mediaId = item.id;
    tr.innerHTML = `
      <td><input type="checkbox" class="media-select" data-id="${item.id}"${isChecked}></td>
      <td>${thumb}</td>
      <td>${item.type || "-"}</td>
      <td>${item.timestamp || "-"}</td>
      <td>${sizeText(item.size_bytes)}</td>
      <td>${statusText}${cachedBadge}</td>
      <td><div class="row-actions">
        <a class="button-link" href="${item.download_path}" target="_blank" rel="noreferrer">Download</a>
        <button data-delete="${item.id}">Delete Server Copy</button>
      </div></td>
    `;
    if (canPreview) {
      const thumbEl = tr.querySelector(".thumb-clickable");
      if (thumbEl) thumbEl.addEventListener("click", () => openLightbox(item));
    }
    tr.querySelector(".media-select").addEventListener("change", (e) => {
      const id = e.target.dataset.id;
      if (e.target.checked) state.selectedMediaIds.add(id);
      else state.selectedMediaIds.delete(id);
      updateSelectAllCheckbox(items);
      updateDeleteSelectedBtn();
    });
    rows.appendChild(tr);
  });
  updateSelectAllCheckbox(items);
}

async function deleteMedia(id) {
  const confirmed = window.confirm(`Delete server copy of media ${id}?\n\nThe trail camera will not be contacted.`);
  if (!confirmed) return;
  setBusy("media", true);
  try {
    const result = await withCameraActivity(`Delete media ${id}`, () =>
      api(`/media/${encodeURIComponent(id)}`, { method: "DELETE", body: { confirm: "delete" } }), {
      message: `Deleting media item ${id}`,
    });
    log(`Media ${id} deleted`, result);
    updateCameraActivity({
      label: `Delete media ${id}`,
      message: `Media ${id} deleted`,
      detail: "Refreshing media list",
      last: `Last result: media ${id} deleted`,
    });
    await refreshMedia();
  } catch (error) {
    log("Delete failed", error.payload || error.message);
  } finally {
    setBusy("media", false);
  }
}

async function downloadAllMedia() {
  setBusy("media", true);
  try {
    const job = await withCameraActivity("Download all media", () =>
      api("/media/durable/start", { method: "POST", body: { action: "download_all_media" } }), {
      message: "Handing the download job to the desktop bridge",
    });
    updateCameraActivity({
      label: "Download all media",
      message: "Desktop bridge job running in background",
      detail: "The job continues if the connection drops",
      last: `Job ${job.job_id} started`,
      startedAt: Date.now(),
    });
    const result = await waitForDurableMediaJob("Download all");
    log(`Downloaded ${result.downloaded || 0}, skipped ${result.skipped || 0} already cached`, {
      directory: result.directory,
      failed: result.failed,
      files: (result.files || []).slice(0, 10),
      errors: (result.errors || []).slice(0, 5),
    });
    await refreshMedia();
  } catch (error) {
    log("Download all failed", error.payload || error.message);
  } finally {
    setBusy("media", false);
  }
}

async function clearServerMedia() {
  const confirmed = window.confirm("Remove all server copies?\n\nThis deletes files downloaded to this server only. Files on the camera are not affected.");
  if (!confirmed) return;
  setBusy("media", true);
  try {
    const result = await withCameraActivity("Clear server copies", () =>
      api("/media/clear-server", { method: "POST", body: { confirm: "clear-server" } }), {
      message: "Removing server-cached media files",
    });
    log(`Cleared ${result.deleted || 0} server copies`, result);
    await refreshMedia();
  } catch (error) {
    log("Clear server failed", error.payload || error.message);
  } finally {
    setBusy("media", false);
  }
}

async function downloadAndDeleteMedia() {
  const confirmed = window.confirm("Download and delete?\n\nAll media currently on the camera will be downloaded to the server, then deleted from the camera one by one.\n\nPhotos taken after this point will not be affected.");
  if (!confirmed) return;
  setBusy("media", true);
  try {
    const job = await withCameraActivity("Download and delete", () =>
      api("/media/durable/start", { method: "POST", body: { action: "download_and_delete_media", confirm: "download-and-delete-media" } }), {
      message: "Handing the download and delete job to the desktop bridge",
    });
    updateCameraActivity({
      label: "Download and delete",
      message: "Desktop bridge job running in background",
      detail: "The job continues if the connection drops",
      last: `Job ${job.job_id} started`,
      startedAt: Date.now(),
    });
    const result = await waitForDurableMediaJob("Download and delete");
    log(`Downloaded ${result.downloaded || 0}, skipped ${result.skipped || 0} already cached, deleted ${result.deleted || 0} from camera`, {
      directory: result.directory,
      failed: result.failed,
      errors: (result.errors || []).slice(0, 5),
    });
    await refreshMedia();
  } catch (error) {
    log("Download and delete failed", error.payload || error.message);
  } finally {
    setBusy("media", false);
  }
}

async function deleteSelectedMedia() {
  const ids = [...state.selectedMediaIds];
  if (!ids.length) return;
  const confirmed = window.confirm(`Delete ${ids.length} selected server item${ids.length === 1 ? "" : "s"}?\n\nThe trail camera will not be contacted.`);
  if (!confirmed) return;
  setBusy("media", true);
  try {
    const result = await withCameraActivity("Delete selected", () =>
      api("/media/delete-selected", { method: "POST", body: { confirm: "delete-selected", ids } }), {
      message: `Deleting ${ids.length} selected items`,
    });
    log(`Deleted ${(result.deleted || []).length} items`, result);
    if ((result.errors || []).length) {
      log("Some deletes failed", result.errors);
    }
    await refreshMedia();
  } catch (error) {
    log("Delete selected failed", error.payload || error.message);
  } finally {
    setBusy("media", false);
  }
}

async function deleteAllMedia() {
  const confirmed = window.confirm("Delete all media from the camera?\n\nThis permanently removes files from the camera SD card. Server copies are not affected.");
  if (!confirmed) return;
  setBusy("media", true);
  try {
    const job = await withCameraActivity("Delete all media", () =>
      api("/media/delete-all", { method: "POST", body: { confirm: "delete-all" } }), {
      message: "Starting bulk delete on the camera",
    });
    updateCameraActivity({
      label: "Delete all media",
      message: "Bulk delete running in background",
      detail: "Polling job status",
      last: `Job ${job.job_id} started`,
      startedAt: Date.now(),
    });
    const result = await waitForMediaJob(job.job_id, "Delete all");
    log(`Deleted ${result.deleted || 0} media files`, result);
    await refreshMedia();
  } catch (error) {
    log("Delete all failed", error.payload || error.message);
  } finally {
    setBusy("media", false);
  }
}

function durableJobFromStatus(status) {
  return status?.live?.job || status?.state?.last_status || null;
}

function durableJobStatus(job) {
  return String(job?.status || job?.state || job?.phase || "").toLowerCase();
}

async function waitForDurableMediaJob(label) {
  // The desktop bridge owns this job after it accepts it. Do not fail the UI
  // merely because the web server temporarily cannot reach the bridge.
  while (true) {
    try {
      const status = await api("/media/durable/status");
      const job = durableJobFromStatus(status);
      const jobStatus = durableJobStatus(job);
      const progress = job?.progress || job?.result?.progress || job || {};
      const isComplete = ["completed", "complete", "done", "success", "succeeded"].includes(jobStatus);
      const isFailed = ["failed", "error", "cancelled", "canceled"].includes(jobStatus);

      if (isFailed) {
        const message = job?.error?.message || job?.error || `${label} failed on the desktop bridge`;
        finishCameraActivity(label, null, new Error(message));
        throw Object.assign(new Error(message), { durableTerminal: true });
      }

      if (isComplete) {
        updateCameraActivity({
          label: `${label} completing`,
          message: "Desktop bridge job complete — copying staged files to this server",
          detail: "The bridge copies remain safe until transfer succeeds",
          last: `Job ${job?.job_id || "-"}: ${jobStatus}`,
          startedAt: state.cameraActivity.startedAt || Date.now(),
        });
        try {
          const pulled = await api("/media/durable/pull", { method: "POST", body: {} });
          if ((pulled.failed || 0) > 0) {
            throw new Error(`${pulled.failed} staged file${pulled.failed === 1 ? "" : "s"} still waiting to transfer`);
          }
          finishCameraActivity(label, pulled);
          return pulled;
        } catch (error) {
          updateCameraActivity({
            label: `${label} waiting for transfer`,
            message: "Desktop bridge job is complete; waiting to copy staged files",
            detail: error.message || "The board is temporarily unreachable",
            last: "The job remains safe on the desktop bridge",
            startedAt: state.cameraActivity.startedAt || Date.now(),
          });
        }
      } else if (status?.connectivity === "unknown") {
        updateCameraActivity({
          label: `${label} running`,
          message: "Desktop bridge connection unavailable — job continues on the bridge",
          detail: "Will resume status checks automatically",
          last: job?.job_id ? `Job ${job.job_id}: last seen ${jobStatus || "running"}` : "Waiting for desktop bridge reconnect",
          startedAt: state.cameraActivity.startedAt || Date.now(),
        });
      } else {
        const progressLabel = durableMediaProgressText(job);
        updateCameraActivity({
          label: `${label} running`,
          message: progressLabel ? `${label} ${progressLabel}` : "Desktop bridge job is running",
          detail: job?.message || job?.detail || (progress.current_media_id
            ? `Current media ${progress.current_media_id}`
            : "The desktop bridge owns this job"),
          last: `Job ${job?.job_id || "-"}: ${jobStatus || "pending"}`,
          startedAt: state.cameraActivity.startedAt || Date.now(),
        });
      }
    } catch (error) {
      if (error.durableTerminal) throw error;
      // A status request failure says nothing about the desktop bridge job itself.
      updateCameraActivity({
        label: `${label} running`,
        message: "Connection to desktop bridge unavailable — job continues on the bridge",
        detail: error.message || "Retrying status check",
        last: "Waiting for desktop bridge reconnect",
        startedAt: state.cameraActivity.startedAt || Date.now(),
      });
    }
    await new Promise((resolve) => setTimeout(resolve, 3000));
  }
}

async function waitForMediaJob(jobId, label) {
  const deadline = Date.now() + 10 * 60 * 1000;
  while (Date.now() < deadline) {
    const job = await api(`/media/jobs/${jobId}`);
    if (job.status === "completed") {
      finishCameraActivity(label, job.result || {});
      return job.result || {};
    }
    if (job.status === "failed") {
      const message = job.error?.message || `${label} job failed`;
      finishCameraActivity(label, null, Object.assign(new Error(message), { payload: job.error || job }));
      throw Object.assign(new Error(message), { payload: job.error || job });
    }
    const progressLabel = progressText(job.progress);
    updateCameraActivity({
      label: `${label} running`,
      message: progressLabel ? `${label} ${progressLabel}` : `${label} job is ${job.status}`,
      detail: job.progress?.current_id
        ? `Current item ${job.progress.current_id}`
        : `Last update ${job.updated_at || "-"}`,
      last: `Job ${jobId}: ${job.status}`,
      startedAt: state.cameraActivity.startedAt || Date.now(),
    });
    await new Promise((resolve) => setTimeout(resolve, 1500));
  }
  finishCameraActivity(label, null, new Error(`${label} job timed out`));
  throw new Error(`${label} job timed out`);
}

async function action(path, label) {
  setBusy("action", true);
  $("actionState").textContent = label;
  try {
    const result = await withCameraActivity(label, () => api(path, { method: "POST", body: {} }), {
      message: `${label} request in progress`,
    });
    log(label, result);
    await refreshStatus().catch(() => {});
  } catch (error) {
    log(`${label} failed`, error.payload || error.message);
  } finally {
    $("actionState").textContent = "Ready";
    setBusy("action", false);
  }
}

async function readClock() {
  setBusy("clock", true);
  try {
    const result = await withCameraActivity("Read clock", () => api("/admin/clock"), {
      message: "Reading camera clock",
    });
    const clockStr = result?.clock?.data?.clock;
    if (clockStr) {
      $("clockInput").value = clockStr.replace(" ", "T");
    }
    log("Clock read", result);
  } catch (error) {
    log("Clock read failed", error.payload || error.message);
  } finally {
    setBusy("clock", false);
  }
}

async function setClock() {
  const value = $("clockInput").value;
  if (!value) return;
  const utc = new Date(value).toISOString().slice(0, 19).replace("T", " ");
  setBusy("clock", true);
  try {
    const result = await withCameraActivity("Set clock", () =>
      api("/admin/set-clock", { method: "POST", body: { timestamp_utc: utc } }), {
      message: "Writing camera clock",
    });
    log("Clock set", result);
  } catch (error) {
    log("Clock set failed", error.payload || error.message);
  } finally {
    setBusy("clock", false);
  }
}

async function checkAndResumeMediaJob() {
  if (state.mediaJobPolling) return;
  try {
    const data = await api("/media/active-job");
    const job = data.job;
    if (!job || !["pending", "starting", "running"].includes(job.status)) return;
    state.mediaJobPolling = true;
    const label = job.label || "Media job";
    const progress = job.progress || {};
    updateCameraActivity({
      label: `${label} in progress`,
      message: `${label} was already running — resuming`,
      detail: progress.current_id ? `Last seen item ${progress.current_id}` : "Reconnected to in-progress job",
      last: `Job ${job.job_id}: ${job.status}`,
      startedAt: Date.now(),
    });
    setBusy("media", true);
    try {
      const result = await waitForMediaJob(job.job_id, label);
      const dl = result.downloaded || 0;
      const sk = result.skipped || 0;
      const del = result.deleted != null ? `, deleted ${result.deleted} from camera` : "";
      log(`${label} complete — downloaded ${dl}, skipped ${sk} already cached${del}`, result);
      await refreshMedia();
    } catch (error) {
      log(`${label} failed`, error.payload || error.message);
    } finally {
      setBusy("media", false);
      state.mediaJobPolling = false;
    }
  } catch {
    // silently ignore — bridge may be unreachable on reconnect
  }
}

async function checkAndResumeDurableMediaJob() {
  if (state.mediaJobPolling) return;
  try {
    const status = await api("/media/durable/status");
    const job = durableJobFromStatus(status);
    if (!job) return;
    const jobStatus = durableJobStatus(job);
    // A completed durable job is terminal even if an older scheduler did not
    // persist its optional server-pull marker.  Otherwise this stale status
    // is incorrectly presented as a job that is still running.
    if (["completed", "complete", "done", "success", "succeeded"].includes(jobStatus)) {
      return;
    }
    if (["failed", "error", "cancelled", "canceled"].includes(jobStatus)) {
      log("Desktop bridge media job reported a failure", job);
      return;
    }
    const action = job.action || status?.state?.action || "download_all_media";
    const label = action === "download_and_delete_media" ? "Download and delete" : "Download all";
    state.mediaJobPolling = true;
    setBusy("media", true);
    updateCameraActivity({
      label: `${label} resumed`,
      message: status.connectivity === "unknown"
        ? "Desktop bridge connection unavailable — job continues on the bridge"
        : "Resuming desktop bridge media-job monitoring",
      detail: `Job ${job.job_id || "-"}`,
      last: `Last status: ${jobStatus || "pending"}`,
      startedAt: Date.now(),
    });
    try {
      const result = await waitForDurableMediaJob(label);
      log(`${label} complete — downloaded ${result.downloaded || 0}, skipped ${result.skipped || 0} already cached`, result);
      await refreshMedia();
    } catch (error) {
      log(`${label} failed on the desktop bridge`, error.payload || error.message);
    } finally {
      setBusy("media", false);
      state.mediaJobPolling = false;
    }
  } catch {
    // The durable state endpoint itself is unavailable; retry on the next refresh.
  }
}

async function refreshAll() {
  if (VIEW === "trail") {
    // The local gallery can contain many videos. It must never delay the
    // camera selector or the Connect/Live controls.
    if (state.selectedMediaIds.size === 0) {
      void refreshMedia();
    }

    await Promise.all([
      refreshStatus().catch(() => null),
      refreshCameraTarget().catch(() => {}),
      refreshTelemetry(),
      refreshBoardStatus(),
      refreshLive().catch(() => {}),
      checkAndResumeDurableMediaJob().catch(() => {}),
      checkAndResumeMediaJob().catch(() => {}),
    ]);
    return;
  }

  await refreshTelemetry();
  await refreshBoardStatus();
  await refreshOnboardStatus({ silent: true }).catch(() => {});
  await refreshOnboardMedia({ silent: true }).catch(() => {});
  await refreshOnboardServerMedia({ silent: true }).catch(() => {});
}


document.addEventListener("click", (event) => {
  const deleteButton = event.target.closest("[data-delete]");
  if (deleteButton) {
    deleteMedia(deleteButton.dataset.delete);
  }
  const onboardDeleteButton = event.target.closest("[data-onboard-delete]");
  if (onboardDeleteButton) {
    deleteOnboardMedia(onboardDeleteButton.dataset.onboardDelete);
  }
  const onboardServerDeleteButton = event.target.closest("[data-onboard-server-delete]");
  if (onboardServerDeleteButton) {
    deleteOnboardServerMedia(onboardServerDeleteButton.dataset.onboardServerDelete);
  }
});

$("refreshAll").addEventListener("click", refreshAll);
const themeToggle = $("themeToggle");
const syncThemeToggle = () => {
  themeToggle.textContent = document.body.classList.contains("dark-mode") ? "Light mode" : "Dark mode";
};
syncThemeToggle();
themeToggle.addEventListener("click", () => {
  const dark = document.body.classList.toggle("dark-mode");
  localStorage.setItem("trail-media-dark", dark ? "1" : "0");
  syncThemeToggle();
});
$("refreshStatus").addEventListener("click", () => refreshStatus().catch(() => {}));
$("refreshBoardStatus").addEventListener("click", () => refreshBoardStatus().catch(() => {}));
$("refreshOnboardBtn").addEventListener("click", () => refreshOnboardStatus().catch(() => {}));
$("refreshOnboardImageBtn").addEventListener("click", () => { void syncOnboardPreview(true); });
$("refreshOnboardMediaBtn").addEventListener("click", () => refreshOnboardMedia().catch(() => {}));
$("refreshOnboardServerMediaBtn").addEventListener("click", () => refreshOnboardServerMedia().catch(() => {}));
$("deleteSelectedOnboardServerMediaBtn").addEventListener("click", deleteSelectedOnboardServerMedia);
$("selectAllOnboardServerMedia").addEventListener("change", (event) => {
  document.querySelectorAll(".onboard-server-select").forEach((checkbox) => {
    const { filename } = checkbox.dataset;
    checkbox.checked = event.target.checked;
    if (event.target.checked) state.selectedOnboardServerFilenames.add(filename);
    else state.selectedOnboardServerFilenames.delete(filename);
  });
  updateDeleteSelectedOnboardServerMediaBtn();
});
$("downloadAndDeleteOnboardMediaBtn").addEventListener("click", downloadAndDeleteOnboardMedia);
$("captureOnboardBtn").addEventListener("click", captureOnboard);
$("deleteSelectedOnboardMediaBtn").addEventListener("click", deleteSelectedOnboardMedia);
$("deleteAllOnboardMediaBtn").addEventListener("click", deleteAllOnboardMedia);
$("selectAllOnboardMedia").addEventListener("change", (e) => {
  document.querySelectorAll(".onboard-select").forEach((cb) => {
    const id = cb.dataset.id;
    cb.checked = e.target.checked;
    if (e.target.checked) state.selectedOnboardIds.add(id);
    else state.selectedOnboardIds.delete(id);
  });
  updateDeleteSelectedOnboardBtn();
});
$("saveOnboardSettingsBtn").addEventListener("click", saveOnboardSettings);
$("syncOnboardClockBtn").addEventListener("click", syncOnboardClock);
$("startOnboardTimelapseBtn").addEventListener("click", startOnboardTimelapse);
$("stopOnboardTimelapseBtn").addEventListener("click", stopOnboardTimelapse);
$("connectBtn").addEventListener("click", connectCamera);
$("disconnectBtn").addEventListener("click", disconnectCamera);
$("switchCameraBtn").addEventListener("click", switchCameraTarget);
$("refreshTargetBtn").addEventListener("click", refreshCameraTarget);
$("refreshLive").addEventListener("click", refreshLive);
$("startLiveBtn").addEventListener("click", startLive);
$("stopLiveBtn").addEventListener("click", stopLive);
$("loadSettingsBtn").addEventListener("click", loadSettings);
$("saveSettingsBtn").addEventListener("click", saveSettings);
$("selectAllMedia").addEventListener("change", (e) => {
  document.querySelectorAll(".media-select").forEach((cb) => {
    const id = cb.dataset.id;
    cb.checked = e.target.checked;
    if (e.target.checked) state.selectedMediaIds.add(id);
    else state.selectedMediaIds.delete(id);
  });
  updateDeleteSelectedBtn();
});
$("refreshMediaBtn").addEventListener("click", () => refreshMedia());
$("downloadAllMediaBtn").addEventListener("click", downloadAllMedia);
$("downloadAndDeleteMediaBtn").addEventListener("click", downloadAndDeleteMedia);
$("clearServerMediaBtn").addEventListener("click", clearServerMedia);
$("deleteSelectedMediaBtn").addEventListener("click", deleteSelectedMedia);
$("deleteAllMediaBtn").addEventListener("click", deleteAllMedia);
$("takePictureBtn").addEventListener("click", () => action("/actions/take-picture", "Take picture"));
$("videoStartBtn").addEventListener("click", () => action("/actions/video/start", "Start video"));
$("videoStopBtn").addEventListener("click", () => action("/actions/video/stop", "Stop video"));
$("readClockBtn").addEventListener("click", readClock);
$("setClockBtn").addEventListener("click", setClock);
$("clearLogBtn").addEventListener("click", () => { $("logOutput").textContent = ""; });
$("lightboxClose").addEventListener("click", closeLightbox);
$("mediaLightbox").querySelector(".lightbox-backdrop").addEventListener("click", closeLightbox);
document.addEventListener("keydown", (e) => { if (e.key === "Escape") closeLightbox(); });

refreshAll();
