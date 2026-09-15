<?php

declare(strict_types=1);

use Geni\SchemaReader\SchemaReader;

test('validates schema reconstruction on real project 1: packaging', function () {
    $reader = new SchemaReader;
    $fixturesDir = __DIR__.'/../Fixtures/packaging/migrations';

    $schema = $reader->read([$fixturesDir]);

    // Expected snapshot of actual packaging SQLite database
    $expectedTables = [
        'cache' => ['key', 'value', 'expiration'],
        'cache_locks' => ['key', 'owner', 'expiration'],
        'failed_jobs' => ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'],
        'job_batches' => ['id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'failed_job_ids', 'options', 'cancelled_at', 'created_at', 'finished_at'],
        'jobs' => ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'],
        'passkeys' => ['id', 'user_id', 'name', 'credential_id', 'credential', 'last_used_at', 'created_at', 'updated_at'],
        'password_reset_tokens' => ['email', 'token', 'created_at'],
        'sessions' => ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'],
        'team_invitations' => ['id', 'code', 'team_id', 'email', 'role', 'invited_by', 'expires_at', 'accepted_at', 'created_at', 'updated_at'],
        'team_members' => ['id', 'team_id', 'user_id', 'role', 'created_at', 'updated_at'],
        'teams' => ['id', 'name', 'slug', 'is_personal', 'created_at', 'updated_at', 'deleted_at'],
        'users' => [
            'id', 'name', 'email', 'email_verified_at', 'password', 'remember_token',
            'created_at', 'updated_at', 'two_factor_secret', 'two_factor_recovery_codes',
            'two_factor_confirmed_at', 'current_team_id',
        ],
    ];

    foreach ($expectedTables as $table => $columns) {
        expect(isset($schema->tables[$table]))->toBeTrue("Table {$table} should exist");
        $actualColumns = array_keys($schema->tables[$table]->columns);
        expect($actualColumns)->toBe($columns, "Columns for {$table} should match actual schema");
    }

    expect($schema->unresolved)->toBeEmpty('Packaging migrations should have zero unresolved constructs');
});

test('validates schema reconstruction on real project 2: ubek', function () {
    $reader = new SchemaReader;
    $fixturesDir = __DIR__.'/../Fixtures/ubek/migrations';

    $schema = $reader->read([$fixturesDir]);

    // Expected snapshot of actual anti_ubek MySQL database
    $expectedTables = [
        'users' => ['id', 'name', 'email', 'email_verified_at', 'password', 'remember_token', 'created_at', 'updated_at'],
        'password_reset_tokens' => ['email', 'token', 'created_at'],
        'sessions' => ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'],
        'cache' => ['key', 'value', 'expiration'],
        'cache_locks' => ['key', 'owner', 'expiration'],
        'jobs' => ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'],
        'job_batches' => ['id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'failed_job_ids', 'options', 'cancelled_at', 'created_at', 'finished_at'],
        'failed_jobs' => ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'],
        'orders' => ['id', 'sort', 'order_id', 'first_name', 'last_name', 'email', 'company', 'phone_code', 'phone_number', 'occasion', 'delivery_date', 'dimension', 'total', 'created_at', 'updated_at'],
        'items' => ['id', 'name', 'qty', 'price', 'order_id', 'created_at', 'updated_at'],
        'personal_access_tokens' => ['id', 'tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at', 'created_at', 'updated_at'],
        'webhook_calls' => ['id', 'name', 'url', 'headers', 'payload', 'attachments', 'exception', 'created_at', 'updated_at'],
        'order_reviews' => ['id', 'order_id', 'first_name', 'last_name', 'email', 'phone', 'rating', 'comment', 'created_at', 'updated_at'],
        'shopify_orders' => ['id', 'shopify_order_id', 'order_name', 'first_name', 'last_name', 'email', 'phone', 'status', 'reviewed_at', 'created_at', 'updated_at'],
    ];

    foreach ($expectedTables as $table => $columns) {
        expect(isset($schema->tables[$table]))->toBeTrue("Table {$table} should exist");
        $actualColumns = array_keys($schema->tables[$table]->columns);
        expect($actualColumns)->toBe($columns, "Columns for {$table} should match actual schema");
    }

    // Differences are limited to documented known UnresolvedConstruct entries
    expect(count($schema->unresolved))->toBe(1);
    expect($schema->unresolved[0]->reason)->toContain('If_');
});

test('validates schema reconstruction on real project 3: fireman', function () {
    $reader = new SchemaReader;
    $fixturesDir = __DIR__.'/../Fixtures/fireman/migrations';

    $schema = $reader->read([$fixturesDir]);

    // Multi-connection tables defined in fireman migrations
    $expectedTables = [
        'mongodb.users',
        'mongodb.password_reset_tokens',
        'mysql.failed_jobs',
        'mongodb.personal_access_tokens',
        'mongodb.projects',
        'mongodb.auth_tokens',
        'mongodb.campaigns',
        'mysql.jobs',
        'mongodb.meta_tokens',
        'mongodb.topics',
        'mongodb.topic_tokens',
        'mongodb.statuses',
        'mongodb.segmentations',
        'mongodb.temporary_files',
        'mongodb.action_clicks',
        'mysql.job_batches',
        'whitelist_domains',
        'mysql.project_segments',
    ];

    foreach ($expectedTables as $tableKey) {
        expect(isset($schema->tables[$tableKey]))->toBeTrue("Table {$tableKey} should exist in schema");
        expect($schema->tables[$tableKey]->columns)->not->toBeEmpty("Table {$tableKey} should have columns");
    }

    // Check specific columns on mysql.project_segments
    expect(array_keys($schema->tables['mysql.project_segments']->columns))->toBe([
        'id', 'project_id', 'name', 'slug', 'description', 'rules', 'topic_id', 'deleted_at', 'created_at', 'updated_at',
    ]);

    // Check specific columns on whitelist_domains
    expect(array_keys($schema->tables['whitelist_domains']->columns))->toBe([
        'id', 'project_id', 'domain', 'is_active', 'created_at', 'updated_at',
    ]);

    // Differences limited to known conditional checks
    expect($schema->unresolved)->not->toBeEmpty();
    foreach ($schema->unresolved as $u) {
        expect($u->reason)->toContain('If_');
    }
});
