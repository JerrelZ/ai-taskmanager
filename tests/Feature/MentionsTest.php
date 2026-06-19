<?php

use App\Models\User;
use App\Support\Mentions;

test('it highlights known mentions, links urls and escapes html', function () {
    User::factory()->create(['name' => 'Sanne de Vries']);

    $html = Mentions::render('Hoi @Sanne zie https://example.com <script>alert(1)</script>', User::all());

    expect($html)
        ->toContain('text-brand-600')
        ->toContain('@Sanne')
        ->toContain('<a href="https://example.com"')
        ->not->toContain('<script>');
});

test('it does not highlight unknown names', function () {
    User::factory()->create(['name' => 'Sanne de Vries']);

    $html = Mentions::render('Hoi @Niemand', User::all());

    expect($html)->not->toContain('text-brand-600');
});

test('it extracts a mentioned user by full name', function () {
    $sanne = User::factory()->create(['name' => 'Sanne de Vries']);

    $mentioned = Mentions::extractUsers('Hoi @Sanne de Vries, kijk hier even naar', User::all());

    expect($mentioned->pluck('id')->all())->toBe([$sanne->id]);
});

test('it extracts a mentioned user by unambiguous first name', function () {
    $sanne = User::factory()->create(['name' => 'Sanne de Vries']);
    User::factory()->create(['name' => 'Bram Jansen']);

    $mentioned = Mentions::extractUsers('Hoi @Sanne!', User::all());

    expect($mentioned->pluck('id')->all())->toBe([$sanne->id]);
});

test('it ignores an ambiguous first name', function () {
    User::factory()->create(['name' => 'Sanne de Vries']);
    User::factory()->create(['name' => 'Sanne Bakker']);

    $mentioned = Mentions::extractUsers('Hoi @Sanne!', User::all());

    expect($mentioned)->toBeEmpty();
});

test('it ignores unknown mentions and dedupes repeated ones', function () {
    $sanne = User::factory()->create(['name' => 'Sanne de Vries']);

    $mentioned = Mentions::extractUsers('@Sanne de Vries en @Sanne de Vries, niet @Niemand', User::all());

    expect($mentioned->pluck('id')->all())->toBe([$sanne->id]);
});
