<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmployeeImportService;
use App\Services\ReportService;
use App\Services\WhatsAppInboundService;
use Livewire\Volt\Volt;

/*
 * Multi-company tagging: the company is inherited from the requester's enrollment
 * (never typed into the chat or chosen separately on the web form), then travels with
 * the ticket into lists, reports and exports. (process.md items 1–3.)
 */

beforeEach(function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    $this->acme = Company::factory()->create(['name' => 'Acme Manufacturing', 'code' => 'ACME']);
    $this->northwind = Company::factory()->create(['name' => 'Northwind Logistics', 'code' => 'NWIND']);
    $this->operations = Department::factory()->create(['name' => 'Operations']);
});

it('stamps the sender company onto a WhatsApp-created ticket', function () {
    $employee = Employee::factory()->create([
        'whatsapp_number' => '639171234567',
        'is_active' => true,
        'company_id' => $this->acme->id,
    ]);

    app(WhatsAppInboundService::class)->handle([
        'from' => '639171234567',
        'message_id' => 'wamid.IN1',
        'type' => 'text',
        'body' => 'NEW TICKET forklift charger is dead',
        'timestamp' => now()->timestamp,
    ]);

    expect(Ticket::count())->toBe(1)
        ->and(Ticket::first()->employee_id)->toBe($employee->id)
        ->and(Ticket::first()->company_id)->toBe($this->acme->id);
});

it('stamps the requester company onto a web-submitted ticket', function () {
    $this->actingAs(User::factory()->admin()->create());

    $employee = Employee::factory()->create([
        'department_id' => $this->operations->id,
        'company_id' => $this->northwind->id,
    ]);

    Volt::test('tickets.create')
        ->set('employeeId', (string) $employee->id)
        ->set('subject', 'Payroll machine offline')
        ->set('description', 'Biometric payroll terminal will not boot.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Ticket::count())->toBe(1)
        ->and(Ticket::first()->company_id)->toBe($this->northwind->id);
});

it('filters the ticket list by company', function () {
    $this->actingAs(User::factory()->admin()->create());

    $acmeTicket = Ticket::factory()->create([
        'employee_id' => Employee::factory()->create(['company_id' => $this->acme->id])->id,
        'company_id' => $this->acme->id,
        'ticket_number' => 'IT-2026-000111',
    ]);
    $northwindTicket = Ticket::factory()->create([
        'employee_id' => Employee::factory()->create(['company_id' => $this->northwind->id])->id,
        'company_id' => $this->northwind->id,
        'ticket_number' => 'IT-2026-000222',
    ]);

    Volt::test('tickets.index')
        ->set('companyId', (string) $this->acme->id)
        ->assertSee($acmeTicket->ticket_number)
        ->assertDontSee($northwindTicket->ticket_number);
});

it('aborts a CSV import that names an unknown company', function () {
    $csv = "name,whatsapp_number,department,company\n"
        ."Carol,639173333333,Operations,Initech\n";

    $report = app(EmployeeImportService::class)->import($csv);

    expect($report['ok'])->toBeFalse()
        ->and($report['unmapped_companies'])->toContain('Initech');
    $this->assertDatabaseMissing('employees', ['name' => 'Carol']);
});

it('imports employees with their company, by name or code', function () {
    $csv = "name,whatsapp_number,department,company,position\n"
        ."Eve,639175555555,Operations,Acme Manufacturing,Dispatcher\n"
        ."Frank,639176666666,Operations,NWIND,Driver\n";

    $report = app(EmployeeImportService::class)->import($csv);

    expect($report['ok'])->toBeTrue()->and($report['imported'])->toBe(2);

    $this->assertDatabaseHas('employees', [
        'name' => 'Eve',
        'company_id' => $this->acme->id,
        'position' => 'Dispatcher',
    ]);
    $this->assertDatabaseHas('employees', [
        'name' => 'Frank',
        'company_id' => $this->northwind->id,
    ]);
});

it('still imports legacy files that have no company column', function () {
    $report = app(EmployeeImportService::class)
        ->import("name,whatsapp_number,department\nGus,639177777777,Operations\n");

    expect($report['ok'])->toBeTrue();
    $this->assertDatabaseHas('employees', ['name' => 'Gus', 'company_id' => null]);
});

it('reports volume per company and honours the company filter', function () {
    $reports = app(ReportService::class);

    foreach ([$this->acme, $this->acme, $this->northwind] as $index => $company) {
        Ticket::factory()->create([
            'employee_id' => Employee::factory()->create(['company_id' => $company->id])->id,
            'company_id' => $company->id,
        ]);
    }

    $byCompany = collect($reports->report()['by_company'])->keyBy('company');
    expect($byCompany['Acme Manufacturing']['count'])->toBe(2)
        ->and($byCompany['Northwind Logistics']['count'])->toBe(1);

    $scoped = $reports->report(['company' => (string) $this->northwind->id]);
    expect($scoped['totals']['created'])->toBe(1)
        ->and($scoped['by_company'])->toHaveCount(1);
});

it('includes the company column in the CSV export and filters by company', function () {
    Ticket::factory()->create([
        'employee_id' => Employee::factory()->create(['company_id' => $this->acme->id])->id,
        'company_id' => $this->acme->id,
        'ticket_number' => 'IT-2026-000333',
    ]);

    $response = $this->actingAs(User::factory()->admin()->create())->get(route('reports.export'));

    $response->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain('Company')->and($csv)->toContain('Acme Manufacturing');

    $filtered = $this->actingAs(User::factory()->admin()->create())
        ->get(route('reports.export', ['company' => $this->northwind->id]));
    expect($filtered->streamedContent())->not->toContain('IT-2026-000333');
});

it('lets administrators manage companies but not technicians', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('companies'))
        ->assertOk()
        ->assertSee('Companies');

    $this->actingAs(User::factory()->technician()->create())
        ->get(route('companies'))
        ->assertForbidden();
});

it('creates a company from the admin page', function () {
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('companies.index')
        ->call('startCreate')
        ->set('fName', 'Initech')
        ->set('fCode', 'initech')
        ->call('save')
        ->assertHasNoErrors();

    // Codes are normalised to upper case for consistent labelling.
    $this->assertDatabaseHas('companies', ['name' => 'Initech', 'code' => 'INITECH']);
});

it('rejects a duplicate company code', function () {
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('companies.index')
        ->call('startCreate')
        ->set('fName', 'Copy Co')
        ->set('fCode', 'ACME')
        ->call('save')
        ->assertHasErrors(['fCode']);
});
