<?php

use App\Support\EmailBody;

it('returns the whole body when there is no quoted history', function () {
    $body = "Hoi Jan,\n\nBedankt voor je bericht. Tot morgen!\n\nGroet, Piet";

    $result = EmailBody::split($body);

    expect($result['visible'])->toBe($body);
    expect($result['quoted'])->toBeNull();
});

it('splits off an English "On ... wrote:" quote block', function () {
    $body = "Sounds good, see you then.\n\nOn Tue, 3 Jun 2026 at 10:00, Jan <jan@x.nl> wrote:\n> Original question here\n> second line";

    $result = EmailBody::split($body);

    expect($result['visible'])->toBe('Sounds good, see you then.');
    expect($result['quoted'])->toContain('On Tue, 3 Jun 2026');
    expect($result['quoted'])->toContain('Original question here');
});

it('splits off a Dutch "Op ... schreef ...:" quote block', function () {
    $body = "Prima, dat is akkoord.\n\nOp di 3 jun. 2026 om 10:00 schreef Jan <jan@x.nl>:\n> Mijn oorspronkelijke vraag\n> nog een regel";

    $result = EmailBody::split($body);

    expect($result['visible'])->toBe('Prima, dat is akkoord.');
    expect($result['quoted'])->toContain('Op di 3 jun. 2026');
});

it('splits off an Outlook "Van:" header block', function () {
    $body = "Zie mijn reactie hieronder.\n\nVan: Jan <jan@x.nl>\nVerzonden: dinsdag 3 juni 2026\nAan: Piet\nOnderwerp: Vraag\n\nOorspronkelijke inhoud.";

    $result = EmailBody::split($body);

    expect($result['visible'])->toBe('Zie mijn reactie hieronder.');
    expect($result['quoted'])->toContain('Van: Jan');
});

it('splits off a block that starts directly with > quote lines', function () {
    $body = "Eens.\n\n> jij schreef dit\n> en dit";

    $result = EmailBody::split($body);

    expect($result['visible'])->toBe('Eens.');
    expect($result['quoted'])->toBe("> jij schreef dit\n> en dit");
});

it('keeps everything visible when the reply has no fresh text', function () {
    $body = "On Tue, 3 Jun 2026 at 10:00, Jan <jan@x.nl> wrote:\n> Forwarded content only";

    $result = EmailBody::split($body);

    expect($result['visible'])->toContain('Forwarded content only');
    expect($result['quoted'])->toBeNull();
});

it('falls back to stripped HTML when there is no text body', function () {
    $result = EmailBody::split(null, '<p>Hallo</p><p>wereld</p>');

    expect($result['visible'])->toContain('Hallo');
    expect($result['quoted'])->toBeNull();
});

it('does not split on a > that appears mid-sentence', function () {
    $body = 'De prijs is 5 > 3 dus dat klopt.';

    $result = EmailBody::split($body);

    expect($result['visible'])->toBe($body);
    expect($result['quoted'])->toBeNull();
});
