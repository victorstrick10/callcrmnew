<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OfficeTreeTest extends TestCase
{
    use RefreshDatabase;

    private function seedTwoOffices(): array
    {
        $off1 = Company::create(['name' => 'Diligent Placers', 'slug' => 'diligent', 'enabled' => true, 'tree' => 'off1']);
        $off2 = Company::create(['name' => 'Rusell', 'slug' => 'rusell', 'enabled' => true, 'tree' => 'off2']);

        $c1 = Contact::create(['company_id' => $off1->id, 'first_name' => 'Alice', 'last_name' => 'One', 'email' => 'alice@off1.com']);
        $c2 = Contact::create(['company_id' => $off2->id, 'first_name' => 'Bob', 'last_name' => 'Two', 'email' => 'bob@off2.com']);

        Appointment::create(['company_id' => $off1->id, 'contact_id' => $c1->id, 'event_name' => 'Call', 'start_time' => Carbon::now(), 'status' => 'scheduled']);
        Appointment::create(['company_id' => $off2->id, 'contact_id' => $c2->id, 'event_name' => 'Call', 'start_time' => Carbon::now(), 'status' => 'scheduled']);

        return [$off1, $off2];
    }

    public function test_first_visit_redirects_to_workspace_chooser(): void
    {
        $this->seedTwoOffices();

        $this->get(route('clients.index'))->assertRedirect(route('workspace.choose'));
        $this->get(route('dashboard'))->assertRedirect(route('workspace.choose'));

        $this->get(route('workspace.choose'))->assertOk()->assertSee('Off1')->assertSee('Off2');
    }

    public function test_clients_are_scoped_to_the_selected_office(): void
    {
        $this->seedTwoOffices();

        // Off1 sees only Off1 leads.
        $this->withUnencryptedCookie('active_tree', 'off1')
            ->get(route('clients.index', ['schedule' => 'all']))
            ->assertOk()
            ->assertSee('alice@off1.com')
            ->assertDontSee('bob@off2.com');

        // Off2 sees only Off2 leads.
        $this->withUnencryptedCookie('active_tree', 'off2')
            ->get(route('clients.index', ['schedule' => 'all']))
            ->assertOk()
            ->assertSee('bob@off2.com')
            ->assertDontSee('alice@off1.com');
    }

    public function test_selecting_office_sets_cookie_and_redirects(): void
    {
        $this->seedTwoOffices();

        $this->get(route('workspace.select', 'off2'))
            ->assertRedirect(route('dashboard'))
            ->assertPlainCookie('active_tree', 'off2');
    }

    public function test_single_office_needs_no_selection(): void
    {
        Company::create(['name' => 'Only Co', 'slug' => 'only', 'enabled' => true, 'tree' => 'off1']);

        // Only one tree exists → no chooser gate.
        $this->get(route('clients.index', ['schedule' => 'all']))->assertOk();
    }
}
