<?php
/**
 * ChamaLedger — Member Wallet Engine
 *
 * Balance logic:
 *   POSITIVE = member has credit (chama owes them)
 *   NEGATIVE = member owes chama (hasn't paid enough)
 *
 * Each month the required contribution is DEBITED automatically.
 * When a member pays, it is CREDITED.
 * Net result = their running balance.
 */

class Wallet {

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /** Get or create wallet for a member */
    public function getBalance(int $userId): float {
        $stmt = $this->pdo->prepare("SELECT balance FROM member_wallet WHERE user_id=?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            $this->pdo->prepare("INSERT IGNORE INTO member_wallet (user_id, balance) VALUES (?,0)")->execute([$userId]);
            return 0.0;
        }
        return (float)$row['balance'];
    }

    /** Credit — adds money to wallet (member paid) */
    public function credit(int $userId, float $amount, string $description, string $refType = '', int $refId = 0): float {
        return $this->adjust($userId, $amount, 'credit', $description, $refType, $refId);
    }

    /** Debit — removes money from wallet (monthly charge applied) */
    public function debit(int $userId, float $amount, string $description, string $refType = '', int $refId = 0): float {
        return $this->adjust($userId, -$amount, 'debit', $description, $refType, $refId);
    }

    /** Core adjust — atomic balance update */
    private function adjust(int $userId, float $delta, string $type, string $desc, string $refType, int $refId): float {
        // Ensure wallet exists
        $this->pdo->prepare("INSERT IGNORE INTO member_wallet (user_id, balance) VALUES (?,0)")->execute([$userId]);

        // Atomic update
        $this->pdo->prepare("UPDATE member_wallet SET balance = balance + ? WHERE user_id=?")->execute([$delta, $userId]);

        $newBalance = $this->getBalance($userId);

        // Log to ledger
        $this->pdo->prepare("
            INSERT INTO wallet_ledger (user_id, type, amount, balance_after, description, ref_type, ref_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$userId, $type, abs($delta), $newBalance, $desc, $refType ?: null, $refId ?: null]);

        return $newBalance;
    }

    /** Get full ledger for a member */
    public function getLedger(int $userId, int $limit = 24): array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM wallet_ledger WHERE user_id=?
            ORDER BY created_at DESC LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Apply monthly charge to ALL active members who haven't been charged yet this month.
     * Called manually by admin OR automatically when month rolls over.
     */
    public static function applyMonthlyCharges(PDO $pdo, string $month = ''): array {
        if (!$month) $month = date('Y-m');
        $monthLabel = date('F Y', strtotime($month . '-01'));
        $required   = (float)getSetting('monthly_contribution', '2000');
        $wallet     = new self($pdo);

        // Get all active members
        $members = $pdo->query("SELECT id, full_name FROM users WHERE role='member' AND status='active'")->fetchAll();

        $charged = 0; $skipped = 0;
        foreach ($members as $m) {
            // Check if already charged this month
            $alreadyCharged = $pdo->prepare("
                SELECT COUNT(*) FROM wallet_ledger
                WHERE user_id=? AND ref_type='monthly_charge'
                  AND DATE_FORMAT(created_at,'%Y-%m')=?
            ");
            $alreadyCharged->execute([$m['id'], $month]);
            if ((int)$alreadyCharged->fetchColumn() > 0) { $skipped++; continue; }

            $wallet->debit($m['id'], $required, "Monthly contribution charge — {$monthLabel}", 'monthly_charge', 0);
            $charged++;
        }

        return ['charged' => $charged, 'skipped' => $skipped, 'month' => $monthLabel, 'amount' => $required];
    }

    /**
     * Recalculate a member's wallet from scratch based on:
     * - All confirmed contributions (credits)
     * - All monthly charges from join date to now (debits)
     */
    public static function recalculate(PDO $pdo, int $userId): float {
        $required = (float)getSetting('monthly_contribution', '2000');

        // Get member join date
        $member = $pdo->prepare("SELECT created_at FROM users WHERE id=?");
        $member->execute([$userId]); $member = $member->fetch();
        if (!$member) return 0.0;

        $joinMonth  = date('Y-m', strtotime($member['created_at']));
        $thisMonth  = date('Y-m');

        // Wipe existing wallet
        $pdo->prepare("DELETE FROM wallet_ledger WHERE user_id=?")->execute([$userId]);
        $pdo->prepare("UPDATE member_wallet SET balance=0 WHERE user_id=?")->execute([$userId]);
        $pdo->prepare("INSERT IGNORE INTO member_wallet (user_id,balance) VALUES (?,0)")->execute([$userId]);

        $wallet = new self($pdo);

        // Apply monthly charges from join month to current month
        $cursor = $joinMonth;
        while ($cursor <= $thisMonth) {
            $label = date('F Y', strtotime($cursor . '-01'));
            $wallet->debit($userId, $required, "Monthly contribution charge — {$label}", 'monthly_charge', 0);
            $cursor = date('Y-m', strtotime($cursor . '-01 +1 month'));
        }

        // Credit all confirmed contributions
        $contribs = $pdo->prepare("
            SELECT * FROM contributions WHERE user_id=? AND status='confirmed'
            ORDER BY payment_month ASC
        ");
        $contribs->execute([$userId]);
        foreach ($contribs->fetchAll() as $c) {
            $label = date('F Y', strtotime($c['payment_month']));
            $wallet->credit($userId, $c['amount'], "Payment received — {$label}", 'contribution', $c['id']);
        }

        return $wallet->getBalance($userId);
    }
}
