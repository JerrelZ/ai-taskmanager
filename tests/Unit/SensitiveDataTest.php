<?php

use App\Support\SensitiveData;

it('redacts credential-like columns but keeps the rest', function () {
    $row = [
        'id' => 7,
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'password' => '$2y$10$abcdefghijklmnopqrstuv',
        'remember_token' => 'xyz',
        'api_key' => 'sk-123',
        'two_factor_secret' => 'base32',
    ];

    $redacted = SensitiveData::redactRow($row);

    expect($redacted['id'])->toBe(7)
        ->and($redacted['name'])->toBe('Jane')
        ->and($redacted['email'])->toBe('jane@example.com')
        ->and($redacted['password'])->toBe('[verborgen]')
        ->and($redacted['remember_token'])->toBe('[verborgen]')
        ->and($redacted['api_key'])->toBe('[verborgen]')
        ->and($redacted['two_factor_secret'])->toBe('[verborgen]');
});

it('matches case-insensitively and on substrings', function () {
    expect(SensitiveData::isSensitive('PASSWORD'))->toBeTrue()
        ->and(SensitiveData::isSensitive('user_password_hash'))->toBeTrue()
        ->and(SensitiveData::isSensitive('access_token'))->toBeTrue()
        ->and(SensitiveData::isSensitive('email'))->toBeFalse()
        ->and(SensitiveData::isSensitive('company_name'))->toBeFalse();
});
