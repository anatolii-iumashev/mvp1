<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_displays_operational_metric_widgets_for_authenticated_users(): void
    {
        config(['app.env' => 'local']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response
            ->assertOk()
            ->assertSee('Call Flow')
            ->assertSee('Operations Health')
            ->assertDontSee('Documentation')
            ->assertDontSee('GitHub');
    }
}
