<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Validation\ValidationException;
use PandaPanel\Core\PanelManager;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Step;
use PandaPanel\Forms\Layouts\Wizard;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\Panel\WizardCreateUser;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());

    app(PanelManager::class)->setCurrentPanel(panel('admin'));
});

it('serializes steps with their labels and icons', function (): void {
    $props = (new WizardCreateUser)->render(request())
        ->toResponse(request())->original->getData()['page']['props'];

    $wizard = $props['form']['schema'][0];

    expect($wizard['component'])->toBe('wizard')
        ->and($wizard['submitLabel'])->toBe('Create user')
        ->and(array_column($wizard['steps'], 'label'))->toBe(['Identity', 'Access'])
        ->and($wizard['steps'][0]['icon'])->toBe('user')
        ->and($wizard['steps'][0]['description'])->toBe('Who they are');
});

it('tells the frontend which fields each step holds', function (): void {
    $props = (new WizardCreateUser)->render(request())
        ->toResponse(request())->original->getData()['page']['props'];

    $steps = $props['form']['schema'][0]['steps'];

    // This is what lets an error jump to the step holding it without the
    // frontend knowing how the step is laid out.
    expect($steps[0]['fields'])->toBe(['name', 'email'])
        ->and($steps[1]['fields'])->toBe(['password', 'is_admin']);
});

it('validates every step at once, not step by step', function (): void {
    $schema = (new WizardCreateUser)->render(request());

    // Reaching the rules through the schema proves a wizard is flat to the
    // validator: fields from both steps, in one rule set.
    $rules = FormSchema::make()
        ->schema([
            Wizard::make([
                Step::make('One')->schema([TextInput::make('name')->required()]),
                Step::make('Two')->schema([TextInput::make('email')->email()->required()]),
            ]),
        ])
        ->validationRules();

    expect(array_keys($rules))->toBe(['name', 'email'])
        ->and($schema)->not->toBeNull();
});

it('persists exactly what the flat form would', function (): void {
    (new WizardCreateUser)->handle(request()->merge([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'is_admin' => false,
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ]));

    expect(User::query()->where('email', 'ada@example.com')->exists())->toBeTrue();
});

it('rejects a step-two field just as it would in a flat form', function (): void {
    expect(fn () => (new WizardCreateUser)->handle(request()->merge([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'is_admin' => false,
        'password' => 'secret-password',
        'password_confirmation' => 'different-password',
    ])))->toThrow(ValidationException::class);
});

it('collects a wizard field for dehydration like any other', function (): void {
    $schema = FormSchema::make()->schema([
        Wizard::make([
            Step::make('One')->schema([TextInput::make('name')]),
        ]),
    ]);

    expect(array_map(
        static fn ($field): string => $field->getName(),
        $schema->fields(),
    ))->toBe(['name']);
});

/*
 * Per-step validation
 */

it('offers a validation endpoint only for a stepped form', function (): void {
    $stepped = (new WizardCreateUser)->render(request())
        ->toResponse(request())->original->getData()['page']['props'];

    expect($stepped['validateStepUrl'])->toBe('/admin/users/create/step');

    // The ordinary create page has no steps, so there is nothing to check
    // half-way and no endpoint to advertise.
    $flat = $this->get('/admin/users/create')->viewData('page')['props'];

    expect($flat['validateStepUrl'])->toBeNull();
});

/**
 * @return array{status: int, errors: array<string, mixed>}
 */
function validateWizardStep(array $payload): array
{
    $response = (new WizardCreateUser)->validateStep(request()->merge($payload));

    /** @var array{errors: array<string, mixed>} $decoded */
    $decoded = json_decode((string) $response->getContent(), true);

    return ['status' => $response->getStatusCode(), 'errors' => $decoded['errors']];
}

it('accepts a step whose own fields are valid', function (): void {
    $result = validateWizardStep([
        'step' => 0,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ]);

    expect($result['status'])->toBe(200)
        ->and($result['errors'])->toBe([]);
});

it('rejects a step on its own fields', function (): void {
    $result = validateWizardStep([
        'step' => 0,
        'name' => '',
        'email' => 'not-an-email',
    ]);

    expect($result['status'])->toBe(422)
        ->and($result['errors'])->toHaveKeys(['name', 'email']);
});

it('ignores fields belonging to another step', function (): void {
    // Step one says nothing about the password, so a missing one cannot stop
    // the user moving on from it.
    expect(validateWizardStep([
        'step' => 0,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ])['status'])->toBe(200);
});

it('validates a confirmation alongside the password it belongs to', function (): void {
    $result = validateWizardStep([
        'step' => 1,
        'password' => 'secret-password',
        'password_confirmation' => 'different',
    ]);

    expect($result['status'])->toBe(422)
        ->and($result['errors'])->toHaveKey('password');
});

it('refuses a step that does not exist', function (): void {
    expect(fn () => validateWizardStep(['step' => 9]))
        ->toThrow(HttpException::class);
});

it('refuses to validate a step for a user who may not create', function (): void {
    $this->actingAs(User::factory()->create());

    expect(fn () => validateWizardStep(['step' => 0, 'name' => 'Ada']))
        ->toThrow(HttpException::class);
});

it('refuses to validate a step on a form that has none', function (): void {
    // The ordinary create page has no wizard, so there is no half-way point
    // to check and saying so is better than pretending to check one.
    $this->post('/admin/users/create/step', ['step' => 0])->assertStatus(400);
});

it('derives step rules from the form rather than a second definition', function (): void {
    $schema = FormSchema::make()->schema([
        Wizard::make([
            Step::make('One')->schema([TextInput::make('name')->required()]),
            Step::make('Two')->schema([TextInput::make('email')->email()->required()]),
        ]),
    ]);

    expect(array_keys($schema->validationRulesForStep(0)))->toBe(['name'])
        ->and(array_keys($schema->validationRulesForStep(1)))->toBe(['email'])
        // Together they are exactly the whole form's rules.
        ->and(array_keys($schema->validationRules()))->toBe(['name', 'email']);
});

it('has no step rules for a form that is not a wizard', function (): void {
    $schema = FormSchema::make()->schema([TextInput::make('name')->required()]);

    expect($schema->wizard())->toBeNull()
        ->and($schema->validationRulesForStep(0))->toBe([]);
});

it('keeps a step from changing what is persisted', function (): void {
    $flat = FormSchema::make()->schema([TextInput::make('name')]);

    $stepped = FormSchema::make()->schema([
        Wizard::make([Step::make('One')->schema([TextInput::make('name')])]),
    ]);

    // Layout is layout: moving a field into a step cannot change the write.
    expect($stepped->dehydrate(['name' => 'Ada']))
        ->toBe($flat->dehydrate(['name' => 'Ada']));
});
