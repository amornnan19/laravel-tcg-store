<?php

use App\Enums\Language;
use App\Enums\QueryKind;
use App\Models\CardSet;
use App\Models\Printing;
use App\Search\ParsedQuery;
use App\Search\QueryParser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * One set per game code shape, each with printings whose numbers follow that game's shape.
 *
 * @return array<string, CardSet>
 */
function parserCatalogue(): array
{
    $sets = [];

    foreach (['OP-05' => ['119', '001'], 'BT-16' => ['047'], 'ROTA' => ['062'], 'sv3pt5' => ['2', '215/197'], 'SV4a' => ['190/190']] as $code => $numbers) {
        $sets[$code] = CardSet::factory()->create(['code' => $code]);

        foreach ($numbers as $number) {
            Printing::factory()->for($sets[$code], 'set')->create(['number' => $number]);
        }
    }

    return $sets;
}

function parse(string $raw): ParsedQuery
{
    return app(QueryParser::class)->parse($raw);
}

dataset('printing inputs', [
    'one piece hyphen' => ['OP05-119', 'OP-05', '119'],
    'one piece space' => ['OP05 119', 'OP-05', '119'],
    'one piece lower case with hyphenated set' => ['op-05 119', 'OP-05', '119'],
    'one piece hyphen everywhere' => ['OP-05-119', 'OP-05', '119'],
    'one piece spaced set and number' => ['op 05 119', 'OP-05', '119'],
    'yugioh spaced region' => ['rota en 062', 'ROTA', '062'],
    'yugioh japanese region' => ['ROTA-JP062', 'ROTA', '062'],
    'full width' => ['ＯＰ０５－１１９', 'OP-05', '119'],
    'one piece unpadded number' => ['OP05-1', 'OP-05', '001'],
    'digimon' => ['BT16-047', 'BT-16', '047'],
    'yugioh region dropped' => ['ROTA-EN062', 'ROTA', '062'],
    'yugioh without region' => ['ROTA-062', 'ROTA', '062'],
    'pokemon number only' => ['sv3pt5 2', 'sv3pt5', '2'],
    'pokemon fraction' => ['SV3PT5 2/165', 'sv3pt5', '2'],
    'pokemon fraction with spaces' => ['sv3pt5 215 / 197', 'sv3pt5', '215/197'],
    'pokemon sv4a full number' => ['SV4a 190/190', 'SV4a', '190/190'],
    'pokemon sv4a plain part of number' => ['sv4a 190', 'SV4a', '190/190'],
]);

it('recognises a card code and returns every matching printing', function (string $input, string $setCode, string $number) {
    $sets = parserCatalogue();

    $parsed = parse($input);

    expect($parsed->kind)->toBe(QueryKind::Printing)
        ->and($parsed->setIds)->toBe([$sets[$setCode]->id])
        ->and($parsed->printingIds)->toBe(
            Printing::query()->where('set_id', $sets[$setCode]->id)->where('number', $number)->pluck('id')->all()
        )
        ->and($parsed->printingIds)->not->toBeEmpty();
})->with('printing inputs');

it('returns the printing of every language', function () {
    $sets = parserCatalogue();
    $japanese = Printing::factory()->for($sets['OP-05'], 'set')->create(['number' => '119', 'language' => Language::Ja]);

    $parsed = parse('OP05-119');

    expect($parsed->printingIds)->toContain($japanese->id)->toHaveCount(2);
});

dataset('set inputs', [
    'one piece compact' => ['OP05', 'OP-05'],
    'one piece hyphen' => ['OP-05', 'OP-05'],
    'one piece spaced and lower' => ['op 05', 'OP-05'],
    'digimon' => ['BT16', 'BT-16'],
    'yugioh' => ['rota', 'ROTA'],
    'pokemon' => ['sv3pt5', 'sv3pt5'],
    'pokemon mixed case' => ['SV4A', 'SV4a'],
    'surrounding whitespace' => ['  OP05  ', 'OP-05'],
]);

it('treats a set code on its own as a set', function (string $input, string $setCode) {
    $sets = parserCatalogue();

    $parsed = parse($input);

    expect($parsed->kind)->toBe(QueryKind::Set)
        ->and($parsed->setIds)->toBe([$sets[$setCode]->id])
        ->and($parsed->printingIds)->toBe([]);
})->with('set inputs');

it('jumps to the set when the set exists but the number does not', function () {
    $sets = parserCatalogue();

    $parsed = parse('OP05-999');

    expect($parsed->kind)->toBe(QueryKind::Set)->and($parsed->setIds)->toBe([$sets['OP-05']->id]);
});

it('keeps a bare fraction as text carrying its number', function () {
    parserCatalogue();

    $parsed = parse('190/190');

    expect($parsed->kind)->toBe(QueryKind::Text)
        ->and($parsed->numberPlain)->toBe(190)
        ->and($parsed->setIds)->toBe([]);
});

dataset('text inputs', [
    'a card name' => ['Pikachu'],
    'a bare number' => ['151'],
    'empty' => [''],
    'blank' => ['   '],
    'emoji' => ['🔥'],
    'thai name' => ['ลูฟี่'],
    'unknown set code' => ['XX99-001'],
    'set code prefix of a word' => ['rotation'],
    'code then words' => ['OP05 luffy'],
    'letters that are not a region' => ['OP05 ab119'],
    'joined code and number is not split' => ['OP05119'],
    'joined code and number with region is not split' => ['ROTAEN062'],
    'number then fraction junk' => ['OP05 1/2/3'],
]);

it('treats anything else as text', function (string $input) {
    parserCatalogue();

    $parsed = parse($input);

    expect($parsed->kind)->toBe(QueryKind::Text)
        ->and($parsed->setIds)->toBe([])
        ->and($parsed->printingIds)->toBe([])
        ->and($parsed->text)->toBe(trim($input));
})->with('text inputs');

it('does not treat a set code as a set when no such set exists', function () {
    expect(parse('OP05')->kind)->toBe(QueryKind::Text);
});

test('codes that share a prefix are never confused', function (string $input, string $setCode, string $number) {
    $sets = [];

    foreach (['sv1' => '001', 'sv10' => '001', 'BT-1' => '001', 'BT-10' => '001'] as $code => $n) {
        $sets[$code] = CardSet::factory()->create(['code' => $code]);
        Printing::factory()->for($sets[$code], 'set')->create(['number' => $n]);
    }

    Printing::query()->where('set_id', $sets['sv1']->id)->update(['number' => '001/198']);

    $parsed = parse($input);

    expect($parsed->kind)->toBe(QueryKind::Printing)
        ->and($parsed->setIds)->toBe([$sets[$setCode]->id])
        ->and($parsed->printingIds)->toBe(Printing::query()->where('set_id', $sets[$setCode]->id)->pluck('id')->all());
})->with([
    'sv1 fraction' => ['SV1 001/198', 'sv1', '001/198'],
    'sv1 space' => ['sv1 001', 'sv1', '001/198'],
    'sv1 dash' => ['sv1-001', 'sv1', '001/198'],
    'sv10' => ['sv10 001', 'sv10', '001'],
    'BT1' => ['BT1-001', 'BT-1', '001'],
    'BT10' => ['BT10-001', 'BT-10', '001'],
    'split BT-1' => ['BT-1-001', 'BT-1', '001'],
    'split BT-10' => ['BT-10-001', 'BT-10', '001'],
]);

test('a prefix-sharing set code alone jumps to exactly that set', function (string $input, string $setCode) {
    $sets = [];

    foreach (['sv1', 'sv10'] as $code) {
        $sets[$code] = CardSet::factory()->create(['code' => $code]);
    }

    expect(parse($input)->setIds)->toBe([$sets[$setCode]->id]);
})->with([['sv1', 'sv1'], ['sv10', 'sv10'], ['sv1 999', 'sv1']]);

test('a trashed card gives no printing jump, and an inactive game no printing or set jump', function () {
    $sets = parserCatalogue();
    $printing = Printing::query()->where('set_id', $sets['OP-05']->id)->where('number', '119')->first();

    $printing->card->delete();
    expect(parse('OP05-119')->kind)->toBe(QueryKind::Set);

    $sets['BT-16']->game->update(['is_active' => false]);
    app()->forgetScopedInstances(); // the code map is cached per request
    expect(parse('BT16-047')->kind)->toBe(QueryKind::Text)
        ->and(parse('BT16')->kind)->toBe(QueryKind::Text);
});

test('the same code in two games jumps to both sets, capped at two', function () {
    $first = CardSet::factory()->create(['code' => 'XY-1']);
    $second = CardSet::factory()->create(['code' => 'XY1']);
    CardSet::factory()->create(['code' => 'xy 1']);
    Printing::factory()->for($first, 'set')->create(['number' => '5']);
    Printing::factory()->for($second, 'set')->create(['number' => '5']);

    $set = parse('xy1');
    $printing = parse('xy1 5');

    expect($set->setIds)->toBe([$first->id, $second->id])
        ->and($printing->kind)->toBe(QueryKind::Printing)
        ->and($printing->setIds)->toHaveCount(3)
        ->and($printing->printingIds)->toHaveCount(2);
});

test('a long pasted query is plain text and parses quickly', function () {
    $started = microtime(true);
    $parsed = app(QueryParser::class)->parse(str_repeat('a ', 20000).'OP05 119');

    expect($parsed->kind)->toBe(QueryKind::Text)
        ->and(microtime(true) - $started)->toBeLessThan(0.5);
});
