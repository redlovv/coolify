<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Server::flushIdentityMap();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function createLockedVariableForUpdateTest(bool $required): EnvironmentVariable
{
    return EnvironmentVariable::create([
        'key' => 'API_TOKEN',
        'value' => 'locked-secret-value',
        'is_shown_once' => true,
        'is_required' => $required,
        'is_runtime' => false,
        'is_buildtime' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => test()->application->id,
    ]);
}

test('changing a flag of a locked variable keeps its value', function () {
    $env = createLockedVariableForUpdateTest(required: false);

    Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->call('loadValues')
        ->assertSet('value', null)
        ->set('is_runtime', true)
        ->call('submit')
        ->assertDispatched('success')
        ->assertNotDispatched('error');

    $env->refresh();
    expect($env->value)->toBe('locked-secret-value')
        ->and($env->is_runtime)->toBeTrue()
        ->and((bool) $env->is_shown_once)->toBeTrue();
});

test('a locked required variable can be updated without re-entering its value', function () {
    $env = createLockedVariableForUpdateTest(required: true);

    Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->call('loadValues')
        ->set('is_runtime', true)
        ->call('submit')
        ->assertDispatched('success')
        ->assertNotDispatched('error');

    $env->refresh();
    expect($env->value)->toBe('locked-secret-value')
        ->and($env->is_runtime)->toBeTrue();
});

test('a locked required variable with an empty stored value is still rejected', function () {
    $env = createLockedVariableForUpdateTest(required: true);
    $env->value = '';
    $env->save();

    Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->call('loadValues')
        ->set('is_runtime', true)
        ->call('submit')
        ->assertDispatched('error', 'Required environment variables cannot be empty.');

    expect($env->refresh()->is_runtime)->toBeFalse();
});
