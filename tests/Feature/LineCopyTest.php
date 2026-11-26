<?php

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Livewire\Storefront\VariantTable;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the login page mentions LINE only when LINE Login is configured', function () {
    config(['services.line.client_id' => null]);
    $this->get('/login')->assertOk()->assertDontSee(__('storefront.auth.line_login'));

    config(['services.line.client_id' => 'x']);
    $this->get('/login')->assertOk()->assertSee(__('storefront.auth.line_login'));
});

test('the account header mentions LINE only when LINE Login is configured', function () {
    $user = User::factory()->create(['email' => null]);

    config(['services.line.client_id' => null]);
    $this->actingAs($user)->get('/account')->assertOk()->assertDontSee(__('storefront.account.line_not_linked'));

    config(['services.line.client_id' => 'x']);
    $this->actingAs($user)->get('/account')->assertOk()->assertSee(__('storefront.account.line_not_linked'));
});

test('the sold-out restock hint names LINE only when LINE Login is configured', function () {
    $card = Card::factory()->for(Game::factory())->create();
    $printing = Printing::factory()->for($card)->for(CardSet::factory()->for($card->game), 'set')
        ->create(['language' => Language::En, 'has_foil' => true]);
    Variant::factory()->for($printing)->create([
        'condition' => CardCondition::Nm,
        'finish' => Finish::Normal,
        'qty' => 0,
        'price_satang' => 100000,
    ]);
    $table = fn () => Livewire::test(VariantTable::class, ['cardId' => $card->id, 'printingId' => $printing->id]);

    config(['services.line.client_id' => null]);
    $table()->assertSee(__('storefront.alerts.restock_hint_email'))->assertDontSee(__('storefront.alerts.restock_hint'));

    config(['services.line.client_id' => 'x']);
    $table()->assertSee(__('storefront.alerts.restock_hint'))->assertDontSee(__('storefront.alerts.restock_hint_email'));
});
