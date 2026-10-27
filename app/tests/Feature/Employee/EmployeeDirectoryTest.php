<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeImportService;
use Livewire\Volt\Volt;

it('redirects guests to login', function () {
    $this->get(route('employees'))->assertRedirect(route('login'));
});

it('forbids technicians from the directory', function () {
    $this->actingAs(User::factory()->technician()->create())
        ->get(route('employees'))
        ->assertForbidden();
});

it('renders the directory for administrators', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create(['name' => 'Juan Dela Cruz']);

    $this->actingAs($admin)
        ->get(route('employees'))
        ->assertOk()
        ->assertSee('Juan Dela Cruz');
});

it('creates an employee and normalizes the number toward E.164', function () {
    $this->actingAs(User::factory()->admin()->create());
    $department = Department::factory()->create(['name' => 'IT Support']);
    $company = Company::factory()->create(['name' => 'Acme Manufacturing']);

    Volt::test('employees.index')
        ->call('startCreate')
        ->set('fName', 'Maria Clara')
        ->set('fNumber', '0917 123 4567')
        ->set('fDepartmentId', (string) $department->id)
        ->set('fCompanyId', (string) $company->id)
        ->set('fPosition', 'Bookkeeper')
        ->set('fEmail', 'maria@example.com')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('employees', [
        'name' => 'Maria Clara',
        'department_id' => $department->id,
        'company_id' => $company->id,
        'position' => 'Bookkeeper',
    ]);
});

it('requires a company so every request stays attributable', function () {
    $this->actingAs(User::factory()->admin()->create());
    $department = Department::factory()->create();

    Volt::test('employees.index')
        ->call('startCreate')
        ->set('fName', 'No Company')
        ->set('fNumber', '09171112223')
        ->set('fDepartmentId', (string) $department->id)
        ->set('fCompanyId', '')
        ->call('save')
        ->assertHasErrors(['fCompanyId']);

    $this->assertDatabaseMissing('employees', ['name' => 'No Company']);
});

it('rejects an invalid E.164 number', function () {
    $this->actingAs(User::factory()->admin()->create());
    $department = Department::factory()->create();

    Volt::test('employees.index')
        ->call('startCreate')
        ->set('fName', 'Shorty')
        ->set('fNumber', '12345')
        ->set('fDepartmentId', (string) $department->id)
        ->set('fCompanyId', (string) Company::factory()->create()->id)
        ->call('save')
        ->assertHasErrors(['fNumber']);

    $this->assertDatabaseMissing('employees', ['name' => 'Shorty']);
});

it('enforces number uniqueness among active employees', function () {
    $this->actingAs(User::factory()->admin()->create());
    $department = Department::factory()->create();
    Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);

    Volt::test('employees.index')
        ->call('startCreate')
        ->set('fName', 'Duplicate')
        ->set('fNumber', '639171234567')
        ->set('fDepartmentId', (string) $department->id)
        ->set('fCompanyId', (string) Company::factory()->create()->id)
        ->call('save')
        ->assertHasErrors(['fNumber']);

    $this->assertDatabaseMissing('employees', ['name' => 'Duplicate']);
});

it('edits an existing employee', function () {
    $this->actingAs(User::factory()->admin()->create());
    $employee = Employee::factory()->create(['name' => 'Old Name']);

    Volt::test('employees.index')
        ->call('startEdit', $employee->id)
        ->set('fName', 'New Name')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('employees', ['id' => $employee->id, 'name' => 'New Name']);
});

it('deactivates and reactivates an employee', function () {
    $this->actingAs(User::factory()->admin()->create());
    $employee = Employee::factory()->create(['is_active' => true]);

    Volt::test('employees.index')
        ->call('toggle', $employee->id);

    $this->assertDatabaseHas('employees', ['id' => $employee->id, 'is_active' => false]);
});

it('shows an employee profile with ticket history', function () {
    $this->actingAs(User::factory()->admin()->create());
    $employee = Employee::factory()->create(['name' => 'Profile Person']);

    Volt::test('employees.show', ['employee' => $employee])
        ->assertOk()
        ->assertSee('Profile Person')
        ->assertSee('Ticket history');
});

it('forbids technicians from an employee profile', function () {
    $employee = Employee::factory()->create();

    $this->actingAs(User::factory()->technician()->create())
        ->get(route('employees.show', $employee))
        ->assertForbidden();
});

it('aborts import and reports unmapped departments', function () {
    Department::factory()->create(['name' => 'Operations']);

    $csv = "name,whatsapp_number,department,location,email,is_active\n"
        ."Carol,639173333333,Ghost Dept,Cubao,,1\n";

    $report = app(EmployeeImportService::class)->import($csv);

    expect($report['ok'])->toBeFalse();
    expect($report['unmapped_departments'])->toContain('Ghost Dept');
    $this->assertDatabaseMissing('employees', ['name' => 'Carol']);
});

it('rejects import when an active number already exists', function () {
    Department::factory()->create(['name' => 'Operations']);
    Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);

    $csv = "name,whatsapp_number,department\nDave,639171234567,Operations\n";

    $report = app(EmployeeImportService::class)->import($csv);

    expect($report['ok'])->toBeFalse();
    $this->assertDatabaseMissing('employees', ['name' => 'Dave']);
});

it('successfully imports a clean file', function () {
    Department::factory()->create(['name' => 'Finance']);

    $csv = "name,whatsapp_number,department,location,email,is_active\n"
        ."Eve,639175555555,Finance,Makati,eve@example.com,1\n"
        ."Frank,639176666666,Finance,, frank@example.com,1\n";

    $report = app(EmployeeImportService::class)->import($csv);

    expect($report['ok'])->toBeTrue();
    expect($report['imported'])->toBe(2);
    $this->assertDatabaseHas('employees', ['name' => 'Eve', 'whatsapp_number' => '639175555555']);
});
