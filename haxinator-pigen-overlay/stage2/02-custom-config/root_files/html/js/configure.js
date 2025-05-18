document.addEventListener('DOMContentLoaded', function() {
    // Initialize Apply Configuration button
    document.getElementById('applyConfigBtn').addEventListener('click', function() {
        const btn = this;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Applying...';

        fetch('/api/apply_config.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            let resultHtml = '';
            
            if (data.success) {
                resultHtml += '<div class="alert alert-success mb-3"><i class="bi bi-check-circle me-2"></i>Configuration applied successfully</div>';
                
                if (data.connections && data.connections.length > 0) {
                    resultHtml += '<h6 class="mb-2">Configured Connections:</h6>';
                    resultHtml += '<ul class="list-unstyled mb-3">';
                    data.connections.forEach(conn => {
                        resultHtml += `<li><i class="bi bi-check me-2 text-success"></i>${conn}</li>`;
                    });
                    resultHtml += '</ul>';
                }
                
                if (data.warnings && data.warnings.length > 0) {
                    resultHtml += '<h6 class="mb-2">Warnings:</h6>';
                    resultHtml += '<ul class="list-unstyled mb-0">';
                    data.warnings.forEach(warning => {
                        resultHtml += `<li><i class="bi bi-exclamation-triangle me-2 text-warning"></i>${warning}</li>`;
                    });
                    resultHtml += '</ul>';
                }
            } else {
                resultHtml = `<div class="alert alert-danger mb-0">
                    <i class="bi bi-exclamation-circle me-2"></i>
                    ${data.error || 'Failed to apply configuration'}
                </div>`;
            }
            
            document.getElementById('configResults').innerHTML = resultHtml;
            new bootstrap.Modal(document.getElementById('resultsModal')).show();
        })
        .catch(error => {
            document.getElementById('configResults').innerHTML = `
                <div class="alert alert-danger mb-0">
                    <i class="bi bi-exclamation-circle me-2"></i>
                    ${error.message || 'Failed to apply configuration'}
                </div>
            `;
            new bootstrap.Modal(document.getElementById('resultsModal')).show();
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    });

    // Initialize MAC form submission handlers
    document.querySelectorAll('.mac-toggle-form').forEach(function(form) {
        const toggle = form.querySelector('input[type="checkbox"]');
        
        // Handle checkbox change
        toggle.addEventListener('change', function() {
            showMacSpinner();
            form.submit();
        });
        
        // Also handle direct clicks on the toggle slider
        form.querySelector('.mac-toggle-slider').addEventListener('click', function(e) {
            // Prevent immediate propagation to avoid double submission
            e.preventDefault();
            e.stopPropagation();
            // Toggle the checkbox state manually
            toggle.checked = !toggle.checked;
            // Show spinner and submit
            showMacSpinner();
            form.submit();
        });
    });
    
    function showMacSpinner() {
        document.getElementById('macSpinner').style.display = 'flex';
    }
    
    function initializeUploadZone(zone) {
        const input = zone.querySelector('.file-input');
        const content = zone.querySelector('.upload-content');
        const progress = zone.querySelector('.upload-progress');
        const progressBar = progress.querySelector('.progress-bar');
        const status = progress.querySelector('.upload-status');
        const type = zone.dataset.type;

        // Click to select file
        zone.addEventListener('click', (e) => {
            if (e.target !== input) {
                input.click();
            }
        });

        // Drag and drop handlers
        zone.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.add('drag-over');
        });

        zone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.remove('drag-over');
        });

        zone.addEventListener('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.remove('drag-over');
            handleFiles(e.dataTransfer.files);
        });

        // File input change handler
        input.addEventListener('change', () => {
            handleFiles(input.files);
        });

        function handleFiles(files) {
            if (files.length === 0) return;
            
            const file = files[0];
            uploadFile(file);
        }

        function uploadFile(file) {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('type', type);

            // Show progress UI
            content.style.display = 'none';
            progress.style.display = 'block';
            progressBar.style.width = '0%';
            status.textContent = 'Uploading...';
            status.className = 'upload-status mt-2';

            fetch('/api/upload.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json().then(data => ({
                ok: response.ok,
                data: data
            })))
            .then(({ ok, data }) => {
                if (!ok) {
                    throw new Error(data.error || 'Upload failed');
                }
                if (data.error) {
                    throw new Error(data.error);
                }
                progressBar.style.width = '100%';
                status.textContent = 'Upload successful!';
                status.classList.add('upload-success');
                setTimeout(() => {
                    content.style.display = 'block';
                    progress.style.display = 'none';
                    input.value = ''; // Reset input
                }, 2000);
            })
            .catch(error => {
                progressBar.style.width = '100%';
                progressBar.classList.add('bg-danger');
                status.textContent = 'Error: ' + error.message;
                status.classList.add('upload-error');
                setTimeout(() => {
                    content.style.display = 'block';
                    progress.style.display = 'none';
                    progressBar.classList.remove('bg-danger');
                    input.value = ''; // Reset input
                }, 3000);
            });
        }
    }

    // Initialize all upload zones
    document.querySelectorAll('.upload-zone').forEach(initializeUploadZone);

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Handle tab navigation from Load Secrets button
    document.querySelector('a[href="#envSecrets"]').addEventListener('click', function(e) {
        e.preventDefault();
        // Switch to upload tab
        document.querySelector('#upload-tab').click();
        // Scroll to env secrets section after a short delay
        setTimeout(function() {
            document.querySelector('#envSecrets').scrollIntoView({ behavior: 'smooth' });
        }, 150);
    });
}); 