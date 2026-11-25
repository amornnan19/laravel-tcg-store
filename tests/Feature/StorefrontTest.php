<?php

use App\Livewire\Storefront\SearchBox;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;

uses(RefreshDatabase::class);

test('the home page renders the storefront layout with the brand and the search field', function () {
    config()->set('app.name', 'Back Alley Cards');

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<html lang="th">', false)
        ->assertSee('Back Alley Cards')
        ->assertSeeLivewire(SearchBox::class)
        ->assertSee('placeholder="'.__('storefront.search.placeholder_narrow').'"', false)
        ->assertSee('data-wide="'.e(__('storefront.search.placeholder_wide')).'"', false)
        ->assertSee('maxlength="100"', false);
});

test('the bottom bar holds only home and search', function () {
    $this->get(route('home'))
        ->assertSee('aria-current="page"', false)
        ->assertSee('aria-label="'.__('storefront.search.open').'"', false)
        ->assertDontSee('/cart')
        ->assertDontSee('/account');
});

test('the game chips list active games in sort order', function () {
    Game::factory()->create(['name_en' => 'Second Game', 'is_active' => true, 'sort' => 2]);
    Game::factory()->create(['name_en' => 'First Game', 'is_active' => true, 'sort' => 1]);
    Game::factory()->create(['name_en' => 'Hidden Game', 'is_active' => false, 'sort' => 0]);

    $this->get(route('home'))
        ->assertSeeInOrder(['First Game', 'Second Game'])
        ->assertDontSee('Hidden Game');
});

test('the search and game routes resolve and the card and set routes are not built yet', function () {
    $game = Game::factory()->create();
    $set = CardSet::factory()->for($game)->create(['code' => 'OP-05']);
    $card = Card::factory()->for($game)->create(['name_en' => 'Monkey D. Luffy']);

    expect(route('search', absolute: false))->toBe('/search')
        ->and(route('card.show', $card, absolute: false))->toBe('/c/'.$card->slug)
        ->and(route('set.show', $set, absolute: false))->toBe('/s/OP-05')
        ->and(route('game.show', $game, absolute: false))->toBe('/g/'.$game->slug);

    $this->get(route('search'))->assertOk();
    $this->get(route('card.show', $card))->assertNotFound();
    $this->get(route('set.show', $set))->assertNotFound();
});

test('the price component formats satang with tabular figures', function () {
    $whole = Blade::render('<x-price :satang="429000" />');
    $fraction = Blade::render('<x-price :satang="429050" class="text-accent" />');

    expect($whole)->toContain('฿ 4,290')->toContain('tabular-nums')
        ->and($fraction)->toContain('฿ 4,290.50')->toContain('text-accent');
});

test('the card art component is the only place that knows the card ratio', function () {
    $art = Blade::render('<x-card-art size="w-12" class="shrink-0" />');

    expect($art)->toContain('aspect-[63/88]')->toContain('w-12')->toContain('shrink-0')->toContain('aria-hidden="true"')
        ->and(Blade::render('<x-card-art />'))->toContain('w-9');

    $offenders = collect(Finder::create()->files()->in(resource_path('views'))->name('*.blade.php')->notName('card-art.blade.php'))
        ->filter(fn ($file): bool => str_contains($file->getContents(), '63/88'))
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

test('no Thai literal is written in a Blade view or a storefront class', function () {
    $files = Finder::create()
        ->files()
        ->in([resource_path('views'), app_path('Livewire'), app_path('Http/Controllers/Storefront')])
        ->name(['*.blade.php', '*.php']);

    $offenders = collect($files)
        ->filter(fn ($file): bool => preg_match('/\p{Thai}/u', $file->getContents()) === 1)
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
