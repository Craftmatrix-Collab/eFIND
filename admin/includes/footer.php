<!-- Mobile-upload live notification toast -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:9999" id="upload-toast-container"></div>
<script>
(function () {
  if (!window.EventSource) return; // browser doesn't support SSE
  // Only connect on the three document-management pages
  const page = location.pathname.split('/').pop();
  if (!['resolutions.php','minutes_of_meeting.php','ordinances.php','dashboard.php'].includes(page)) return;

  function connectSSE() {
    const src = new EventSource('upload_events.php');
    src.addEventListener('new_upload', function (e) {
      const d = JSON.parse(e.data);
      showUploadToast(d);
    });
    src.addEventListener('reconnect', function () {
      src.close();
      setTimeout(connectSSE, 2000);
    });
    src.onerror = function () {
      src.close();
      setTimeout(connectSSE, 10000); // retry in 10 s on error
    };
  }
  connectSSE();

  function showUploadToast(d) {
    const id  = 'toast-' + Date.now();
    const html = `
      <div id="${id}" class="toast align-items-center text-bg-primary border-0 show" role="alert">
        <div class="d-flex">
          <div class="toast-body">
            <strong>📄 New ${escH(d.label)} uploaded</strong><br>
            <span class="small">${escH(d.title)}</span> by <em>${escH(d.uploaded_by)}</em><br>
            <a href="${escH(d.page)}" class="text-white fw-semibold small">View now →</a>
          </div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto"
                  onclick="document.getElementById('${id}').remove()"></button>
        </div>
      </div>`;
    const container = document.getElementById('upload-toast-container');
    if (container) {
      container.insertAdjacentHTML('beforeend', html);
      setTimeout(() => { const t = document.getElementById(id); if (t) t.remove(); }, 8000);
    }
  }

  function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
})();
</script>

<footer class="bg-darkblue text-white py-3" style="background-color: #002147; margin-left: 250px;">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-6 mb-2"> 
                <small><i class="fas fa-phone me-2"></i> <a href="tel:+63781234567" class="text-white text-decoration-none">09161691871</a></small> |
                <small><i class="fas fa-envelope me-2"></i> <a href="mailto:poblacionsouth.solano@gmail.com" class="text-white text-decoration-none">poblacionsouth.solano@gmail.com</a></small> <br>
                <small><i class="fab fa-facebook-f me-2"></i><a href="https://www.facebook.com/profile.php?id=61574579927079" class="text-white text-decoration-none" target="_blank">Barangay Poblacion South, Solano</a></small>
            </div>
            <div class="col-md-6 text-md-end d-flex flex-column justify-content-center">
                <small class="mb-1">&copy; <?= date('Y') ?> Barangay Poblacion South. All rights reserved.</small>
                <small>Developed by PBD</small>
            </div>
        </div>
    </div>
</footer>
