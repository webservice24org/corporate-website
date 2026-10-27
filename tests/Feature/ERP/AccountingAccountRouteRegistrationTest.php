<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Http\Controllers\ERP\Accounting\AccountingAccountController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AccountingAccountRouteRegistrationTest extends TestCase
{
    public function test_account_routes_are_mounted_at_the_expected_urls(): void
    {
        $routes = [
            ['GET', 'erp/accounting/accounts', 'index', 'index', null],
            ['GET', 'erp/accounting/accounts/create', 'create', 'create', 'erp.accounts.create'],
            ['POST', 'erp/accounting/accounts/initialize-groups', 'initialize-groups', 'initializeGroups', 'erp.accounts.create'],
            ['POST', 'erp/accounting/accounts', 'store', 'store', 'erp.accounts.create'],
            ['GET', 'erp/accounting/accounts/123/edit', 'edit', 'edit', 'erp.accounts.edit'],
            ['PUT', 'erp/accounting/accounts/123', 'update', 'update', 'erp.accounts.edit'],
            ['POST', 'erp/accounting/accounts/123/status', 'status', 'status', 'erp.accounts.deactivate'],
            ['GET', 'erp/accounting/accounts/123', 'show', 'show', null],
        ];

        foreach ($routes as [$method, $uri, $name, $action, $permission]) {
            $name = 'erp.accounting.accounts.'.$name;
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, 'Missing '.$name.'. Mount routes/accounting-accounts.php at the top level of routes/web.php and clear the route cache.');
            $matched = Route::getRoutes()->match(Request::create('/'.$uri, $method));
            $this->assertSame($name, $matched->getName(), 'The expected account URL is intercepted by another route.');
            $this->assertSame(AccountingAccountController::class.'@'.$action, $matched->getActionName());
            $middleware = $matched->gatherMiddleware();
            $this->assertContains('auth', $middleware);
            $this->assertContains('can:erp.accounts.view', $middleware);
            $this->assertNotContains('can:erp.accounting-settings.view', $middleware);
            if ($permission !== null) {
                $this->assertContains('can:'.$permission, $middleware);
            }
            $duplicates = array_filter(Route::getRoutes()->getRoutes(), static fn ($candidate): bool => $candidate->getName() === $name);
            $this->assertCount(1, $duplicates, 'An account route is registered more than once.');
        }
    }
}
