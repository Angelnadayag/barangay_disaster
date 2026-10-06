    </main>
  </div>
</div>

<!-- Reusable Confirmation Modal -->
<div class="modal-overlay" id="confirmationModal" style="z-index: 1200 !important;">
  <div class="modal-dialog" style="max-width:440px;">
    <div class="modal-header">
      <h3 class="modal-title" id="confirmModalTitle">Confirm Action</h3>
      <button class="modal-close-btn" onclick="closeModal('confirmationModal')">&times;</button>
    </div>
    <div class="modal-body">
      <p id="confirmModalMessage" style="font-size:12px;color:var(--color-text);line-height:1.5;">Are you sure you want to proceed with this operation?</p>
    </div>
    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('confirmationModal')">Cancel</button>
      <button type="button" class="btn btn-primary btn-sm" id="confirmModalSubmitBtn">Confirm</button>
    </div>
  </div>
</div>

<!-- Toast Container for Micro-feedback -->
<div class="toast-container" id="toastContainer"></div>

<!-- Core Frontend JS Bundle -->
<script src="<?= BASE_URL ?>/frontend/js/app.js?v=<?= filemtime(__DIR__ . '/../../frontend/js/app.js') ?>"></script>
</body>
</html>
