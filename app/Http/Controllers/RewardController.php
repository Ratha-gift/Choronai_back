<?php

namespace App\Http\Controllers;

use App\Models\MemberCard;
use App\Models\Product;
use App\Models\ProductReward;
use App\Models\RewardEntry;
use App\Services\RewardService;
use App\Services\StockService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RewardController extends Controller
{
    public function history(Request $request, MemberCard $card)
    {
        return CrudController::paginate($request, RewardEntry::where('member_card_id', $card->id), ['kind']);
    }

    public function redeem(Request $request, MemberCard $card, RewardService $rewards, StockService $stock)
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'reward_id' => ['required', 'uuid', 'exists:product_rewards,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
        ]);

        return DB::transaction(function () use ($request, $card, $data, $rewards, $stock) {
            $card = MemberCard::whereKey($card->id)->lockForUpdate()->firstOrFail();
            if ($existing = RewardEntry::find($data['id'])) {
                abort_unless($existing->member_card_id === $card->id && $existing->reward_id === $data['reward_id']
                    && $existing->warehouse_id === $data['warehouse_id'] && $existing->kind === 'redeem', 409, 'Redemption ID is already in use.');

                return $existing;
            }
            abort_if($card->expiry && $card->expiry->endOfDay()->isPast(), 422, 'Member card has expired.');
            $reward = ProductReward::whereKey($data['reward_id'])->lockForUpdate()->firstOrFail();
            abort_unless($reward->points > 0 && $reward->product_id && $reward->quantity > 0, 422, 'Configure a product, quantity and positive points for this reward.');
            abort_if($card->award_points < $reward->points, 422, 'Not enough points.');
            $product = Product::findOrFail($reward->product_id);
            abort_unless($product->type === 'standard', 422, 'Rewards currently require a standard stock product.');
            $entry = $rewards->post([
                'id' => $data['id'], 'event_key' => 'redeem:'.$data['id'], 'kind' => 'redeem',
                'member_card_id' => $card->id, 'customer_id' => $card->customer_id,
                'reward_id' => $reward->id, 'warehouse_id' => $data['warehouse_id'],
                'points' => -$reward->points, 'spent' => 0,
                'details' => ['product_id' => $product->id, 'quantity' => $reward->quantity, 'name' => $reward->name],
            ]);
            $stock->apply('reward', $entry->id, 'reward_redemption', $data['warehouse_id'], [
                ['product_id' => $product->id, 'quantity' => -$reward->quantity],
            ], false);
            Audit::log('redeem', 'member_card', $card->id, null, ['entry_id' => $entry->id]);

            return response()->json($entry, 201);
        });
    }
}
