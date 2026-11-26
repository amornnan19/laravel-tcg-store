<?php

use App\Actions\SubmitBuylist;
use App\Enums\BuylistPayout;
use App\Enums\BuylistStatus;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Exceptions\BuylistQuotaFull;
use App\Exceptions\BuylistQuoteChanged;
use App\Models\BuylistPrice;
use App\Models\BuylistSubmission;
use App\Models\Printing;
use App\Models\User;
use App\Notifications\BuylistSubmitted;
use App\Support\BuylistQuote;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('a submission snapshots the card, the claimed condition and the quoted unit and line', function () {
    $customer = User::factory()->create();
    $row = buylistRow(
        ['condition' => CardCondition::Lp, 'finish' => Finish::Foil, 'cash_satang' => 10000, 'credit_satang' => 11500],
        ['name_en' => 'Monkey D. Luffy'],
        ['number' => '119', 'language' => Language::Ja, 'has_foil' => true],
    );

    $name = $row->printing->display_name;
    $submission = submitBuylist($customer, [$row->id => 3]);
    $item = $submission->items()->sole();

    expect($submission->number)->toBe('BUY-'.str_pad((string) $submission->id, 5, '0', STR_PAD_LEFT))
        ->and($submission->status)->toBe(BuylistStatus::Submitted)
        ->and($submission->user_id)->toBe($customer->id)
        ->and($submission->payout)->toBe(BuylistPayout::Credit)
        ->and($item->buylist_price_id)->toBe($row->id)
        ->and($item->printing_id)->toBe($row->printing_id)
        ->and($item->card_name)->toBe($row->printing->display_name)
        ->and($item->display_code)->toBe($row->printing->display_code)
        ->and($item->language)->toBe(Language::Ja)
        ->and($item->finish)->toBe(Finish::Foil)
        ->and($item->claimed_condition)->toBe(CardCondition::Lp)
        ->and($item->qty)->toBe(3)
        ->and($item->quoted_unit_satang)->toBe(11500)
        ->and($item->quoted_line_satang)->toBe(34500)
        ->and($item->assessed_condition)->toBeNull();

    // The snapshot survives a later change of the price and of the card.
    $row->update(['credit_satang' => 1]);
    $row->printing->card->update(['name_en' => 'Renamed']);

    expect($item->fresh()->quoted_unit_satang)->toBe(11500)
        ->and($item->fresh()->card_name)->toBe($name);
});

test('the unit and the total follow the payout the customer chose', function (BuylistPayout $payout, int $unit) {
    $a = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500]);
    $b = buylistRow(['cash_satang' => 20000, 'credit_satang' => 23000]);

    $submission = submitBuylist(User::factory()->create(), [$a->id => 2, $b->id => 1], $payout);

    expect($submission->items->firstWhere('buylist_price_id', $a->id)->quoted_unit_satang)->toBe($unit)
        ->and($submission->quoted_total_satang)->toBe($unit * 2 + $unit * 2);
})->with([
    'cash' => [BuylistPayout::Cash, 10000],
    'credit' => [BuylistPayout::Credit, 11500],
]);

test('the quote runs to the end of the seventh day in shop time', function () {
    $this->travelTo(now()->setTimezone(config('shop.timezone'))->setDate(2026, 11, 26)->setTime(23, 30));

    $submission = submitBuylist(User::factory()->create(), [buylistRow()->id => 1]);

    expect($submission->quote_expires_at->copy()->setTimezone(config('shop.timezone'))->toDateTimeString())->toBe('2026-12-03 23:59:59');
});

test('submitting counts the quantity against the weekly quota', function () {
    $capped = buylistRow(['weekly_quota' => 10, 'accepted_this_week' => 2]);
    $open = buylistRow();

    submitBuylist(User::factory()->create(), [$capped->id => 5, $open->id => 4]);

    expect($capped->fresh()->accepted_this_week)->toBe(7)
        ->and($capped->fresh()->remainingQuota())->toBe(3)
        ->and($open->fresh()->accepted_this_week)->toBe(4);
});

test('a line above the remaining quota is refused by name and writes nothing', function () {
    $other = buylistRow(['cash_satang' => 5000, 'credit_satang' => 5750]);
    $row = buylistRow(['weekly_quota' => 5, 'accepted_this_week' => 3], ['name_en' => 'Zoro']);

    try {
        submitBuylist(User::factory()->create(), [$other->id => 1, $row->id => 3]);
        $this->fail('The quota was not enforced.');
    } catch (BuylistQuotaFull $full) {
        expect($full->cardName)->toBe($row->printing->display_name)
            ->and($full->remaining)->toBe(2);
    }

    expect(BuylistSubmission::query()->count())->toBe(0)
        ->and($row->fresh()->accepted_this_week)->toBe(3)
        ->and($other->fresh()->accepted_this_week)->toBe(0);
});

test('the price rows are locked before anything is read or written about the submission', function () {
    $a = buylistRow();
    $b = buylistRow();
    $log = [];

    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    submitBuylist(User::factory()->create(), [$b->id => 1, $a->id => 1]);

    $begin = array_search('BEGIN', $log, true);
    $update = collect($log)->search(fn (string $sql): bool => str_starts_with($sql, 'update "buylist_prices"'));
    $insert = collect($log)->search(fn (string $sql): bool => str_starts_with($sql, 'insert into "buylist_submissions"'));

    expect($begin)->not->toBeFalse()
        ->and($log[$begin + 1])->toStartWith('select * from "buylist_prices"')
        ->and($log[$begin + 1])->toContain('order by "id" asc')
        ->and($update)->toBeGreaterThan($begin + 1)
        ->and($insert)->toBeGreaterThan($begin + 1);
});

test('a signature that is not the one the customer saw refuses the whole submit', function () {
    $row = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500]);
    $signature = BuylistQuote::signature([['price' => $row, 'qty' => 2]]);

    $row->update(['credit_satang' => 11600]);

    expect(fn () => submitBuylist(User::factory()->create(), [$row->id => 2], signature: $signature))->toThrow(BuylistQuoteChanged::class)
        ->and(BuylistSubmission::query()->count())->toBe(0)
        ->and($row->fresh()->accepted_this_week)->toBe(0);
});

test('the signature is the whole price-to-unit-and-quantity map, so two changes that cancel out in a total still refuse', function () {
    $a = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500]);
    $b = buylistRow(['cash_satang' => 20000, 'credit_satang' => 23000]);
    $signature = BuylistQuote::signature([['price' => $a, 'qty' => 1], ['price' => $b, 'qty' => 1]]);

    $a->update(['cash_satang' => 11000]);
    $b->update(['cash_satang' => 19000]);

    expect(fn () => submitBuylist(User::factory()->create(), [$a->id => 1, $b->id => 1], BuylistPayout::Cash, $signature))->toThrow(BuylistQuoteChanged::class);

    // Another quantity under the same prices is another list.
    $fresh = BuylistQuote::signature([['price' => $a->fresh(), 'qty' => 1], ['price' => $b->fresh(), 'qty' => 1]]);

    expect(fn () => submitBuylist(User::factory()->create(), [$a->id => 2, $b->id => 1], BuylistPayout::Cash, $fresh))->toThrow(BuylistQuoteChanged::class);
});

test('a row that stopped being bought since the page was shown refuses the submit', function (Closure $stop) {
    $row = buylistRow();
    $signature = BuylistQuote::signature([['price' => $row, 'qty' => 1]]);

    $stop($row);

    expect(fn () => submitBuylist(User::factory()->create(), [$row->id => 1], signature: $signature))->toThrow(BuylistQuoteChanged::class);
})->with([
    'deactivated' => [fn (BuylistPrice $row) => $row->update(['is_active' => false])],
    'printing trashed' => [fn (BuylistPrice $row) => $row->printing->delete()],
    'game closed' => [fn (BuylistPrice $row) => $row->printing->set->game->update(['is_active' => false])],
]);

test('an empty list, too many lines, a bad quantity, a repeated row and a staff user are refused', function () {
    $customer = User::factory()->create();
    $a = buylistRow();
    $b = buylistRow();
    $handle = fn (User $user, array $lines) => app(SubmitBuylist::class)->handle($user, BuylistPayout::Cash, $lines, 'x');

    config(['buylist.max_lines' => 1, 'buylist.max_qty_per_line' => 5]);

    expect(fn () => $handle($customer, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $handle($customer, [['buylist_price_id' => $a->id, 'qty' => 1], ['buylist_price_id' => $b->id, 'qty' => 1]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $handle($customer, [['buylist_price_id' => $a->id, 'qty' => 0]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $handle($customer, [['buylist_price_id' => $a->id, 'qty' => 6]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => submitBuylist(User::factory()->staff()->create(), [$a->id => 1]))->toThrow(InvalidArgumentException::class);

    config(['buylist.max_lines' => 5]);

    expect(fn () => $handle($customer, [['buylist_price_id' => $a->id, 'qty' => 1], ['buylist_price_id' => $a->id, 'qty' => 1]]))->toThrow(InvalidArgumentException::class)
        ->and(BuylistSubmission::query()->count())->toBe(0)
        ->and($a->fresh()->accepted_this_week)->toBe(0);
});

test('the customer is notified with the number, the total and the day the quote runs out, and the session list is cleared', function () {
    Notification::fake();
    session(['buylist.lines' => [['buylist_price_id' => 1, 'qty' => 1]]]);
    $customer = User::factory()->create();

    $submission = submitBuylist($customer, [buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500])->id => 2]);

    Notification::assertSentTo($customer, BuylistSubmitted::class, function (BuylistSubmitted $notification) use ($submission, $customer): bool {
        $mail = $notification->toMail($customer);

        return $notification->number === $submission->number
            && $notification->total === '฿ 230'
            && str_contains($notification->toLine($customer), $submission->number)
            && str_contains($notification->toLine($customer), route('buylist.show', $submission->number))
            && str_contains(implode(' ', $mail->introLines), '฿ 230');
    });
    expect(session('buylist.lines'))->toBeNull();
});

test('a row re-pointed at another printing, condition or finish after the page was shown refuses the submit', function (Closure $repoint) {
    $row = buylistRow(['condition' => CardCondition::Nm, 'finish' => Finish::Normal], printing: ['has_foil' => true]);
    $other = Printing::factory()->for($row->printing->card)->for($row->printing->set, 'set')->create();
    $signature = BuylistQuote::signature([['price' => $row, 'qty' => 1]]);

    $repoint($row, $other);

    expect(fn () => submitBuylist(User::factory()->create(), [$row->id => 1], signature: $signature))->toThrow(BuylistQuoteChanged::class)
        ->and(BuylistSubmission::query()->count())->toBe(0);
})->with([
    'printing' => [fn (BuylistPrice $row, Printing $other) => $row->update(['printing_id' => $other->id])],
    'condition' => [fn (BuylistPrice $row) => $row->update(['condition' => CardCondition::Lp])],
    'finish' => [fn (BuylistPrice $row) => $row->update(['finish' => Finish::Foil])],
]);
