<?php

declare(strict_types=1);

use Tests\TestCase;

/**
 * @internal
 */
final class BasicAuthDocsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('geni.docs_auth.username', 'admin');
        $app['config']->set('geni.docs_auth.password', 'secret');
    }

    public function test_docs_route_requires_basic_auth_when_configured(): void
    {
        $this->get('/docs/api')
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Basic realm="API Documentation"');
    }

    public function test_docs_route_allows_correct_basic_auth_credentials(): void
    {
        $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('admin:secret'),
        ])->get('/docs/api')->assertStatus(200);
    }

    public function test_docs_route_rejects_incorrect_basic_auth_credentials(): void
    {
        $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('admin:wrong'),
        ])->get('/docs/api')->assertStatus(401);
    }

    public function test_blank_credentials_leave_docs_public(): void
    {
        config(['geni.docs_auth.username' => '', 'geni.docs_auth.password' => '']);

        $this->get('/docs/api')->assertOk();
    }

    public function test_blank_password_fails_closed_instead_of_matching_empty_input(): void
    {
        config(['geni.docs_auth.password' => '']);

        $this->get('/docs/api')->assertStatus(401);
        $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('admin:'),
        ])->get('/docs/api')->assertStatus(401);
    }
}
