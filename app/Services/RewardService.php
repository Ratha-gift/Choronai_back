<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\MemberCard;
use App\Models\RewardEntry;
use App\Models\Sale;
use App\Support\Settings;

/** Called inside a database transaction, after locking the sale/card. */
class RewardService
{
    public function post(array $data): RewardEntry
    {
        if ($existing = RewardEntry::where('event_key', $data['event_key'])->first()) {
            return $existing;
        }
        if ($data['member_card_id'] ?? null) {
            $card = MemberCard::whereKey($data['member_card_id'])->lockForUpdate()->firstOrFail();
            $card->increment('award_points', $data['points']);
            $card->increment('spent', $data['spent'] ?? 0);
        }
        if ($data['customer_id'] ?? null) {
            Customer::whereKey($data['customer_id'])->increment('award_points', $data['points']);
        }

        return RewardEntry::create([...$data, 'created_by' => auth()->id()]);
    }

    public function earn(Sale $sale): void
    {
        if (! $sale->customer_id && ! $sale->member_card_id) {
            return;
        }
        $spent = (int) Settings::get('system', 'customer_spent', 0);
        $points = $spent > 0 ? intdiv($sale->grand_total, $spent) * max(0, (int) Settings::get('system', 'customer_points', 0)) : 0;
        $this->post([
            'event_key' => 'sale:'.$sale->id, 'kind' => 'earn', 'sale_id' => $sale->id,
            'member_card_id' => $sale->member_card_id, 'customer_id' => $sale->customer_id,
            'points' => $points, 'spent' => $sale->grand_total,
        ]);
    }

    public function reverseReturn(Sale $sale, string $returnId, int $amount): void
    {
        $earned = RewardEntry::where('event_key', 'sale:'.$sale->id)->first();
        if (! $earned || $sale->grand_total <= 0) {
            return; // Legacy sales have no reliable original earn formula.
        }
        $prior = -(int) RewardEntry::where('sale_id', $sale->id)->where('kind', 'reversal')->sum('points');
        $target = (int) floor($earned->points * min($sale->returned, $sale->grand_total) / $sale->grand_total);
        // A previously redeemed balance may become negative after a legitimate refund.
        // Keep the debt in the ledger; a later redemption requires sufficient points.
        $this->post([
            'event_key' => 'return:'.$returnId, 'kind' => 'reversal', 'sale_id' => $sale->id,
            'member_card_id' => $sale->member_card_id, 'customer_id' => $sale->customer_id,
            'points' => -max(0, $target - $prior), 'spent' => -$amount,
        ]);
    }
}
