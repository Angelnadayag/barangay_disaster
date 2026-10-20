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
      <p id="confirmModalMessage" style="font-size:12px;color:var(--color-text);line-height:1.5;">Are you sure you want
        to proceed with this operation?</p>
    </div>
    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('confirmationModal')">Cancel</button>
      <button type="button" class="btn btn-primary btn-sm" id="confirmModalSubmitBtn">Confirm</button>
    </div>
  </div>
</div>

<!-- Toast Container for Micro-feedback -->
<div class="toast-container" id="toastContainer"></div>

<?php
$dssModalRole = $currentUser['role'] ?? ($user['role'] ?? ($_SESSION['user']['role'] ?? ''));
if ($dssModalRole === 'icdrrmo'):
  ?>
  <!-- ========================================================================= -->
  <!-- Modal: Decision Support System (DSS) Recommender Execution & Pipeline     -->
  <!-- ========================================================================= -->
  <div class="modal-overlay" id="dssExecutionModal" style="z-index: 1300 !important;">
    <div class="modal-dialog" style="max-width: 580px;">

      <!-- STAGE 1: Modality Selection & Setup -->
      <div id="dssStageSetup" style="display: flex; flex-direction: column; height: 100%;">
        <div class="modal-header">
          <div>
            <h3 class="modal-title" style="margin:0;">Decision Support System (DSS)</h3>
            <div class="dss-subtext">NDRRMC &amp; DSWD Resource Allocation Engine</div>
          </div>
          <button type="button" class="modal-close-btn" onclick="closeModal('dssExecutionModal')">&times;</button>
        </div>

        <div class="modal-body" style="padding:16px;max-height:calc(85vh - 120px);overflow-y:auto;">

          <!-- Incident Context -->
          <div class="dss-context">
            <div class="dss-context-head">
              <span class="dss-label">Target Disaster Incident</span>
              <span class="badge badge-neutral" id="dssModalTrackingBadge">REQ-000</span>
            </div>
            <div class="dss-context-grid">
              <div>
                <div class="dss-label">Barangay &amp; Location</div>
                <div class="dss-value" id="dssModalBarangay">Barangay</div>
              </div>
              <div>
                <div class="dss-label">Disaster Profile</div>
                <div class="dss-value" id="dssModalDisaster">Flood (Critical)</div>
              </div>
              <div>
                <div class="dss-label">Affected Families</div>
                <div class="dss-value dss-num" id="dssModalFamilies">0 Families</div>
              </div>
              <div>
                <div class="dss-label">Displaced in Camps</div>
                <div class="dss-value dss-num" id="dssModalDisplaced">0 Families</div>
              </div>
            </div>
          </div>

          <div class="dss-section-title">Select recommendation modality</div>

          <!-- Option 1: Comprehensive Dual Support -->
          <label class="dss-modality-card active" onclick="selectDssModality(this)">
            <input type="radio" name="dss_modality" value="both" checked>
            <div class="dss-modality-content">
              <div class="dss-modality-title-row">
                <span class="dss-modality-title">Comprehensive Dual Support</span>
                <span class="dss-modality-tag">Recommended</span>
              </div>
              <div class="dss-modality-desc">
                Executes dual analysis:
                <ol class="dss-modality-list">
                  <li><strong>Physical Relief Allocation</strong> (food packs, hygiene kits, rescue boats, tents vs.
                    Central Depot inventory)</li>
                  <li><strong>Emergency Financial Aid (Ayuda)</strong> budget estimation under LGU Calamity Fund</li>
                </ol>
              </div>
            </div>
          </label>

          <!-- Option 2: Physical Relief Resources Only -->
          <label class="dss-modality-card" onclick="selectDssModality(this)">
            <input type="radio" name="dss_modality" value="resources">
            <div class="dss-modality-content">
              <div class="dss-modality-title-row">
                <span class="dss-modality-title">Physical Relief Resources Only</span>
              </div>
              <div class="dss-modality-desc">
                Logistics and physical supplies dispatch: family food rations, bottled water, camp hygiene kits, and
                specialized rescue equipment, with depot stock bottleneck detection.
              </div>
            </div>
          </label>

          <!-- Option 3: Emergency Financial Aid (Ayuda) Only -->
          <label class="dss-modality-card" onclick="selectDssModality(this)">
            <input type="radio" name="dss_modality" value="financial_aid">
            <div class="dss-modality-content">
              <div class="dss-modality-title-row">
                <span class="dss-modality-title">Emergency Financial Aid (Ayuda) Only</span>
              </div>
              <div class="dss-modality-desc">
                Direct cash assistance only: calamity grant rates (₱2,000 – ₱5,000 per family) based on DSWD Emergency
                Cash Transfer guidelines and the LGU Quick Response Fund.
              </div>
            </div>
          </label>

          <!-- Financial Aid Parameters (optional customization - active for 'both' or 'financial_aid') -->
          <div class="dss-aid-inputs-box" id="dssAidInputsContainer">
            <div class="dss-aid-head">
              <span class="dss-section-title" style="margin:0;">Financial Aid Parameters &amp; Funding Source</span>
              <span class="badge badge-neutral" id="dssAidActiveBadge">Optional</span>
            </div>

            <label class="dss-check">
              <input type="checkbox" id="dssAidCustomizeToggle" onchange="toggleDssAidCustomize(this.checked)">
              <span>Customize parameters
                <span class="dss-subtext" style="display:block;">Leave unchecked to use the AI-recommended amount,
                  source and scope.</span>
              </span>
            </label>

            <div id="dssAidCustomFields" style="display:none;margin-top:10px;">
              <div class="dss-aid-grid">
                <div>
                  <label class="dss-label" for="dssInputAidAmount">Cash aid amount (₱ / family)</label>
                  <input type="number" id="dssInputAidAmount" name="financial_aid_amount" class="form-control"
                    placeholder="e.g. 4000" min="500" step="100">
                  <div class="dss-subtext">DSWD standard: ₱5k Critical, ₱4k High, ₱3k Moderate</div>
                </div>
                <div>
                  <label class="dss-label" for="dssInputAidSponsor">Funding sponsor</label>
                  <input type="text" id="dssInputAidSponsor" name="financial_aid_sponsor" list="dssSponsorOptions"
                    class="form-control" placeholder="e.g. LGU Calamity Fund (QRF)" value="LGU Calamity Fund (QRF)">
                  <datalist id="dssSponsorOptions">
                    <option value="LGU Calamity Fund (QRF)">
                    <option value="DSWD Quick Response Fund (QRF)">
                    <option value="City Mayor's Calamity Grant">
                    <option value="Office of Civil Defense (OCD)">
                    <option value="Philippine Red Cross Calamity Grant">
                    <option value="Private Donor / NGO Assistance">
                  </datalist>
                  <div class="dss-subtext">Authorized agency, LGU fund, or sponsor</div>
                </div>
              </div>

              <div style="margin-top:10px;">
                <label class="dss-label" for="dssInputAidTarget">Target beneficiary scope</label>
                <select id="dssInputAidTarget" name="financial_aid_target" class="form-control">
                  <option value="displaced" selected>Displaced families in evacuation centers</option>
                  <option value="all_affected">All affected resident families</option>
                </select>
              </div>

              <div class="dss-chip-row">
                <span class="dss-subtext">Quick sponsors:</span>
                <span class="dss-sponsor-chip"
                  onclick="document.getElementById('dssInputAidSponsor').value='LGU Calamity Fund (QRF)'">LGU Calamity
                  Fund</span>
                <span class="dss-sponsor-chip"
                  onclick="document.getElementById('dssInputAidSponsor').value='DSWD Quick Response Fund (QRF)'">DSWD
                  QRF</span>
                <span class="dss-sponsor-chip"
                  onclick="document.getElementById('dssInputAidSponsor').value='City Mayor\'s Calamity Grant'">Mayor's
                  Grant</span>
                <span class="dss-sponsor-chip"
                  onclick="document.getElementById('dssInputAidSponsor').value='Philippine Red Cross'">Red Cross</span>
              </div>
            </div><!-- /dssAidCustomFields -->
          </div>

          <div class="dss-note">
            <strong>Rule-based transparency:</strong> The Decision Tree model traverses DSWD/NDRRMC disaster matrices
            and checks Central Depot stock before producing an explainable confidence rating and a recommendation for
            human review.
          </div>
        </div>

        <div class="modal-footer dss-footer">
          <div class="dss-subtext">Changes take effect immediately upon re-evaluation.</div>
          <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-outline btn-sm" onclick="closeModal('dssExecutionModal')">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="dssRunAnalysisBtn"
              onclick="executeDssRecommender()">Run Recommendation</button>
          </div>
        </div>
      </div>

      <!-- STAGE 2: Evaluation Progress -->
      <div id="dssStageLoading" style="display: none; padding: 24px 20px;">
        <div class="dss-loading-wrapper">
          <h3 class="dss-result-title">Running Decision Tree Engine</h3>
          <p id="dssLoadingStatusText" class="dss-subtext" style="margin-bottom:10px;">
            Traversing decision rules and NDRRMC disaster matrices...
          </p>

          <div class="dss-progress-bar-container">
            <div class="dss-progress-bar-fill" id="dssProgressBar"></div>
          </div>

          <div class="dss-step-pipeline">
            <div class="dss-step-item active" id="dssStep1">
              <span class="dss-step-icon">1</span>
              <span>Classify disaster incident and selected modality</span>
            </div>
            <div class="dss-step-item" id="dssStep2">
              <span class="dss-step-icon">2</span>
              <span>Evaluate severity index and evacuation impact</span>
            </div>
            <div class="dss-step-item" id="dssStep3">
              <span class="dss-step-icon">3</span>
              <span>Query Central Depot stock and bottleneck check</span>
            </div>
            <div class="dss-step-item" id="dssStep4">
              <span class="dss-step-icon">4</span>
              <span>Calculate calamity cash aid (Ayuda) rates</span>
            </div>
            <div class="dss-step-item" id="dssStep5">
              <span class="dss-step-icon">5</span>
              <span>Compute explainable path and confidence score</span>
            </div>
          </div>
        </div>
      </div>

      <!-- STAGE 3: Results -->
      <div id="dssStageResults" style="display: none; padding: 20px 20px 16px;">
        <div style="margin-bottom:14px;">
          <h3 class="dss-result-title">Recommendation Generated</h3>
          <p class="dss-subtext" id="dssResultsSubtitle">DSS algorithm completed with full explainability.</p>
        </div>

        <div class="dss-result-grid">
          <div class="dss-result-card">
            <div class="dss-label">Recommendation Code</div>
            <div id="dssResultRecCode" class="dss-value dss-num" style="font-size:13px;">REC-000</div>
            <div id="dssResultPriority" style="margin-top:4px;"></div>
          </div>
          <div class="dss-result-card">
            <div class="dss-label">Evaluated Confidence</div>
            <div id="dssResultConfidenceWrap" style="margin-top:3px;"></div>
            <div class="dss-subtext" style="margin-top:4px;">Hover badge to view breakdown</div>
          </div>
        </div>

        <div id="dssResultDetailsBox" class="dss-result-details"></div>

        <div class="dss-footer" style="justify-content:flex-end;gap:8px;border-top:1px solid var(--color-border);">
          <button type="button" class="btn btn-outline btn-sm"
            onclick="closeModal('dssExecutionModal'); if (typeof handleDssModalClose === 'function') handleDssModalClose();">
            Close
          </button>
          <a id="dssResultInspectBtn" href="#" class="btn btn-primary btn-sm">View Full Recommendation &rarr;</a>
        </div>
      </div>

    </div>
  </div>
<?php endif; ?>

<?php
$fUser = getCurrentUser();
$fRole = $fUser['role'] ?? '';
$fCurrentPage = basename($_SERVER['PHP_SELF']);
if ($fRole === 'resident' || $fRole === 'responder'):
  ?>
  <!-- Mobile Bottom Navigation Bar (< 900px) -->
  <nav class="mobile-bottom-nav" id="mobileBottomNav" aria-label="Mobile Navigation">
    <a href="<?= BASE_URL ?>/views/<?= $fRole ?>/dashboard.php"
      class="bottom-nav-item <?= $fCurrentPage === 'dashboard.php' ? 'active' : '' ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
        <polyline points="9 22 9 12 15 12 15 22" />
      </svg>
      <span>Home</span>
    </a>
    <a href="<?= BASE_URL ?>/views/<?= $fRole ?>/events.php"
      class="bottom-nav-item <?= in_array($fCurrentPage, ['events.php', 'preparedness.php'], true) ? 'active' : '' ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
        <line x1="16" y1="2" x2="16" y2="6" />
        <line x1="8" y1="2" x2="8" y2="6" />
        <line x1="3" y1="10" x2="21" y2="10" />
      </svg>
      <span>Events</span>
    </a>
    <a href="<?= BASE_URL ?>/views/<?= $fRole ?>/notifications.php"
      class="bottom-nav-item <?= $fCurrentPage === 'notifications.php' ? 'active' : '' ?>" style="position:relative;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
      </svg>
      <span>Notification</span>
      <?php if (!empty($unreadCount) && $unreadCount > 0): ?>
        <span
          style="position:absolute;top:6px;right:calc(50% - 14px);width:7px;height:7px;background:#ef4444;border-radius:50%;border:1px solid #FFF;"></span>
      <?php endif; ?>
    </a>
  </nav>
<?php endif; ?>

<!-- Core Frontend JS Bundle -->
<script src="<?= BASE_URL ?>/frontend/js/app.js?v=<?= filemtime(__DIR__ . '/../../frontend/js/app.js') ?>"></script>
</body>

</html>