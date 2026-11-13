<?php

namespace FullSmack\EnvironmentGate\Tests\Feature;

use FullSmack\EnvironmentGate\Tests\TestCase;

class CommandsTest extends TestCase
{
    public function test_link_command_prints_a_shareable_url(): void
    {
        config(['app.url' => 'https://staging.example.com']);

        $this->artisan('environment-gate:link', ['label' => 'client-acme'])
            ->expectsOutput('https://staging.example.com/?access=acme-secret-code')
            ->assertSuccessful();
    }

    public function test_link_command_accepts_a_url(): void
    {
        $this->artisan('environment-gate:link', ['label' => 'alice', '--url' => 'https://demo.example.com/events?id=4'])
            ->expectsOutput('https://demo.example.com/events?id=4&access=alice-secret-code')
            ->assertSuccessful();
    }

    public function test_link_command_fails_for_unknown_label(): void
    {
        $this->artisan('environment-gate:link', ['label' => 'bob'])->assertFailed();
    }

    public function test_generate_command_outputs_an_entry(): void
    {
        $this->artisan('environment-gate:generate', ['label' => 'bob'])
            ->expectsOutputToContain('bob:')
            ->assertSuccessful();
    }

    public function test_generate_command_rejects_invalid_labels(): void
    {
        $this->artisan('environment-gate:generate', ['label' => 'bad label'])->assertFailed();
    }
}
