<?php
/**
 * ChamaLedger — Loan Eligibility & AI Credit Scoring Engine
 * Include this wherever loan eligibility needs to be checked.
 *
 * Usage:
 *   require_once ROOT . '/includes/loan_eligibility.php';
 *   $elig = checkLoanEligibility($pdo, $userId);
 *   if (!$elig['eligible']) { // show $elig['reasons'] }
 *   echo $elig['score']; // 0-100 AI credit score
 */

function checkLoanEligibility(PDO $pdo, int $userId): array {
    $curr        = getSetting('currency', 'KES');
    $multiplier  = (float)getSetting('max_loan_multiplier', '3');
    $minMonths   = (int)getSetting('loan_min_months', '3');
    $minConsec   = (int)getSetting('loan_min_consecutive', '3');
    $blockFines  = getSetting('loan_block_unpaid_fines', '1') === '1';
    $blockActive = getSetting('loan_block_active_loan', '1') === '1';

    $reasons  = [];   // blocking reasons
    $warnings = [];   // non-blocking warnings shown to user
    $positive = [];   // positive signals
    $score    = 0;    // AI credit score 0-100

    // ── Load member ──────────────────────────────────────────────────────────
    $user = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $user->execute([$userId]);
    $user = $user->fetch();
    if (!$user) return ['eligible'=>false,'reasons'=>['Member not found'],'score'=>0];

    // ── Safe join date ───────────────────────────────────────────────────────
    $joinDate     = getMemberStartDate($user); // Auto: later of join date or chama start
    $joinTs       = strtotime($joinDate);
    $monthsActive = (int)floor((time() - $joinTs) / (30.44 * 86400));

    // ── Rule 1: Minimum months active ───────────────────────────────────────
    if ($monthsActive < $minMonths) {
        $remaining = $minMonths - $monthsActive;
        $reasons[] = "You must be an active member for at least {$minMonths} months before applying. "
                   . "You have been a member for {$monthsActive} month(s). "
                   . "You can apply in {$remaining} more month(s).";
    } else {
        $score += 20;
        $positive[] = "Active member for {$monthsActive} months ✓";
    }

    // ── Rule 2: Total confirmed savings & max loan ───────────────────────────
    $stSav = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=? AND status='confirmed'");
    $stSav->execute([$userId]);
    $totalSavings = (float)$stSav->fetchColumn();
    $maxLoan      = $totalSavings * $multiplier;

    if ($totalSavings <= 0) {
        $reasons[] = "You have no confirmed contributions yet. You must contribute before applying for a loan.";
    } else {
        $score += 15;
        $positive[] = "Total savings: " . number_format($totalSavings, 2) . " {$curr} ✓";
    }

    // ── Rule 3: Consecutive months paid (no gaps in last N months) ───────────
    $consecMissed = 0;
    $consecOk     = true;
    $joinYm       = date('Y-m', $joinTs);
    $consecPaid   = 0;

    for ($i = 1; $i <= $minConsec; $i++) {
        $mo = date('Y-m', strtotime("-{$i} months"));
        if ($mo < $joinYm) break; // before member joined — skip
        $st = $pdo->prepare("SELECT COUNT(*) FROM contributions WHERE user_id=? AND status='confirmed' AND DATE_FORMAT(payment_month,'%Y-%m')=?");
        $st->execute([$userId, $mo]);
        if ((int)$st->fetchColumn() > 0) {
            $consecPaid++;
        } else {
            $consecMissed++;
            $consecOk = false;
        }
    }

    if ($monthsActive >= $minConsec && !$consecOk) {
        $reasons[] = "You must have paid at least {$minConsec} consecutive months with no gaps. "
                   . "You missed {$consecMissed} month(s) in the last {$minConsec} months.";
    } elseif ($consecOk && $consecPaid >= $minConsec) {
        $score += 25;
        $positive[] = "Paid {$consecPaid} consecutive months ✓";
    }

    // ── Rule 4: No unpaid fines ──────────────────────────────────────────────
    $stFines = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM member_fines WHERE user_id=? AND status='pending'");
    $stFines->execute([$userId]);
    $unpaidFines = (float)$stFines->fetchColumn();

    if ($blockFines && $unpaidFines > 0) {
        $reasons[] = "You have unpaid fines of " . number_format($unpaidFines, 2) . " {$curr}. "
                   . "Please clear all fines before applying for a loan.";
    } elseif ($unpaidFines === 0.0) {
        $score += 15;
        $positive[] = "No outstanding fines ✓";
    }

    // ── Rule 5: No existing active loan ─────────────────────────────────────
    $stLoan = $pdo->prepare("SELECT id, status FROM loans WHERE user_id=? AND status IN ('pending','approved','disbursed') LIMIT 1");
    $stLoan->execute([$userId]);
    $activeLoan = $stLoan->fetch();

    if ($blockActive && $activeLoan) {
        $statusLabel = ucfirst($activeLoan['status']);
        $reasons[] = "You already have an active loan ({$statusLabel}). "
                   . "You must fully repay it before applying for a new one.";
    } elseif (!$activeLoan) {
        $score += 10;
        $positive[] = "No existing active loan ✓";
    }

    // ── AI SCORING: extra signals (non-blocking, boost score) ───────────────

    // Payment consistency rate (all-time)
    $stTotal = $pdo->prepare("SELECT COUNT(*) FROM contributions WHERE user_id=? AND status='confirmed'");
    $stTotal->execute([$userId]);
    $totalPaid = (int)$stTotal->fetchColumn();
    $expectedTotal = max(1, $monthsActive);
    $consistencyRate = min(100, round($totalPaid / $expectedTotal * 100));
    $score += (int)($consistencyRate * 0.10); // up to 10 pts
    if ($consistencyRate >= 90) $positive[] = "Excellent payment consistency ({$consistencyRate}%) ✓";
    elseif ($consistencyRate >= 70) $positive[] = "Good payment consistency ({$consistencyRate}%) ✓";
    else $warnings[] = "Payment consistency is {$consistencyRate}% — improve to increase your score";

    // Loan repayment history
    $stRepaid = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE user_id=? AND status='completed'");
    $stRepaid->execute([$userId]);
    $repaidLoans = (int)$stRepaid->fetchColumn();
    if ($repaidLoans > 0) {
        $score += min(5, $repaidLoans * 2);
        $positive[] = "Has repaid {$repaidLoans} loan(s) in the past ✓";
    }

    // Profile completeness
    $filled = 0;
    foreach (['phone','occupation','address','next_of_kin','next_of_kin_phone'] as $f) {
        if (!empty($user[$f])) $filled++;
    }
    $profilePct = round($filled / 5 * 100);
    if ($profilePct >= 80) { $score += 5; $positive[] = "Complete profile ({$profilePct}%) ✓"; }
    else $warnings[] = "Complete your profile to improve your score (currently {$profilePct}%)";

    // Cap score
    $score = min(100, max(0, $score));

    // Score label + recommendation
    if ($score >= 80) {
        $scoreLabel = 'Excellent';
        $scoreColor = '#00c471';
        $recommendation = "You are a strong candidate. Your application is likely to be approved quickly.";
    } elseif ($score >= 65) {
        $scoreLabel = 'Good';
        $scoreColor = '#22c55e';
        $recommendation = "You are eligible. Keep up consistent payments to improve your score further.";
    } elseif ($score >= 50) {
        $scoreLabel = 'Fair';
        $scoreColor = '#f59e0b';
        $recommendation = "You qualify but your score could be better. Clear fines and pay consistently.";
    } elseif ($score >= 35) {
        $scoreLabel = 'Weak';
        $scoreColor = '#ef4444';
        $recommendation = "You may qualify for a small loan. Address the issues below to improve your standing.";
    } else {
        $scoreLabel = 'Poor';
        $scoreColor = '#991b1b';
        $recommendation = "You do not currently qualify. See the requirements below.";
    }

    return [
        'eligible'       => empty($reasons),
        'reasons'        => $reasons,
        'warnings'       => $warnings,
        'positive'       => $positive,
        'score'          => $score,
        'score_label'    => $scoreLabel,
        'score_color'    => $scoreColor,
        'recommendation' => $recommendation,
        'total_savings'  => $totalSavings,
        'max_loan'       => $maxLoan,
        'multiplier'     => $multiplier,
        'months_active'  => $monthsActive,
        'consec_paid'    => $consecPaid,
        'unpaid_fines'   => $unpaidFines,
        'consistency'    => $consistencyRate,
        'repaid_loans'   => $repaidLoans,
    ];
}
