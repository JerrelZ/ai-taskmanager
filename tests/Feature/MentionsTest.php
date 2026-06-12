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
