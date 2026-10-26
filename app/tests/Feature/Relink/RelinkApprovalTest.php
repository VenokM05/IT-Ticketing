<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\RelinkRequest;
use App\Models\User;
use App\Services\RelinkService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    Department::factory()->create();
});

it('links the requested number to the chosen employee on approval', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create(['whatsapp_number' => '639170000000']);
    $request = RelinkRequest::create(['requested_number' => '639179999999', 'status' => RelinkRequest::STATUS_PENDING]);

    app(RelinkService::class)->approve($request, $employee, $admin);

    expect($employee->fresh()->whatsapp_number)->toBe('639179999999');
    expect($request->fresh()->status)->toBe(RelinkRequest::STATUS_APPROVED)
        ->and($request->fresh()->decided_by)->toBe($admin->id);
});

it('refuses to approve when an active employee already owns the number', function () {
    $admin = User::factory()->admin()->create();
    Employee::factory()->create(['whatsapp_number' => '639179999999', 'is_active' => true]);
    $target = Employee::factory()->create(['whatsapp_number' => '639170000000']);
    $request = RelinkRequest::create(['requested_number' => '639179999999', 'status' => RelinkRequest::STATUS_PENDING]);

    expect(fn () => app(RelinkService::class)->approve($request, $target, $admin))
        ->toThrow(ValidationException::class);

    expect($request->fresh()->status)->toBe(RelinkRequest::STATUS_PENDING);
});

it('rejects a request without touching any employee', function () {
    $admin = User::factory()->admin()->create();
    $request = RelinkRequest::create(['requested_number' => '639179999999', 'status' => RelinkRequest::STATUS_PENDING]);

    app(RelinkService::class)->reject($request, $admin);

    expect($request->fresh()->status)->toBe(RelinkRequest::STATUS_REJECTED);
});

it('is administrator only', function () {
    $this->actingAs(User::factory()->technician()->create())
        ->get(route('relinks'))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('relinks'))
        ->assertOk();
});

it('approves through the admin UI', function () {
    $this->actingAs(User::factory()->admin()->create());
    $employee = Employee::factory()->create(['whatsapp_number' => '639170000000']);
    $request = RelinkRequest::create(['requested_number' => '639179999999', 'status' => RelinkRequest::STATUS_PENDING]);

    Volt::test('relinks.index')
        ->set('assign.'.$request->id, $employee->id)
        ->call('approve', $request->id);

    expect($employee->fresh()->whatsapp_number)->toBe('639179999999');
    expect($request->fresh()->status)->toBe(RelinkRequest::STATUS_APPROVED);
});
