<?php

use App\Services\KwikPay\Signature;

/*
 * KwikPay's MD5 signature (merchant docs §2), checked against the docs' own
 * worked example and against the traps their FAQ lists by name.
 */

it('reproduces the worked example in the merchant docs', function () {
    // Docs §2: payload {k1: 123, k3: 456, k2: "abc"}, sorted to
    // k1=123&k2=abc&k3=456&key=I9h4luCVUiKKcZm9al1ZupANHHbNj3HG
    $sign = Signature::make(['k1' => 123, 'k3' => 456, 'k2' => 'abc'], 'I9h4luCVUiKKcZm9al1ZupANHHbNj3HG');

    expect($sign)->toBe('f5a61a8f2487ff03e3ae85bb7174daef');
});

it('leaves sign itself out of what it signs', function () {
    $fields = ['k1' => 123, 'k3' => 456, 'k2' => 'abc', 'sign' => 'anything'];

    expect(Signature::make($fields, 'I9h4luCVUiKKcZm9al1ZupANHHbNj3HG'))->toBe('f5a61a8f2487ff03e3ae85bb7174daef');
});

it('verifies the docs example, and accepts an upper-case signature', function () {
    $key = 'I9h4luCVUiKKcZm9al1ZupANHHbNj3HG';

    expect(Signature::verify(['k1' => '123', 'k3' => '456', 'k2' => 'abc', 'sign' => 'f5a61a8f2487ff03e3ae85bb7174daef'], $key))->toBeTrue();
    expect(Signature::verify(['k1' => '123', 'k3' => '456', 'k2' => 'abc', 'sign' => 'F5A61A8F2487FF03E3AE85BB7174DAEF'], $key))->toBeTrue();
});

it('signs over every field received, so a remark the sender included must be included', function () {
    $key = 'test-key';
    $fields = [
        'status' => '5', 'amount' => '100.000000', 'message' => '成功', 'merchant' => 'M1',
        'order_id' => 'PAY-2026-000001-ABC123', 'callback_url' => 'https://x.test/cb', 'remark' => 'note',
    ];
    $fields['sign'] = Signature::make($fields, $key);

    expect(Signature::verify($fields, $key))->toBeTrue();

    // A handler that signed over a fixed list without `remark` would compute
    // a different hash — the FAQ's "hardcoded field list" mismatch.
    $withoutRemark = $fields;
    unset($withoutRemark['remark']);
    expect(Signature::verify($withoutRemark, $key))->toBeFalse();
});

it('signs the amount as the raw six-decimal string, so normalising it first breaks the match', function () {
    $key = 'test-key';
    $fields = ['amount' => '100.000000', 'merchant' => 'M1', 'order_id' => 'A', 'status' => '5'];
    $fields['sign'] = Signature::make($fields, $key);

    expect(Signature::verify($fields, $key))->toBeTrue();

    // Casting to a number before hashing is the FAQ's "most common cause".
    $normalised = ['amount' => (string) (float) '100.000000'] + $fields;
    expect($normalised['amount'])->toBe('100');
    expect(Signature::verify($normalised, $key))->toBeFalse();
});

it('refuses a wrong signature, a missing one, and a blank key', function () {
    $fields = ['k1' => '123', 'k2' => 'abc', 'k3' => '456'];

    expect(Signature::verify($fields + ['sign' => str_repeat('0', 32)], 'I9h4luCVUiKKcZm9al1ZupANHHbNj3HG'))->toBeFalse();
    expect(Signature::verify($fields, 'I9h4luCVUiKKcZm9al1ZupANHHbNj3HG'))->toBeFalse();
    expect(Signature::verify($fields + ['sign' => 'f5a61a8f2487ff03e3ae85bb7174daef'], ''))->toBeFalse();
});

it('sorts keys by ASCII byte order, which puts capitals before lower case', function () {
    // "Z" (0x5A) sorts before "a" (0x61); a case-insensitive sort would not.
    expect(Signature::make(['a' => '1', 'Z' => '2'], 'k'))->toBe(md5('Z=2&a=1&key=k'));
});
