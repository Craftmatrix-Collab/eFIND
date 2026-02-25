import { Editor } from 'https://esm.sh/@tiptap/core@2.11.5?target=es2022';
import StarterKit from 'https://esm.sh/@tiptap/starter-kit@2.11.5?target=es2022';

const PAGE_LABELS = {
  'ordinances.php': 'ordinance',
  'resolutions.php': 'resolution',
  'minutes_of_meeting.php': 'minutes',
};

const editorRegistry = new Map();
let latestDuplicateComparison = null;

function ensureEditorStyles() {
  if (document.getElementById('efind-tiptap-style')) return;
  const style = document.createElement('style');
  style.id = 'efind-tiptap-style';
  style.textContent = `
    .efind-tiptap-wrapper { border: 1px solid #ced4da; border-radius: .375rem; background: #fff; }
    .efind-tiptap-toolbar { padding: .5rem; border-bottom: 1px solid #e9ecef; background: #f8f9fa; border-radius: .375rem .375rem 0 0; }
    .efind-tiptap-toolbar .btn.active { background: #0d6efd; border-color: #0d6efd; color: #fff; }
    .efind-tiptap-editor .ProseMirror {
      min-height: 180px;
      padding: .75rem;
      outline: none;
      white-space: pre-wrap;
      word-break: break-word;
    }
    .efind-tiptap-editor .ProseMirror p { margin: 0 0 .75rem; }
    .efind-tiptap-editor .ProseMirror p:last-child { margin-bottom: 0; }
    textarea.field-highlight + .efind-tiptap-wrapper .efind-tiptap-editor {
      box-shadow: 0 0 0 .15rem rgba(13, 110, 253, .18);
    }
    .efind-compare-preview {
      min-height: 240px;
      max-height: 320px;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 1px solid #dee2e6;
      border-radius: .375rem;
      background: #f8f9fa;
      overflow: hidden;
      color: #6c757d;
      text-align: center;
      padding: .75rem;
    }
    .efind-compare-preview img,
    .efind-compare-preview iframe,
    .efind-compare-preview object {
      width: 100%;
      max-height: 318px;
      border: 0;
      border-radius: .25rem;
      object-fit: contain;
      background: #fff;
    }
    .efind-compare-meta {
      margin: .75rem 0 0;
      padding-left: 1rem;
      font-size: .875rem;
    }
  `;
  document.head.appendChild(style);
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function plainTextToHtml(text) {
  const normalized = String(text || '').replace(/\r\n/g, '\n').trim();
  if (!normalized) return '<p></p>';

  return normalized
    .split(/\n{2,}/)
    .map((paragraph) => `<p>${escapeHtml(paragraph).replace(/\n/g, '<br>')}</p>`)
    .join('');
}

function getEditorText(editor) {
  return editor.getText({ blockSeparator: '\n\n' }).trim();
}

function slugify(value) {
  const cleaned = String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
  return cleaned || 'document';
}

function getFileBaseName(form) {
  const pageName = window.location.pathname.split('/').pop() || '';
  const fallbackLabel = PAGE_LABELS[pageName] || 'document';
  const titleInput = form ? form.querySelector('input[name="title"]') : null;
  const title = titleInput ? titleInput.value : '';
  const datePart = new Date().toISOString().slice(0, 10);
  return `${slugify(title || fallbackLabel)}-${datePart}`;
}

function downloadBlob(blob, fileName) {
  const link = document.createElement('a');
  const objectUrl = URL.createObjectURL(blob);
  link.href = objectUrl;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}

function exportEditorToPdf(editor, form) {
  const jsPDFCtor = window.jspdf && window.jspdf.jsPDF;
  if (!jsPDFCtor) {
    alert('PDF export is unavailable right now.');
    return;
  }

  const pdf = new jsPDFCtor({ unit: 'pt', format: 'a4' });
  const margin = 40;
  const usableWidth = pdf.internal.pageSize.getWidth() - (margin * 2);
  const usableHeight = pdf.internal.pageSize.getHeight() - (margin * 2);
  const lineHeight = 16;
  const text = getEditorText(editor) || ' ';
  const lines = pdf.splitTextToSize(text, usableWidth);
  let y = margin;

  lines.forEach((line) => {
    if (y > margin + usableHeight) {
      pdf.addPage();
      y = margin;
    }
    pdf.text(line, margin, y);
    y += lineHeight;
  });

  pdf.save(`${getFileBaseName(form)}.pdf`);
}

function exportEditorToDocx(editor, form) {
  const docxFactory = window.htmlDocx;
  if (!docxFactory || typeof docxFactory.asBlob !== 'function') {
    alert('DOCX export is unavailable right now.');
    return;
  }

  const html = `<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>${editor.getHTML()}</body></html>`;
  const blob = docxFactory.asBlob(html);
  downloadBlob(blob, `${getFileBaseName(form)}.docx`);
}

window.efindComposerOcr = async function (source, options = {}) {
  const { onProgress, documentType = 'document' } = options;
  const emitProgress = (percent, message) => {
    if (typeof onProgress === 'function') {
      onProgress({ percent, message });
    }
  };

  let file;
  let imageUrl = '';
  if (source instanceof File) {
    file = source;
  } else if (source instanceof Blob) {
    file = new File([source], `ocr_${Date.now()}.jpg`, { type: source.type || 'image/jpeg' });
  } else if (typeof source === 'string' && source.trim() !== '') {
    const rawUrl = source.trim();
    try {
      imageUrl = new URL(rawUrl, window.location.href).href;
    } catch (error) {
      throw new Error('Invalid OCR image URL.');
    }
  } else {
    throw new Error('OCR source is required.');
  }

  emitProgress(35, 'Uploading image to server OCR...');
  const formData = new FormData();
  if (file) {
    formData.append('file', file);
  } else {
    formData.append('image_url', imageUrl);
  }
  formData.append('document_type', String(documentType || 'document'));

  const response = await fetch('composer_tesseract_ocr.php', {
    method: 'POST',
    body: formData,
  });

  let result = null;
  try {
    result = await response.json();
  } catch (error) {
    throw new Error(`Invalid OCR response (HTTP ${response.status})`);
  }

  if (!response.ok || !result || !result.success) {
    const message = result && result.error ? result.error : `OCR failed (HTTP ${response.status})`;
    throw new Error(message);
  }

  emitProgress(100, 'OCR complete.');
  return {
    text: typeof result.text === 'string' ? result.text : '',
    confidence: typeof result.confidence === 'number' ? result.confidence : null,
  };
};

function getDuplicateConfirmModal() {
  let modal = document.getElementById('efindDuplicateImageModal');
  if (modal) return modal;

  modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'efindDuplicateImageModal';
  modal.tabIndex = -1;
  modal.setAttribute('aria-hidden', 'true');
  modal.innerHTML = `
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title"><i class="fas fa-triangle-exclamation me-2"></i>Duplicate Image</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-0" data-duplicate-message>This image is already upploaded.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-duplicate-cancel>Cancel</button>
          <button type="button" class="btn btn-warning text-dark" data-duplicate-proceed>Proceed anyway</button>
        </div>
      </div>
    </div>
  `;

  document.body.appendChild(modal);
  return modal;
}

window.efindConfirmDuplicateImage = function (message) {
  const text = String(message || 'This image is already upploaded.');
  if (!window.bootstrap || typeof window.bootstrap.Modal !== 'function') {
    return Promise.resolve(window.confirm(text));
  }

  const modalEl = getDuplicateConfirmModal();
  const messageEl = modalEl.querySelector('[data-duplicate-message]');
  const cancelBtn = modalEl.querySelector('[data-duplicate-cancel]');
  const proceedBtn = modalEl.querySelector('[data-duplicate-proceed]');
  if (messageEl) {
    messageEl.textContent = text;
  }

  return new Promise((resolve) => {
    const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl, {
      backdrop: 'static',
      keyboard: false,
    });

    let settled = false;
    const cleanup = () => {
      if (cancelBtn) cancelBtn.removeEventListener('click', onCancel);
      if (proceedBtn) proceedBtn.removeEventListener('click', onProceed);
      modalEl.removeEventListener('hidden.bs.modal', onHidden);
    };
    const finish = (decision) => {
      if (settled) return;
      settled = true;
      cleanup();
      resolve(decision);
    };
    const onCancel = () => {
      modal.hide();
      finish(false);
    };
    const onProceed = () => {
      modal.hide();
      finish(true);
    };
    const onHidden = () => {
      finish(false);
    };

    if (cancelBtn) cancelBtn.addEventListener('click', onCancel);
    if (proceedBtn) proceedBtn.addEventListener('click', onProceed);
    modalEl.addEventListener('hidden.bs.modal', onHidden);
    modal.show();
  });
};

function formatBytes(value) {
  const bytes = Number(value) || 0;
  if (bytes <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  const index = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  const amount = bytes / (1024 ** index);
  return `${amount.toFixed(index === 0 ? 0 : 2)} ${units[index]}`;
}

function getFileExtension(value) {
  const source = String(value || '').split('?')[0].split('#')[0];
  const token = source.includes('.') ? source.split('.').pop() : '';
  return String(token || '').toLowerCase();
}

function toAbsoluteAssetUrl(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';
  try {
    return new URL(raw, window.location.href).href;
  } catch (error) {
    return raw;
  }
}

function setPreviewMessage(container, message) {
  if (!container) return;
  container.innerHTML = `<span>${escapeHtml(message)}</span>`;
}

function setPreviewFromFile(container, file, modalEl) {
  if (!container || !(file instanceof File)) {
    setPreviewMessage(container, 'No duplicate file selected.');
    return '';
  }

  const previousObjectUrl = modalEl ? String(modalEl.dataset.compareDuplicateObjectUrl || '') : '';
  if (previousObjectUrl) {
    URL.revokeObjectURL(previousObjectUrl);
    if (modalEl) {
      modalEl.dataset.compareDuplicateObjectUrl = '';
    }
  }

  const extension = getFileExtension(file.name);
  const objectUrl = URL.createObjectURL(file);
  if (modalEl) {
    modalEl.dataset.compareDuplicateObjectUrl = objectUrl;
  }
  if (file.type.startsWith('image/')) {
    container.innerHTML = `<img src="${objectUrl}" alt="Duplicate preview">`;
    return objectUrl;
  }
  if (file.type === 'application/pdf' || extension === 'pdf') {
    container.innerHTML = `<iframe src="${objectUrl}" title="Duplicate PDF preview"></iframe>`;
    return objectUrl;
  }
  setPreviewMessage(container, 'Preview unavailable for this file type.');
  return objectUrl;
}

function setPreviewFromUrl(container, sourceUrl) {
  if (!container) return '';
  const absoluteUrl = toAbsoluteAssetUrl(sourceUrl);
  if (!absoluteUrl) {
    setPreviewMessage(container, 'Original file path is unavailable.');
    return '';
  }

  const extension = getFileExtension(absoluteUrl);
  const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tif', 'tiff'];
  if (imageExts.includes(extension)) {
    container.innerHTML = `<img src="${escapeHtml(absoluteUrl)}" alt="Original preview">`;
    return absoluteUrl;
  }
  if (extension === 'pdf') {
    container.innerHTML = `<iframe src="${escapeHtml(absoluteUrl)}" title="Original PDF preview"></iframe>`;
    return absoluteUrl;
  }

  container.innerHTML = `<a href="${escapeHtml(absoluteUrl)}" target="_blank" rel="noopener">Open original document</a>`;
  return absoluteUrl;
}

function renderCompareMetadata(container, rows) {
  if (!container) return;
  const items = Array.isArray(rows) ? rows.filter((row) => row && row.value !== '') : [];
  if (items.length === 0) {
    container.innerHTML = '';
    return;
  }
  container.innerHTML = items
    .map((row) => `<li><strong>${escapeHtml(row.label)}:</strong> ${escapeHtml(row.value)}</li>`)
    .join('');
}

function getDuplicateCompareModal() {
  let modal = document.getElementById('efindDuplicateCompareModal');
  if (modal) return modal;

  modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'efindDuplicateCompareModal';
  modal.tabIndex = -1;
  modal.setAttribute('aria-hidden', 'true');
  modal.innerHTML = `
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title"><i class="fas fa-columns me-2"></i>Duplicate Comparison</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning py-2 mb-3" data-compare-status>No duplicate comparison data yet. Select a duplicate image first.</div>
          <div class="row g-3">
            <div class="col-lg-6">
              <h6 class="mb-2">Duplicate (Selected)</h6>
              <div class="efind-compare-preview" data-compare-duplicate-preview></div>
              <ul class="efind-compare-meta" data-compare-duplicate-meta></ul>
            </div>
            <div class="col-lg-6">
              <h6 class="mb-2">Original (Existing Record)</h6>
              <div class="efind-compare-preview" data-compare-original-preview></div>
              <ul class="efind-compare-meta" data-compare-original-meta></ul>
              <a href="#" class="btn btn-sm btn-outline-primary mt-2 d-none" data-compare-open-original target="_blank" rel="noopener">
                <i class="fas fa-external-link-alt me-1"></i>Open Original
              </a>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  `;
  modal.addEventListener('hidden.bs.modal', () => {
    const objectUrl = String(modal.dataset.compareDuplicateObjectUrl || '');
    if (objectUrl) {
      URL.revokeObjectURL(objectUrl);
      modal.dataset.compareDuplicateObjectUrl = '';
    }
  });

  document.body.appendChild(modal);
  return modal;
}

function renderDuplicateCompareModal(modalEl) {
  if (!modalEl) return;

  const statusEl = modalEl.querySelector('[data-compare-status]');
  const duplicatePreview = modalEl.querySelector('[data-compare-duplicate-preview]');
  const originalPreview = modalEl.querySelector('[data-compare-original-preview]');
  const duplicateMeta = modalEl.querySelector('[data-compare-duplicate-meta]');
  const originalMeta = modalEl.querySelector('[data-compare-original-meta]');
  const openOriginal = modalEl.querySelector('[data-compare-open-original]');

  if (!latestDuplicateComparison || !(latestDuplicateComparison.duplicateFile instanceof File)) {
    if (statusEl) {
      statusEl.className = 'alert alert-warning py-2 mb-3';
      statusEl.textContent = 'No duplicate comparison data yet. Select a duplicate image first.';
    }
    setPreviewMessage(duplicatePreview, 'Duplicate preview unavailable.');
    setPreviewMessage(originalPreview, 'Original preview unavailable.');
    renderCompareMetadata(duplicateMeta, []);
    renderCompareMetadata(originalMeta, []);
    if (openOriginal) {
      openOriginal.classList.add('d-none');
      openOriginal.href = '#';
    }
    return;
  }

  const { duplicateFile, originalMatch, duplicateHash, documentType } = latestDuplicateComparison;
  const originalUrl = setPreviewFromUrl(originalPreview, originalMatch && originalMatch.image_path ? originalMatch.image_path : '');
  setPreviewFromFile(duplicatePreview, duplicateFile, modalEl);

  if (statusEl) {
    statusEl.className = 'alert alert-info py-2 mb-3';
    const originalTitle = originalMatch && originalMatch.title ? originalMatch.title : 'existing record';
    statusEl.textContent = `Comparing duplicate file against ${originalTitle}.`;
  }

  renderCompareMetadata(duplicateMeta, [
    { label: 'Name', value: duplicateFile.name || '' },
    { label: 'Type', value: duplicateFile.type || getFileExtension(duplicateFile.name).toUpperCase() || 'Unknown' },
    { label: 'Size', value: formatBytes(duplicateFile.size) },
    { label: 'Document Type', value: String(documentType || '') },
    { label: 'Hash', value: String(duplicateHash || '') },
  ]);

  renderCompareMetadata(originalMeta, [
    { label: 'Title', value: originalMatch && originalMatch.title ? String(originalMatch.title) : '' },
    { label: 'Record ID', value: originalMatch && originalMatch.document_id ? String(originalMatch.document_id) : '' },
    { label: 'Path', value: originalMatch && originalMatch.image_path ? String(originalMatch.image_path) : '' },
  ]);

  if (openOriginal) {
    if (originalUrl) {
      openOriginal.href = originalUrl;
      openOriginal.classList.remove('d-none');
    } else {
      openOriginal.href = '#';
      openOriginal.classList.add('d-none');
    }
  }
}

window.efindOpenDuplicateComparison = function () {
  if (!window.bootstrap || typeof window.bootstrap.Modal !== 'function') {
    window.alert('Compare modal is unavailable right now.');
    return;
  }
  ensureEditorStyles();
  const modalEl = getDuplicateCompareModal();
  renderDuplicateCompareModal(modalEl);
  const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
  modal.show();
};

function initDuplicateCompareButtons() {
  if (!document.body || document.body.dataset.efindCompareReady === '1') {
    return;
  }
  document.body.dataset.efindCompareReady = '1';
  document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element
      ? event.target.closest('[data-efind-open-compare]')
      : null;
    if (!trigger) return;
    event.preventDefault();
    window.efindOpenDuplicateComparison();
  });
}

window.efindCheckImageDuplicate = async function (file, documentType = 'document') {
  if (!(file instanceof File)) {
    throw new Error('Image file is required for duplicate checking.');
  }

  const formData = new FormData();
  formData.append('file', file);
  formData.append('document_type', String(documentType || 'document'));

  const response = await fetch('check_image_duplicate.php', {
    method: 'POST',
    body: formData,
  });

  const result = await response.json().catch(() => null);
  if (!response.ok || !result || !result.success) {
    const message = (result && result.error) ? result.error : `Duplicate check failed (HTTP ${response.status})`;
    throw new Error(message);
  }

  return {
    isDuplicate: !!result.is_duplicate,
    hash: String(result.hash || ''),
    matches: Array.isArray(result.matches) ? result.matches : [],
  };
};

window.efindHandleDuplicateImageSelection = async function (input, options = {}) {
  const fileInput = input instanceof HTMLInputElement ? input : null;
  const files = fileInput ? Array.from(fileInput.files || []) : [];
  const {
    documentType = 'document',
    allowFieldId = '',
  } = options || {};

  const allowField = allowFieldId ? document.getElementById(allowFieldId) : null;
  if (allowField) {
    allowField.value = '0';
  }

  if (!fileInput || files.length === 0) {
    return { proceed: false, filesRemaining: 0, duplicateCount: 0 };
  }

  const duplicateEntries = [];
  const uniqueFiles = [];
  for (const file of files) {
    const extension = (file.name.split('.').pop() || '').toLowerCase();
    if (!['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tif', 'tiff'].includes(extension)) {
      uniqueFiles.push(file);
      continue;
    }

    try {
      const duplicateResult = await window.efindCheckImageDuplicate(file, documentType);
      if (duplicateResult.isDuplicate) {
        duplicateEntries.push({ file, duplicateResult });
      } else {
        uniqueFiles.push(file);
      }
    } catch (error) {
      console.warn('Image duplicate check skipped:', error);
      uniqueFiles.push(file);
    }
  }

  if (duplicateEntries.length === 0) {
    latestDuplicateComparison = null;
    return { proceed: true, filesRemaining: files.length, duplicateCount: 0 };
  }

  const firstEntry = duplicateEntries[0];
  const firstMatch = firstEntry.duplicateResult.matches[0];
  latestDuplicateComparison = {
    duplicateFile: firstEntry.file,
    originalMatch: firstMatch || null,
    duplicateHash: String(firstEntry.duplicateResult.hash || ''),
    documentType: String(documentType || 'document'),
    updatedAt: Date.now(),
  };
  const duplicateLabel = firstMatch && firstMatch.title ? ` (matches: ${firstMatch.title})` : '';
  const proceedAnyway = await window.efindConfirmDuplicateImage(
    `This image is already upploaded${duplicateLabel}.`
  );

  if (!proceedAnyway) {
    const retainedFiles = uniqueFiles;
    if (typeof DataTransfer !== 'undefined') {
      const dt = new DataTransfer();
      retainedFiles.forEach((file) => dt.items.add(file));
      fileInput.files = dt.files;
    } else {
      fileInput.value = '';
    }
    if (allowField) {
      allowField.value = '0';
    }
    return {
      proceed: retainedFiles.length > 0,
      filesRemaining: retainedFiles.length,
      duplicateCount: duplicateEntries.length,
      cancelled: true,
    };
  }

  if (allowField) {
    allowField.value = '1';
  }
  return {
    proceed: true,
    filesRemaining: files.length,
    duplicateCount: duplicateEntries.length,
    proceededAnyway: true,
  };
};

window.efindFinalizeOcrMarkdown = async function (text, documentType = 'document') {
  const rawText = String(text || '').trim();
  if (!rawText) return null;

  try {
    const response = await fetch('finalize_ocr_markdown.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        text: rawText,
        document_type: documentType,
      }),
    });

    const result = await response.json();
    if (!response.ok || !result.success || typeof result.markdown !== 'string') {
      const message = (result && result.error) ? result.error : `HTTP ${response.status}`;
      console.warn('Gemini OCR finalization skipped:', message);
      return null;
    }

    return result.markdown.trim() || null;
  } catch (error) {
    console.warn('Gemini OCR finalization failed:', error);
    return null;
  }
};

function createToolbarButton(config) {
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-sm btn-outline-secondary';
  btn.dataset.ttAction = config.action;
  btn.innerHTML = config.label;
  btn.title = config.title;
  return btn;
}

function initTiptapForTextarea(textarea) {
  if (!textarea || editorRegistry.has(textarea.id)) return;
  ensureEditorStyles();

  const wrapper = document.createElement('div');
  wrapper.className = 'efind-tiptap-wrapper';

  const toolbar = document.createElement('div');
  toolbar.className = 'efind-tiptap-toolbar d-flex flex-wrap gap-1';

  const toolbarButtons = [
    { action: 'bold', label: '<i class="fas fa-bold"></i>', title: 'Bold' },
    { action: 'italic', label: '<i class="fas fa-italic"></i>', title: 'Italic' },
    { action: 'strike', label: '<i class="fas fa-strikethrough"></i>', title: 'Strike' },
    { action: 'heading-1', label: 'H1', title: 'Heading 1' },
    { action: 'heading-2', label: 'H2', title: 'Heading 2' },
    { action: 'bullet-list', label: '<i class="fas fa-list-ul"></i>', title: 'Bullet list' },
    { action: 'ordered-list', label: '<i class="fas fa-list-ol"></i>', title: 'Numbered list' },
    { action: 'blockquote', label: '<i class="fas fa-quote-right"></i>', title: 'Blockquote' },
    { action: 'undo', label: '<i class="fas fa-undo"></i>', title: 'Undo' },
    { action: 'redo', label: '<i class="fas fa-redo"></i>', title: 'Redo' },
    { action: 'clear', label: '<i class="fas fa-eraser"></i>', title: 'Clear formatting' },
  ];
  toolbarButtons.forEach((config) => toolbar.appendChild(createToolbarButton(config)));

  const editorEl = document.createElement('div');
  editorEl.className = 'efind-tiptap-editor';

  const exportActions = document.createElement('div');
  exportActions.className = 'd-flex justify-content-end flex-wrap gap-2 p-2 pt-0';
  exportActions.innerHTML = `
    <button type="button" class="btn btn-sm btn-outline-danger" data-tt-download="pdf">
      <i class="fas fa-file-pdf me-1"></i>Download PDF
    </button>
    <button type="button" class="btn btn-sm btn-outline-primary" data-tt-download="docx">
      <i class="fas fa-file-word me-1"></i>Download DOCX
    </button>
  `;

  wrapper.appendChild(toolbar);
  wrapper.appendChild(editorEl);
  wrapper.appendChild(exportActions);
  textarea.insertAdjacentElement('afterend', wrapper);
  textarea.classList.add('d-none');

  let suppressSync = false;
  let lastTextareaValue = String(textarea.value || '');
  const editor = new Editor({
    element: editorEl,
    extensions: [StarterKit],
    content: plainTextToHtml(lastTextareaValue),
    onUpdate: ({ editor: instance }) => {
      if (suppressSync) return;
      const nextText = getEditorText(instance);
      if (textarea.value !== nextText) {
        textarea.value = nextText;
      }
      lastTextareaValue = textarea.value;
      updateToolbarActiveState();
    },
  });

  const runToolbarAction = (action) => {
    const chain = editor.chain().focus();
    switch (action) {
      case 'bold': chain.toggleBold().run(); break;
      case 'italic': chain.toggleItalic().run(); break;
      case 'strike': chain.toggleStrike().run(); break;
      case 'heading-1': chain.toggleHeading({ level: 1 }).run(); break;
      case 'heading-2': chain.toggleHeading({ level: 2 }).run(); break;
      case 'bullet-list': chain.toggleBulletList().run(); break;
      case 'ordered-list': chain.toggleOrderedList().run(); break;
      case 'blockquote': chain.toggleBlockquote().run(); break;
      case 'undo': chain.undo().run(); break;
      case 'redo': chain.redo().run(); break;
      case 'clear': chain.clearNodes().unsetAllMarks().run(); break;
      default: break;
    }
    updateToolbarActiveState();
  };

  const updateToolbarActiveState = () => {
    toolbar.querySelectorAll('[data-tt-action]').forEach((button) => {
      const action = button.dataset.ttAction;
      const active = (
        (action === 'bold' && editor.isActive('bold')) ||
        (action === 'italic' && editor.isActive('italic')) ||
        (action === 'strike' && editor.isActive('strike')) ||
        (action === 'heading-1' && editor.isActive('heading', { level: 1 })) ||
        (action === 'heading-2' && editor.isActive('heading', { level: 2 })) ||
        (action === 'bullet-list' && editor.isActive('bulletList')) ||
        (action === 'ordered-list' && editor.isActive('orderedList')) ||
        (action === 'blockquote' && editor.isActive('blockquote'))
      );
      button.classList.toggle('active', active);
    });
  };

  toolbar.querySelectorAll('[data-tt-action]').forEach((button) => {
    button.addEventListener('click', () => runToolbarAction(button.dataset.ttAction));
  });

  const syncFromTextarea = (force = false) => {
    const nextValue = String(textarea.value || '');
    if (!force && nextValue === lastTextareaValue) return;
    const currentText = getEditorText(editor);
    if (!force && currentText === nextValue.trim()) {
      lastTextareaValue = nextValue;
      return;
    }
    suppressSync = true;
    editor.commands.setContent(plainTextToHtml(nextValue), false);
    suppressSync = false;
    lastTextareaValue = nextValue;
    updateToolbarActiveState();
  };

  textarea.addEventListener('input', () => syncFromTextarea(true));
  textarea.addEventListener('change', () => syncFromTextarea(true));

  const form = textarea.form;
  if (form) {
    form.addEventListener('reset', () => {
      setTimeout(() => {
        textarea.value = '';
        syncFromTextarea(true);
      }, 0);
    });
  }

  const pdfButton = exportActions.querySelector('[data-tt-download="pdf"]');
  const docxButton = exportActions.querySelector('[data-tt-download="docx"]');
  if (pdfButton) {
    pdfButton.addEventListener('click', () => exportEditorToPdf(editor, form));
  }
  if (docxButton) {
    docxButton.addEventListener('click', () => exportEditorToDocx(editor, form));
  }

  editorRegistry.set(textarea.id, { editor, syncFromTextarea });
  updateToolbarActiveState();
}

window.efindSyncTiptapFromTextarea = function (textareaId = 'content') {
  const target = editorRegistry.get(textareaId);
  if (target) {
    target.syncFromTextarea(true);
  }
};

function init() {
  initDuplicateCompareButtons();
  const textarea = document.querySelector(
    '#addOrdinanceForm textarea#content, #addResolutionForm textarea#content, #addMinuteForm textarea#content'
  );
  if (!textarea) return;
  initTiptapForTextarea(textarea);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
