<?php
/**
 * ChamaLedger — Year-End Dividend & Interest Distribution
 * Admin sets total profit pool → system calculates each member's share
 * based on their average wallet balance across the year
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Dividends — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();

$pdo     = getDB();
$adminId = (int)$_SESSION['user_id'];

// Auto-create tables if missing
$pdo->exec("CREATE TABLE IF NOT EXISTS dividends (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year YEAR NOT NULL,
    total_profit DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_savings DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes TEXT DEFAULT NULL,
    status ENUM('draft','distributed') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    distributed_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS dividend_shares (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dividend_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    avg_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    share_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
    share_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    notified TINYINT(1) DEFAULT 0,
    FOREIGN KEY (dividend_id) REFERENCES dividends(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_div_user (dividend_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$curr    = getSetting('currency', 'KES');

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // CALCULATE / SAVE DRAFT
    if ($action === 'calculate') {
        $year        = (int)($_POST['year']         ?? date('Y'));
        $totalProfit = (float)($_POST['total_profit'] ?? 0);
        $notes       = sanitize($_POST['notes']       ?? '');

        if ($totalProfit <= 0) { setFlash('danger', 'Total profit must be greater than 0.'); redirect(APP_URL.'/admin/dividends.php'); }

        // Get all active members
        $members = $pdo->query("SELECT id, full_name, membership_number FROM users WHERE role='member' AND status='active' ORDER BY full_name")->fetchAll();

        // Calculate each member's average wallet balance for the year
        // We look at wallet_ledger and compute average end-of-month balance
        $shares = [];
        $totalSavings = 0;

        foreach ($members as $m) {
            // Sum all credits (contributions + event contribs) minus debits (loans) for the year
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE -amount END), 0) AS net_saved
                FROM wallet_ledger
                WHERE user_id=? AND YEAR(created_at)=?
            ");
            $stmt->execute([$m['id'], $year]);
            $net = max(0, (float)$stmt->fetchColumn());

            // Average monthly contributions confirmed in this year
            $cStmt = $pdo->prepare("
                SELECT COALESCE(SUM(amount), 0) / 12 AS avg_monthly
                FROM contributions
                WHERE user_id=? AND status='confirmed' AND YEAR(payment_month)=?
            ");
            $cStmt->execute([$m['id'], $year]);
            $avgMonthly = (float)$cStmt->fetchColumn();

            // Use whichever is more meaningful — total confirmed savings / 12
            $cTotal = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=? AND status='confirmed' AND YEAR(payment_month)=?");
            $cTotal->execute([$m['id'], $year]);
            $yearlyContrib = (float)$cTotal->fetchColumn();

            $shares[$m['id']] = [
                'user'     => $m,
                'avg_bal'  => $yearlyContrib, // use yearly contributions as the basis
                'share_pct'   => 0,
                'share_amount'=> 0,
            ];
            $totalSavings += $yearlyContrib;
        }

        // Calculate percentages and amounts
        foreach ($shares as $uid => &$s) {
            $s['share_pct']    = $totalSavings > 0 ? round(($s['avg_bal'] / $totalSavings) * 100, 4) : 0;
            $s['share_amount'] = $totalSavings > 0 ? round(($s['avg_bal'] / $totalSavings) * $totalProfit, 2) : 0;
        }
        unset($s);

        // Upsert dividend record
        $pdo->prepare("
            INSERT INTO dividends (year, total_profit, total_savings, notes, status, created_by)
            VALUES (?,?,?,?,'draft',?)
            ON DUPLICATE KEY UPDATE total_profit=VALUES(total_profit), total_savings=VALUES(total_savings), notes=VALUES(notes), status='draft'
        ")->execute([$year, $totalProfit, $totalSavings, $notes, $adminId]);
        $divId = (int)$pdo->query("SELECT id FROM dividends WHERE year={$year}")->fetchColumn();

        // Delete old shares and insert new
        $pdo->prepare("DELETE FROM dividend_shares WHERE dividend_id=?")->execute([$divId]);
        foreach ($shares as $uid => $s) {
            if ($s['avg_bal'] > 0) {
                $pdo->prepare("INSERT INTO dividend_shares (dividend_id,user_id,avg_balance,share_pct,share_amount) VALUES (?,?,?,?,?)")
                    ->execute([$divId, $uid, $s['avg_bal'], $s['share_pct'], $s['share_amount']]);
            }
        }

        logActivity('DIVIDEND_CALCULATED', "Year:{$year} Profit:{$totalProfit} Savings:{$totalSavings}");
        setFlash('success', "Dividend calculated for {$year}. Review the shares below, then click Distribute.");
        redirect(APP_URL . '/admin/dividends.php?year=' . $year);
    }

    // DISTRIBUTE
    if ($action === 'distribute') {
        $divId = (int)($_POST['div_id'] ?? 0);
        $div   = $pdo->prepare("SELECT * FROM dividends WHERE id=?"); $div->execute([$divId]); $div = $div->fetch();

        if (!$div || $div['status'] === 'distributed') {
            setFlash('warning', 'Already distributed or not found.'); redirect(APP_URL.'/admin/dividends.php'); 
        }

        $shares = $pdo->prepare("SELECT ds.*, u.full_name, u.phone FROM dividend_shares ds JOIN users u ON u.id=ds.user_id WHERE ds.dividend_id=?");
        $shares->execute([$divId]); $shares = $shares->fetchAll();

        $wallet = new Wallet($pdo);
        $distributed = 0;
        foreach ($shares as $s) {
            if ((float)$s['share_amount'] <= 0) continue;
            // Credit wallet
            $wallet->credit($s['user_id'], (float)$s['share_amount'],
                "Dividend {$div['year']} — " . round($s['share_pct'],2) . "% share",
                'dividend', $divId);
            // Notify member
            createNotification($s['user_id'], '🎉 ' . $div['year'] . ' Dividend Credited',
                "Your share of the {$div['year']} chama dividend is {$curr} " . number_format($s['share_amount'],2) . " (" . round($s['share_pct'],1) . "% of profits). Check your wallet.",
                'success', APP_URL . '/member/dashboard.php');
            // SMS
            try { SMS::dividendNotice(['full_name'=>$s['full_name'],'phone'=>$s['phone']], (float)$s['share_amount'], $div['year']); } catch(Exception $e) {}
            // Mark notified
            $pdo->prepare("UPDATE dividend_shares SET notified=1 WHERE id=?")->execute([$s['id']]);
            $distributed++;
        }

        $pdo->prepare("UPDATE dividends SET status='distributed', distributed_at=NOW() WHERE id=?")->execute([$divId]);
        logActivity('DIVIDEND_DISTRIBUTED', "Div#{$divId} year:{$div['year']} distributed to {$distributed} members");
        setFlash('success', "Dividend distributed to {$distributed} members! Wallets updated.");
        redirect(APP_URL . '/admin/dividends.php?year=' . $div['year']);
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$selectedYear = (int)($_GET['year'] ?? date('Y'));
$dividend     = $pdo->prepare("SELECT * FROM dividends WHERE year=?"); $dividend->execute([$selectedYear]); $dividend = $dividend->fetch();
$divShares    = [];
if ($dividend) {
    $st = $pdo->prepare("SELECT ds.*, u.full_name, u.membership_number FROM dividend_shares ds JOIN users u ON u.id=ds.user_id WHERE ds.dividend_id=? ORDER BY ds.share_amount DESC");
    $st->execute([$dividend['id']]); $divShares = $st->fetchAll();
}

// Loan interest earned this year (for reference)
$interestEarned = (float)$pdo->prepare("
    SELECT COALESCE(SUM(lp.amount - (l.amount_approved / l.duration_months)), 0)
    FROM loan_payments lp JOIN loans l ON l.id=lp.loan_id
    WHERE lp.status='confirmed' AND YEAR(lp.paid_at)=?
")->execute([$selectedYear]) ? 0 : 0;
$st2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(expense_date)=?");
$st2->execute([$selectedYear]); $totalExpenses = (float)$st2->fetchColumn();

$loanIncome = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM loan_payments WHERE status='confirmed' AND YEAR(paid_at)=?");
$loanIncome->execute([$selectedYear]); $loanIncome = (float)$loanIncome->fetchColumn();

$totalContribYear = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status='confirmed' AND YEAR(payment_month)=?");
$totalContribYear->execute([$selectedYear]); $totalContribYear = (float)$totalContribYear->fetchColumn();

$years = [];
for ($y = date('Y'); $y >= date('Y')-5; $y--) $years[] = $y;

require_once ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-gift me-2" style="color:#00c471"></i><?= t('nav_dividends') ?></h4>
        <small class="text-muted"><?= t('adm_div_subtitle') ?></small>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <label style="font-size:.8rem;color:var(--text-muted)"><?= t('lbl_year_label') ?></label>
        <select class="form-select form-select-sm" style="width:100px" onchange="location.href='?year='+this.value">
            <?php foreach ($years as $y): ?>
            <option value="<?= $y ?>" <?= $y===$selectedYear?'selected':'' ?>><?= $y ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<!-- Financial summary for the year -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.35rem;font-weight:800;color:#00c471"><?= $curr ?> <?= number_format($totalContribYear,0) ?></div>
            <div style="font-size:.72rem;color:var(--text-muted)">Total Contributions <?= $selectedYear ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.35rem;font-weight:800;color:#3b82f6"><?= $curr ?> <?= number_format($loanIncome,0) ?></div>
            <div style="font-size:.72rem;color:var(--text-muted)"><?= t('adm_div_loan_repay') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.35rem;font-weight:800;color:#ef4444"><?= $curr ?> <?= number_format($totalExpenses,0) ?></div>
            <div style="font-size:.72rem;color:var(--text-muted)">Total Expenses <?= $selectedYear ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center" style="background:rgba(0,196,113,.06);border:1px solid rgba(0,196,113,.15)!important">
            <div style="font-size:1.35rem;font-weight:800;color:#00c471"><?= $curr ?> <?= number_format(max(0,$loanIncome-$totalExpenses),0) ?></div>
            <div style="font-size:.72rem;color:var(--text-muted)">Est. Net Profit <?= $selectedYear ?></div>
            <div style="font-size:.68rem;color:var(--text-muted)"><?= t('lbl_net_repay_exp') ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Calculate form -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header border-0">
                <h6 class="mb-0 fw-bold"><i class="bi bi-calculator me-2 text-success"></i>
                    <?= $dividend && $dividend['status']==='distributed' ? 'Already Distributed' : 'Calculate Shares' ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if ($dividend && $dividend['status'] === 'distributed'): ?>
                <div class="text-center py-3">
                    <i class="bi bi-check-circle-fill text-success" style="font-size:2.5rem"></i>
                    <p class="mt-2 fw-bold">Distributed on <?= date('d M Y', strtotime($dividend['distributed_at'])) ?></p>
                    <p class="text-muted small">Total: <?= $curr ?> <?= number_format($dividend['total_profit'],2) ?></p>
                </div>
                <?php else: ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="calculate">
                    <input type="hidden" name="year" value="<?= $selectedYear ?>">

                    <div class="mb-3">
                        <label class="form-label" style="font-size:.8rem">Total Profit Pool (<?= $curr ?>)</label>
                        <input type="number" name="total_profit" class="form-control form-control-sm"
                               min="1" step="0.01" required
                               value="<?= $dividend ? number_format($dividend['total_profit'],2,'.','') : number_format(max(0,$loanIncome-$totalExpenses),2,'.','') ?>"
                               placeholder="e.g. 50000">
                        <div style="font-size:.7rem;color:var(--text-muted);margin-top:.3rem">
                            This is the total interest + gains to share among members
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-size:.8rem"><?= t('lbl_notes') ?></label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"
                                  placeholder="e.g. Interest earned on loans Jan–Dec <?= $selectedYear ?>"><?= htmlspecialchars($dividend['notes']??'') ?></textarea>
                    </div>

                    <div class="p-2 rounded mb-3" style="background:rgba(59,130,246,.07);font-size:.74rem;color:var(--text-muted)">
                        <i class="bi bi-info-circle me-1"></i>
                        Shares are proportional to each member's total <?= $selectedYear ?> contributions. Members who contributed more get a larger share.
                    </div>

                    <button type="submit" class="btn btn-primary w-100 btn-sm fw-bold">
                        <i class="bi bi-calculator me-1"></i>Calculate Shares
                    </button>
                </form>

                <?php if ($dividend && !empty($divShares)): ?>
                <form method="POST" class="mt-3" onsubmit="return confirm('Distribute dividend to all <?= count($divShares) ?> members? This will credit their wallets and cannot be undone.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="distribute">
                    <input type="hidden" name="div_id" value="<?= $dividend['id'] ?>">
                    <button type="submit" class="btn btn-success w-100 btn-sm fw-bold">
                        <i class="bi bi-gift me-1"></i>Distribute to <?= count($divShares) ?> Members
                    </button>
                    <div style="font-size:.7rem;color:var(--text-muted);text-align:center;margin-top:.4rem">
                        This credits wallets, sends notifications + SMS
                    </div>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Past distributions -->
        <?php
        $pastDivs = $pdo->query("SELECT * FROM dividends ORDER BY year DESC")->fetchAll();
        if (!empty($pastDivs)):
        ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-header border-0"><h6 class="mb-0 fw-bold" style="font-size:.85rem"><?= t('lbl_history') ?></h6></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0" style="font-size:.78rem">
                    <thead><tr><th class="ps-3">Year</th><th><?= t('lbl_amount') ?></th><th><?= t('lbl_status') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($pastDivs as $pd): $sb=$pd['status']==='distributed'?'success':'warning'; ?>
                    <tr>
                        <td class="ps-3"><a href="?year=<?= $pd['year'] ?>" style="color:var(--green)"><?= $pd['year'] ?></a></td>
                        <td><?= $curr ?> <?= number_format($pd['total_profit'],0) ?></td>
                        <td><span class="badge bg-<?= $sb ?> bg-opacity-15 text-<?= $sb ?>" style="font-size:.68rem"><?= ucfirst($pd['status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Shares table -->
    <div class="col-md-8">
        <?php if (!empty($divShares)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header border-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-people me-2 text-success"></i>
                    Member Shares — <?= $selectedYear ?>
                    <?php if ($dividend['status']==='distributed'): ?>
                    <span class="badge bg-success ms-2" style="font-size:.68rem"><?= t('adm_div_distributed2') ?></span>
                    <?php else: ?>
                    <span class="badge bg-warning ms-2" style="font-size:.68rem"><?= t('adm_div_draft') ?></span>
                    <?php endif; ?>
                </h6>
                <span style="font-size:.75rem;color:var(--text-muted)">
                    Pool: <?= $curr ?> <?= number_format($dividend['total_profit'],0) ?>
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size:.82rem">
                    <thead style="background:var(--sidebar-bg)">
                        <tr>
                            <th class="ps-3">#</th>
                            <th><?= t('lbl_member') ?></th>
                            <th class="text-end"><?= $selectedYear ?> Contributions</th>
                            <th class="text-end">Share %</th>
                            <th class="text-end pe-3">Dividend Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($divShares as $i => $s): ?>
                    <tr>
                        <td class="ps-3 text-muted"><?= $i+1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($s['full_name']) ?></div>
                            <div style="font-size:.7rem;color:var(--text-muted)"><?= $s['membership_number'] ?? '' ?></div>
                        </td>
                        <td class="text-end"><?= $curr ?> <?= number_format($s['avg_balance'],0) ?></td>
                        <td class="text-end">
                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:.5rem">
                                <div style="width:60px;height:5px;background:rgba(255,255,255,.07);border-radius:99px;overflow:hidden">
                                    <div style="width:<?= min(100,round($s['share_pct'])) ?>%;height:100%;background:#00c471;border-radius:99px"></div>
                                </div>
                                <?= number_format($s['share_pct'],1) ?>%
                            </div>
                        </td>
                        <td class="text-end pe-3 fw-bold" style="color:#00c471">
                            <?= $curr ?> <?= number_format($s['share_amount'],2) ?>
                            <?php if ($s['notified']): ?>
                            <i class="bi bi-check-circle-fill text-success ms-1" title="Distributed"></i>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:800;border-top:2px solid var(--border)">
                            <td colspan="2" class="ps-3">TOTAL</td>
                            <td class="text-end"><?= $curr ?> <?= number_format($dividend['total_savings'],0) ?></td>
                            <td class="text-end">100%</td>
                            <td class="text-end pe-3" style="color:#00c471"><?= $curr ?> <?= number_format($dividend['total_profit'],2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-gift" style="font-size:3rem;opacity:.2"></i>
                <p class="mt-3 text-muted">No dividend calculated for <?= $selectedYear ?> yet.<br>Enter the profit pool on the left and click Calculate.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>
