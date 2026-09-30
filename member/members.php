<?php
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Our Members — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireMember();
require_once ROOT . '/includes/header.php';

$pdo      = getDB();
$userId   = (int)$_SESSION['user_id'];
$showFull = getSetting('member_list_public', '1') === '1'; // admin can toggle this

$members = $pdo->query("
    SELECT full_name, membership_number, nickname, chama_position, joined_date, profile_photo,
           (SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=users.id AND status='confirmed') AS total_saved
    FROM users
    WHERE role='member' AND status='active'
    ORDER BY chama_position DESC, full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-people-fill me-2 text-primary"></i><?= t('mm_title') ?></h4>
    <small class="text-muted"><?= count($members) ?> active member(s) in the chama</small>
</div>
<?= getFlash() ?>
<div class="row g-3">
<?php foreach($members as $m):
    $initials = implode('', array_map(fn($p)=>strtoupper($p[0]), array_slice(explode(' ', trim($m['full_name'])),0,2)));
    $photoPath = $m['profile_photo'] ? ROOT.'/uploads/avatars/'.$m['profile_photo'] : null;
    $isMe = false; // we don't expose other members' user_id
?>
<div class="col-md-4 col-lg-3">
    <div class="card border-0 shadow-sm text-center h-100">
        <div class="card-body py-4">
            <?php if($photoPath && file_exists($photoPath)): ?>
            <img src="<?= APP_URL ?>/uploads/avatars/<?= htmlspecialchars($m['profile_photo']) ?>"
                 class="rounded-circle mb-3 border border-2 border-primary"
                 style="width:72px;height:72px;object-fit:cover" alt="">
            <?php else: ?>
            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto mb-3"
                 style="width:72px;height:72px;font-size:1.5rem;font-weight:700"><?= $initials ?></div>
            <?php endif; ?>
            <div class="fw-bold"><?= htmlspecialchars($m['full_name']) ?></div>
            <?php if($m['nickname']): ?>
            <div class="text-muted small">"<?= htmlspecialchars($m['nickname']) ?>"</div>
            <?php endif; ?>
            <div class="font-monospace text-primary small mt-1"><?= htmlspecialchars($m['membership_number'] ?? '') ?></div>
            <?php if($m['chama_position']): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle mt-1 small">
                <?= htmlspecialchars($m['chama_position']) ?>
            </span>
            <?php endif; ?>
            <div class="mt-2 small text-muted">
                <i class="bi bi-calendar3 me-1"></i>
                Since <?= $m['joined_date'] ? date('M Y', strtotime($m['joined_date'])) : '—' ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php require_once ROOT . '/includes/footer.php'; ?>
