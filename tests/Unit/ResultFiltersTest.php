<?php

use App\Search\ResultFilters;

test('the default request only asks for printings in stock', function () {
    expect((new ResultFilters)->meilisearchFilter())->toBe('in_stock = true')
        ->and((new ResultFilters)->activeGroups())->toBe(['stock']);
});

test('every filter maps to its Meilisearch attribute, options ORed and groups ANDed', function () {
    $filters = new ResultFilters(
        games: ['one-piece'],
        sets: ['OP-05', 'OP-06'],
        rarities: ['sar'],
        cardTypes: ['leader'],
        colors: ['Red'],
        attributes: ['Slash'],
        languages: ['ja', 'en'],
        conditions: ['nm', 'lp'],
        minSatang: 50000,
        maxSatang: 500000,
        inStockOnly: true,
        foilOnly: true,
        realPhotoOnly: true,
    );

    expect($filters->meilisearchFilter())->toBe(implode(' AND ', [
        'game IN ["one-piece"]',
        'set_code IN ["OP-05", "OP-06"]',
        'rarity IN ["sar"]',
        'card_type IN ["leader"]',
        'traits.color IN ["Red"]',
        'traits.attribute IN ["Slash"]',
        'language IN ["ja", "en"]',
        'conditions IN ["nm", "lp"]',
        'from_satang >= 50000',
        'from_satang <= 500000',
        'in_stock = true',
        'has_foil = true',
        'has_real_photo = true',
    ]));
});

test('a price bound is a filter on its own', function () {
    expect((new ResultFilters(maxSatang: 9900, inStockOnly: false))->meilisearchFilter())->toBe('from_satang <= 9900')
        ->and((new ResultFilters(minSatang: 100, inStockOnly: false))->meilisearchFilter())->toBe('from_satang >= 100');
});

test('quotes and backslashes in a value cannot break out of the filter string', function () {
    $filter = (new ResultFilters(sets: ['A"] OR game = "x', 'B\\'], inStockOnly: false))->meilisearchFilter();

    expect($filter)->toBe('set_code IN ["A\\"] OR game = \\"x", "B\\\\"]');
});

test('a group can be left out to count its own options against every other filter', function () {
    $filters = new ResultFilters(rarities: ['sar'], conditions: ['nm']);

    expect($filters->meilisearchFilter('rarity'))->toBe('conditions IN ["nm"] AND in_stock = true')
        ->and($filters->meilisearchFilter('stock'))->toBe('rarity IN ["sar"] AND conditions IN ["nm"]')
        ->and($filters->without('condition')->activeGroups())->toBe(['rarity', 'stock'])
        ->and($filters->activeGroups())->toBe(['condition', 'rarity', 'stock']);
});
