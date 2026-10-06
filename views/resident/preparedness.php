<?php
// ============================================================================
// Views (Resident): Community Preparedness Drills & Safety Guidelines
// Schedule of community earthquake/flood drills, family emergency protocols, and survival tips
// ============================================================================

require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/services/Auth.php';
require_once __DIR__ . '/../../backend/services/Helpers.php';

requireRole('resident');
$user = getCurrentUser();
$barangayId = (int)($user['barangay_id'] ?? 1);
$db = getDBConnection();

$drills = $db->query("
    SELECT pa.*, pa.start_datetime AS scheduled_date, pa.venue AS location, b.name AS barangay_name 
    FROM preparedness_activities pa
    LEFT JOIN barangays b ON pa.barangay_id = b.id
    WHERE pa.barangay_id = {$barangayId} OR pa.barangay_id IS NULL
    ORDER BY pa.start_datetime ASC
")->fetchAll();

$pageTitle = "Community Preparedness & Drills";
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-header-title-wrap">
      <h1>Community Drills & Family Preparedness</h1>
      <p class="page-header-desc">
        Participate in local BDRRMC disaster preparedness drills, learn family survival protocols, and safeguard your household.
      </p>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1.2fr 0.8fr;gap:var(--space-4);align-items:start;">
    <!-- Left Column: Upcoming Drills & Activities -->
    <div style="display:flex;flex-direction:column;gap:var(--space-4);">
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h2 class="card-title">Scheduled Community Preparedness Drills</h2>
          <span class="badge badge-primary"><?= count($drills) ?> Scheduled</span>
        </div>
        <div class="card-body" style="padding:0;">
          <?php if (empty($drills)): ?>
            <div style="padding:32px;text-align:center;color:var(--color-text-muted);font-size:12px;">
              No community drills are currently scheduled for your barangay. Check back soon.
            </div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($drills as $drill): 
                $isUpcoming = strtotime($drill['scheduled_date']) >= strtotime('today');
              ?>
                <div style="padding:16px 20px;border-bottom:1px solid var(--color-border);display:flex;gap:16px;align-items:flex-start;">
                  <div style="background:var(--color-surface-subtle);border:1px solid var(--color-border);border-radius:8px;padding:8px 12px;text-align:center;min-width:64px;">
                    <div style="font-size:10px;text-transform:uppercase;color:var(--color-primary);font-weight:700;">
                      <?= date('M', strtotime($drill['scheduled_date'])) ?>
                    </div>
                    <div style="font-size:18px;font-weight:700;color:var(--color-text);line-height:1.1;">
                      <?= date('d', strtotime($drill['scheduled_date'])) ?>
                    </div>
                    <div style="font-size:9px;color:var(--color-text-muted);">
                      <?= date('Y', strtotime($drill['scheduled_date'])) ?>
                    </div>
                  </div>

                  <div style="flex:1;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                      <h3 style="font-size:14px;font-weight:700;color:var(--color-text);margin:0 0 4px 0;">
                        <?= clean($drill['title']) ?>
                      </h3>
                      <?= renderStatusBadge($drill['status']) ?>
                    </div>
                    <div style="font-size:11px;color:var(--color-primary);font-weight:600;margin-bottom:4px;">
                      <?= clean($drill['activity_type'] ?? 'Preparedness') ?> &bull; Venue: <?= clean($drill['location'] ?? 'Barangay Gymnasium') ?>
                    </div>
                    <p style="font-size:12px;color:var(--color-text-muted);margin:0 0 8px 0;line-height:1.4;">
                      <?= clean($drill['description'] ?? 'Community simulation drill and educational seminar organized by BDRRMC.') ?>
                    </p>
                    <div style="font-size:10px;color:var(--color-text-muted);">
                      Organized by: <strong><?= clean($drill['barangay_name'] ? "Barangay {$drill['barangay_name']} BDRRMC" : "ICDRRMO Central") ?></strong>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Action Protocol Cards -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h2 class="card-title">Disaster Action Protocols (BDRRMC Standard)</h2>
        </div>
        <div class="card-body" style="font-size:12px;line-height:1.5;">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div style="background:var(--color-surface-subtle);padding:14px;border-radius:6px;border-left:3px solid #3b82f6;">
              <strong style="color:var(--color-primary);display:block;margin-bottom:6px;">FLOOD & RIVER SWELLING</strong>
              <ul style="margin:0;padding-left:16px;color:var(--color-text-muted);font-size:11px;">
                <li>Monitor river level advisories from Hinaplanon / Mandulog sensors.</li>
                <li>Turn off main electrical breaker and gas tanks before water enters.</li>
                <li>Never walk, swim, or drive through moving flood waters.</li>
                <li>Head immediately to elevated shelters before nightfall.</li>
              </ul>
            </div>

            <div style="background:var(--color-surface-subtle);padding:14px;border-radius:6px;border-left:3px solid #f59e0b;">
              <strong style="color:#d97706;display:block;margin-bottom:6px;">EARTHQUAKE SAFETY</strong>
              <ul style="margin:0;padding-left:16px;color:var(--color-text-muted);font-size:11px;">
                <li><strong>DROP, COVER, AND HOLD ON:</strong> Protect your head beneath a sturdy table.</li>
                <li>Stay clear of glass windows, shelves, and brick walls.</li>
                <li>After shaking stops, evacuate via designated stairs—never elevators.</li>
                <li>Assemble at designated barangay open fields.</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Column: Family Action Plan -->
    <div style="display:flex;flex-direction:column;gap:var(--space-4);">
      <!-- Family Emergency Plan -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h2 class="card-title">Family Emergency Action Plan</h2>
        </div>
        <div class="card-body" style="font-size:12px;">
          <p style="color:var(--color-text-muted);line-height:1.4;margin-bottom:12px;">
            Review these critical steps with every member of your household, including children and elderly family members:
          </p>
          <div style="display:flex;flex-direction:column;gap:10px;">
            <div style="border-left:2px solid var(--color-primary);padding-left:10px;">
              <strong>1. Designated Safe Meeting Place:</strong>
              <div style="color:var(--color-text-muted);font-size:11px;margin-top:2px;">
                Identify two meeting spots: one right outside your home, and one outside your neighborhood (such as the Barangay Hall).
              </div>
            </div>
            <div style="border-left:2px solid var(--color-primary);padding-left:10px;">
              <strong>2. Out-of-City Contact Person:</strong>
              <div style="color:var(--color-text-muted);font-size:11px;margin-top:2px;">
                In major disasters, local cell towers may be congested. An out-of-town relative can act as a central communications bridge.
              </div>
            </div>
            <div style="border-left:2px solid var(--color-primary);padding-left:10px;">
              <strong>3. Special Needs Inventory:</strong>
              <div style="color:var(--color-text-muted);font-size:11px;margin-top:2px;">
                Keep baby formula, diapers, senior mobility aids, and prescription medications packed in waterproof pouches.
              </div>
            </div>
            <div style="border-left:2px solid var(--color-primary);padding-left:10px;">
              <strong>4. Pet Evacuation Plan:</strong>
              <div style="color:var(--color-text-muted);font-size:11px;margin-top:2px;">
                Bring leashes, carriers, and a 3-day food supply for domestic pets. Check pet-friendly zones in designated shelters.
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Emergency Numbers -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <h2 class="card-title">Direct Command Hotlines</h2>
        </div>
        <div class="card-body" style="padding:0;">
          <table class="table" style="font-size:11px;margin-bottom:0;">
            <tbody>
              <tr>
                <td><strong>ICDRRMO Central Rescue</strong></td>
                <td style="text-align:right;"><a href="tel:161" style="font-weight:700;color:var(--color-danger);">161</a></td>
              </tr>
              <tr>
                <td><strong>Philippine Coast Guard Iligan</strong></td>
                <td style="text-align:right;"><a href="tel:0632213031" style="font-weight:600;color:var(--color-primary);">(063) 221-3031</a></td>
              </tr>
              <tr>
                <td><strong>Iligan City Emergency Hospital</strong></td>
                <td style="text-align:right;"><a href="tel:0632212222" style="font-weight:600;color:var(--color-primary);">(063) 221-2222</a></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
