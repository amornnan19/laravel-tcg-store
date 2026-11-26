<?php

use App\Actions\AcceptBuylistSubmission;
use App\Actions\AssessBuylistSubmission;
use App\Actions\MarkBuylistPaid;
use App\Actions\ReceiveBuylistSubmission;
use App\Actions\RecordStoreCredit;
use App\Actions\RespondToBuylistRevision;
use App\Actions\ReturnBuylistSubmission;
use App\Enums\BuylistPayout;
use App\Enums\BuylistStatus;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\StoreCreditReason;
use App\Exceptions\BuylistAwaitingCustomer;
use App\Exceptions\BuylistRefused;
use App\Filament\Resources\BuylistSubmissions\Pages\EditBuylistSubmission;
use App\Filament\Resources\BuylistSubmissions\Pages\ListBuylistSubmissions;
use App\Filament\Widgets\BuylistAwaitingCardsWidget;
use App\Jobs\CheckVariantAlerts;
use App\Livewire\Storefront\BuylistRevisionBox;
use App\Models\BuylistPrice;
use App\Models\BuylistSubmission;
use App\Models\StoreCreditEntry;
use App\Models\User;
use App\Models\Variant;
use App\Notifications\BuylistAccepted;
use App\Notifications\BuylistPaid;
use App\Notifications\BuylistRevised;
use App\Notifications\BuylistSubmitted;
use App\Support\BuylistAssessment;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A card the shop buys at NM (cash 100, credit 115) and LP (cash 80, credit 92), the NM row first.
 *
 * @return array{nm: BuylistPrice, lp: BuylistPrice}
 */
function pricedCard(array $nm = [], array $lp = []): array
{
    $nmRow = buylistRow($nm + ['condition' => CardCondition::Nm, 'cash_satang' => 10000, 'credit_satang' => 11500]);
    $lpRow = buylistRow($lp + ['condition' => CardCondition::Lp, 'cash_satang' => 8000, 'credit_satang' => 9200], for: $nmRow->printing);

    return ['nm' => $nmRow, 'lp' => $lpRow];
}

/**
 * A submission of `qty` of the NM row that has arrived (`received`).
 */
function receivedSubmission(BuylistPrice $row, int $qty = 2, BuylistPayout $payout = BuylistPayout::Credit, ?User $customer = null): BuylistSubmission
{
    $submission = submitBuylist($customer ?? User::factory()->create(), [$row->id => $qty], $payout);

    return app(ReceiveBuylistSubmission::class)->handle($submission);
}

/**
 * The assessment of every line at `$condition`, with a stored photo where it is lower than claimed.
 *
 * @return list<array{item_id: int, assessed_condition: CardCondition, assessed_note: ?string, photo_path: ?string}>
 */
function assessAll(BuylistSubmission $submission, CardCondition $condition, ?string $photo = 'buylist-photos/found.jpg'): array
{
    Storage::disk('public')->put('buylist-photos/found.jpg', 'photo');

    return $submission->items()->get()->map(fn ($item): array => [
        'item_id' => $item->id,
        'assessed_condition' => $condition,
        'assessed_note' => null,
        'photo_path' => $condition === $item->claimed_condition ? null : $photo,
    ])->all();
}

function revisedSubmission(BuylistPayout $payout = BuylistPayout::Credit, ?User $customer = null): BuylistSubmission
{
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm, 2, $payout, $customer);

    return app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Lp));
}

beforeEach(function () {
    Storage::fake('public');
});

test('a submission re-assessed at a lower condition needs the customer\'s confirmation before it can be paid', function () {
    Notification::fake();
    $customer = User::factory()->create();
    $submission = revisedSubmission(customer: $customer);

    expect($submission->status)->toBe(BuylistStatus::Revised)
        ->and($submission->final_total_satang)->toBe(2 * 9200)
        ->and($submission->revised_at)->not->toBeNull();
    Notification::assertSentTo($customer, BuylistRevised::class, fn (BuylistRevised $n) => $n->count === 2 && $n->final === '฿ 184');

    // Staff cannot accept it, and nothing moves: no stock, no credit.
    expect(fn () => app(AcceptBuylistSubmission::class)->handle($submission, User::factory()->staff()->create()))->toThrow(BuylistAwaitingCustomer::class)
        ->and(Variant::query()->count())->toBe(0)
        ->and(StoreCreditEntry::query()->count())->toBe(0);

    // The desk offers no way to pay or accept while it waits.
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());
    Livewire::test(EditBuylistSubmission::class, ['record' => $submission->getRouteKey()])
        ->assertActionHidden('accept')
        ->assertActionHidden('markPaid')
        ->assertActionVisible('return')
        ->assertSee(__('admin.buylist.submissions.awaiting_customer'))
        ->assertDontSee(__('admin.buylist.submissions.actions.save'));

    // The customer's answer is what lets it through.
    app(RespondToBuylistRevision::class)->handle($submission, $customer, accept: true);

    expect($submission->fresh()->status)->toBe(BuylistStatus::Paid)
        ->and($customer->fresh()->store_credit_satang)->toBe(2 * 9200);
});

test('a card graded as claimed keeps the quoted unit even when the price has moved since', function () {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm);
    $nm->update(['cash_satang' => 5000, 'credit_satang' => 5500]);

    $assessed = app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Nm));
    $item = $assessed->items->first();

    expect($item->assessed_unit_satang)->toBe(11500)
        ->and($item->photo_path)->toBeNull()
        ->and($assessed->status)->toBe(BuylistStatus::Received);
});

test('a lower condition is paid the current price for that condition, never above the quote', function () {
    ['nm' => $nm, 'lp' => $lp] = pricedCard();
    $submission = receivedSubmission($nm);

    $lp->update(['credit_satang' => 9000]);
    $item = $submission->items()->first()->setRelation('submission', $submission);
    expect(BuylistAssessment::unitFor($item, CardCondition::Lp))->toBe(9000);

    // A price that rose above the quote is capped at it.
    $lp->update(['credit_satang' => 20000]);
    expect(BuylistAssessment::unitFor($item, CardCondition::Lp))->toBe(11500);
});

test('a condition the shop has no buy price for is worth nothing and the line says so', function () {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm);

    $assessed = app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Hp));
    $item = $assessed->items->first();

    expect($item->assessed_unit_satang)->toBe(0)
        ->and($item->assessed_line_satang)->toBe(0)
        ->and($item->assessed_note)->toBe(__('admin.buylist.not_bought'))
        ->and($assessed->status)->toBe(BuylistStatus::Revised)
        ->and($assessed->final_total_satang)->toBe(0);
});

test('a line cannot be assessed above the condition the customer claimed', function () {
    ['lp' => $lp] = pricedCard();
    $submission = receivedSubmission($lp);
    $item = $submission->items()->first()->setRelation('submission', $submission);

    expect(fn () => BuylistAssessment::unitFor($item, CardCondition::Nm))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Nm)))
        ->toThrow(BuylistRefused::class, 'condition_above_claimed')
        ->and(BuylistAssessment::optionsFor(CardCondition::Lp))->toBe([CardCondition::Lp, CardCondition::Mp, CardCondition::Hp, CardCondition::Dmg]);
});

test('a line graded lower needs a photo that exists', function () {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm);

    expect(fn () => app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Lp, photo: null)))
        ->toThrow(BuylistRefused::class, 'photo_required')
        ->and(fn () => app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Lp, photo: 'buylist-photos/nope.jpg')))
        ->toThrow(BuylistRefused::class, 'photo_missing')
        ->and(fn () => app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Lp, photo: '../escape.jpg')))
        ->toThrow(BuylistRefused::class, 'photo_missing')
        ->and($submission->fresh()->status)->toBe(BuylistStatus::Received);
});

test('the admin form requires a photo on a lowered line and saves the assessment through the action', function () {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm);
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());

    $page = Livewire::test(EditBuylistSubmission::class, ['record' => $submission->getRouteKey()])->assertOk();
    $key = array_key_first($page->get('data.assessments'));

    $page->set("data.assessments.{$key}.assessed_condition", 'lp')
        ->call('save')
        ->assertHasErrors(["data.assessments.{$key}.photo_path" => 'required']);
    expect($submission->fresh()->status)->toBe(BuylistStatus::Received);

    // The unit on screen is recalculated for the picked condition.
    $page->assertSee('฿ 92');
});

test('a parcel that arrives after the quote ran out is received only with a reason', function () {
    ['nm' => $nm] = pricedCard();
    $submission = submitBuylist(User::factory()->create(), [$nm->id => 1]);
    $submission->update(['quote_expires_at' => now()->subDay()]);

    expect(fn () => app(ReceiveBuylistSubmission::class)->handle($submission))->toThrow(BuylistRefused::class, 'late_note_required')
        ->and($submission->fresh()->status)->toBe(BuylistStatus::Submitted);

    $received = app(ReceiveBuylistSubmission::class)->handle($submission, 'พัสดุล่าช้าที่ขนส่ง');

    expect($received->status)->toBe(BuylistStatus::Received)
        ->and($received->late_note)->toBe('พัสดุล่าช้าที่ขนส่ง')
        ->and($received->received_at)->not->toBeNull();
});

test('an on-time parcel needs no reason, and only a submitted list can be received', function () {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm);

    expect($submission->late_note)->toBeNull()
        ->and(fn () => app(ReceiveBuylistSubmission::class)->handle($submission))->toThrow(BuylistRefused::class, 'illegal_status');
});

test('a line marked down by even one satang sends the list to the customer, and an unchanged one does not', function () {
    ['nm' => $nm] = pricedCard(lp: ['credit_satang' => 11499]);
    $down = receivedSubmission($nm);
    $same = receivedSubmission($nm);

    expect(app(AssessBuylistSubmission::class)->handle($down, assessAll($down, CardCondition::Lp))->status)->toBe(BuylistStatus::Revised)
        ->and(app(AssessBuylistSubmission::class)->handle($same, assessAll($same, CardCondition::Nm))->status)->toBe(BuylistStatus::Received);

    // A lower condition priced exactly at the quote is no reduction in money, though the photo is still needed.
    $nm->update(['credit_satang' => 11499]);
    $equal = receivedSubmission($nm);
    expect(app(AssessBuylistSubmission::class)->handle($equal, assessAll($equal, CardCondition::Lp))->status)->toBe(BuylistStatus::Received);
});

test('accepting with credit pays the ledger with the submission as source and puts the cards in stock', function () {
    Notification::fake();
    Bus::fake([CheckVariantAlerts::class]);
    ['nm' => $nm] = pricedCard();
    $customer = User::factory()->create();
    $submission = receivedSubmission($nm, 3, customer: $customer);
    $variant = Variant::factory()->create(['printing_id' => $nm->printing_id, 'condition' => CardCondition::Nm, 'finish' => Finish::Normal, 'qty' => 0, 'price_satang' => 15000]);
    $staff = User::factory()->staff()->create();

    $result = app(AcceptBuylistSubmission::class)->handle($submission, $staff);

    $entry = StoreCreditEntry::query()->sole();
    expect($result->submission->status)->toBe(BuylistStatus::Paid)
        ->and($result->createdVariants)->toBe(0)
        ->and($result->submission->paid_at)->not->toBeNull()
        ->and($result->submission->accepted_at)->not->toBeNull()
        ->and($result->submission->final_total_satang)->toBe(3 * 11500)
        ->and($entry->delta_satang)->toBe(3 * 11500)
        ->and($entry->reason)->toBe(StoreCreditReason::Buylist)
        ->and($entry->source->is($submission))->toBeTrue()
        ->and($entry->created_by)->toBe($staff->id)
        ->and($customer->fresh()->store_credit_satang)->toBe(3 * 11500)
        ->and($variant->fresh()->qty)->toBe(3);
    Notification::assertSentTo($customer, BuylistPaid::class, fn (BuylistPaid $n) => $n->reference === null && $n->amount === '฿ 345');
    Bus::assertDispatched(CheckVariantAlerts::class, fn (CheckVariantAlerts $job) => $job->variantId === $variant->id);
});

test('a variant the shop does not have yet is created unpriced, so it cannot be bought', function () {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm, 2);

    $result = app(AcceptBuylistSubmission::class)->handle($submission);

    $variant = Variant::query()->sole();
    expect($result->createdVariants)->toBe(1)
        ->and($variant->printing_id)->toBe($nm->printing_id)
        ->and($variant->condition)->toBe(CardCondition::Nm)
        ->and($variant->finish)->toBe(Finish::Normal)
        ->and($variant->price_satang)->toBeNull()
        ->and($variant->qty)->toBe(2)
        ->and($variant->isPurchasable())->toBeFalse();
});

test('cards graded lower go into the variant of the condition they were found in', function () {
    ['nm' => $nm, 'lp' => $lp] = pricedCard();
    $customer = User::factory()->create();
    $submission = receivedSubmission($nm, 2, customer: $customer);
    $submission = app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Lp));

    app(RespondToBuylistRevision::class)->handle($submission, $customer, accept: true);

    expect(Variant::query()->sole()->condition)->toBe(CardCondition::Lp)
        ->and(Variant::query()->sole()->qty)->toBe(2);
});

test('a line worth nothing is not absorbed into stock and is marked as returned with the parcel', function () {
    ['nm' => $nm] = pricedCard();
    $customer = User::factory()->create();
    $second = buylistRow(['condition' => CardCondition::Nm, 'cash_satang' => 5000, 'credit_satang' => 5750]);
    $submission = submitBuylist($customer, [$nm->id => 1, $second->id => 1]);
    $submission = app(ReceiveBuylistSubmission::class)->handle($submission);

    $first = $submission->items()->where('buylist_price_id', $nm->id)->first();
    $assessments = $submission->items()->get()->map(fn ($item): array => $item->is($first)
        ? ['item_id' => $item->id, 'assessed_condition' => CardCondition::Dmg, 'assessed_note' => null, 'photo_path' => assessAll($submission, CardCondition::Dmg)[0]['photo_path']]
        : ['item_id' => $item->id, 'assessed_condition' => CardCondition::Nm, 'assessed_note' => null, 'photo_path' => null])->all();
    $submission = app(AssessBuylistSubmission::class)->handle($submission, $assessments);

    app(RespondToBuylistRevision::class)->handle($submission, $customer, accept: true);

    expect(Variant::query()->count())->toBe(1)
        ->and(Variant::query()->where('printing_id', $nm->printing_id)->exists())->toBeFalse()
        ->and($first->fresh()->assessed_note)->toBe(__('admin.buylist.returned_with_parcel'))
        ->and($customer->fresh()->store_credit_satang)->toBe(5750);
});

test('accepting is one transaction: a failure after the stock step leaves no credit and no stock', function () {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm, 2);
    $variant = Variant::factory()->create(['printing_id' => $nm->printing_id, 'condition' => CardCondition::Nm, 'finish' => Finish::Normal, 'qty' => 1]);

    $this->mock(RecordStoreCredit::class)->shouldReceive('handle')->andThrow(new RuntimeException('ledger down'));

    expect(fn () => app(AcceptBuylistSubmission::class)->handle($submission))->toThrow(RuntimeException::class, 'ledger down')
        ->and($variant->fresh()->qty)->toBe(1)
        ->and($submission->fresh()->status)->toBe(BuylistStatus::Received)
        ->and(StoreCreditEntry::query()->count())->toBe(0);
});

test('a cash submission is accepted and then marked paid with the transfer reference', function () {
    Notification::fake();
    ['nm' => $nm] = pricedCard();
    $customer = User::factory()->create();
    $staff = User::factory()->staff()->create();
    $submission = receivedSubmission($nm, 2, BuylistPayout::Cash, $customer);

    $accepted = app(AcceptBuylistSubmission::class)->handle($submission, $staff)->submission;

    expect($accepted->status)->toBe(BuylistStatus::Accepted)
        ->and($accepted->paid_at)->toBeNull()
        ->and(StoreCreditEntry::query()->count())->toBe(0)
        ->and(Variant::query()->sole()->qty)->toBe(2);
    Notification::assertSentTo($customer, BuylistAccepted::class, fn (BuylistAccepted $n) => $n->amount === '฿ 200');

    expect(fn () => app(MarkBuylistPaid::class)->handle($accepted, '  ', $staff))->toThrow(BuylistRefused::class, 'reference_required')
        ->and(fn () => app(MarkBuylistPaid::class)->handle($accepted, 'REF-1', $customer))->toThrow(BuylistRefused::class, 'not_staff');

    $paid = app(MarkBuylistPaid::class)->handle($accepted, ' REF-1 ', $staff);

    expect($paid->status)->toBe(BuylistStatus::Paid)
        ->and($paid->payout_reference)->toBe('REF-1')
        ->and($paid->paid_at)->not->toBeNull();
    Notification::assertSentTo($customer, BuylistPaid::class, fn (BuylistPaid $n) => $n->reference === 'REF-1');
    expect(fn () => app(MarkBuylistPaid::class)->handle($paid, 'REF-2', $staff))->toThrow(BuylistRefused::class, 'illegal_status');
});

test('only an accepted cash submission can be marked paid', function () {
    ['nm' => $nm] = pricedCard();
    $staff = User::factory()->staff()->create();
    $credit = app(AcceptBuylistSubmission::class)->handle(receivedSubmission($nm, 1, BuylistPayout::Credit))->submission;
    $cashReceived = receivedSubmission($nm, 1, BuylistPayout::Cash);

    expect(fn () => app(MarkBuylistPaid::class)->handle($credit, 'REF', $staff))->toThrow(BuylistRefused::class, 'illegal_status')
        ->and(fn () => app(MarkBuylistPaid::class)->handle($cashReceived, 'REF', $staff))->toThrow(BuylistRefused::class, 'illegal_status');
});

test('accept is refused from any status but received, and twice never pays twice', function () {
    ['nm' => $nm] = pricedCard();
    $submitted = submitBuylist(User::factory()->create(), [$nm->id => 1]);

    expect(fn () => app(AcceptBuylistSubmission::class)->handle($submitted))->toThrow(BuylistRefused::class, 'illegal_status')
        ->and(Variant::query()->count())->toBe(0);

    $received = receivedSubmission($nm, 1);
    app(AcceptBuylistSubmission::class)->handle($received);

    expect(fn () => app(AcceptBuylistSubmission::class)->handle($received))->toThrow(BuylistRefused::class, 'illegal_status')
        ->and(StoreCreditEntry::query()->count())->toBe(1)
        ->and(Variant::query()->sole()->qty)->toBe(1);
});

test('the customer confirms a revised total: credit pays at once, cash waits for the transfer', function (BuylistPayout $payout, BuylistStatus $expected) {
    $customer = User::factory()->create();
    $submission = revisedSubmission($payout, $customer);

    $answered = app(RespondToBuylistRevision::class)->handle($submission, $customer, accept: true);

    expect($answered->status)->toBe($expected)
        ->and($answered->responded_at)->not->toBeNull()
        ->and($answered->accepted_at)->not->toBeNull()
        ->and(Variant::query()->sole()->qty)->toBe(2);
})->with([
    'credit' => [BuylistPayout::Credit, BuylistStatus::Paid],
    'cash' => [BuylistPayout::Cash, BuylistStatus::Accepted],
]);

test('asking for the cards back returns the list and gives the quota back once', function () {
    ['nm' => $nm] = pricedCard(['weekly_quota' => 10]);
    $customer = User::factory()->create();
    $submission = receivedSubmission($nm, 4, customer: $customer);
    $submission = app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Lp));
    expect($nm->fresh()->accepted_this_week)->toBe(4);

    $returned = app(RespondToBuylistRevision::class)->handle($submission, $customer, accept: false);

    expect($returned->status)->toBe(BuylistStatus::Returned)
        ->and($returned->responded_at)->not->toBeNull()
        ->and($returned->returned_at)->toBeNull()
        ->and($returned->quota_released_at)->not->toBeNull()
        ->and($nm->fresh()->accepted_this_week)->toBe(0)
        ->and(Variant::query()->count())->toBe(0)
        ->and(StoreCreditEntry::query()->count())->toBe(0);

    // Staff recording the parcel's tracking later releases nothing more.
    $nm->forceFill(['accepted_this_week' => 3])->save();
    $tracked = app(ReturnBuylistSubmission::class)->handle($returned, 'TH123');

    expect($tracked->return_tracking)->toBe('TH123')
        ->and($tracked->returned_at)->not->toBeNull()
        ->and($nm->fresh()->accepted_this_week)->toBe(3);
});

test('only the owner can answer, and only while the list is revised', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $submission = revisedSubmission(customer: $owner);

    expect(fn () => app(RespondToBuylistRevision::class)->handle($submission, $stranger, accept: true))->toThrow(BuylistRefused::class, 'not_owner')
        ->and(fn () => app(RespondToBuylistRevision::class)->handle($submission, $stranger, accept: false))->toThrow(BuylistRefused::class, 'not_owner')
        ->and($submission->fresh()->status)->toBe(BuylistStatus::Revised)
        ->and(Variant::query()->count())->toBe(0);

    ['nm' => $nm] = pricedCard();
    $received = receivedSubmission($nm, 1, customer: $owner);

    expect(fn () => app(RespondToBuylistRevision::class)->handle($received, $owner, accept: true))->toThrow(BuylistRefused::class, 'not_revised')
        ->and(fn () => app(RespondToBuylistRevision::class)->handle($received, $owner, accept: false))->toThrow(BuylistRefused::class, 'not_revised')
        ->and(Variant::query()->count())->toBe(0);

    app(RespondToBuylistRevision::class)->handle($submission, $owner, accept: true);

    expect(fn () => app(RespondToBuylistRevision::class)->handle($submission, $owner, accept: true))->toThrow(BuylistRefused::class, 'not_revised')
        ->and(StoreCreditEntry::query()->count())->toBe(1);
});

test('a revised total of zero can only be answered by asking for the cards back', function () {
    ['nm' => $nm] = pricedCard();
    $customer = User::factory()->create();
    $submission = receivedSubmission($nm, 1, customer: $customer);
    $submission = app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Hp));

    expect(fn () => app(RespondToBuylistRevision::class)->handle($submission, $customer, accept: true))->toThrow(BuylistRefused::class, 'nothing_to_pay')
        ->and($submission->fresh()->status)->toBe(BuylistStatus::Revised)
        ->and(app(RespondToBuylistRevision::class)->handle($submission, $customer, accept: false)->status)->toBe(BuylistStatus::Returned);
});

test('staff returning a list release its quota once, whatever the status it was returned from', function (BuylistStatus $from) {
    ['nm' => $nm] = pricedCard(['weekly_quota' => 10]);
    $customer = User::factory()->create();
    $submission = submitBuylist($customer, [$nm->id => 3]);

    if ($from !== BuylistStatus::Submitted) {
        $submission = app(ReceiveBuylistSubmission::class)->handle($submission);
    }

    if ($from === BuylistStatus::Revised) {
        $submission = app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Lp));
    }

    $returned = app(ReturnBuylistSubmission::class)->handle($submission);

    expect($returned->status)->toBe(BuylistStatus::Returned)
        ->and($returned->returned_at)->not->toBeNull()
        ->and($nm->fresh()->accepted_this_week)->toBe(0);

    // A second return is refused, and recording the parcel's number does not release it again.
    expect(fn () => app(ReturnBuylistSubmission::class)->handle($returned))->toThrow(BuylistRefused::class, 'tracking_required');
    $nm->forceFill(['accepted_this_week' => 5])->save();
    app(ReturnBuylistSubmission::class)->handle($returned, 'TRACK-1');

    expect($nm->fresh()->accepted_this_week)->toBe(5)
        ->and(fn () => app(ReturnBuylistSubmission::class)->handle($returned, 'TRACK-2'))->toThrow(BuylistRefused::class, 'illegal_status');
})->with([BuylistStatus::Submitted, BuylistStatus::Received, BuylistStatus::Revised]);

test('a paid or accepted list cannot be returned by staff', function () {
    ['nm' => $nm] = pricedCard();
    $paid = app(AcceptBuylistSubmission::class)->handle(receivedSubmission($nm, 1))->submission;

    expect(fn () => app(ReturnBuylistSubmission::class)->handle($paid))->toThrow(BuylistRefused::class, 'illegal_status');
});

test('a stale assessment form is refused', function () {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm);
    $shown = BuylistAssessment::fingerprint($submission->load('items'));

    app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Nm));

    expect(fn () => app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Nm), $shown))
        ->toThrow(BuylistRefused::class, 'stale');
});

test('accepting takes the variants first, then the submission, then the customer\'s credit', function () {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm, 2);
    Variant::factory()->create(['printing_id' => $nm->printing_id, 'condition' => CardCondition::Nm, 'finish' => Finish::Normal, 'qty' => 1]);
    $log = [];

    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    app(AcceptBuylistSubmission::class)->handle($submission);

    $begin = array_search('BEGIN', $log, true);
    $after = array_slice($log, $begin + 1);
    $variants = collect($after)->search(fn (string $sql): bool => str_starts_with($sql, 'select * from "variants"'));
    $locked = collect($after)->search(fn (string $sql): bool => str_starts_with($sql, 'select * from "buylist_submissions"'));
    $users = collect($after)->search(fn (string $sql): bool => str_starts_with($sql, 'select * from "users"'));

    expect($variants)->toBe(0)
        ->and($after[0])->toContain('order by "id" asc')
        ->and($locked)->toBeGreaterThan($variants)
        ->and($users)->toBeGreaterThan($locked);
});

test('the assessment locks the price rows before the submission', function () {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm, 1);
    $assessments = assessAll($submission, CardCondition::Lp);
    $log = [];

    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    app(AssessBuylistSubmission::class)->handle($submission, $assessments);

    $after = array_slice($log, array_search('BEGIN', $log, true) + 1);

    expect($after[0])->toStartWith('select "id" from "buylist_prices"')
        ->and($after[0])->toContain('order by "id" asc')
        ->and($after[1])->toStartWith('select * from "buylist_submissions"');
});

test('the customer page shows the revised box with the gold confirm and the confirmed return, to its owner only', function () {
    $owner = User::factory()->create();
    $submission = revisedSubmission(customer: $owner);
    $item = $submission->items()->first();
    $item->update(['assessed_note' => 'มุมการ์ดงอ']);

    $this->actingAs($owner)
        ->get(route('buylist.show', $submission->number))
        ->assertOk()
        ->assertSee(__('storefront.buylist.revision.confirm', ['final' => '฿ 184']))
        ->assertSee(__('storefront.buylist.revision.return'))
        ->assertSee('มุมการ์ดงอ')
        ->assertSee(__('storefront.buylist.revision.new_unit', ['amount' => '฿ 92']))
        ->assertSee(Storage::disk('public')->url('buylist-photos/found.jpg'), false);

    $this->actingAs(User::factory()->create())
        ->get(route('buylist.show', $submission->number))
        ->assertNotFound();
});

test('the revision box confirms and returns through the actions, and refuses another user', function () {
    $owner = User::factory()->create();
    $submission = revisedSubmission(customer: $owner);

    $this->actingAs(User::factory()->create());
    Livewire::test(BuylistRevisionBox::class, ['submission' => $submission])->call('confirmNewTotal')->assertNotFound();
    expect($submission->fresh()->status)->toBe(BuylistStatus::Revised);

    $this->actingAs($owner);
    Livewire::test(BuylistRevisionBox::class, ['submission' => $submission])
        ->call('confirmNewTotal')
        ->assertRedirect(route('buylist.show', $submission->number));
    expect($submission->fresh()->status)->toBe(BuylistStatus::Paid);

    // A second click, from a page that has not noticed, is told why nothing happened.
    Livewire::test(BuylistRevisionBox::class, ['submission' => $submission])
        ->call('askForCardsBack')
        ->assertSet('error', __('storefront.buylist.revision.refused.not_revised'));
    expect($submission->fresh()->status)->toBe(BuylistStatus::Paid);

    $other = revisedSubmission(customer: $owner);
    Livewire::test(BuylistRevisionBox::class, ['submission' => $other])->call('askForCardsBack');
    expect($other->fresh()->status)->toBe(BuylistStatus::Returned);
});

test('the customer page says where the list stands in every state', function (BuylistStatus $status, array $attributes, array $see) {
    $owner = User::factory()->create();
    ['nm' => $nm] = pricedCard();
    $submission = submitBuylist($owner, [$nm->id => 1]);
    $submission->update(['status' => $status] + $attributes);

    $page = $this->actingAs($owner)->get(route('buylist.show', $submission->number))->assertOk();

    foreach ($see as $text) {
        $page->assertSee($text());
    }
})->with([
    'received' => [BuylistStatus::Received, [], [fn () => __('storefront.buylist.show.status_received')]],
    'accepted cash' => [BuylistStatus::Accepted, [], [fn () => __('storefront.buylist.show.status_accepted')]],
    'paid credit' => [BuylistStatus::Paid, [], [fn () => __('storefront.buylist.show.status_paid'), fn () => __('storefront.buylist.show.status_paid_credit', ['amount' => '฿ 115'])]],
    'paid cash' => [BuylistStatus::Paid, ['payout_reference' => 'REF-9'], [fn () => __('storefront.buylist.show.status_paid_cash', ['ref' => 'REF-9'])]],
    'returned' => [BuylistStatus::Returned, [], [fn () => __('storefront.buylist.show.status_returned')]],
    'returned with tracking' => [BuylistStatus::Returned, ['return_tracking' => 'TH55'], [fn () => __('storefront.buylist.show.status_returned_tracking', ['tracking' => 'TH55'])]],
]);

test('the submissions table opens on the open statuses, oldest first, and non-staff are refused', function () {
    ['nm' => $nm] = pricedCard();
    $first = submitBuylist(User::factory()->create(), [$nm->id => 1]);
    $second = submitBuylist(User::factory()->create(), [$nm->id => 1]);
    $done = submitBuylist(User::factory()->create(), [$nm->id => 1]);
    $done->update(['status' => BuylistStatus::Paid]);

    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());
    Livewire::test(ListBuylistSubmissions::class)
        ->assertCanSeeTableRecords([$first, $second], inOrder: true)
        ->assertCanNotSeeTableRecords([$done]);

    // The widget links here with the status already filtered.
    Livewire::withQueryParams(['filters' => ['status' => ['values' => ['submitted']]]])
        ->test(ListBuylistSubmissions::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->assertCanNotSeeTableRecords([$done]);
    $revised = revisedSubmission();
    Livewire::withQueryParams(['filters' => ['status' => ['values' => ['revised']]]])
        ->test(ListBuylistSubmissions::class)
        ->assertCanSeeTableRecords([$revised])
        ->assertCanNotSeeTableRecords([$first, $second]);

    $this->actingAs(User::factory()->create())
        ->get('/admin/buylist-submissions')
        ->assertForbidden();
});

test('the desk walks a list from received to paid with the actions', function () {
    Notification::fake();
    ['nm' => $nm] = pricedCard();
    $customer = User::factory()->create();
    $submission = submitBuylist($customer, [$nm->id => 2], BuylistPayout::Cash);
    $staff = User::factory()->staff()->withAppAuthentication()->create();
    $this->actingAs($staff);
    $page = fn () => Livewire::test(EditBuylistSubmission::class, ['record' => $submission->getRouteKey()]);

    $page()->assertActionVisible('receive')->assertActionHidden('accept')->assertActionVisible('return')
        ->callAction('receive');
    expect($submission->fresh()->status)->toBe(BuylistStatus::Received);

    $page()->assertActionHidden('receive')->assertActionVisible('accept')->assertActionHidden('markPaid')
        ->callAction('accept');
    expect($submission->fresh()->status)->toBe(BuylistStatus::Accepted)
        ->and(Variant::query()->sole()->qty)->toBe(2);

    $page()->assertActionHidden('accept')->assertActionVisible('markPaid')->assertActionHidden('return')
        ->callAction('markPaid', ['reference' => ''])->assertHasActionErrors(['reference' => 'required']);
    $page()->callAction('markPaid', ['reference' => 'KBANK-1']);
    expect($submission->fresh()->status)->toBe(BuylistStatus::Paid)
        ->and($submission->fresh()->payout_reference)->toBe('KBANK-1');
});

test('a late parcel cannot be received from the desk without the reason', function () {
    ['nm' => $nm] = pricedCard();
    $submission = submitBuylist(User::factory()->create(), [$nm->id => 1]);
    $submission->update(['quote_expires_at' => now()->subDay()]);
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());

    Livewire::test(EditBuylistSubmission::class, ['record' => $submission->getRouteKey()])
        ->callAction('receive', ['late_note' => ''])
        ->assertHasActionErrors(['late_note' => 'required']);
    expect($submission->fresh()->status)->toBe(BuylistStatus::Submitted);

    Livewire::test(EditBuylistSubmission::class, ['record' => $submission->getRouteKey()])
        ->callAction('receive', ['late_note' => 'ขนส่งช้า']);

    expect($submission->fresh()->status)->toBe(BuylistStatus::Received)
        ->and($submission->fresh()->late_note)->toBe('ขนส่งช้า');
});

test('a returned list with no tracking offers the tracking action and records it', function () {
    ['nm' => $nm] = pricedCard();
    $submission = app(ReturnBuylistSubmission::class)->handle(submitBuylist(User::factory()->create(), [$nm->id => 1]));
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());

    Livewire::test(EditBuylistSubmission::class, ['record' => $submission->getRouteKey()])
        ->assertActionVisible('recordTracking')
        ->assertActionHidden('return')
        ->callAction('recordTracking', ['tracking' => 'TH999']);

    expect($submission->fresh()->return_tracking)->toBe('TH999');
});

test('the widget counts lists waiting for cards and lists waiting for the customer', function () {
    ['nm' => $nm] = pricedCard();
    submitBuylist(User::factory()->create(), [$nm->id => 1]);
    submitBuylist(User::factory()->create(), [$nm->id => 1]);
    revisedSubmission();
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());

    $stats = Livewire::test(BuylistAwaitingCardsWidget::class)->assertOk();

    $stats->assertSee(__('admin.widgets.buylist_awaiting_cards'))
        ->assertSee(__('admin.widgets.buylist_revised'));
    $values = collect((new ReflectionMethod(BuylistAwaitingCardsWidget::class, 'getStats'))->invoke($stats->instance()))
        ->map(fn ($stat) => $stat->getValue())->all();
    expect($values)->toBe([2, 1]);

    expect(BuylistAwaitingCardsWidget::canView())->toBeTrue();
    $this->actingAs(User::factory()->create());
    expect(BuylistAwaitingCardsWidget::canView())->toBeFalse();
});

test('an assessment is written only while the list is received', function (BuylistStatus $status) {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm);
    $assessments = assessAll($submission, CardCondition::Lp);
    $submission->update(['status' => $status]);

    expect(fn () => app(AssessBuylistSubmission::class)->handle($submission, $assessments))->toThrow(BuylistRefused::class, 'illegal_status')
        ->and($submission->items()->first()->assessed_condition)->toBeNull()
        ->and($submission->fresh()->status)->toBe($status);
})->with([BuylistStatus::Submitted, BuylistStatus::Revised, BuylistStatus::Accepted, BuylistStatus::Paid, BuylistStatus::Returned]);

class LockMarkingGrammar extends SQLiteGrammar
{
    /**
     * SQLite has no row locks and drops `for update`; this one leaves a comment where a lock was asked for, so a test can
     * see that the query builder requested it (not that a database held it).
     */
    protected function compileLock(Builder $query, $value): string
    {
        return $value ? ' /* lock */' : '';
    }
}

test('a LINE push that fails for good falls back to the customer\'s address as it was when the message was made', function (string $notification) {
    Notification::fake();
    $customer = User::factory()->create(['email' => 'seller@example.test']);
    $submission = revisedSubmission(customer: $customer)->load('items', 'user');
    $message = new $notification($submission);
    $customer->update(['email' => 'changed@example.test']);

    $message->failed(new RequestException(new Illuminate\Http\Client\Response(new Response(500))));

    Notification::assertSentOnDemand($notification, fn ($sent, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'seller@example.test' && $channels === ['mail']);
})->with([
    'submitted' => [BuylistSubmitted::class],
    'revised' => [BuylistRevised::class],
    'accepted' => [BuylistAccepted::class],
    'paid' => [BuylistPaid::class],
]);

test('a customer with no email has no fallback address, and nothing is sent', function () {
    Notification::fake();
    $customer = User::factory()->create(['email' => null]);
    $message = new BuylistPaid(revisedSubmission(customer: $customer)->load('user'));

    $message->failed(new RequestException(new Illuminate\Http\Client\Response(new Response(500))));

    expect($message->fallbackMailRoute(null))->toBeNull();
    Notification::assertNothingSent();
});

test('accepting is not offered once a line is marked down on the form, saved or not', function () {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm, 2);
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());

    $page = Livewire::test(EditBuylistSubmission::class, ['record' => $submission->getRouteKey()])->assertActionVisible('accept');
    $key = array_key_first($page->get('data.assessments'));

    $page->set("data.assessments.{$key}.assessed_condition", 'lp')
        ->assertActionHidden('accept');
    expect($submission->fresh()->status)->toBe(BuylistStatus::Received);

    // Back to the claimed condition: offered again, and the modal names the total on the form.
    $page->set("data.assessments.{$key}.assessed_condition", 'nm')
        ->assertActionVisible('accept')
        ->mountAction('accept')
        ->assertMountedActionModalSee(__('admin.buylist.submissions.actions.accept_credit', ['amount' => '฿ 230']));
});

test('the assessment reads the lines under a lock after locking the submission', function () {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm, 1);
    $assessments = assessAll($submission, CardCondition::Nm);
    $log = [];
    $connection = DB::connection();
    $connection->setQueryGrammar(new LockMarkingGrammar($connection));

    try {
        Event::listen(TransactionBeginning::class, function () use (&$log): void {
            $log[] = 'BEGIN';
        });
        DB::listen(function ($query) use (&$log): void {
            $log[] = $query->sql;
        });

        app(AssessBuylistSubmission::class)->handle($submission, $assessments);
    } finally {
        $connection->useDefaultQueryGrammar();
    }

    $after = array_slice($log, array_search('BEGIN', $log, true) + 1);
    $lockedSubmission = collect($after)->search(fn (string $sql): bool => str_starts_with($sql, 'select * from "buylist_submissions"'));
    $items = collect($after)->search(fn (string $sql): bool => str_starts_with($sql, 'select * from "buylist_submission_items"'));

    expect($after[$lockedSubmission])->toContain('/* lock */')
        ->and($items)->toBeGreaterThan($lockedSubmission)
        ->and($after[$items])->toContain('/* lock */');
});

test('a variant the shop already has is not duplicated and a missing one is created exactly once, even inside an outer transaction', function () {
    ['nm' => $nm] = pricedCard();
    $have = Variant::factory()->create(['printing_id' => $nm->printing_id, 'condition' => CardCondition::Nm, 'finish' => Finish::Normal, 'qty' => 5, 'price_satang' => 20000]);

    $first = DB::transaction(fn () => app(AcceptBuylistSubmission::class)->handle(receivedSubmission($nm, 1)));

    expect($first->createdVariants)->toBe(0)
        ->and(Variant::query()->count())->toBe(1)
        ->and($have->fresh()->qty)->toBe(6)
        ->and($have->fresh()->price_satang)->toBe(20000);

    $other = buylistRow(['condition' => CardCondition::Nm, 'cash_satang' => 1000, 'credit_satang' => 1150]);
    $created = DB::transaction(fn () => app(AcceptBuylistSubmission::class)->handle(receivedSubmission($other, 1)));
    $again = app(AcceptBuylistSubmission::class)->handle(receivedSubmission($other, 2));

    $variant = Variant::query()->where('printing_id', $other->printing_id)->sole();
    expect($created->createdVariants)->toBe(1)
        ->and($again->createdVariants)->toBe(0)
        ->and($variant->qty)->toBe(3)
        ->and($variant->price_satang)->toBeNull()
        ->and($variant->sku)->not->toBeEmpty()
        ->and(Variant::query()->count())->toBe(2);
});

test('a replaced or cleared assessment photo loses its file after the commit', function () {
    ['nm' => $nm] = pricedCard(lp: ['credit_satang' => 11500]);
    $submission = receivedSubmission($nm, 1);
    $disk = Storage::disk('public');
    foreach (['a', 'b'] as $name) {
        $disk->put("buylist-photos/{$name}.jpg", $name);
    }

    $assess = fn (CardCondition $condition, ?string $photo) => app(AssessBuylistSubmission::class)->handle($submission, [[
        'item_id' => $submission->items()->first()->id, 'assessed_condition' => $condition, 'assessed_note' => null, 'photo_path' => $photo,
    ]]);

    // A lower condition priced at the quote is no reduction, so the list stays `received` and can be assessed again.
    expect($assess(CardCondition::Lp, 'buylist-photos/a.jpg')->status)->toBe(BuylistStatus::Received);
    expect($disk->exists('buylist-photos/a.jpg'))->toBeTrue();

    $assess(CardCondition::Lp, 'buylist-photos/b.jpg');
    expect($disk->exists('buylist-photos/a.jpg'))->toBeFalse()
        ->and($disk->exists('buylist-photos/b.jpg'))->toBeTrue();

    $assess(CardCondition::Nm, null);
    expect($disk->exists('buylist-photos/b.jpg'))->toBeFalse()
        ->and($submission->items()->first()->photo_path)->toBeNull();
});

test('a photo uploaded with a refused save is deleted, and one that a saved line uses is kept', function () {
    Storage::fake('local');
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm, 1);
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());

    // A save from a page that has gone stale is refused, and the photo it uploaded must not stay behind.
    $stale = Livewire::test(EditBuylistSubmission::class, ['record' => $submission->getRouteKey()]);
    $key = array_key_first($stale->get('data.assessments'));
    app(AssessBuylistSubmission::class)->handle($submission, assessAll($submission, CardCondition::Nm));

    $stale->set("data.assessments.{$key}.assessed_condition", 'lp')
        ->set("data.assessments.{$key}.photo_path", UploadedFile::fake()->image('found.jpg'))
        ->call('save');

    expect($submission->fresh()->status)->toBe(BuylistStatus::Received)
        // `assessAll()` stored `found.jpg` itself; the uploaded file has a name of its own.
        ->and(collect(Storage::disk('public')->allFiles('buylist-photos'))->reject(fn (string $path): bool => $path === 'buylist-photos/found.jpg'))->toBeEmpty();

    // A save that goes through keeps the file its line now points at.
    $fresh = Livewire::test(EditBuylistSubmission::class, ['record' => $submission->getRouteKey()]);
    $key = array_key_first($fresh->get('data.assessments'));
    $fresh->set("data.assessments.{$key}.assessed_condition", 'lp')
        ->set("data.assessments.{$key}.photo_path", UploadedFile::fake()->image('found.jpg'))
        ->call('save');

    $kept = $submission->items()->first()->photo_path;
    expect($submission->fresh()->status)->toBe(BuylistStatus::Revised)
        ->and($kept)->toStartWith('buylist-photos/')
        ->and(Storage::disk('public')->exists($kept))->toBeTrue();
});

test('a list gives its quota back only when it was submitted since the last Monday 00:00 shop time', function (string $submittedAt, string $returnedAt, int $expectedCount) {
    $zone = config('shop.timezone');
    ['nm' => $nm] = pricedCard(['weekly_quota' => 10]);
    $customer = User::factory()->create();

    $this->travelTo(Carbon::parse($submittedAt, $zone));
    $submission = submitBuylist($customer, [$nm->id => 3]);

    $this->travelTo(Carbon::parse($returnedAt, $zone));
    $returned = app(ReturnBuylistSubmission::class)->handle($submission);

    expect($nm->fresh()->accepted_this_week)->toBe($expectedCount)
        ->and($returned->quota_released_at)->not->toBeNull()
        ->and($returned->status)->toBe(BuylistStatus::Returned);
})->with([
    'the same week' => ['2026-12-02 12:00:00', '2026-12-06 23:59:59', 0],
    'submitted on the Monday midnight itself' => ['2026-12-07 00:00:00', '2026-12-09 10:00:00', 0],
    'submitted the second before the reset' => ['2026-12-06 23:59:59', '2026-12-07 00:00:01', 3],
    'a week and more before' => ['2026-11-25 12:00:00', '2026-12-09 10:00:00', 3],
]);

test('the customer page asks for the packing and the address only while the cards are awaited', function (BuylistStatus $status, bool $shown) {
    config(['buylist.receiving_address' => 'ร้านทดลอง 1 ถนนทดสอบ']);
    $owner = User::factory()->create();
    ['nm' => $nm] = pricedCard();
    $submission = submitBuylist($owner, [$nm->id => 1]);
    $submission->update(['status' => $status]);

    $page = $this->actingAs($owner)->get(route('buylist.show', $submission->number))->assertOk();

    $shown
        ? $page->assertSee(__('storefront.buylist.show.packing_title'))->assertSee('ร้านทดลอง 1 ถนนทดสอบ')
        : $page->assertDontSee(__('storefront.buylist.show.packing_title'))->assertDontSee('ร้านทดลอง 1 ถนนทดสอบ');
})->with([
    'submitted' => [BuylistStatus::Submitted, true],
    'received' => [BuylistStatus::Received, false],
    'paid' => [BuylistStatus::Paid, false],
    'returned' => [BuylistStatus::Returned, false],
]);

test('a returned list says what happened in the past tense once it has a tracking number, and its road ends there', function () {
    $owner = User::factory()->create();
    ['nm' => $nm] = pricedCard();
    $submission = app(ReturnBuylistSubmission::class)->handle(submitBuylist($owner, [$nm->id => 1]));

    $this->actingAs($owner)->get(route('buylist.show', $submission->number))
        ->assertOk()
        ->assertSee(__('storefront.buylist.show.status_returned'))
        ->assertDontSee(__('storefront.buylist.show.step_paid'))
        ->assertSee(__('storefront.buylist.show.step_returned'))
        ->assertSee('text-muted', false)
        ->assertDontSee('text-h3 font-medium text-accent', false);

    app(ReturnBuylistSubmission::class)->handle($submission, 'TH-777');

    $this->actingAs($owner)->get(route('buylist.show', $submission->number))
        ->assertSee(__('storefront.buylist.show.status_returned_tracking', ['tracking' => 'TH-777']))
        ->assertDontSee(__('storefront.buylist.show.status_returned'));
});

test('a paid credit list says the credit is in and links to the credit section', function () {
    $owner = User::factory()->create();
    ['nm' => $nm] = pricedCard();
    $paid = app(AcceptBuylistSubmission::class)->handle(receivedSubmission($nm, 1, customer: $owner))->submission;

    $this->actingAs($owner)->get(route('buylist.show', $paid->number))
        ->assertOk()
        ->assertSee(__('storefront.buylist.show.status_paid_credit', ['amount' => '฿ 115']))
        ->assertSee(__('storefront.buylist.show.view_credit'))
        ->assertSee(route('account').'#account-credit-heading', false);
});

test('while revised the list shows each changed line with its own old and new unit and total, never a mixed pair', function () {
    $owner = User::factory()->create();
    ['nm' => $nm] = pricedCard();
    $unchanged = buylistRow(['condition' => CardCondition::Nm, 'cash_satang' => 5000, 'credit_satang' => 5750]);
    $submission = app(ReceiveBuylistSubmission::class)->handle(submitBuylist($owner, [$nm->id => 2, $unchanged->id => 1]));
    $submission = app(AssessBuylistSubmission::class)->handle($submission, $submission->items()->get()->map(fn ($item): array => $item->buylist_price_id === $nm->id
        ? ['item_id' => $item->id, 'assessed_condition' => CardCondition::Lp, 'assessed_note' => null, 'photo_path' => assessAll($submission, CardCondition::Lp)[0]['photo_path']]
        : ['item_id' => $item->id, 'assessed_condition' => CardCondition::Nm, 'assessed_note' => null, 'photo_path' => null])->all());

    $html = $this->actingAs($owner)->get(route('buylist.show', $submission->number))->assertOk()->getContent();

    expect($html)
        ->toContain(__('storefront.buylist.show.unit', ['amount' => '฿ 115']).' → '.__('storefront.buylist.show.unit_now', ['amount' => '฿ 92']))
        ->toContain('line-through')
        ->toContain('฿ 230')
        ->toContain(__('storefront.buylist.show.line_total', ['amount' => '฿ 184']))
        ->toContain(__('storefront.buylist.show.unit', ['amount' => '฿ 57.50']))
        ->toContain(__('storefront.buylist.show.line_total', ['amount' => '฿ 57.50']))
        ->toContain(__('storefront.buylist.revision.line_change', ['old' => '฿ 230', 'new' => '฿ 184']));
});

test('cards the shop did not take are listed on the accepted or paid list, and the tracking of their parcel is recorded without moving the status', function (BuylistPayout $payout, BuylistStatus $status) {
    $owner = User::factory()->create();
    ['nm' => $nm] = pricedCard();
    $second = buylistRow(['condition' => CardCondition::Nm, 'cash_satang' => 5000, 'credit_satang' => 5750]);
    $submission = app(ReceiveBuylistSubmission::class)->handle(submitBuylist($owner, [$nm->id => 2, $second->id => 1], $payout));
    $submission = app(AssessBuylistSubmission::class)->handle($submission, $submission->items()->get()->map(fn ($item): array => $item->buylist_price_id === $nm->id
        ? ['item_id' => $item->id, 'assessed_condition' => CardCondition::Dmg, 'assessed_note' => null, 'photo_path' => assessAll($submission, CardCondition::Dmg)[0]['photo_path']]
        : ['item_id' => $item->id, 'assessed_condition' => CardCondition::Nm, 'assessed_note' => null, 'photo_path' => null])->all());
    $submission = app(RespondToBuylistRevision::class)->handle($submission, $owner, accept: true);
    $unbought = $submission->items()->where('buylist_price_id', $nm->id)->first();

    expect($submission->status)->toBe($status);
    $this->actingAs($owner)->get(route('buylist.show', $submission->number))
        ->assertSee(__('storefront.buylist.show.unbought', ['n' => 2]))
        ->assertSee($unbought->card_name);

    $staff = User::factory()->staff()->withAppAuthentication()->create();
    $this->actingAs($staff);
    Livewire::test(EditBuylistSubmission::class, ['record' => $submission->getRouteKey()])
        ->assertActionVisible('recordTracking')
        ->callAction('recordTracking', ['tracking' => 'TH-RET-1']);

    expect($submission->fresh()->return_tracking)->toBe('TH-RET-1')
        ->and($submission->fresh()->status)->toBe($status)
        ->and($submission->fresh()->returned_at)->toBeNull();
    Livewire::test(EditBuylistSubmission::class, ['record' => $submission->getRouteKey()])->assertActionHidden('recordTracking');

    $this->actingAs($owner)->get(route('buylist.show', $submission->number))
        ->assertSee(__('storefront.buylist.show.unbought_sent', ['n' => 2, 'tracking' => 'TH-RET-1']));
})->with([
    'credit' => [BuylistPayout::Credit, BuylistStatus::Paid],
    'cash' => [BuylistPayout::Cash, BuylistStatus::Accepted],
]);

test('an accepted list with every card taken offers no tracking to record', function () {
    ['nm' => $nm] = pricedCard();
    $paid = app(AcceptBuylistSubmission::class)->handle(receivedSubmission($nm, 1))->submission;
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());

    Livewire::test(EditBuylistSubmission::class, ['record' => $paid->getRouteKey()])->assertActionHidden('recordTracking');
    expect(fn () => app(ReturnBuylistSubmission::class)->handle($paid, 'TH-1'))->toThrow(BuylistRefused::class, 'illegal_status');
});

test('the desk calls a revised list by who it waits on, and offers no save once the list is past inspection', function (BuylistStatus $status) {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm);
    $submission->update(['status' => $status]);
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());

    $page = Livewire::test(EditBuylistSubmission::class, ['record' => $submission->getRouteKey()])->assertOk();

    $page->assertDontSee(__('admin.buylist.submissions.actions.save'));
    expect(BuylistStatus::Revised->staffLabel())->toBe('รอลูกค้ายืนยันราคาใหม่')
        ->and(BuylistStatus::Revised->label())->toBe('รอคุณยืนยันราคาใหม่')
        ->and(BuylistStatus::Paid->staffLabel())->toBe(BuylistStatus::Paid->label());
})->with([BuylistStatus::Revised, BuylistStatus::Accepted, BuylistStatus::Paid, BuylistStatus::Returned]);

test('the staff table and the customer page word a revised list for who reads them', function () {
    $owner = User::factory()->create();
    $submission = revisedSubmission(customer: $owner);
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());

    Livewire::test(ListBuylistSubmissions::class)->assertSee('รอลูกค้ายืนยันราคาใหม่')->assertDontSee('รอคุณยืนยันราคาใหม่');

    $this->actingAs($owner)->get(route('buylist.show', $submission->number))->assertSee('รอคุณยืนยันราคาใหม่');
});

test('the photo drop zone carries its Thai text and the gold confirm hides while the return is being confirmed', function () {
    ['nm' => $nm] = pricedCard();
    $submission = receivedSubmission($nm);
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create());

    $page = Livewire::test(EditBuylistSubmission::class, ['record' => $submission->getRouteKey()]);
    $key = array_key_first($page->get('data.assessments'));
    $page->set("data.assessments.{$key}.assessed_condition", 'lp')
        ->assertSee(__('admin.buylist.submissions.upload_idle'), false);

    $owner = User::factory()->create();
    $revised = revisedSubmission(customer: $owner);
    $this->actingAs($owner)->get(route('buylist.show', $revised->number))
        ->assertSee('x-show="! confirming"', false);
});
