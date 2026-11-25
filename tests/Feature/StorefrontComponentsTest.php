<?php

use App\Enums\CardCondition;
use App\Enums\Language;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Printing;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

test('the foil sheen class is on the art only when the art is foil', function () {
    $foilPrinting = Printing::factory()->withFoil()->make();
    $plainPrinting = Printing::factory()->make(['has_foil' => false]);

    expect(Blade::render('<x-card-art :printing="$printing" />', ['printing' => $foilPrinting]))->toContain('foil')
        ->and(Blade::render('<x-card-art :printing="$printing" />', ['printing' => $plainPrinting]))->not->toContain('foil')
        ->and(Blade::render('<x-card-art :foil="true" />'))->toContain('foil')
        ->and(Blade::render('<x-card-art :printing="$printing" :foil="false" />', ['printing' => $foilPrinting]))->not->toContain('foil')
        ->and(Blade::render('<x-card-art />'))->not->toContain('foil');
});

test('the foil sheen stops under reduced motion', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toMatch('/prefers-reduced-motion: reduce\) \{ \.foil::after \{ animation: none \} \}/');
});

test('the rarity badge shows the code upper-case in its own token colour', function (string $code, string $token) {
    $html = Blade::render('<x-rarity-badge :code="$code" />', ['code' => $code]);

    expect($html)->toContain('text-rarity-'.$token)->toContain(strtoupper($code).'</span>');
})->with([['c', 'c'], ['u', 'u'], ['r', 'r'], ['rr', 'rr'], ['sar', 'sar'], ['sec', 'sec'], ['x', 'c']]);

test('the condition badge shows the code, the enum label and its token colour', function (CardCondition $condition) {
    $html = Blade::render('<x-condition-badge :condition="$condition" />', ['condition' => $condition]);

    expect($html)->toContain('<span aria-hidden="true">'.strtoupper($condition->value).'</span>')
        ->toContain('<span class="sr-only">'.e($condition->label()).'</span>')
        ->toContain('text-cond-'.$condition->value);
})->with(CardCondition::cases());

test('the condition badge also accepts the stored string', function () {
    expect(Blade::render('<x-condition-badge condition="lp" />'))->toContain('text-cond-lp');
});

test('the language badge shows the short code with the language as its label', function () {
    $html = Blade::render('<x-language-badge language="ja" />');

    expect($html)->toContain('<span aria-hidden="true">JP</span>')->toContain('<span class="sr-only">'.__('enums.language.ja').'</span>');
});

test('the stock indicator uses the same thresholds as the search dropdown', function (bool $inStock, int $qty, string $text, string $colour) {
    $html = Blade::render('<x-stock-indicator :in-stock="$inStock" :qty="$qty" />', ['inStock' => $inStock, 'qty' => $qty]);

    expect($html)->toContain($text)->toContain($colour);
})->with([
    'sold out' => [false, 0, 'หมด', 'text-faint'],
    'one left' => [true, 1, 'เหลือ 1', 'text-low'],
    'two left' => [true, 2, 'เหลือ 2', 'text-low'],
    'three' => [true, 3, 'มี 3', 'text-stock'],
    'forty' => [true, 40, 'มี 40<', 'text-stock'],
    'above forty' => [true, 41, 'มี 40+', 'text-stock'],
]);

test('the price strikes the compare-at amount only when it is higher', function () {
    $sale = Blade::render('<x-price :satang="429000" :compare-at="500000" />');

    expect($sale)->toContain('฿ 4,290')->toContain('<s ')->toContain('฿ 5,000')
        ->and(Blade::render('<x-price :satang="429000" :compare-at="429000" />'))->not->toContain('<s ')
        ->and(Blade::render('<x-price :satang="429000" />'))->not->toContain('<s ');
});

test('the filter chip is a link when it has an href and a toggle button when it does not', function () {
    $link = Blade::render('<x-filter-chip href="/x" :active="true">NM</x-filter-chip>');
    $button = Blade::render('<x-filter-chip>NM</x-filter-chip>');

    expect($link)->toContain('<a href="/x"')->toContain('bg-accent')->toContain('min-h-11')
        ->and($button)->toContain('<button type="button"')->toContain('aria-pressed="false"')->not->toContain('bg-accent');
});

test('a removable filter chip is a plain button with a cross, and a tone replaces its resting colours', function () {
    $remove = Blade::render('<x-filter-chip :active="true" :remove="true" aria-label="ถอด NM">NM</x-filter-chip>');
    $tone = Blade::render('<x-filter-chip tone="border-rarity-sar text-rarity-sar">SAR</x-filter-chip>');

    expect($remove)->toContain('<button type="button"')->toContain('<svg')->toContain('aria-label="ถอด NM"')->not->toContain('aria-pressed')
        ->and($tone)->toContain('border-rarity-sar')->toContain('aria-pressed="false"')->not->toContain('text-muted');
});

test('the trust row claims real photos only when there are some and never a number', function () {
    $without = Blade::render('<x-trust-row />');
    $with = Blade::render('<x-trust-row :has-real-photo="true" />');

    expect($without)->not->toContain(__('storefront.trust.real_photo'))
        ->and($with)->toContain(__('storefront.trust.real_photo'))
        ->and(strip_tags($without))->not->toMatch('/\d/');
});

test('the empty state shows the title, the hint and its actions', function () {
    $html = Blade::render('<x-empty-state title="T" hint="H"><button>Go</button></x-empty-state>');

    expect($html)->toContain('T')->toContain('H')->toContain('<button>Go</button>')
        ->and(Blade::render('<x-empty-state title="T" />'))->not->toContain('mt-1 flex');
});

test('the skeleton tile keeps the card ratio through the card art and is hidden from assistive tech', function () {
    $tile = Blade::render('<x-skeleton :tile="true" />');

    expect($tile)->toContain('aspect-[63/88]')->toContain('skeleton')->toContain('aria-hidden="true"')
        ->and(Blade::render('<x-skeleton class="h-4" />'))->toContain('h-4');
});

test('the card tile shows name, code, language, price and stock and links with the printing', function () {
    $printing = Printing::factory()->withFoil()->create(['rarity_code' => 'sar', 'number' => '215']);
    Variant::factory()->for($printing)->create(['price_satang' => 429000, 'qty' => 3]);
    Variant::factory()->for($printing)->foil()->create(['price_satang' => 559000, 'qty' => 1]);
    $printing = Printing::query()->withCheapestVariant()->findOrFail($printing->id);

    $html = Blade::render('<x-card-tile :printing="$printing" />', ['printing' => $printing]);

    expect($html)->toContain($printing->card->name_en)
        ->toContain($printing->display_code.' · EN')
        ->toContain('฿ 4,290')->toContain('text-accent')
        ->toContain('มี 4')
        ->toContain('text-rarity-sar')
        ->toContain('foil')
        ->toContain('p='.$printing->id)
        ->not->toContain('opacity-55');
});

test('a sold-out card tile is dimmed, says so and shows its lowest price in faint ink', function () {
    $printing = Printing::factory()->create(['rarity_code' => 'sar']);
    Variant::factory()->for($printing)->create(['qty' => 0, 'price_satang' => 150000]);
    Variant::factory()->for($printing)->create(['qty' => 0, 'price_satang' => 100000, 'condition' => CardCondition::Lp]);
    $printing = Printing::query()->withCheapestVariant()->findOrFail($printing->id);

    $html = Blade::render('<x-card-tile :printing="$printing" />', ['printing' => $printing]);

    expect($html)->toContain('opacity-55')->toContain('หมด')->toContain('฿ 1,000')->toMatch('/text-card font-medium text-faint[^>]*>฿ 1,000/')
        ->not->toContain('฿ 1,500')->not->toContain('text-accent');
});

test('the list row carries the same facts as the tile', function () {
    $printing = Printing::factory()->withFoil()->create(['rarity_code' => 'rr', 'language' => Language::Ja, 'number' => '062']);
    Variant::factory()->for($printing)->create(['price_satang' => 99000, 'qty' => 5, 'condition' => CardCondition::Lp]);
    Variant::factory()->for($printing)->foil()->create(['price_satang' => 120000, 'qty' => 1]);
    $printing = Printing::query()->withCheapestVariant()->findOrFail($printing->id);

    $html = Blade::render('<x-card-row :printing="$printing" />', ['printing' => $printing]);

    expect($html)->toContain($printing->display_name)->toContain($printing->display_code)
        ->toContain('฿ 990')->toContain('มี 6')->toContain('p='.$printing->id)
        ->toContain('text-rarity-rr')->toContain('>JP<')->toContain('>LP<')->toContain('foil');
});

test('the card tile grid costs the printings query plus card, set and variants however many tiles there are', function () {
    Printing::factory()->count(8)->create()->each(fn (Printing $printing) => Variant::factory()->for($printing)->create(['qty' => 2]));

    DB::enableQueryLog();
    $printings = Printing::query()->withCheapestVariant()->get();
    Blade::render('@foreach ($printings as $printing)<x-card-tile :printing="$printing" />@endforeach', ['printings' => $printings]);

    expect($printings)->toHaveCount(8)->and(DB::getQueryLog())->toHaveCount(4);
});

test('the foil tag and sheen show only when a foil copy is available to buy', function () {
    $sold = Printing::factory()->withFoil()->create();
    Variant::factory()->for($sold)->foil()->create(['qty' => 0, 'price_satang' => 900000]);
    Variant::factory()->for($sold)->create(['qty' => 4, 'price_satang' => 129000]);
    $available = Printing::factory()->withFoil()->create();
    Variant::factory()->for($available)->foil()->create(['qty' => 2, 'price_satang' => 900000]);

    $render = fn (Printing $printing): string => Blade::render('<x-card-tile :printing="$printing" />', ['printing' => Printing::query()->withCheapestVariant()->findOrFail($printing->id)]);

    expect($render($sold))->not->toContain(__('storefront.tile.foil'))->not->toContain('foil ')->toContain('฿ 1,290')
        ->and($render($available))->toContain(__('storefront.tile.foil'))->toContain('foil ');
});

test('the card art takes its radius from the prop', function () {
    expect(Blade::render('<x-card-art radius="rounded-control" />'))->toContain('rounded-control')->not->toContain('rounded-tag')
        ->and(Blade::render('<x-card-art />'))->toContain('rounded-tag');
});

test('the display name follows the printing language and falls back to English', function () {
    $card = Card::factory()->create(['name_en' => 'Pikachu', 'name_ja' => 'ピカチュウ', 'name_th' => 'พิคาชู']);
    $bare = Card::factory()->create(['name_en' => 'Eevee', 'name_ja' => null, 'name_th' => null]);

    expect(Printing::factory()->for($card)->make(['language' => Language::Ja])->display_name)->toBe('ピカチュウ')
        ->and(Printing::factory()->for($card)->make(['language' => Language::Th])->display_name)->toBe('พิคาชู')
        ->and(Printing::factory()->for($card)->make(['language' => Language::En])->display_name)->toBe('Pikachu')
        ->and(Printing::factory()->for($bare)->make(['language' => Language::Ja])->display_name)->toBe('Eevee')
        ->and(Printing::factory()->for($bare)->make(['language' => Language::Th])->display_name)->toBe('Eevee');
});

test('set and printing codes show upper-case, the printing form without the dash', function () {
    $set = CardSet::factory()->create(['code' => 'op-05']);
    $printing = Printing::factory()->for($set, 'set')->create(['number' => '119']);

    expect($set->display_code)->toBe('OP-05')->and($printing->fresh()->display_code)->toBe('OP05-119');
});
