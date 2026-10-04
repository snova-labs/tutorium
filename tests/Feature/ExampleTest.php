<?php

declare(strict_types=1);

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->withoutVite();

        $this->get('/')->assertRedirect(route('sign-in'));

        $this->get(route('sign-in'))->assertOk();
    }
}
