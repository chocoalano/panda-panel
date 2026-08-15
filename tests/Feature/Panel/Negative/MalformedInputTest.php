<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Panel\Forms\FormFixturePanel;

/**
 * Input of the wrong shape entirely.
 *
 * Not an attack so much as the ordinary state of a public endpoint: a client
 * that sends an array where a string was expected, a key that is missing, a
 * value that is null. What matters is the *class* of answer. A 4xx is the
 * endpoint saying no; a 500 is the endpoint falling over, and a 500 leaks a
 * stack trace, fills a log, and is something an attacker can cause on demand.
 *
 * So every assertion here is the same one: whatever this does, it is answered
 * rather than crashed into.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->target = User::factory()->create();

    $this->actingAs($this->admin);
});

/**
 * Every status that means "the endpoint decided", as opposed to "the endpoint
 * broke". A redirect counts: an action that refuses on the way back to the
 * page it came from has still decided.
 *
 * @return list<int>
 */
function answeredStatuses(): array
{
    return [200, 201, 204, 302, 400, 401, 403, 404, 405, 409, 422, 429];
}

/*
 * The action endpoints
 */

it('answers rather than breaks on a malformed record action payload', function (): void {
    $payloads = [
        [],
        ['resource' => 'users'],
        ['resource' => 'users', 'action' => 'delete'],
        ['resource' => ['users'], 'action' => 'delete', 'record' => 1],
        ['resource' => 'users', 'action' => ['delete'], 'record' => 1],
        ['resource' => 'users', 'action' => 'delete', 'record' => null],
        ['resource' => 'users', 'action' => 'delete', 'record' => ['a' => 'b']],
        ['resource' => null, 'action' => null, 'record' => null],
        ['resource' => 'users', 'action' => 'delete', 'record' => str_repeat('9', 500)],
        ['resource' => str_repeat('a', 5000), 'action' => 'delete', 'record' => 1],
        ['resource' => "users\0", 'action' => 'delete', 'record' => 1],
    ];

    foreach ($payloads as $index => $payload) {
        $status = $this->post('/admin/actions/record', $payload)->getStatusCode();

        expect($status)->toBeIn(answeredStatuses(), "record payload #{$index} produced {$status}");
    }
});

it('answers rather than breaks on a malformed bulk action payload', function (): void {
    $payloads = [
        ['resource' => 'users', 'action' => 'verify'],
        ['resource' => 'users', 'action' => 'verify', 'records' => 'not-an-array'],
        ['resource' => 'users', 'action' => 'verify', 'records' => []],
        ['resource' => 'users', 'action' => 'verify', 'records' => [null]],
        ['resource' => 'users', 'action' => 'verify', 'records' => [['nested']]],
        ['resource' => 'users', 'action' => 'verify', 'records' => range(1, 2000)],
    ];

    foreach ($payloads as $index => $payload) {
        $status = $this->post('/admin/actions/bulk', $payload)->getStatusCode();

        expect($status)->toBeIn(answeredStatuses(), "bulk payload #{$index} produced {$status}");
    }
});

it('answers rather than breaks on a malformed cell action payload', function (): void {
    $payloads = [
        ['resource' => 'users', 'column' => 'name'],
        ['resource' => 'users', 'column' => 'nope', 'record' => 1, 'value' => 'x'],
        ['resource' => 'users', 'column' => ['name'], 'record' => 1, 'value' => 'x'],
        ['resource' => 'users', 'column' => 'name', 'record' => 1, 'value' => ['x']],
        ['resource' => 'users', 'column' => 'avatar', 'record' => 1, 'value' => 'x'],
    ];

    foreach ($payloads as $index => $payload) {
        $status = $this->post('/admin/actions/cell', $payload)->getStatusCode();

        expect($status)->toBeIn(answeredStatuses(), "cell payload #{$index} produced {$status}");
    }
});

/*
 * The read endpoints
 */

it('answers rather than breaks on malformed option lookups', function (): void {
    $queries = [
        '',
        'resource=users',
        'resource[]=users&field=name',
        'resource=users&field[]=name',
        'resource=nope&field=name',
        'resource=users&field=name&relation=nope',
        'resource=users&field=name&relation=labels&operation=nope',
        'resource=users&field=name&search='.str_repeat('a', 5000),
    ];

    foreach ($queries as $index => $query) {
        $status = $this->get('/admin/options?'.$query)->getStatusCode();

        expect($status)->toBeIn(answeredStatuses(), "options query #{$index} produced {$status}");
    }
});

it('answers rather than breaks on malformed search requests', function (): void {
    $queries = [
        '',
        'q[]=a',
        'q='.str_repeat('a', 10_000),
        'q=%00',
        'q='.urlencode("' or 1=1--"),
        'search=ignored-key',
    ];

    foreach ($queries as $index => $query) {
        $status = $this->get('/admin/search?'.$query)->getStatusCode();

        expect($status)->toBeIn(answeredStatuses(), "search query #{$index} produced {$status}");
    }
});

it('answers rather than breaks on malformed notification requests', function (): void {
    expect($this->postJson('/admin/notifications/read', ['id' => ['a']])->getStatusCode())
        ->toBeIn(answeredStatuses());

    expect($this->postJson('/admin/notifications/read', ['id' => str_repeat('a', 500)])->getStatusCode())
        ->toBeIn(answeredStatuses());

    expect($this->postJson('/admin/notifications/clear', ['all' => 'yes please'])->getStatusCode())
        ->toBeIn(answeredStatuses());
});

/*
 * Record keys that are not record keys
 */

it('answers rather than breaks on a hostile record key in a URL', function (): void {
    $keys = ['abc', '0', '-1', '1e400', '../../etc/passwd', '%00', str_repeat('9', 400)];

    foreach ($keys as $key) {
        $status = $this->get('/admin/users/'.rawurlencode($key))->getStatusCode();

        expect($status)->toBeIn(answeredStatuses(), "record key {$key} produced {$status}");
    }
});

it('answers rather than breaks on a hostile record key in an edit submission', function (): void {
    foreach (['abc', '0', str_repeat('9', 400)] as $key) {
        $status = $this->put('/admin/users/'.rawurlencode($key).'/edit', [
            'name' => 'x',
            'email' => 'x@example.test',
        ])->getStatusCode();

        expect($status)->toBeIn(answeredStatuses(), "edit key {$key} produced {$status}");
    }
});

/*
 * Uploads
 */

function uploadUrl(): string
{
    return route('panel.'.FormFixturePanel::ID.'.uploads', [
        'resource' => 'form-fixtures',
        'page' => 'create',
    ], absolute: false);
}

it('answers rather than breaks on a malformed upload', function (): void {
    Storage::fake('public');

    FormFixturePanel::boot();

    $url = route('panel.'.FormFixturePanel::ID.'.uploads', [
        'resource' => 'form-fixtures',
        'page' => 'create',
    ], absolute: false);

    $payloads = [
        [],
        ['resource' => 'form-fixtures'],
        ['resource' => 'form-fixtures', 'field' => 'attachment'],
        ['resource' => 'form-fixtures', 'field' => 'nope', 'file' => UploadedFile::fake()->image('a.png')],
        ['resource' => 'nope', 'field' => 'attachment', 'file' => UploadedFile::fake()->image('a.png')],
        ['resource' => 'form-fixtures', 'field' => ['attachment'], 'file' => UploadedFile::fake()->image('a.png')],
        ['resource' => 'form-fixtures', 'field' => 'attachment', 'file' => 'not-a-file'],
    ];

    foreach ($payloads as $index => $payload) {
        $status = $this->post($url, $payload)->getStatusCode();

        expect($status)->toBeIn(answeredStatuses(), "upload payload #{$index} produced {$status}");
    }
});

it('refuses a PHP script wearing a png extension', function (): void {
    Storage::fake('public');

    FormFixturePanel::boot();

    // A *real* uploaded file, not `UploadedFile::fake()`: the fake reports its
    // type from the file name, so it would answer `image/png` for anything
    // called `.png` and this test would pass without testing anything. A real
    // one reads the bytes, which is what the `mimetypes:` rule needs and what
    // the browser cannot lie about.
    $path = tempnam(sys_get_temp_dir(), 'panda').'.png';

    file_put_contents($path, "<?php echo 'hi'; ?>\n");

    $file = new UploadedFile($path, 'payload.png', null, null, test: true);

    expect($file->getMimeType())->not->toBe('image/png');

    $this->postJson(uploadUrl(), [
        'resource' => 'form-fixtures',
        'field' => 'attachment',
        'file' => $file,
    ])->assertStatus(422);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();

    @unlink($path);
});

it('refuses a file larger than the field allows', function (): void {
    Storage::fake('public');

    FormFixturePanel::boot();

    // The field declares `maxSize(64)`, in kilobytes.
    $this->postJson(uploadUrl(), [
        'resource' => 'form-fixtures',
        'field' => 'attachment',
        'file' => UploadedFile::fake()->image('huge.png')->size(2048),
    ])->assertStatus(422);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

/*
 * Verbs the routes do not offer
 */

it('answers rather than breaks when an endpoint is called with the wrong verb', function (): void {
    expect($this->get('/admin/actions/record')->getStatusCode())->toBeIn(answeredStatuses());
    expect($this->post('/admin/users')->getStatusCode())->toBeIn(answeredStatuses());
    expect($this->delete('/admin/users/'.$this->target->id)->getStatusCode())->toBeIn(answeredStatuses());
});
