<?php

use App\Models\Attachment;

it('classifies attachment types from mime and filename', function (string $mime, string $filename, string $expected) {
    $attachment = new Attachment(['mime_type' => $mime, 'filename' => $filename]);

    expect($attachment->isImage())->toBe($expected === 'image')
        ->and($attachment->isVideo())->toBe($expected === 'video')
        ->and($attachment->isPdf())->toBe($expected === 'pdf')
        // Images and videos preview in the modal; everything else downloads.
        ->and($attachment->isPreviewable())->toBe(in_array($expected, ['image', 'video'], true));
})->with([
    'png image' => ['image/png', 'foto.png', 'image'],
    'jpeg image' => ['image/jpeg', 'foto.jpg', 'image'],
    'mp4 video' => ['video/mp4', 'clip.mp4', 'video'],
    'quicktime video' => ['video/quicktime', 'clip.mov', 'video'],
    'pdf by mime' => ['application/pdf', 'rapport.pdf', 'pdf'],
    'pdf by extension' => ['application/octet-stream', 'rapport.PDF', 'pdf'],
    'spreadsheet' => ['text/csv', 'export.csv', 'other'],
]);
