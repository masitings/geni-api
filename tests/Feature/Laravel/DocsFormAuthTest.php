<?php

declare(strict_types=1);

use Tests\TestCase;

class DocsFormAuthTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:6Cu/4u+W467S5+L467S5+L467S5+L467S5+L467S5+I=');
        $app['config']->set('geni.docs_auth.mode', 'form');
        $app['config']->set('geni.docs_auth.username', 'admin');
        $app['config']->set('geni.docs_auth.password', 'secret');
        $app['config']->set('geni.apis', [
            'v1' => [
                'title' => 'API v1',
                'version' => '1.0.0',
                'api_path' => 'api/v1',
            ],
        ]);
    }

    public function test_unauthenticated_guest_accessing_docs_ui_is_redirected_to_login(): void
    {
        $response = $this->get('/docs/api');

        $response->assertRedirect('/docs/api/login');
    }

    public function test_login_form_renders_successfully(): void
    {
        $response = $this->get('/docs/api/login');

        $response->assertOk();
        $response->assertSee('API Documentation');
        $response->assertSee('name="username"', false);
        $response->assertSee('name="password"', false);
    }

    public function test_submitting_invalid_credentials_redirects_back_with_error(): void
    {
        $response = $this->from('/docs/api/login')->post('/docs/api/login', [
            'username' => 'wrong',
            'password' => 'wrong',
        ]);

        $response->assertRedirect('/docs/api/login');
        $response->assertSessionHasErrors('credentials');
        $this->assertSame('wrong', session('_old_input.username'));
        $this->assertNull(session('geni_docs_authenticated'));
    }

    public function test_submitting_valid_credentials_authenticates_session_and_redirects_to_docs(): void
    {
        $response = $this->post('/docs/api/login', [
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/docs/api');
        $this->assertTrue(session('geni_docs_authenticated'));

        // Subsequent request with session should succeed
        $docsResponse = $this->get('/docs/api');
        $docsResponse->assertOk();
        $docsResponse->assertSee('geniDocs');
    }

    public function test_unauthenticated_request_to_json_spec_returns_401_json(): void
    {
        $response = $this->get('/docs/api.json');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthorized.']);
    }

    public function test_authenticated_session_can_access_json_spec(): void
    {
        $this->withSession(['geni_docs_authenticated' => true]);

        $response = $this->get('/docs/api.json');

        $response->assertOk();
        $response->assertJsonPath('openapi', '3.1.0');
    }

    public function test_logout_clears_session_and_redirects_to_login(): void
    {
        $this->withSession(['geni_docs_authenticated' => true]);

        $response = $this->post('/docs/api/logout');

        $response->assertRedirect('/docs/api/login');
        $this->assertNull(session('geni_docs_authenticated'));

        // Next visit to docs UI should be redirected
        $this->get('/docs/api')->assertRedirect('/docs/api/login');
    }

    public function test_when_credentials_null_routes_are_public(): void
    {
        config(['geni.docs_auth.username' => null]);
        config(['geni.docs_auth.password' => null]);

        $response = $this->get('/docs/api');

        $response->assertOk();
    }

    public function test_unauthenticated_request_to_versioned_json_spec_returns_401_json(): void
    {
        $response = $this->get('/docs/api/v1.json');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthorized.']);
    }

    public function test_authenticated_session_can_access_versioned_json_spec(): void
    {
        $this->withSession(['geni_docs_authenticated' => true]);

        $response = $this->get('/docs/api/v1.json');

        $response->assertOk();
        $response->assertJsonPath('openapi', '3.1.0');
    }
}
