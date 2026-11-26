<?php

use App\Actions\RecordStoreCredit;
use App\Enums\BuylistPayout;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Enums\StoreCreditReason;
use App\Livewire\Storefront\BuylistBuilder;
use App\Models\BuylistPrice;
use App\Models\BuylistSubmission;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\StoreCreditEntry;
use App\Models\User;
use App\Search\FuzzyCardMatcher;
use App\Support\CustomerLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The paste check only reads codes and exact names here: the search engine is never asked.
    app()->bind(FuzzyCardMatcher::class, fn () => new class implements FuzzyCardMatcher
    {
        public function match(Game $game, string $name): ?array
        {
            return null;
        }
    });
});

/**
 * The signature the page would hand `submit()`, read from its own markup.
 */
function builderSignature($component): array
{
    preg_match("/submit\('([0-9a-f]{64})', '([0-9a-f]{64})'\)/", $component->html(), $matches);

    return [$matches[1] ?? '', $matches[2] ?? ''];
}

test('the page is open to guests and says the list is empty', function () {
    $this->get(route('buylist'))
        ->assertOk()
        ->assertSee(__('storefront.buylist.empty'))
        ->assertSee(__('storefront.buylist.empty_hint'))
        ->assertSee(__('storefront.buylist.empty_cta'));
});

test('search finds only printings the shop buys', function () {
    $bought = buylistRow([], ['name_en' => 'Monkey D. Luffy']);
    buylistRow(['is_active' => false], ['name_en' => 'Luffy Inactive']);
    $trashed = buylistRow([], ['name_en' => 'Luffy Trashed']);
    $trashed->printing->delete();
    Printing::factory()->for($bought->printing->card)->for($bought->printing->set, 'set')->create(['number' => '500']);

    Livewire::test(BuylistBuilder::class)
        ->set('query', 'Luffy')
        ->assertSee('Monkey D. Luffy')
        ->assertDontSee('Luffy Inactive')
        ->assertDontSee('Luffy Trashed')
        ->assertSeeHtml(__('storefront.buylist.result_max', ['cash' => '<span class="whitespace-nowrap">฿ '.number_format($bought->cash_satang / 100).'</span>', 'credit' => '<span class="whitespace-nowrap">฿ '.number_format($bought->credit_satang / 100).'</span>']))
        ->assertDontSee(__('storefront.buylist.no_results'));
});

test('a card the shop does not buy gets the one-line answer, not a zero', function () {
    buylistRow([], ['name_en' => 'Monkey D. Luffy']);
    Printing::factory()->for(Card::factory()->create(['name_en' => 'Roronoa Zoro']))->for(CardSet::factory()->create(), 'set')->create();

    Livewire::test(BuylistBuilder::class)
        ->set('query', 'Zoro')
        ->assertSee(__('storefront.buylist.no_results'))
        ->assertDontSee('฿ 0');
});

test('search shows at most ten printings', function () {
    foreach (range(1, 12) as $i) {
        buylistRow([], ['name_en' => "Pikachu {$i}"]);
    }

    $html = Livewire::test(BuylistBuilder::class)->set('query', 'Pikachu')->html();

    expect(substr_count($html, 'wire:click="add('))->toBe(10);
});

test('adding a printing puts its best condition on the list and adding it again raises the line', function () {
    $lp = buylistRow(['condition' => CardCondition::Lp, 'cash_satang' => 5000, 'credit_satang' => 5800], ['name_en' => 'Luffy']);
    $nm = buylistRow(['condition' => CardCondition::Nm, 'cash_satang' => 9000, 'credit_satang' => 10400], for: $lp->printing);

    $component = Livewire::test(BuylistBuilder::class)
        ->call('add', $lp->printing_id, 'normal')
        ->call('add', $lp->printing_id, 'normal');

    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $nm->id, 'qty' => 2]]);
    $component->assertSee('Luffy');
});

test('adding something the shop does not buy says so instead of doing nothing', function () {
    $row = buylistRow(['is_active' => false]);

    Livewire::test(BuylistBuilder::class)
        ->call('add', $row->printing_id, 'normal')
        ->assertSee(__('storefront.buylist.no_longer'))
        ->call('add', $row->printing_id, 'bogus')
        ->assertSee(__('storefront.buylist.no_longer'));

    expect(session('buylist.lines'))->toBeNull();
});

test('a line offers only the conditions that have a buy price, each with its cash price', function () {
    $nm = buylistRow(['condition' => CardCondition::Nm, 'cash_satang' => 9000, 'credit_satang' => 10400]);
    buylistRow(['condition' => CardCondition::Mp, 'cash_satang' => 4000, 'credit_satang' => 4600], for: $nm->printing);
    buylistRow(['condition' => CardCondition::Lp, 'cash_satang' => 6000, 'credit_satang' => 6900, 'is_active' => false], for: $nm->printing);
    buylistRow(['condition' => CardCondition::Hp, 'cash_satang' => 2000, 'credit_satang' => 2300, 'finish' => Finish::Foil], for: $nm->printing);

    session(['buylist.lines' => [['buylist_price_id' => $nm->id, 'qty' => 1]]]);

    Livewire::test(BuylistBuilder::class)
        ->assertSee(__('storefront.buylist.condition_option', ['condition' => CardCondition::Nm->label(), 'payout' => 'เครดิตร้าน', 'amount' => '฿ 104']))
        ->assertSee(__('storefront.buylist.condition_option', ['condition' => CardCondition::Mp->label(), 'payout' => 'เครดิตร้าน', 'amount' => '฿ 46']))
        ->assertDontSee(CardCondition::Lp->label())
        ->assertDontSee(CardCondition::Hp->label())
        ->set('payout', 'cash')
        ->assertSee(__('storefront.buylist.condition_option', ['condition' => CardCondition::Nm->label(), 'payout' => 'เงินสด', 'amount' => '฿ 90']))
        ->assertSee(__('storefront.buylist.condition_option', ['condition' => CardCondition::Mp->label(), 'payout' => 'เงินสด', 'amount' => '฿ 40']));
});

test('changing the condition of a line takes only a buyable row of the same printing and finish', function () {
    $nm = buylistRow(['condition' => CardCondition::Nm]);
    $mp = buylistRow(['condition' => CardCondition::Mp], for: $nm->printing);
    $foil = buylistRow(['condition' => CardCondition::Nm, 'finish' => Finish::Foil], for: $nm->printing);
    $other = buylistRow();
    session(['buylist.lines' => [['buylist_price_id' => $nm->id, 'qty' => 2]]]);

    $component = Livewire::test(BuylistBuilder::class)->call('setCondition', $nm->id, $foil->id);
    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $nm->id, 'qty' => 2]]);

    $component->call('setCondition', $nm->id, $other->id);
    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $nm->id, 'qty' => 2]]);

    $component->call('setCondition', $nm->id, $mp->id);
    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $mp->id, 'qty' => 2]]);
});

test('a line at zero weekly quota says the shop is full, is left out of the totals and cannot be submitted', function () {
    $full = buylistRow(['weekly_quota' => 5, 'accepted_this_week' => 5, 'cash_satang' => 10000, 'credit_satang' => 11500]);
    $open = buylistRow(['cash_satang' => 20000, 'credit_satang' => 23000]);
    session(['buylist.lines' => [['buylist_price_id' => $full->id, 'qty' => 3], ['buylist_price_id' => $open->id, 'qty' => 1]]]);

    Livewire::test(BuylistBuilder::class)
        ->assertSee(__('storefront.buylist.quota_full'))
        ->assertSee(__('storefront.buylist.payout_cash', ['cash' => '฿ 200']))
        ->assertDontSee('฿ 300');

    $customer = User::factory()->create();
    $component = Livewire::actingAs($customer)->test(BuylistBuilder::class);
    $component->call('submit', ...builderSignature($component));

    expect(BuylistSubmission::query()->sole()->items()->pluck('buylist_price_id')->all())->toBe([$open->id])
        ->and($full->fresh()->accepted_this_week)->toBe(5);
});

test('a quantity above what the quota has left is capped with a note, in the totals and in the submit', function () {
    $row = buylistRow(['weekly_quota' => 5, 'accepted_this_week' => 3, 'cash_satang' => 10000, 'credit_satang' => 11500]);
    session(['buylist.lines' => [['buylist_price_id' => $row->id, 'qty' => 9]]]);

    $component = Livewire::actingAs(User::factory()->create())->test(BuylistBuilder::class)
        ->assertSee(__('storefront.buylist.quota_left', ['n' => 2]))
        ->assertSee(__('storefront.buylist.payout_cash', ['cash' => '฿ 200']));

    $component->call('increment', $row->id);
    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $row->id, 'qty' => 9]]);

    $component->call('submit', ...builderSignature($component));

    expect(BuylistSubmission::query()->sole()->items()->sole()->qty)->toBe(2)
        ->and($row->fresh()->accepted_this_week)->toBe(5);
});

test('the quantity stepper stays between one and the per-line maximum', function () {
    config(['buylist.max_qty_per_line' => 3]);
    $row = buylistRow();
    session(['buylist.lines' => [['buylist_price_id' => $row->id, 'qty' => 2]]]);

    $component = Livewire::test(BuylistBuilder::class)->call('increment', $row->id)->call('increment', $row->id);
    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $row->id, 'qty' => 3]]);
    $component->assertSee(__('storefront.buylist.max_qty', ['max' => 3]));

    $component->call('decrement', $row->id)->call('decrement', $row->id)->call('decrement', $row->id);
    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $row->id, 'qty' => 1]]);
});

test('the totals show cash and credit and the payout cards show what credit adds', function () {
    $a = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500]);
    $b = buylistRow(['cash_satang' => 20000, 'credit_satang' => 23000]);
    session(['buylist.lines' => [['buylist_price_id' => $a->id, 'qty' => 2], ['buylist_price_id' => $b->id, 'qty' => 1]]]);

    Livewire::actingAs(User::factory()->create())->test(BuylistBuilder::class)
        ->assertSee(__('storefront.buylist.summary_count', ['n' => 3, 'lines' => 2]))
        ->assertSee(__('storefront.buylist.payout_credit', ['credit' => '฿ 460', 'diff' => '฿ 60']))
        ->assertSee(__('storefront.buylist.payout_cash', ['cash' => '฿ 400']))
        ->assertSee(__('storefront.buylist.warning'))
        ->assertSee(__('storefront.buylist.quote_days', ['days' => 7]))
        ->assertSee(__('storefront.buylist.submit', ['total' => '฿ 460']))
        ->set('payout', 'cash')
        ->assertSee(__('storefront.buylist.submit', ['total' => '฿ 400']));
});

test('a pasted list adds the lines the shop buys and names the ones it does not', function () {
    $luffy = buylistRow(['condition' => CardCondition::Lp, 'cash_satang' => 5000, 'credit_satang' => 5800], ['name_en' => 'Monkey D. Luffy'], ['number' => '119']);
    $luffyNm = buylistRow(['condition' => CardCondition::Nm, 'cash_satang' => 9000, 'credit_satang' => 10400], for: $luffy->printing);
    $set = $luffy->printing->set;
    $set->update(['code' => 'OP-05']);
    $zoro = Printing::factory()->for(Card::factory()->for($set->game, 'game')->create(['name_en' => 'Roronoa Zoro']))->for($set, 'set')->create(['number' => '120']);
    $set->game->update(['name_en' => 'One Piece']);

    $component = Livewire::test(BuylistBuilder::class)
        ->call('setMode', 'paste')
        ->set('gameId', $set->game_id)
        ->set('raw', "4x OP05-119 Luffy\n2 OP05-120\nTotally Unknown Card")
        ->call('check')
        ->assertHasNoErrors()
        ->assertSee(__('storefront.buylist.pasted_added', ['n' => 1]))
        ->assertSee(__('storefront.buylist.unbought_title'))
        ->assertSee('2 OP05-120')
        ->assertSee('Totally Unknown Card');

    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $luffyNm->id, 'qty' => 4]])
        ->and($zoro->id)->not->toBeNull();

    $component->assertSee('Monkey D. Luffy');
});

test('the lines of a paste the shop does not buy are capped at twenty with a count of the rest', function () {
    $game = Game::factory()->create();
    $lines = collect(range(1, 25))->map(fn (int $i): string => "Nobody {$i}")->implode("\n");

    Livewire::test(BuylistBuilder::class)
        ->call('setMode', 'paste')
        ->set('gameId', $game->id)
        ->set('raw', $lines)
        ->call('check')
        ->assertSee('Nobody 20')
        ->assertDontSee('Nobody 21')
        ->assertSee(__('storefront.buylist.unbought_more', ['n' => 5]));
});

test('a paste needs text and an open game', function () {
    Livewire::test(BuylistBuilder::class)
        ->call('setMode', 'paste')
        ->set('raw', '')
        ->call('check')
        ->assertHasErrors(['raw'])
        ->set('raw', 'OP05-119')
        ->set('gameId', 999999)
        ->call('check')
        ->assertHasErrors(['gameId']);
});

test('checking a paste is limited to twenty a minute on its own key and says how long to wait', function () {
    $this->freezeTime();
    Game::factory()->create();
    $component = Livewire::test(BuylistBuilder::class)->call('setMode', 'paste')->set('raw', 'OP05-119');

    foreach (range(1, 20) as $i) {
        $component->call('check')->assertHasNoErrors();
    }

    $component->call('check')
        ->assertHasErrors(['raw'])
        ->assertSee(__('storefront.buylist.errors.throttled', ['seconds' => 60]));

    expect(BuylistBuilder::throttleKey('203.0.113.7'))->toBe('buylist-check:203.0.113.7')
        ->and(BuylistBuilder::throttleKey('::ffff:203.0.113.7'))->toBe('buylist-check:203.0.113.7');
});

test('a changed paste shows that its result is old', function () {
    Game::factory()->create();

    Livewire::test(BuylistBuilder::class)
        ->call('setMode', 'paste')
        ->set('raw', 'OP05-119')
        ->call('check')
        ->assertDontSeeHtml('<p role="status" class="text-small font-medium text-low">'.__('storefront.buylist.stale').'</p>')
        ->set('raw', 'OP05-120')
        ->assertSeeHtml('<p role="status" class="text-small font-medium text-low">'.__('storefront.buylist.stale').'</p>');
});

test('the list survives a login and comes back on the same page', function () {
    $row = buylistRow([], ['name_en' => 'Monkey D. Luffy']);
    Livewire::test(BuylistBuilder::class)->call('add', $row->printing_id, 'normal');

    CustomerLogin::login(User::factory()->create());

    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $row->id, 'qty' => 1]]);

    $this->get(route('buylist'))->assertOk()->assertSee('Monkey D. Luffy');
});

test('a guest sees the login prompt in place of submit and is sent to log in with the page as the way back', function () {
    $row = buylistRow();
    session(['buylist.lines' => [['buylist_price_id' => $row->id, 'qty' => 1]]]);

    $component = Livewire::test(BuylistBuilder::class)
        ->assertSee(__('storefront.buylist.login_to_submit'))
        ->assertDontSee(__('storefront.buylist.submit', ['total' => '']));

    $component->call('submit', ...builderSignature($component))->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe(route('buylist'))
        ->and(BuylistSubmission::query()->count())->toBe(0);
});

test('a staff account is told it cannot submit', function () {
    $row = buylistRow();
    session(['buylist.lines' => [['buylist_price_id' => $row->id, 'qty' => 1]]]);

    $component = Livewire::actingAs(User::factory()->staff()->create())->test(BuylistBuilder::class);
    $component->call('submit', ...builderSignature($component))->assertSee(__('storefront.buylist.staff'));

    expect(BuylistSubmission::query()->count())->toBe(0);
});

test('submitting sends the list, empties it and lands on the submission page', function () {
    $row = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500]);
    session(['buylist.lines' => [['buylist_price_id' => $row->id, 'qty' => 2]]]);
    $customer = User::factory()->create();

    $component = Livewire::actingAs($customer)->test(BuylistBuilder::class)->set('payout', 'cash');
    $component->call('submit', ...builderSignature($component));

    $submission = BuylistSubmission::query()->sole();

    $component->assertRedirect(route('buylist.show', $submission->number));
    expect($submission->payout)->toBe(BuylistPayout::Cash)
        ->and($submission->quoted_total_satang)->toBe(20000)
        ->and(session('buylist.lines'))->toBeNull();
});

test('a price that moved since the page was shown refuses the submit, explains it and shows the new total', function () {
    $row = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500]);
    session(['buylist.lines' => [['buylist_price_id' => $row->id, 'qty' => 2]]]);

    $component = Livewire::actingAs(User::factory()->create())->test(BuylistBuilder::class)->assertSee('฿ 230');
    $shown = builderSignature($component);

    $row->update(['credit_satang' => 12000]);

    $component->call('submit', ...$shown)
        ->assertSee(__('storefront.buylist.quote_changed'))
        ->assertSee('฿ 240');

    expect(BuylistSubmission::query()->count())->toBe(0)
        ->and(builderSignature($component)[0])->not->toBe($shown[0]);

    $component->call('submit', ...builderSignature($component));

    expect(BuylistSubmission::query()->sole()->quoted_total_satang)->toBe(24000);
});

test('a client cannot write the list, the mode or the lines it was shown', function () {
    expect(fn () => Livewire::test(BuylistBuilder::class)->set('lines', [['buylist_price_id' => 1, 'qty' => 99]]))->toThrow('Cannot update locked property');
});

test('the language of a printing shows next to its code', function () {
    $row = buylistRow(printing: ['language' => Language::Ja]);
    session(['buylist.lines' => [['buylist_price_id' => $row->id, 'qty' => 1]]]);

    Livewire::test(BuylistBuilder::class)->assertSee($row->printing->display_code.' · '.Language::Ja->shortCode());
});

describe('the submission page', function () {
    test('it shows the whole road, with the steps not reached yet muted, and the packing and address above the lines', function () {
        $owner = User::factory()->create();
        $row = buylistRow([], ['name_en' => 'Monkey D. Luffy']);
        $submission = submitBuylist($owner, [$row->id => 1]);

        $html = $this->actingAs($owner)->get(route('buylist.show', $submission->number))->assertOk()->getContent();

        foreach (['submitted', 'received', 'inspected', 'paid'] as $step) {
            expect($html)->toContain(__('storefront.buylist.show.step_'.$step));
        }

        expect($html)->toMatch('/text-faint[^>]*>\s*<span[^>]*>'.preg_quote(__('storefront.buylist.show.step_paid'), '/').'/')
            ->and(strpos($html, __('storefront.buylist.show.packing_1')))->toBeLessThan(strpos($html, 'Monkey D. Luffy'))
            ->and($html)->toContain(__('storefront.buylist.show.all_in_account'));
    });

    function submittedBy(User $user): BuylistSubmission
    {
        return BuylistSubmission::factory()->for($user)->create();
    }

    test('only its owner can open it: another customer gets a 404 and a guest is sent to log in', function () {
        $owner = User::factory()->create();
        $submission = submittedBy($owner);

        $this->get(route('buylist.show', $submission->number))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('buylist.show', $submission->number))->assertNotFound();
        $this->actingAs($owner)->get(route('buylist.show', $submission->number))->assertOk()->assertSee($submission->number);
    });

    test('it shows the quote date, the lines, the packing steps and the receiving address from config', function () {
        config(['buylist.receiving_address' => "Shop Co.\n1 Example Road\nBangkok 10110"]);
        $owner = User::factory()->create();
        $row = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500], ['name_en' => 'Monkey D. Luffy']);
        $submission = submitBuylist($owner, [$row->id => 2]);

        $this->actingAs($owner)->get(route('buylist.show', $submission->number))
            ->assertOk()
            ->assertSee('Monkey D. Luffy')
            ->assertSee(__('storefront.buylist.show.packing_1'))
            ->assertSee(__('storefront.buylist.show.packing_2', ['number' => $submission->number]))
            ->assertSee(__('storefront.buylist.show.packing_3'))
            ->assertSee(__('storefront.buylist.show.quote_until', ['date' => $submission->quote_expires_at->copy()->setTimezone(config('shop.timezone'))->locale('th')->translatedFormat('j M Y')]))
            ->assertSee('1 Example Road')
            ->assertSee('฿ 230')
            ->assertDontSee(__('storefront.buylist.show.address_missing'));
    });

    test('without a configured address it says the shop will send one, and prints no address of its own', function () {
        config(['buylist.receiving_address' => null]);
        $owner = User::factory()->create();

        $this->actingAs($owner)->get(route('buylist.show', submittedBy($owner)->number))
            ->assertOk()
            ->assertSee(__('storefront.buylist.show.address_missing'))
            ->assertDontSee(__('storefront.buylist.show.address_title').' ');
    });
});

describe('the account page', function () {
    test('it lists the latest submissions with their status and total, and offers the buylist when there are none', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account'))
            ->assertOk()
            ->assertSee(__('storefront.account.buylist.empty'))
            ->assertSee('href="'.route('buylist').'"', false);

        $submission = BuylistSubmission::factory()->for($user)->create(['quoted_total_satang' => 23000]);

        $this->actingAs($user)->get(route('account'))
            ->assertOk()
            ->assertSee($submission->number.'', false)
            ->assertSee(BuylistSubmission::query()->first()->status->label())
            ->assertSee(__('storefront.account.buylist.quoted', ['amount' => '฿ 230']))
            ->assertSee(route('buylist.show', $submission->number))
            ->assertDontSee(__('storefront.account.buylist.empty'));
    });

    test('the credit ledger links a submission source to its page and the empty credit state links to the buylist', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account'))
            ->assertSee(__('storefront.account.credit.empty_cta'))
            ->assertSee('href="'.route('buylist').'" class', false);

        $submission = BuylistSubmission::factory()->for($user)->create();
        app(RecordStoreCredit::class)->handle($user, 5000, StoreCreditReason::Buylist, $submission);

        $this->actingAs($user)->get(route('account'))
            ->assertSee('href="'.route('buylist.show', $submission->number).'"', false);

        expect(StoreCreditEntry::query()->sole()->source_type)->toBe('buylist_submission');
    });
});

test('the header links to the buylist and marks it current there', function () {
    $this->get(route('buylist'))->assertSee(__('storefront.nav.buylist'))->assertSee('aria-current="page"', false);
    $this->get(route('home'))->assertSee(__('storefront.nav.buylist'))->assertSee(__('storefront.home.sell_cards'));
});

test('the customer copy is the approved Thai, verbatim', function () {
    expect(__('storefront.buylist.warning'))->toBe('สภาพที่คุณเลือกเป็นการประเมินเบื้องต้น ร้านจะตรวจซ้ำเมื่อการ์ดถึง ถ้าไม่ตรง ร้านจะส่งรูปและราคาใหม่ให้ยืนยัน หรือส่งการ์ดคืนฟรี')
        ->and(__('storefront.buylist.no_results'))->toBe('ค้นไม่เจอแปลว่าตอนนี้ร้านยังไม่รับใบนั้น')
        ->and(__('storefront.buylist.quota_full'))->toBe('ร้านรับซื้อครบโควตาสัปดาห์นี้')
        ->and(__('storefront.buylist.quote_changed'))->toBe('ราคารับซื้อเปลี่ยนแล้ว ตรวจยอดใหม่อีกครั้ง')
        ->and(__('storefront.buylist.empty'))->toBe('ลิสต์ขายยังว่าง')
        ->and(__('storefront.buylist.show.address_missing'))->toBe('ร้านจะแจ้งที่อยู่สำหรับส่งการ์ดทาง LINE หรืออีเมล')
        ->and(__('storefront.buylist.payout_credit', ['credit' => '฿ 460', 'diff' => '฿ 60']))->toBe('เครดิตร้าน ฿ 460 · ได้มากกว่า ฿ 60 · เข้าบัญชีทันทีหลังร้านตรวจ')
        ->and(__('storefront.buylist.payout_cash', ['cash' => '฿ 400']))->toBe('เงินสด ฿ 400 · โอนภายใน 1 วันทำการหลังร้านตรวจ')
        ->and(__('enums.buylist_status.revised'))->toBe('รอคุณยืนยันราคาใหม่');
});

test('the phone submit bar carries the id the scroll padding rule is keyed on', function () {
    $row = buylistRow();
    session(['buylist.lines' => [['buylist_price_id' => $row->id, 'qty' => 1]]]);

    Livewire::test(BuylistBuilder::class)->assertSeeHtml('id="buylist-bar"');

    expect(file_get_contents(resource_path('css/app.css')))->toContain('html:has(#buylist-bar) { scroll-padding-bottom: 7rem; }');
});

test('an add or a paste lands on a condition that still has quota, not on the best condition when that one is full', function () {
    $nm = buylistRow(['condition' => CardCondition::Nm, 'weekly_quota' => 5, 'accepted_this_week' => 5], ['name_en' => 'Monkey D. Luffy'], ['number' => '119']);
    $lp = buylistRow(['condition' => CardCondition::Lp], for: $nm->printing);
    $nm->printing->set->update(['code' => 'OP-05']);

    Livewire::test(BuylistBuilder::class)->call('add', $nm->printing_id, 'normal');

    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $lp->id, 'qty' => 1]]);

    session()->forget('buylist.lines');

    Livewire::test(BuylistBuilder::class)
        ->call('setMode', 'paste')
        ->set('gameId', $nm->printing->set->game_id)
        ->set('raw', '3 OP05-119')
        ->call('check');

    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $lp->id, 'qty' => 3]]);
});

test('a full line keeps its condition select, lists the full conditions as unselectable and can move to an open one', function () {
    $nm = buylistRow(['condition' => CardCondition::Nm, 'weekly_quota' => 5, 'accepted_this_week' => 5, 'cash_satang' => 10000, 'credit_satang' => 11500]);
    $lp = buylistRow(['condition' => CardCondition::Lp, 'cash_satang' => 8000, 'credit_satang' => 9200], for: $nm->printing);
    $mp = buylistRow(['condition' => CardCondition::Mp, 'weekly_quota' => 2, 'accepted_this_week' => 2], for: $nm->printing);
    session(['buylist.lines' => [['buylist_price_id' => $nm->id, 'qty' => 1]]]);

    $component = Livewire::test(BuylistBuilder::class)
        ->assertSee(__('storefront.buylist.quota_full'))
        ->assertSeeHtml('<option value="'.$mp->id.'"')
        ->assertSee(__('storefront.buylist.condition_full', ['option' => __('storefront.buylist.condition_option', ['condition' => CardCondition::Mp->label(), 'payout' => 'เครดิตร้าน', 'amount' => '฿ '.number_format($mp->credit_satang / 100)])]));

    expect($component->html())->toMatch('/<option value="'.$mp->id.'"[^>]*disabled/');

    $component->call('setCondition', $nm->id, $mp->id);
    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $nm->id, 'qty' => 1]]);

    $component->call('setCondition', $nm->id, $lp->id);
    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $lp->id, 'qty' => 1]]);
});

test('a search row whose every condition is full says so instead of a price, and its add button answers with that reason', function () {
    $nm = buylistRow(['weekly_quota' => 5, 'accepted_this_week' => 5], ['name_en' => 'Monkey D. Luffy']);
    buylistRow(['condition' => CardCondition::Lp, 'weekly_quota' => 1, 'accepted_this_week' => 1], for: $nm->printing);

    $component = Livewire::test(BuylistBuilder::class)
        ->set('query', 'Luffy')
        ->assertSee(__('storefront.buylist.quota_full_week'))
        ->assertDontSee(__('storefront.buylist.result_max', ['cash' => '', 'credit' => '']))
        ->call('add', $nm->printing_id, 'normal')
        ->assertSee(__('storefront.buylist.quota_full'));

    expect(session('buylist.lines', []))->toBe([])
        ->and($component->html())->toContain('aria-disabled="true"');
});

test('adding a printing whose line is full and has no open condition gives the notice and leaves the line alone', function () {
    $nm = buylistRow(['weekly_quota' => 5, 'accepted_this_week' => 5]);
    session(['buylist.lines' => [['buylist_price_id' => $nm->id, 'qty' => 4]]]);

    Livewire::test(BuylistBuilder::class)
        ->set('query', $nm->printing->card->name_en)
        ->call('add', $nm->printing_id, 'normal')
        ->assertSee(__('storefront.buylist.quota_full'));

    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $nm->id, 'qty' => 4]]);
});

test('an add replaces a line whose row is dead with the best open row of the same printing and finish, never raising it unseen', function (Closure $kill) {
    $dead = buylistRow(['condition' => CardCondition::Nm]);
    $open = buylistRow(['condition' => CardCondition::Lp], for: $dead->printing);
    $kill($dead);
    session(['buylist.lines' => [['buylist_price_id' => $dead->id, 'qty' => 4]]]);

    Livewire::test(BuylistBuilder::class)->call('add', $dead->printing_id, 'normal');

    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $open->id, 'qty' => 1]]);
})->with([
    'stopped being bought' => [fn (BuylistPrice $row) => $row->update(['is_active' => false])],
    'quota used up' => [fn (BuylistPrice $row) => $row->forceFill(['weekly_quota' => 1, 'accepted_this_week' => 1])->save()],
]);

test('a paste counts a line as added only when it became submittable and names a full line apart', function () {
    $row = buylistRow(['weekly_quota' => 1, 'accepted_this_week' => 1], ['name_en' => 'Monkey D. Luffy'], ['number' => '119']);
    $row->printing->set->update(['code' => 'OP-05']);

    $component = Livewire::test(BuylistBuilder::class)
        ->call('setMode', 'paste')
        ->set('gameId', $row->printing->set->game_id)
        ->set('raw', '2 OP05-119')
        ->call('check')
        ->assertSee(__('storefront.buylist.pasted_added', ['n' => 0]))
        ->assertSee(__('storefront.buylist.quota_full_title'))
        ->assertSee('2 OP05-119');

    expect(session('buylist.lines', []))->toBe([]);
});

test('a pasted name that fits several printings is listed to be specified, with a link into search, and is not guessed', function () {
    $en = buylistRow([], ['name_en' => 'Monkey D. Luffy'], ['number' => '119', 'language' => Language::En]);
    $ja = buylistRow([], for: Printing::factory()->for($en->printing->card)->for($en->printing->set, 'set')->create(['number' => '119', 'language' => Language::Ja]));
    $en->printing->set->update(['code' => 'OP-05']);

    $component = Livewire::test(BuylistBuilder::class)
        ->call('setMode', 'paste')
        ->set('gameId', $en->printing->set->game_id)
        ->set('raw', "2 Monkey D. Luffy\n1 OP05-119")
        ->call('check')
        ->assertSee(__('storefront.buylist.ambiguous_title'))
        ->assertSee(__('storefront.buylist.ambiguous_hint'))
        ->assertSee('2 Monkey D. Luffy')
        ->assertSee(__('storefront.buylist.ambiguous_search'));

    // The code line is a code match and stays what it was; the name line was not guessed.
    expect(collect(session('buylist.lines'))->sum('qty'))->toBe(1);

    $component->call('searchFor', 0)
        ->assertSet('mode', 'search')
        ->assertSet('query', 'Monkey D. Luffy')
        ->assertSee('Monkey D. Luffy');
});

test('a name that fits one buyable printing is added as before', function () {
    $row = buylistRow([], ['name_en' => 'Monkey D. Luffy'], ['number' => '119']);

    Livewire::test(BuylistBuilder::class)
        ->call('setMode', 'paste')
        ->set('gameId', $row->printing->set->game_id)
        ->set('raw', '2 Monkey D. Luffy')
        ->call('check')
        ->assertDontSee(__('storefront.buylist.ambiguous_title'));

    expect(session('buylist.lines'))->toBe([['buylist_price_id' => $row->id, 'qty' => 2]]);
});

test('a refusal caused by a shrunken quota says the quantity changed, not the price', function () {
    $row = buylistRow(['weekly_quota' => 5, 'cash_satang' => 10000, 'credit_satang' => 11500]);
    session(['buylist.lines' => [['buylist_price_id' => $row->id, 'qty' => 4]]]);

    $component = Livewire::actingAs(User::factory()->create())->test(BuylistBuilder::class);
    $shown = builderSignature($component);

    $row->forceFill(['accepted_this_week' => 3])->save();

    $component->call('submit', ...$shown)
        ->assertSee(__('storefront.buylist.quantity_changed'))
        ->assertDontSee(__('storefront.buylist.quote_changed'));

    expect(BuylistSubmission::query()->count())->toBe(0);
});

test('a submit refusal renders inside the fixed submit bar, above the button', function () {
    $row = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500]);
    session(['buylist.lines' => [['buylist_price_id' => $row->id, 'qty' => 1]]]);

    $component = Livewire::actingAs(User::factory()->create())->test(BuylistBuilder::class);
    $shown = builderSignature($component);
    $row->update(['credit_satang' => 12000]);

    $html = $component->call('submit', ...$shown)->html();
    $bar = substr($html, (int) strpos($html, 'id="buylist-bar"'));

    expect(strpos($bar, __('storefront.buylist.quote_changed')))->not->toBeFalse()
        ->and(strpos($bar, __('storefront.buylist.quote_changed')))->toBeLessThan(strpos($bar, 'wire:click="submit('))
        ->and(substr($html, 0, (int) strpos($html, 'id="buylist-bar"')))->not->toContain(__('storefront.buylist.quote_changed'));
});

test('the phone bar carries the total once, in the button, and the mode control is not gold', function () {
    $row = buylistRow(['cash_satang' => 10000, 'credit_satang' => 11500]);
    session(['buylist.lines' => [['buylist_price_id' => $row->id, 'qty' => 2]]]);

    $html = Livewire::actingAs(User::factory()->create())->test(BuylistBuilder::class)->html();
    $bar = substr($html, (int) strpos($html, 'id="buylist-bar"'));
    $mode = substr($html, (int) strpos($html, 'aria-label="'.__('storefront.buylist.mode_group')));
    $mode = substr($mode, 0, (int) strpos($mode, '<label'));

    expect(substr_count($bar, '฿ 230'))->toBe(1)
        ->and($mode)->toContain('border-accent bg-raised')
        ->and($mode)->not->toContain('bg-accent');
});

test('the warning comes before the payout choice, and a single line replaces the totals box', function () {
    $row = buylistRow();
    session(['buylist.lines' => [['buylist_price_id' => $row->id, 'qty' => 2]]]);

    $html = Livewire::test(BuylistBuilder::class)->html();

    expect(strpos($html, __('storefront.buylist.warning')))->toBeLessThan(strpos($html, __('storefront.buylist.payout_group')))
        ->and($html)->toContain(__('storefront.buylist.summary_count', ['n' => 2, 'lines' => 1]));
});
