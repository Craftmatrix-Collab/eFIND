<?php
/**
 * Mobile-first direct-upload page.
 * Users on mobile devices upload images/documents for resolutions,
 * minutes of meeting, and ordinances directly to MinIO via presigned URLs.
 * The PHP server only orchestrates — it never handles the file bytes.
 *
 * Access: /admin/mobile_upload.php
 */
session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';


$preselectedType = in_array($_GET['type'] ?? '', ['resolutions', 'minutes', 'ordinances'])
    ? $_GET['type']
    : '';

$autoCameraMode = ($preselectedType !== '' && ($_GET['camera'] ?? '') === '1');
$mobileSession  = preg_replace('/[^a-f0-9]/', '', $_GET['session'] ?? '');
$deferToDesktop = (($_GET['flow'] ?? '') === 'modal_ocr');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta name="theme-color" content="#002147">
<title>Mobile Upload — eFIND</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root { --brand: #002147; --brand-light: #0a3d6b; }
  body  { background: #f0f2f5; font-size: 15px; }
  .top-bar {
    background: var(--brand); color: #fff;
    padding: 14px 16px; position: sticky; top: 0; z-index: 100;
    display: flex; align-items: center; gap: 12px;
  }
  .top-bar h1 { font-size: 18px; margin: 0; }
  .card       { border: none; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.08); margin-bottom: 16px; }
  .card-header{ background: var(--brand); color: #fff; border-radius: 12px 12px 0 0 !important; font-weight: 600; }
  .type-btn   {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px; border-radius: 10px;
    border: 2px solid #dee2e6; background: #fff;
    cursor: pointer; width: 100%; margin-bottom: 10px;
    transition: border-color .2s, background .2s;
  }
  .type-btn.selected { border-color: var(--brand); background: #e8f0fe; }
  .type-btn .icon    { font-size: 28px; width: 36px; text-align: center; }
  .type-btn .info h6 { margin: 0; font-size: 15px; font-weight: 600; }
  .type-btn .info p  { margin: 0; font-size: 12px; color: #666; }

  .drop-zone {
    border: 2px dashed #adb5bd; border-radius: 12px;
    padding: 32px 16px; text-align: center; cursor: pointer;
    background: #fafafa; transition: border-color .2s, background .2s;
  }
  .drop-zone.dragover { border-color: var(--brand); background: #e8f0fe; }
  .drop-zone .dz-icon { font-size: 40px; color: #adb5bd; }
  .file-preview {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; background: #fff; border-radius: 8px;
    border: 1px solid #e0e0e0; margin-bottom: 8px;
  }
  .file-preview img  { width: 44px; height: 44px; object-fit: cover; border-radius: 6px; }
  .file-preview .pdf-icon { width: 44px; height: 44px; display: flex; align-items: center; justify-content: center;
    background: #fee2e2; border-radius: 6px; font-size: 22px; color: #dc2626; }
  .file-preview .file-info { flex: 1; overflow: hidden; }
  .file-preview .file-info .name { font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .file-preview .file-info .size { font-size: 11px; color: #888; }
  .file-preview .remove-btn { color: #dc2626; cursor: pointer; font-size: 18px; flex-shrink: 0; }

  .progress-wrap { margin-top: 4px; }
  .progress      { height: 6px; border-radius: 3px; }

  #step-indicator { display: flex; gap: 8px; margin-bottom: 20px; }
  .step-dot {
    flex: 1; height: 4px; border-radius: 2px; background: #dee2e6; transition: background .3s;
  }
  .step-dot.active   { background: var(--brand); }
  .step-dot.complete { background: #198754; }

  .btn-primary   { background: var(--brand); border-color: var(--brand); }
  .btn-primary:hover { background: var(--brand-light); border-color: var(--brand-light); }

  .success-card {
    text-align: center; padding: 40px 24px;
  }
  .success-card .check { font-size: 72px; color: #198754; }
  .success-card h4     { margin-top: 12px; }

  @media (min-width: 600px) {
    body > .container { max-width: 520px; }
  }
</style>
</head>
<body>

<?php if ($autoCameraMode): ?>
<!-- Camera splash overlay — shown when arriving via QR with ?camera=1 -->
<div id="camera-splash" onclick="dismissCameraSplash()"
     style="position:fixed;inset:0;z-index:9999;background:#002147;
            display:flex;flex-direction:column;align-items:center;justify-content:center;
            color:#fff;cursor:pointer;user-select:none;-webkit-tap-highlight-color:transparent;">
  <i class="fas fa-camera" style="font-size:72px;margin-bottom:24px;opacity:.9;"></i>
  <div style="font-size:22px;font-weight:700;margin-bottom:10px;">Tap to Open Camera</div>
  <div style="font-size:14px;opacity:.7;">
    <?php
      $labels = ['resolutions'=>'Resolution','minutes'=>'Minutes of Meeting','ordinances'=>'Ordinance'];
      echo 'Uploading ' . ($labels[$preselectedType] ?? '');
    ?>
  </div>
</div>
<?php endif; ?>

<div class="top-bar">
  <a href="dashboard.php" class="text-white me-1"><i class="fas fa-arrow-left"></i></a>
  <i class="fas fa-cloud-upload-alt fa-lg"></i>
  <h1>Mobile Upload</h1>
</div>

<div class="container py-3 px-3">

  <!-- Step indicator (hidden in direct mode) -->
  <div id="step-indicator" <?= $preselectedType ? 'class="d-none"' : '' ?>>
    <div class="step-dot active"  id="dot-1"></div>
    <div class="step-dot"         id="dot-2"></div>
    <div class="step-dot"         id="dot-3"></div>
  </div>

  <!-- ─── STEP 1: Document type ─── -->
  <div id="step-1" <?= $preselectedType ? 'class="d-none"' : '' ?>>
    <div class="card">
      <div class="card-header py-2 px-3"><i class="fas fa-file-alt me-2"></i>Step 1 — Select Document Type</div>
      <div class="card-body">
        <button class="type-btn <?= $preselectedType === 'resolutions' ? 'selected' : '' ?>"
                onclick="selectType('resolutions', this)">
          <span class="icon">📋</span>
          <span class="info"><h6>Resolution</h6><p>Barangay council resolutions</p></span>
        </button>
        <button class="type-btn <?= $preselectedType === 'minutes' ? 'selected' : '' ?>"
                onclick="selectType('minutes', this)">
          <span class="icon">📝</span>
          <span class="info"><h6>Minutes of Meeting</h6><p>Council session minutes</p></span>
        </button>
        <button class="type-btn <?= $preselectedType === 'ordinances' ? 'selected' : '' ?>"
                onclick="selectType('ordinances', this)">
          <span class="icon">⚖️</span>
          <span class="info"><h6>Ordinance</h6><p>Barangay ordinances</p></span>
        </button>
      </div>
    </div>

    <!-- Metadata fields rendered by JS -->
    <div id="meta-fields-step1"></div>

    <button class="btn btn-primary w-100 py-2" id="btn-next-1" disabled onclick="goToStep2()">
      Next — Add Files <i class="fas fa-arrow-right ms-1"></i>
    </button>
  </div>

  <!-- ─── DIRECT MODE: Combined form shown when type is pre-selected via QR ─── -->
  <?php if ($preselectedType): ?>
  <div id="direct-mode">
    <div class="card mb-2">
      <div class="card-header py-2 px-3">
        <?php
          $icons = ['resolutions'=>'📋','minutes'=>'📝','ordinances'=>'⚖️'];
          $labels= ['resolutions'=>'Resolution','minutes'=>'Minutes of Meeting','ordinances'=>'Ordinance'];
          echo $icons[$preselectedType] . ' ' . $labels[$preselectedType] . ' — Upload';
        ?>
      </div>
      <div class="card-body pb-1">
        <!-- Metadata injected by JS -->
        <div id="meta-fields-direct"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header py-2 px-3"><i class="fas fa-camera me-2"></i>Capture / Select Images</div>
      <div class="card-body">
        <div class="d-flex gap-2 mb-3">
          <button class="btn btn-primary flex-fill py-3 d-flex flex-column align-items-center gap-1"
                  onclick="openCamera()">
            <i class="fas fa-camera fa-lg"></i>
            <span class="small">Take Photo</span>
          </button>
          <button class="btn btn-outline-secondary flex-fill py-3 d-flex flex-column align-items-center gap-1"
                  onclick="document.getElementById('file-input').click()">
            <i class="fas fa-folder-open fa-lg"></i>
            <span class="small">Browse Files</span>
          </button>
        </div>

        <!-- Hidden inputs -->
        <input type="file" id="camera-input" class="d-none" accept="image/*" capture="environment" multiple>
        <input type="file" id="file-input"   class="d-none" accept="image/*" multiple>

        <!-- Live camera viewfinder -->
        <div id="camera-viewfinder" class="d-none mb-3" style="position:relative;">
          <video id="camera-video" autoplay playsinline muted
                 style="width:100%;border-radius:10px;background:#000;max-height:60vh;object-fit:cover;"></video>
          <div class="d-flex gap-2 mt-2">
            <button class="btn btn-primary flex-fill py-2" onclick="capturePhoto()">
              <i class="fas fa-camera me-2"></i>Capture
            </button>
            <button class="btn btn-outline-secondary flex-fill py-2" onclick="stopCamera()">
              <i class="fas fa-times me-1"></i>Cancel
            </button>
          </div>
          <canvas id="capture-canvas" class="d-none"></canvas>
        </div>

        <div id="file-list" class="mt-2"></div>
      </div>
    </div>

    <button class="btn btn-primary w-100 py-2 mt-2" id="btn-direct-upload" disabled onclick="startUpload()">
      <i class="fas fa-cloud-upload-alt me-2"></i>Upload
    </button>
  </div>
  <?php else: ?>

  <!-- ─── STEP 2: File selection (multi-step mode only) ─── -->
  <div id="step-2" class="d-none">
    <div class="card">
      <div class="card-header py-2 px-3"><i class="fas fa-images me-2"></i>Step 2 — Select Files</div>
      <div class="card-body">

        <!-- Camera capture buttons -->
        <div class="d-flex gap-2 mb-3">
          <button class="btn btn-primary flex-fill py-3 d-flex flex-column align-items-center gap-1"
                  onclick="openCamera()">
            <i class="fas fa-camera fa-lg"></i>
            <span class="small">Take Photo</span>
          </button>
          <button class="btn btn-outline-secondary flex-fill py-3 d-flex flex-column align-items-center gap-1"
                  onclick="document.getElementById('file-input').click()">
            <i class="fas fa-folder-open fa-lg"></i>
            <span class="small">Browse Files</span>
          </button>
        </div>

        <!-- Hidden inputs -->
        <input type="file" id="camera-input" class="d-none" accept="image/*" capture="environment" multiple>
        <input type="file" id="file-input"   class="d-none" accept="image/*" multiple>

        <!-- Live camera viewfinder (shown when getUserMedia is used as fallback) -->
        <div id="camera-viewfinder" class="d-none mb-3" style="position:relative;">
          <video id="camera-video" autoplay playsinline muted
                 style="width:100%;border-radius:10px;background:#000;max-height:60vh;object-fit:cover;"></video>
          <div class="d-flex gap-2 mt-2">
            <button class="btn btn-primary flex-fill py-2" onclick="capturePhoto()">
              <i class="fas fa-camera me-2"></i>Capture
            </button>
            <button class="btn btn-outline-secondary flex-fill py-2" onclick="stopCamera()">
              <i class="fas fa-times me-1"></i>Cancel
            </button>
          </div>
          <canvas id="capture-canvas" class="d-none"></canvas>
        </div>

        <div id="file-list" class="mt-2"></div>
      </div>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-outline-secondary flex-fill py-2" onclick="goToStep(1)">
        <i class="fas fa-arrow-left me-1"></i> Back
      </button>
      <button class="btn btn-primary flex-fill py-2" id="btn-next-2" disabled onclick="startUpload()">
        Upload <i class="fas fa-arrow-right ms-1"></i>
      </button>
    </div>
  </div>

  <?php endif; ?>

  <!-- ─── STEP 3: Uploading ─── -->
  <div id="step-3" class="d-none">
    <div class="card">
      <div class="card-header py-2 px-3"><i class="fas fa-spinner fa-spin me-2"></i>Uploading…</div>
      <div class="card-body" id="upload-progress-list"></div>
    </div>
    <div id="upload-result"></div>
  </div>

</div><!-- /container -->

<script>
// ──────────────────────────────────────────────────────────────
// State
// ──────────────────────────────────────────────────────────────
let selectedType    = '<?= $preselectedType ?>';
let selectedFiles   = [];
let currentStep     = 1;
let uploadInProgress = false;
const MAX_MOBILE_FILES = 8;
const mobileSession = '<?= $mobileSession ?>';
const autoCameraMode = <?= $autoCameraMode ? 'true' : 'false' ?>;
const deferToDesktop = <?= $deferToDesktop ? 'true' : 'false' ?>;
const requiresMobileMeta = !deferToDesktop;

function getMobileUploadWsUrl() {
  if (window.EFIND_MOBILE_WS_URL) {
    return window.EFIND_MOBILE_WS_URL;
  }
  const scheme = location.protocol === 'https:' ? 'wss' : 'ws';
  const host = location.hostname;
  const port = window.EFIND_MOBILE_WS_PORT || '8090';
  return `${scheme}://${host}:${port}/mobile-upload`;
}

async function notifyDesktopUploadComplete(payload) {
  if (!mobileSession || !window.WebSocket) return;

  await new Promise((resolve) => {
    let settled = false;
    const finish = () => {
      if (settled) return;
      settled = true;
      resolve();
    };

    let ws;
    try {
      ws = new WebSocket(getMobileUploadWsUrl());
    } catch (error) {
      finish();
      return;
    }

    const timeout = setTimeout(() => {
      try { ws.close(); } catch (e) {}
      finish();
    }, 2500);

    ws.onopen = () => {
      ws.send(JSON.stringify({
        action: 'upload_complete',
        session_id: mobileSession,
        doc_type: selectedType,
        title: payload.title || 'Document',
        uploaded_by: payload.uploaded_by || 'mobile',
        result_id: payload.result_id || null,
        object_keys: Array.isArray(payload.object_keys) ? payload.object_keys : [],
        image_urls: Array.isArray(payload.image_urls) ? payload.image_urls : [],
        deferred_to_desktop: !!payload.deferred_to_desktop,
      }));
      setTimeout(() => {
        clearTimeout(timeout);
        try { ws.close(); } catch (e) {}
        finish();
      }, 200);
    };

    ws.onerror = () => {
      clearTimeout(timeout);
      finish();
    };

    ws.onclose = () => {
      clearTimeout(timeout);
      finish();
    };
  });
}

// ──────────────────────────────────────────────────────────────
// Step navigation helpers
// ──────────────────────────────────────────────────────────────
function goToStep(n) {
  [1, 2, 3].forEach(i => {
    const stepEl = document.getElementById(`step-${i}`);
    if (stepEl) {
      stepEl.classList.toggle('d-none', i !== n);
    }
    const dot = document.getElementById(`dot-${i}`);
    if (dot) {
      dot.classList.remove('active', 'complete');
      if (i < n)  dot.classList.add('complete');
      if (i === n) dot.classList.add('active');
    }
  });
  currentStep = n;
  if (n !== 2) stopCamera();
}

// ──────────────────────────────────────────────────────────────
// Step 1 — type selection + metadata
// ──────────────────────────────────────────────────────────────
const metaTemplates = {
  resolutions: `
    <div class="card"><div class="card-header py-2 px-3"><i class="fas fa-pencil me-2"></i>Resolution Details</div>
    <div class="card-body">
      <div class="mb-3"><label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
        <input class="form-control" name="title" placeholder="e.g. Resolution Approving Budget 2025" required></div>
      <div class="mb-3"><label class="form-label fw-semibold">Resolution Number <span class="text-danger">*</span></label>
        <input class="form-control" name="resolution_number" placeholder="e.g. 2025-001" required></div>
      <div class="row g-2 mb-3">
        <div class="col"><label class="form-label fw-semibold">Resolution Date</label>
          <input class="form-control" type="date" name="resolution_date"></div>
        <div class="col"><label class="form-label fw-semibold">Date Issued</label>
          <input class="form-control" type="date" name="date_issued"></div>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">Reference Number</label>
        <input class="form-control" name="reference_number" placeholder="Optional"></div>
      <div class="mb-3"><label class="form-label fw-semibold">Description</label>
        <textarea class="form-control" name="description" rows="2" placeholder="Brief description"></textarea></div>
    </div></div>`,

  minutes: `
    <div class="card"><div class="card-header py-2 px-3"><i class="fas fa-pencil me-2"></i>Minutes Details</div>
    <div class="card-body">
      <div class="mb-3"><label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
        <input class="form-control" name="title" placeholder="e.g. Regular Session Minutes" required></div>
      <div class="mb-3"><label class="form-label fw-semibold">Session Number <span class="text-danger">*</span></label>
        <input class="form-control" name="session_number" placeholder="e.g. 1st Regular Session 2025" required></div>
      <div class="mb-3"><label class="form-label fw-semibold">Meeting Date</label>
        <input class="form-control" type="date" name="meeting_date"></div>
      <div class="mb-3"><label class="form-label fw-semibold">Reference Number</label>
        <input class="form-control" name="reference_number" placeholder="Optional"></div>
    </div></div>`,

  ordinances: `
    <div class="card"><div class="card-header py-2 px-3"><i class="fas fa-pencil me-2"></i>Ordinance Details</div>
    <div class="card-body">
      <div class="mb-3"><label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
        <input class="form-control" name="title" placeholder="e.g. Ordinance on Noise Regulation" required></div>
      <div class="mb-3"><label class="form-label fw-semibold">Ordinance Number <span class="text-danger">*</span></label>
        <input class="form-control" name="ordinance_number" placeholder="e.g. ORD-2025-001" required></div>
      <div class="row g-2 mb-3">
        <div class="col"><label class="form-label fw-semibold">Ordinance Date</label>
          <input class="form-control" type="date" name="ordinance_date"></div>
        <div class="col"><label class="form-label fw-semibold">Date Issued</label>
          <input class="form-control" type="date" name="date_issued"></div>
      </div>
      <div class="mb-3"><label class="form-label fw-semibold">Status</label>
        <select class="form-select" name="status">
          <option value="Active">Active</option>
          <option value="Inactive">Inactive</option>
          <option value="Repealed">Repealed</option>
        </select></div>
      <div class="mb-3"><label class="form-label fw-semibold">Reference Number</label>
        <input class="form-control" name="reference_number" placeholder="Optional"></div>
      <div class="mb-3"><label class="form-label fw-semibold">Description</label>
        <textarea class="form-control" name="description" rows="2" placeholder="Brief description"></textarea></div>
     </div></div>`
};
const deferredMetaNotice = `
  <div class="alert alert-info mb-0">
    <i class="fas fa-info-circle me-1"></i>
    Document details will be completed in the desktop add-document modal after upload.
  </div>`;

function selectType(type, btn) {
  selectedType = type;
  document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  const stepMeta = document.getElementById('meta-fields-step1');
  if (!stepMeta) return;
  stepMeta.innerHTML = requiresMobileMeta ? (metaTemplates[type] || '') : deferredMetaNotice;
  // Enable next button if required fields are eventually valid
  if (requiresMobileMeta) {
    stepMeta.addEventListener('input', validateStep1);
  }
  validateStep1();
}

function validateStep1() {
  const nextBtn = document.getElementById('btn-next-1');
  if (!nextBtn) return;
  if (!selectedType) { nextBtn.disabled = true; return; }
  if (!requiresMobileMeta) {
    nextBtn.disabled = false;
    return;
  }
  const required = document.querySelectorAll('#meta-fields-step1 [required]');
  const allFilled = [...required].every(el => el.value.trim() !== '');
  nextBtn.disabled = !allFilled;
}

// If type pre-selected via URL param, render its form immediately
if (selectedType) {
  const btn = document.querySelector(`.type-btn[onclick*="${selectedType}"]`);
  if (btn) btn.classList.add('selected');

  // Direct mode: meta form is in #direct-mode, re-wire validation to btn-direct-upload
  const directMeta = document.getElementById('meta-fields-direct');
  if (directMeta && document.getElementById('btn-direct-upload')) {
    // Re-render meta template into the direct-mode #meta-fields
    if (requiresMobileMeta) {
      directMeta.innerHTML = metaTemplates[selectedType] || '';
      directMeta.addEventListener('input', validateDirectMode);
    } else {
      directMeta.innerHTML = deferredMetaNotice;
    }
    validateDirectMode();
  } else if (btn) {
    selectType(selectedType, btn);
  }
}

function validateDirectMode() {
  const btn = document.getElementById('btn-direct-upload');
  if (!btn) return;
  const req = requiresMobileMeta
    ? document.querySelectorAll('#meta-fields-direct [required]')
    : [];
  const metaOk = !requiresMobileMeta || [...req].every(el => el.value.trim() !== '');
  btn.disabled = uploadInProgress || !(metaOk && selectedFiles.length > 0);
}

function goToStep2() {
  goToStep(2);
  requestCameraPermission();
}

// ──────────────────────────────────────────────────────────────
// Step 2 — file selection + camera
// ──────────────────────────────────────────────────────────────
const fileInput   = document.getElementById('file-input');
const cameraInput = document.getElementById('camera-input');

fileInput.addEventListener('change',   e => addFiles([...e.target.files]));
cameraInput.addEventListener('change', e => addFiles([...e.target.files]));

// ── Camera helpers ──
let cameraStream = null;
let liveStreamWs = null;
let liveFrameTimer = null;
let liveFrameCanvas = null;

// Dismiss the camera splash overlay and open the native camera
function dismissCameraSplash() {
  const splash = document.getElementById('camera-splash');
  if (splash) splash.remove();
  openCamera();
}

function openCamera() {
  cameraInput.value = '';
  if (autoCameraMode) {
    // QR flow should launch native camera capture directly.
    cameraInput.click();
    return;
  }
  if (mobileSession && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
    openLiveCamera();
    return;
  }
  // Native capture sheet fallback
  cameraInput.click();
}

function ensureLiveStreamSocket() {
  if (!mobileSession || !window.WebSocket) return null;
  if (liveStreamWs && (liveStreamWs.readyState === WebSocket.OPEN || liveStreamWs.readyState === WebSocket.CONNECTING)) {
    return liveStreamWs;
  }
  try {
    liveStreamWs = new WebSocket(getMobileUploadWsUrl());
  } catch (error) {
    liveStreamWs = null;
    return null;
  }

  liveStreamWs.onopen = () => {
    sendLiveStreamMessage({
      action: 'camera_status',
      session_id: mobileSession,
      doc_type: selectedType,
      status: 'live',
    });
  };

  liveStreamWs.onclose = () => {
    liveStreamWs = null;
  };

  liveStreamWs.onerror = () => {};
  return liveStreamWs;
}

function sendLiveStreamMessage(payload) {
  if (!liveStreamWs || liveStreamWs.readyState !== WebSocket.OPEN) return;
  liveStreamWs.send(JSON.stringify(payload));
}

function startLiveFrameBroadcast() {
  if (!mobileSession) return;
  ensureLiveStreamSocket();
  if (liveFrameTimer) return;

  if (!liveFrameCanvas) {
    liveFrameCanvas = document.createElement('canvas');
  }

  liveFrameTimer = setInterval(() => {
    const video = document.getElementById('camera-video');
    if (!video || !video.videoWidth || !video.videoHeight) return;

    ensureLiveStreamSocket();
    if (!liveStreamWs || liveStreamWs.readyState !== WebSocket.OPEN) return;

    const maxWidth = 640;
    let frameWidth = video.videoWidth;
    let frameHeight = video.videoHeight;
    if (frameWidth > maxWidth) {
      const scale = maxWidth / frameWidth;
      frameWidth = Math.round(frameWidth * scale);
      frameHeight = Math.round(frameHeight * scale);
    }

    liveFrameCanvas.width = frameWidth;
    liveFrameCanvas.height = frameHeight;
    const ctx = liveFrameCanvas.getContext('2d');
    if (!ctx) return;

    ctx.drawImage(video, 0, 0, frameWidth, frameHeight);
    sendLiveStreamMessage({
      action: 'camera_frame',
      session_id: mobileSession,
      doc_type: selectedType,
      frame_data: liveFrameCanvas.toDataURL('image/jpeg', 0.58),
      width: frameWidth,
      height: frameHeight,
      ts: Date.now(),
    });
  }, 350);
}

function stopLiveFrameBroadcast() {
  if (liveFrameTimer) {
    clearInterval(liveFrameTimer);
    liveFrameTimer = null;
  }

  if (liveStreamWs && liveStreamWs.readyState === WebSocket.OPEN && mobileSession) {
    sendLiveStreamMessage({
      action: 'camera_status',
      session_id: mobileSession,
      doc_type: selectedType,
      status: 'stopped',
    });
  }

  if (liveStreamWs) {
    try { liveStreamWs.close(); } catch (e) {}
    liveStreamWs = null;
  }
}

// Fallback live viewfinder (for browsers / desktop that don't support capture attribute)
async function openLiveCamera() {
  stopCamera();
  const vf = document.getElementById('camera-viewfinder');
  const vid = document.getElementById('camera-video');
  try {
    cameraStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } },
      audio: false
    });
    vid.srcObject = cameraStream;
    vf.classList.remove('d-none');
    startLiveFrameBroadcast();
  } catch (err) {
    stopLiveFrameBroadcast();
    if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
      alert('Camera permission denied. Please allow camera access in your browser settings and try again.');
    } else {
      alert('Unable to open camera: ' + err.message);
    }
  }
}

function capturePhoto() {
  const vid    = document.getElementById('camera-video');
  const canvas = document.getElementById('capture-canvas');
  canvas.width  = vid.videoWidth;
  canvas.height = vid.videoHeight;
  canvas.getContext('2d').drawImage(vid, 0, 0);
  canvas.toBlob(blob => {
    const name = `photo_${Date.now()}.jpg`;
    const file = new File([blob], name, { type: 'image/jpeg' });
    addFiles([file]);
    stopCamera();
  }, 'image/jpeg', 0.92);
}

function stopCamera() {
  stopLiveFrameBroadcast();
  if (cameraStream) {
    cameraStream.getTracks().forEach(t => t.stop());
    cameraStream = null;
  }
  document.getElementById('camera-video').srcObject = null;
  document.getElementById('camera-viewfinder').classList.add('d-none');
}

// Request camera permission proactively when step 2 loads
function requestCameraPermission() {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;
  // Just ask for permission; don't show the stream yet
  navigator.mediaDevices.getUserMedia({ video: true, audio: false })
    .then(stream => stream.getTracks().forEach(t => t.stop()))
    .catch(() => {}); // silently ignore if denied — user can still use Browse Files
}

function addFiles(files) {
  const MAX = 10 * 1024 * 1024; // 10 MB
  let maxFilesReached = false;
  files.forEach(f => {
    if (selectedFiles.length >= MAX_MOBILE_FILES) {
      maxFilesReached = true;
      return;
    }
    if (!f.type || !f.type.startsWith('image/')) {
      alert(`${f.name} is not an image. Only image uploads are allowed.`);
      return;
    }
    if (f.size > MAX) { alert(`${f.name} exceeds 10 MB limit.`); return; }
    if (selectedFiles.find(x => x.name === f.name && x.size === f.size)) return; // skip duplicate
    selectedFiles.push(f);
  });
  if (maxFilesReached) {
    alert(`You can upload up to ${MAX_MOBILE_FILES} images per document.`);
  }
  renderFileList();
  const n2 = document.getElementById('btn-next-2');
  if (n2) n2.disabled = uploadInProgress || selectedFiles.length === 0;
  validateDirectMode();
  // reset input so same file can be re-added if removed
  fileInput.value = '';
  cameraInput.value = '';
}

function removeFile(idx) {
  if (uploadInProgress) return;
  selectedFiles.splice(idx, 1);
  renderFileList();
  const n2 = document.getElementById('btn-next-2');
  if (n2) n2.disabled = uploadInProgress || selectedFiles.length === 0;
  validateDirectMode();
}

function renderFileList() {
  const list = document.getElementById('file-list');
  list.innerHTML = selectedFiles.map((f, i) => {
    const isImg = f.type.startsWith('image/');
    const url   = isImg ? URL.createObjectURL(f) : null;
    const size  = f.size < 1024*1024 ? (f.size/1024).toFixed(1)+' KB' : (f.size/1024/1024).toFixed(1)+' MB';
    const thumb = isImg
      ? `<img src="${url}" alt="">`
      : `<div class="pdf-icon"><i class="fas fa-file-pdf"></i></div>`;
    return `
      <div class="file-preview">
        ${thumb}
        <div class="file-info">
          <div class="name">${escHtml(f.name)}</div>
          <div class="size">${size}</div>
        </div>
        <span class="remove-btn" onclick="removeFile(${i})"><i class="fas fa-times-circle"></i></span>
      </div>`;
  }).join('');
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function compressImageBeforeUpload(file) {
  return new Promise((resolve) => {
    if (!file.type || !file.type.startsWith('image/')) {
      resolve(file);
      return;
    }

    const supportedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    if (!supportedTypes.includes(file.type)) {
      resolve(file);
      return;
    }

    const img = new Image();
    const srcUrl = URL.createObjectURL(file);

    img.onload = () => {
      let width = img.width;
      let height = img.height;
      const maxDimension = 1920;

      if (width > maxDimension || height > maxDimension) {
        const ratio = Math.min(maxDimension / width, maxDimension / height);
        width = Math.round(width * ratio);
        height = Math.round(height * ratio);
      }

      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d');
      if (!ctx) {
        URL.revokeObjectURL(srcUrl);
        resolve(file);
        return;
      }

      ctx.drawImage(img, 0, 0, width, height);
      canvas.toBlob((blob) => {
        URL.revokeObjectURL(srcUrl);
        if (blob && blob.size > 0 && blob.size < file.size) {
          resolve(new File([blob], file.name, { type: file.type, lastModified: file.lastModified }));
          return;
        }
        resolve(file);
      }, file.type, 0.82);
    };

    img.onerror = () => {
      URL.revokeObjectURL(srcUrl);
      resolve(file);
    };

    img.src = srcUrl;
  });
}

// ──────────────────────────────────────────────────────────────
// Step 3 — Upload
// ──────────────────────────────────────────────────────────────
async function startUpload() {
  if (uploadInProgress) return;
  if (selectedFiles.length === 0) {
    showResult(false, 'Please capture at least one image before uploading.');
    return;
  }

  uploadInProgress = true;
  validateDirectMode();
  const stepUploadBtn = document.getElementById('btn-next-2');
  if (stepUploadBtn) stepUploadBtn.disabled = true;

  // In direct mode, hide the combined form and show step-3
  const dm = document.getElementById('direct-mode');
  if (dm) {
    dm.classList.add('d-none');
    document.getElementById('step-3').classList.remove('d-none');
  } else {
    goToStep(3);
  }
  const stepHeader = document.querySelector('#step-3 .card-header');
  if (stepHeader) {
    stepHeader.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Uploading…';
  }
  const resultEl = document.getElementById('upload-result');
  if (resultEl) resultEl.innerHTML = '';

  const progressList = document.getElementById('upload-progress-list');
  progressList.innerHTML = '';

  const objectKeys = [];
  const failedFiles = [];

  for (let i = 0; i < selectedFiles.length; i++) {
    const file = selectedFiles[i];
    let fileToUpload = file;
    const rowId = `file-progress-${i}`;

    progressList.insertAdjacentHTML('beforeend', `
      <div id="${rowId}" class="mb-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span class="small fw-semibold text-truncate" style="max-width:75%">${escHtml(file.name)}</span>
          <span class="small text-muted" id="${rowId}-pct">0%</span>
        </div>
        <div class="progress"><div class="progress-bar" id="${rowId}-bar" style="width:0%"></div></div>
        <div class="small text-muted mt-1" id="${rowId}-status">Getting upload URL…</div>
      </div>`);

    document.getElementById(`${rowId}-status`).textContent = 'Optimizing image…';
    fileToUpload = await compressImageBeforeUpload(file);
    document.getElementById(`${rowId}-status`).textContent = 'Getting upload URL…';

    // 1. Request presigned URL from PHP
    let presignedUrl, objectKey;
    try {
      ({ presignedUrl, objectKey } = await requestPresignedUrlWithRetry(fileToUpload, rowId));
    } catch (err) {
      setFileStatus(rowId, 'danger', `Error: ${err.message}`);
      failedFiles.push(file.name);
      continue;
    }

    // 2. Upload directly to MinIO with XHR (for progress)
    try {
      await uploadToMinioWithRetry(fileToUpload, presignedUrl, rowId);
      objectKeys.push(objectKey);
      setFileStatus(rowId, 'success', 'Uploaded ✓');
    } catch (err) {
      setFileStatus(rowId, 'danger', `Upload failed: ${err.message}`);
      failedFiles.push(file.name);
    }
  }

  if (failedFiles.length > 0 || objectKeys.length === 0) {
    uploadInProgress = false;
    const failedCount = failedFiles.length || selectedFiles.length;
    showResult(false, `${failedCount} of ${selectedFiles.length} image(s) failed to upload. Check the red rows and tap Go Back to retry.`);
    return;
  }

  // 3. Confirm upload with PHP (save now or defer to desktop modal flow)
  const meta = collectMeta();
  try {
    const res = await fetch('confirm_upload.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        doc_type: selectedType,
        object_keys: objectKeys,
        session_id: mobileSession,
        defer_to_desktop: deferToDesktop,
        ...meta
      }),
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.error || `DB save failed (HTTP ${res.status})`);
    await notifyDesktopUploadComplete({
      title: meta.title || 'Document',
      uploaded_by: 'mobile',
      result_id: data.id || null,
      object_keys: Array.isArray(data.object_keys) ? data.object_keys : objectKeys,
      image_urls: Array.isArray(data.image_urls) ? data.image_urls : [],
      deferred_to_desktop: !!data.deferred_to_desktop,
    });
    uploadInProgress = false;
    showResult(true, data);
  } catch (err) {
    uploadInProgress = false;
    showResult(false, err.message);
  }
}

async function requestPresignedUrlWithRetry(fileToUpload, rowId, maxAttempts = 3) {
  let lastError = null;

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    if (attempt > 1) {
      const statusEl = document.getElementById(`${rowId}-status`);
      if (statusEl) statusEl.textContent = `Retrying URL request (${attempt}/${maxAttempts})…`;
    }

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 15000);
    try {
      const res = await fetch('generate_presigned_url.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        signal: controller.signal,
        body: JSON.stringify({
          doc_type:     selectedType,
          file_name:    fileToUpload.name,
          content_type: fileToUpload.type || 'application/octet-stream',
          session_id:   mobileSession,
        }),
      });

      const raw = await res.text();
      let data = {};
      if (raw) {
        try {
          data = JSON.parse(raw);
        } catch (parseErr) {
          throw new Error(`Server returned invalid JSON (HTTP ${res.status})`);
        }
      }

      if (!res.ok || !data.success) {
        throw new Error(data.error || `Failed to get presigned URL (HTTP ${res.status})`);
      }

      return {
        presignedUrl: data.presigned_url,
        objectKey: data.object_key,
      };
    } catch (err) {
      lastError = err.name === 'AbortError' ? new Error('Request timed out') : err;
      if (attempt < maxAttempts) {
        await new Promise(resolve => setTimeout(resolve, 350 * attempt));
      }
    } finally {
      clearTimeout(timeoutId);
    }
  }

  throw lastError || new Error('Failed to get presigned URL');
}

async function uploadToMinioWithRetry(file, presignedUrl, rowId, maxAttempts = 2) {
  let lastError = null;

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    if (attempt > 1) {
      const statusEl = document.getElementById(`${rowId}-status`);
      if (statusEl) statusEl.textContent = `Retrying upload (${attempt}/${maxAttempts})…`;
    }

    try {
      await uploadToMinio(file, presignedUrl, rowId);
      return;
    } catch (err) {
      lastError = err;
      if (attempt < maxAttempts) {
        await new Promise(resolve => setTimeout(resolve, 500 * attempt));
      }
    }
  }

  throw lastError || new Error('Upload failed');
}

function uploadToMinio(file, presignedUrl, rowId) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', presignedUrl);
    xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
    xhr.timeout = 120000;

    xhr.upload.onprogress = e => {
      if (e.lengthComputable) {
        const pct = Math.round((e.loaded / e.total) * 100);
        document.getElementById(`${rowId}-bar`).style.width = pct + '%';
        document.getElementById(`${rowId}-pct`).textContent  = pct + '%';
        document.getElementById(`${rowId}-status`).textContent = 'Uploading…';
      }
    };
    xhr.onload  = () => {
      if (xhr.status >= 200 && xhr.status < 300) resolve();
      else reject(new Error(`HTTP ${xhr.status}`));
    };
    xhr.onerror = () => reject(new Error('Network error'));
    xhr.ontimeout = () => reject(new Error('Upload timed out'));
    xhr.onabort = () => reject(new Error('Upload canceled'));
    xhr.send(file);
  });
}

function setFileStatus(rowId, type, msg) {
  const bar = document.getElementById(`${rowId}-bar`);
  if (bar) {
    bar.classList.add(type === 'success' ? 'bg-success' : 'bg-danger');
    bar.style.width = '100%';
  }
  const status = document.getElementById(`${rowId}-status`);
  if (status) {
    status.textContent = msg;
    status.className = `small mt-1 text-${type === 'success' ? 'success' : 'danger'}`;
  }
}

function goBackAfterUploadFailure() {
  uploadInProgress = false;
  const step3 = document.getElementById('step-3');
  if (step3) step3.classList.add('d-none');

  const directMode = document.getElementById('direct-mode');
  if (directMode) {
    directMode.classList.remove('d-none');
    validateDirectMode();
    return;
  }

  goToStep(2);
  const stepUploadBtn = document.getElementById('btn-next-2');
  if (stepUploadBtn) stepUploadBtn.disabled = selectedFiles.length === 0;
}

function collectMeta() {
  if (!requiresMobileMeta) return {};
  const fields = {};
  const metaRootSelector = document.getElementById('btn-direct-upload')
    ? '#meta-fields-direct'
    : '#meta-fields-step1';
  document.querySelectorAll(`${metaRootSelector} input, ${metaRootSelector} textarea, ${metaRootSelector} select`).forEach(el => {
    if (el.name) fields[el.name] = el.value;
  });
  return fields;
}

function showResult(success, data) {
  const resultEl = document.getElementById('upload-result');

  // Update step 3 card header
  const header = document.querySelector('#step-3 .card-header');
  if (header) {
    header.innerHTML = success
      ? '<i class="fas fa-check-circle text-success me-2"></i>Upload Complete'
      : '<i class="fas fa-exclamation-circle text-danger me-2"></i>Upload Failed';
  }

  const docPages = {
    resolutions: 'resolutions.php',
    minutes:     'minutes_of_meeting.php',
    ordinances:  'ordinances.php',
  };

  if (success) {
    const deferred = !!(data && data.deferred_to_desktop);
    resultEl.innerHTML = deferred
      ? `
      <div class="card">
        <div class="card-body success-card">
          <div class="check"><i class="fas fa-check-circle"></i></div>
          <h4 class="fw-bold">Images Sent to Desktop!</h4>
          <p class="text-muted">Return to your computer. The add-document form will auto-load these images and run OCR.</p>
          <button class="btn btn-outline-secondary w-100 py-2" onclick="location.reload()">
            <i class="fas fa-camera me-2"></i>Capture Another Set
          </button>
        </div>
      </div>`
      : `
      <div class="card">
        <div class="card-body success-card">
          <div class="check"><i class="fas fa-check-circle"></i></div>
          <h4 class="fw-bold">Upload Successful!</h4>
          <p class="text-muted">Your document has been saved and is now available in the system.</p>
          <a href="${docPages[selectedType]}" class="btn btn-primary w-100 py-2 mb-2">
            <i class="fas fa-eye me-2"></i>View in Admin
          </a>
          <button class="btn btn-outline-secondary w-100 py-2" onclick="location.reload()">
            <i class="fas fa-plus me-2"></i>Upload Another
          </button>
        </div>
      </div>`;
  } else {
    resultEl.innerHTML = `
      <div class="alert alert-danger mt-3">
        <strong>Error:</strong> ${escHtml(typeof data === 'string' ? data : JSON.stringify(data))}
      </div>
      <button class="btn btn-outline-secondary w-100" onclick="goBackAfterUploadFailure()">
        <i class="fas fa-arrow-left me-1"></i> Go Back
      </button>`;
  }
}

window.addEventListener('beforeunload', stopLiveFrameBroadcast);
</script>
</body>
</html>
