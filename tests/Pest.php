<?php

declare(strict_types=1);
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

/* Tests\Feature\SchemaReaderTest and Tests\Feature\RealProjectValidationTest run without Testbench or DB connections. */

/* Tests\Feature\Laravel* tests use Testbench and require a base TestCase. */
uses(TestCase::class)->in('Feature/Laravel');
