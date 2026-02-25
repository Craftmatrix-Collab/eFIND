import { Editor } from 'https://esm.sh/@tiptap/core@2.11.5?target=es2022';
import StarterKit from 'https://esm.sh/@tiptap/starter-kit@2.11.5?target=es2022';

const PAGE_LABELS = {
  'ordinances.php': 'ordinance',
  'resolutions.php': 'resolution',
  'minutes_of_meeting.php': 'minutes',
};

const editorRegistry = new Map();

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
