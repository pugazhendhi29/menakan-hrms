<?php
use App\Services\EmployeeService;

/** Read-only work experience and education tables. Expects $experienceRows, $educationRows, optional $editUrl. */
$dash = '<span class="text-muted-2">—</span>';
?>
<div class="card" id="experience">
  <div class="card-header">Work experience
    <?php if (!empty($editUrl)): ?><a class="btn btn-sm btn-outline-primary" href="<?= e($editUrl . '#experience') ?>"><i class="bi bi-pencil"></i> Add / edit work experience</a><?php endif; ?></div>
  <div class="table-responsive"><table class="table">
    <thead><tr><th>Company name</th><th>Job title</th><th>From</th><th>To</th><th>Duration</th><th>Location</th><th>Professional reference</th></tr></thead>
    <tbody>
    <?php foreach ($experienceRows as $w): ?>
      <tr>
        <td class="fw-semibold"><?= e($w['company_name']) ?></td><td><?= e($w['job_title']) ?></td>
        <td class="text-nowrap"><?= e(uk_date($w['from_date'])) ?></td>
        <td class="text-nowrap"><?= $w['to_date'] ? e(uk_date($w['to_date'])) : '<span class="badge text-bg-success">Current</span>' ?></td>
        <td class="text-nowrap"><?= e(EmployeeService::formatMonths(EmployeeService::monthsBetween($w['from_date'], $w['to_date'] ?? uk_today()))) ?></td>
        <td><?= $w['location'] ? e($w['location']) : $dash ?></td>
        <td class="small"><?php if ($w['reference_name'] || $w['reference_email'] || $w['reference_phone']): ?>
          <?= e($w['reference_name'] ?? '') ?>
          <?php if ($w['reference_email']): ?><br><a href="mailto:<?= e($w['reference_email']) ?>"><?= e($w['reference_email']) ?></a><?php endif; ?>
          <?php if ($w['reference_phone']): ?><br><a href="tel:<?= e(str_replace(' ', '', $w['reference_phone'])) ?>"><?= e($w['reference_phone']) ?></a><?php endif; ?>
        <?php else: ?><?= $dash ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$experienceRows): ?><tr><td colspan="7" class="empty">No work experience recorded.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<div class="card" id="education">
  <div class="card-header">Education details
    <?php if (!empty($editUrl)): ?><a class="btn btn-sm btn-outline-primary" href="<?= e($editUrl . '#education') ?>"><i class="bi bi-pencil"></i> Add / edit education</a><?php endif; ?></div>
  <div class="table-responsive"><table class="table">
    <thead><tr><th>Institute name</th><th>Education level</th><th>Specialization</th><th>Date of completion</th></tr></thead>
    <tbody>
    <?php foreach ($educationRows as $ed): ?>
      <tr><td class="fw-semibold"><?= e($ed['institute_name']) ?></td><td><?= e($ed['education_level'] ?: ($ed['qualification'] ?? '')) ?></td>
        <td><?= $ed['specialization'] ? e($ed['specialization']) : $dash ?></td><td><?= $ed['completion_date'] ? e(uk_date($ed['completion_date'])) : $dash ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$educationRows): ?><tr><td colspan="4" class="empty">No education recorded.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
