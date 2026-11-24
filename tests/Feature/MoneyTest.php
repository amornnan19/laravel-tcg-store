<?php

use App\Support\Money;

test('fromBaht parses whole and fractional baht into satang', function (string $baht, int $satang) {
    expect(Money::fromBaht($baht)->satang())->toBe($satang);
})->with([
    'whole baht' => ['4290', 429000],
    'one decimal' => ['4290.5', 429050],
    'two decimals' => ['4290.50', 429050],
    'zero' => ['0', 0],
    'satang only' => ['0.07', 7],
    'the maximum' => ['9999999.99', 999999999],
    'leading zeros' => ['0000000004290', 429000],
]);

test('fromBaht rejects anything that is not a plain non-negative amount', function (string $baht) {
    Money::fromBaht($baht);
})->with(['1.234', '-1', 'abc', '', '1,000', ' 5', '5.', '.5', "4290\n", '10000000', '99999999999999999999', '9999999.995'])
    ->throws(InvalidArgumentException::class);

test('fromSatang round-trips through satang', function () {
    expect(Money::fromSatang(429050)->satang())->toBe(429050);
});

test('toBahtString drops empty satang and keeps two digits otherwise', function (int $satang, string $expected) {
    expect(Money::fromSatang($satang)->toBahtString())->toBe($expected);
})->with([
    [429000, '4290'],
    [429050, '4290.50'],
    [429005, '4290.05'],
    [7, '0.07'],
    [0, '0'],
]);

test('format writes the baht sign and thousands separators', function (int $satang, string $expected) {
    expect(Money::fromSatang($satang)->format())->toBe($expected);
})->with([
    [429000, '฿ 4,290'],
    [429050, '฿ 4,290.50'],
    [100, '฿ 1'],
    [123456700, '฿ 1,234,567'],
]);
