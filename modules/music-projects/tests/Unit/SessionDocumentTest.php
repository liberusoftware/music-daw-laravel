<?php

use Illuminate\Validation\ValidationException;
use Liberu\Music\Projects\Data\SessionDocument;

function sessionDocumentFixture(): array
{
    return ['schema_version' => 1, 'engine_version' => 'test/1', 'sample_rate' => 48000,
        'tracks' => [
            ['id' => '00000000-0000-4000-8000-000000000001', 'name' => 'Drums', 'kind' => 'folder', 'parent_id' => null, 'media_ids' => []],
            ['id' => '00000000-0000-4000-8000-000000000002', 'name' => 'Kick', 'kind' => 'audio', 'parent_id' => '00000000-0000-4000-8000-000000000001', 'media_ids' => ['asset-1']],
        ],
        'assets' => [['id' => 'asset-1', 'sha256' => str_repeat('a', 64), 'sample_rate' => 48000, 'channels' => 1, 'frames' => 96000]],
        'modules' => ['plugin-hosting' => ['schema_version' => 9, 'state' => ['opaque' => 'preserved']]],
    ];
}

it('round trips a portable document without changing ids, media or opaque module state', function () {
    $input = sessionDocumentFixture();
    $document = SessionDocument::fromArray($input);
    $decoded = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($decoded)->toBe($input)
        ->and($document->assets())->toBe($input['assets'])
        ->and($document->checksum())->toBe(SessionDocument::fromArray($decoded)->checksum());
    $copy = $document->jsonSerialize();
    $copy['tracks'][0]['name'] = 'Changed';
    expect($document->jsonSerialize()['tracks'][0]['name'])->toBe('Drums');
});

it('creates an empty session with explicit sample rate and schema', function () {
    expect(SessionDocument::empty()->jsonSerialize())->toBe([
        'schema_version' => 1, 'engine_version' => 'unassigned', 'sample_rate' => 48000,
        'tracks' => [], 'assets' => [], 'modules' => [],
    ])->and(SessionDocument::empty(96000)->jsonSerialize()['sample_rate'])->toBe(96000);
});

it('rejects invalid document fields', function (string $path, mixed $value) {
    $input = sessionDocumentFixture();
    data_set($input, $path, $value);
    expect(fn () => SessionDocument::fromArray($input))->toThrow(ValidationException::class);
})->with([
    'future schema' => ['schema_version', 2],
    'string schema' => ['schema_version', '1'],
    'sample rate without valid units' => ['sample_rate', 0],
    'string rate' => ['sample_rate', '48000'],
    'invalid track identifier' => ['tracks.0.id', 'arbitrary'],
    'unknown track kind' => ['tracks.1.kind', 'video'],
    'missing referenced asset' => ['tracks.1.media_ids', ['missing']],
    'folder media' => ['tracks.0.media_ids', ['asset-1']],
    'unknown parent' => ['tracks.1.parent_id', '00000000-0000-4000-8000-000000000099'],
    'non-folder parent' => ['tracks.0.parent_id', '00000000-0000-4000-8000-000000000002'],
    'self cycle' => ['tracks.0.parent_id', '00000000-0000-4000-8000-000000000001'],
    'unverified checksum syntax' => ['assets.0.sha256', 'filename.wav'],
    'channel count' => ['assets.0.channels', 0],
    'sample frames' => ['assets.0.frames', -1],
    'future fields' => ['unsupported', 'value'],
    'unversioned module state' => ['modules.plugin-hosting.schema_version', 0],
    'extra track properties' => ['tracks.0.unhandled', 'value'],
]);

it('rejects duplicated track and asset identities', function () {
    $input = sessionDocumentFixture();
    $input['tracks'][] = $input['tracks'][1];
    expect(fn () => SessionDocument::fromArray($input))->toThrow(ValidationException::class);
    $input = sessionDocumentFixture();
    $input['assets'][] = $input['assets'][0];
    expect(fn () => SessionDocument::fromArray($input))->toThrow(ValidationException::class);
});

it('rejects indirect folder cycles and accepts nested folders', function () {
    $input = sessionDocumentFixture();
    $input['tracks'][1]['kind'] = 'folder';
    $input['tracks'][1]['media_ids'] = [];
    expect(SessionDocument::fromArray($input)->jsonSerialize())->toBe($input);
    $input['tracks'][0]['parent_id'] = $input['tracks'][1]['id'];
    expect(fn () => SessionDocument::fromArray($input))->toThrow(ValidationException::class);
});

it('bounds serialized manifest size and rejects nonfinite plugin data', function () {
    $input = sessionDocumentFixture();
    $input['modules']['plugin-hosting']['state']['opaque'] = str_repeat('x', 16 * 1024 * 1024);
    expect(fn () => SessionDocument::fromArray($input))->toThrow(ValidationException::class);
    $input['modules']['plugin-hosting']['state']['opaque'] = INF;
    expect(fn () => SessionDocument::fromArray($input))->toThrow(JsonException::class);
});
