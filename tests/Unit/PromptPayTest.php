<?php

use App\Support\Money;
use App\Support\PromptPay;

/**
 * An independent CRC-16/CCITT-FALSE: the byte-wise form with the bit loop unrolled the other way, so it does not share
 * code with the implementation under test.
 */
function referenceCrc(string $data): string
{
    $crc = 0xFFFF;

    foreach (unpack('C*', $data) as $byte) {
        $crc ^= $byte << 8;

        for ($i = 0; $i < 8; $i++) {
            $crc = $crc & 0x8000 ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            $crc &= 0xFFFF;
        }
    }

    return sprintf('%04X', $crc);
}

test('the CRC of 123456789 is 29B1, the CCITT-FALSE check value', function () {
    expect(PromptPay::crc16('123456789'))->toBe('29B1');
});

test('a mobile number pays through sub-tag 01 as 0066 and the number without its leading zero', function () {
    // Written out field by field from the EMVCo layout: 00 version, 01 dynamic, 29 account, 53 THB, 54 amount, 58 TH.
    $body = '000201'
        .'010212'
        .'2937'.'0016A000000677010111'.'0113'.'0066812345678'
        .'5303764'
        .'5406100.00'
        .'5802TH'
        .'6304';

    expect(PromptPay::payload('0812345678', 10000))->toBe($body.referenceCrc($body))
        ->and(PromptPay::payload('081-234-5678', 10000))->toBe(PromptPay::payload('0812345678', 10000));
});

test('a 13-digit id pays through sub-tag 02 and an e-wallet id through 03', function () {
    $national = '000201010212'.'2937'.'0016A000000677010111'.'0213'.'1234567890123'.'5303764'.'54041.50'.'5802TH'.'6304';
    $wallet = '000201010212'.'2939'.'0016A000000677010111'.'0315'.'004999000000001'.'5303764'.'54041.50'.'5802TH'.'6304';

    expect(PromptPay::payload('1234567890123', 150))->toBe($national.referenceCrc($national))
        ->and(PromptPay::payload('004999000000001', 150))->toBe($wallet.referenceCrc($wallet));
});

test('the amount always carries two decimals and the CRC closes the payload', function () {
    $payload = PromptPay::payload('0812345678', 1935000);

    expect($payload)->toContain('540819350.00')
        ->and(substr($payload, -8, 4))->toBe('6304')
        ->and(substr($payload, -4))->toBe(referenceCrc(substr($payload, 0, -4)));
});

test('an id that is not PromptPay and an amount that is not above zero are refused', function () {
    expect(fn () => PromptPay::payload('12345', 100))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PromptPay::payload('1812345678', 100))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PromptPay::payload('0812345678', 0))->toThrow(InvalidArgumentException::class);
});

test('ids are written the way people write them', function () {
    expect(PromptPay::format('0812345678'))->toBe('081-234-5678')
        ->and(PromptPay::format('1234567890123'))->toBe('1-2345-67890-12-3')
        ->and(Money::fromSatang(1935000)->formatWithSatang())->toBe('฿ 19,350.00')
        ->and(Money::fromSatang(1935000)->toDecimalString())->toBe('19350.00')
        ->and(Money::fromSatang(5)->toDecimalString())->toBe('0.05');
});

test('a mobile number written with +66, 66, spaces or dashes pays the same as its 0-form', function () {
    $expected = PromptPay::payload('0812345678', 10000);

    foreach (['+66812345678', '66812345678', '+66 81 234 5678', '66-81-234-5678', '081 234 5678', '0066812345678'] as $id) {
        expect(PromptPay::payload($id, 10000))->toBe($expected)
            ->and(PromptPay::isValidId($id))->toBeTrue()
            ->and(PromptPay::format($id))->toBe('081-234-5678');
    }

    expect(PromptPay::normalize('+66812345678'))->toBe('0812345678');
});

test('only a mobile number, a 13-digit id or a 15-digit e-wallet id is a valid PromptPay id', function (string $id, bool $valid) {
    expect(PromptPay::isValidId($id))->toBe($valid);
})->with([
    ['0812345678', true], ['1234567890123', true], ['004999000000001', true],
    ['', false], ['12345', false], ['1812345678', false], ['0812345', false], ['+66812345', false],
]);

test('the wire form 0066 and nine digits is the mobile number, while a real 13-digit id is untouched', function () {
    expect(PromptPay::normalize('0066812345678'))->toBe('0812345678')
        ->and(PromptPay::normalize('1234567890123'))->toBe('1234567890123')
        ->and(PromptPay::isValidId('0066812345678'))->toBeTrue();
});
