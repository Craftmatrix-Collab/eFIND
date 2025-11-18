document.addEventListener('DOMContentLoaded', function() {
  // Handle image link clicks
  document.querySelectorAll('.image-link').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const imageSrc = this.getAttribute('data-image-src');
      const modalImage = document.getElementById('modalImage');
      modalImage.src = imageSrc;

      // Show the modal
      const imageModal = new bootstrap.Modal(document.getElementById('imageModal'));
      imageModal.show();
    });
  });
});

document.addEventListener('DOMContentLoaded', function() {
    // Toggle sidebar
    document.getElementById('sidebarToggleTop').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('active');
    });
    
    // Activate Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Prevent dropdown from closing when clicking inside
    document.querySelectorAll('.dropdown-menu').forEach(function(element) {
        element.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
    
    // Handle image preview for announcements
    const imageInput = document.getElementById('image');
    if (imageInput) {
        imageInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const preview = document.getElementById('imagePreview');
                    if (!preview) {
                        const previewDiv = document.createElement('div');
                        previewDiv.className = 'mt-2';
                        previewDiv.id = 'imagePreview';
                        previewDiv.innerHTML = '<img src="' + event.target.result + '" class="img-thumbnail" style="max-height: 150px;">';
                        imageInput.parentNode.appendChild(previewDiv);
                    } else {
                        preview.innerHTML = '<img src="' + event.target.result + '" class="img-thumbnail" style="max-height: 150px;">';
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }
});