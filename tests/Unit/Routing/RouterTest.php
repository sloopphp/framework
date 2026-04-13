<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Routing\Router;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testGetRouteMatches(): void
    {
        $this->router->get('/users', 'UserController', 'index');
        $result = $this->router->resolve('GET', '/users');

        $this->assertNotNull($result);
        [$route, $params] = $result;
        $this->assertSame('UserController', $route->controller);
        $this->assertSame('index', $route->action);
        $this->assertSame([], $params);
    }

    public function testPostRouteMatches(): void
    {
        $this->router->post('/users', 'UserController', 'create');
        $result = $this->router->resolve('POST', '/users');

        $this->assertNotNull($result);
        $this->assertSame('create', $result[0]->action);
    }

    public function testPutRouteMatches(): void
    {
        $this->router->put('/users/{id}', 'UserController', 'update');
        $result = $this->router->resolve('PUT', '/users/42');

        $this->assertNotNull($result);
        $this->assertSame('update', $result[0]->action);
        $this->assertSame(['id' => '42'], $result[1]);
    }

    public function testPatchRouteMatches(): void
    {
        $this->router->patch('/users/{id}', 'UserController', 'update');
        $result = $this->router->resolve('PATCH', '/users/42');

        $this->assertNotNull($result);
        $this->assertSame('update', $result[0]->action);
    }

    public function testDeleteRouteMatches(): void
    {
        $this->router->delete('/users/{id}', 'UserController', 'delete');
        $result = $this->router->resolve('DELETE', '/users/42');

        $this->assertNotNull($result);
        $this->assertSame('delete', $result[0]->action);
    }

    public function testRouteParameterExtraction(): void
    {
        $this->router->get('/posts/{postId}/comments/{commentId}', 'CommentController', 'find');
        $result = $this->router->resolve('GET', '/posts/10/comments/25');

        $this->assertNotNull($result);
        $this->assertSame(['postId' => '10', 'commentId' => '25'], $result[1]);
    }

    public function testNoMatchReturnsNull(): void
    {
        $this->router->get('/users', 'UserController', 'index');

        $this->assertNull($this->router->resolve('GET', '/posts'));
        $this->assertNull($this->router->resolve('POST', '/users'));
    }

    public function testStaticRoutePrioritizedOverParameterized(): void
    {
        $this->router->get('/users/{id}', 'UserController', 'find');
        $this->router->get('/users/me', 'UserController', 'me');
        $result = $this->router->resolve('GET', '/users/me');

        $this->assertNotNull($result);
        $this->assertSame('me', $result[0]->action);
    }

    public function testResourceRegistersAllCrudRoutes(): void
    {
        $this->router->resource('/users', 'UserController');
        $routes = $this->router->routes;

        $this->assertCount(5, $routes);

        $result = $this->router->resolve('GET', '/users');
        $this->assertNotNull($result);
        $this->assertSame('index', $result[0]->action);

        $result = $this->router->resolve('GET', '/users/1');
        $this->assertNotNull($result);
        $this->assertSame('find', $result[0]->action);

        $result = $this->router->resolve('POST', '/users');
        $this->assertNotNull($result);
        $this->assertSame('create', $result[0]->action);

        $result = $this->router->resolve('PUT', '/users/1');
        $this->assertNotNull($result);
        $this->assertSame('update', $result[0]->action);

        $result = $this->router->resolve('DELETE', '/users/1');
        $this->assertNotNull($result);
        $this->assertSame('delete', $result[0]->action);
    }

    public function testResourceWithOnly(): void
    {
        $this->router->resource('/users', 'UserController', only: ['index', 'find']);
        $routes = $this->router->routes;

        $this->assertCount(2, $routes);
        $this->assertSame('index', $routes[0]->action);
        $this->assertSame('find', $routes[1]->action);
        $this->assertNotNull($this->router->resolve('GET', '/users'));
        $this->assertNotNull($this->router->resolve('GET', '/users/1'));
        $this->assertNull($this->router->resolve('POST', '/users'));
        $this->assertNull($this->router->resolve('PUT', '/users/1'));
        $this->assertNull($this->router->resolve('DELETE', '/users/1'));
    }

    public function testResourceWithOnlyIgnoresInvalidMethodNames(): void
    {
        $this->router->resource('/items', 'ItemController', only: ['index', 'nonexistent']);
        $routes = $this->router->routes;

        $this->assertCount(1, $routes);
        $this->assertSame('index', $routes[0]->action);
    }

    public function testResourceWithExcept(): void
    {
        $this->router->resource('/users', 'UserController', except: ['delete']);
        $routes = $this->router->routes;

        $this->assertCount(4, $routes);
        $this->assertNull($this->router->resolve('DELETE', '/users/1'));
    }

    public function testResourceAssignsRouteNames(): void
    {
        $this->router->resource('/users', 'UserController');

        $route = $this->router->findByName('users.index');
        $this->assertSame('index', $route->action);

        $route = $this->router->findByName('users.find');
        $this->assertSame('find', $route->action);
    }

    public function testNamedRoutes(): void
    {
        $this->router->get('/health', 'HealthController', 'check')->name('health');
        $route = $this->router->findByName('health');

        $this->assertSame('HealthController', $route->controller);
        $this->assertSame('check', $route->action);
    }

    public function testFindByNameThrowsForUnknown(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Route not found: nonexistent');

        $this->router->findByName('nonexistent');
    }

    public function testRouteMiddleware(): void
    {
        $route = $this->router->get('/admin', 'AdminController', 'index');
        $route->middleware('AuthMiddleware', 'AdminMiddleware');

        $this->assertSame(['AuthMiddleware', 'AdminMiddleware'], $route->middleware);
    }

    public function testGroupAppliesMiddleware(): void
    {
        $this->router->group(['middleware' => ['AuthMiddleware']], function (Router $router): void {
            $router->get('/profile', 'ProfileController', 'show');
            $router->get('/settings', 'SettingsController', 'index');
        });

        $routes = $this->router->routes;
        $this->assertSame(['AuthMiddleware'], $routes[0]->middleware);
        $this->assertSame(['AuthMiddleware'], $routes[1]->middleware);
    }

    public function testGroupAppliesPrefix(): void
    {
        $this->router->group(['prefix' => '/api/v1'], function (Router $router): void {
            $router->get('/users', 'UserController', 'index');
        });

        $result = $this->router->resolve('GET', '/api/v1/users');
        $this->assertNotNull($result);
        $this->assertSame('index', $result[0]->action);
    }

    public function testNestedGroups(): void
    {
        $this->router->group(['prefix' => '/api', 'middleware' => ['CorsMiddleware']], function (Router $router): void {
            $router->group(['prefix' => '/v1', 'middleware' => ['AuthMiddleware']], function (Router $router): void {
                $router->get('/users', 'UserController', 'index');
            });
        });

        $result = $this->router->resolve('GET', '/api/v1/users');
        $this->assertNotNull($result);
        $this->assertSame(['CorsMiddleware', 'AuthMiddleware'], $result[0]->middleware);
    }

    public function testGroupDoesNotAffectOuterRoutes(): void
    {
        $this->router->group(['middleware' => ['AuthMiddleware'], 'prefix' => '/admin'], function (Router $router): void {
            $router->get('/dashboard', 'DashboardController', 'index');
        });

        $this->router->get('/health', 'HealthController', 'check');
        $routes = $this->router->routes;

        $this->assertSame(['AuthMiddleware'], $routes[0]->middleware);
        $this->assertSame([], $routes[1]->middleware);

        $this->assertNull($this->router->resolve('GET', '/dashboard'));
        $this->assertNotNull($this->router->resolve('GET', '/admin/dashboard'));
        $this->assertNotNull($this->router->resolve('GET', '/health'));
    }

    public function testResourceWithGroupMiddleware(): void
    {
        $this->router->resource('/users', 'UserController');
        $group = $this->router->resource('/admin/posts', 'PostController');
        $group->middleware('AuthMiddleware');

        $userRoutes = [];
        $postRoutes = [];
        foreach ($this->router->routes as $route) {
            if ($route->controller === 'UserController') {
                $userRoutes[] = $route;
            } else {
                $postRoutes[] = $route;
            }
        }

        $this->assertSame([], $userRoutes[0]->middleware);
        $this->assertSame(['AuthMiddleware'], $postRoutes[0]->middleware);
    }

    public function testGroupMiddlewareIgnoresNonArrayValue(): void
    {
        // Single-string form is intentionally unsupported; users must wrap in array.
        // This test exists to detect future regressions if support is added unintentionally.
        $this->router->group(['middleware' => 'AuthMiddleware'], function (Router $router): void {
            $router->get('/test', 'TestController', 'index');
        });

        $this->assertSame([], $this->router->routes[0]->middleware);
    }

    public function testResolveReturnsNullForMethodMismatch(): void
    {
        $this->router->get('/users', 'UserController', 'index');

        $this->assertNull($this->router->resolve('POST', '/users'));
    }

    public function testResolveReturnsFirstParameterizedMatch(): void
    {
        // When multiple parameterized routes match, the first registered one wins
        // via the `??=` (null coalesce assign) in resolve().
        $this->router->get('/items/{id}', 'ItemController', 'find');
        $this->router->get('/items/{slug}', 'ItemController', 'findBySlug');

        $result = $this->router->resolve('GET', '/items/42');

        $this->assertNotNull($result);
        [$route, $params] = $result;
        $this->assertSame('find', $route->action);
        $this->assertSame(['id' => '42'], $params);
    }

    public function testRouteWithMultipleParameters(): void
    {
        $this->router->get('/users/{userId}/posts/{postId}', 'PostController', 'show');

        $result = $this->router->resolve('GET', '/users/5/posts/42');

        $this->assertNotNull($result);
        [, $params] = $result;
        $this->assertSame(['userId' => '5', 'postId' => '42'], $params);
    }

    public function testGroupIgnoresNonStringPrefix(): void
    {
        // Prefix must be a string; non-string values are silently ignored
        // via the `is_string($attributes['prefix'])` guard.
        $this->router->group(['prefix' => 42], function (Router $router): void {
            $router->get('/test', 'TestController', 'index');
        });

        $this->assertNotNull($this->router->resolve('GET', '/test'));
        $this->assertNull($this->router->resolve('GET', '/42/test'));
    }

    public function testParameterizedRouteDoesNotMatchPartialPath(): void
    {
        $this->router->get('/users/{id}', 'UserController', 'find');

        $this->assertNull($this->router->resolve('GET', '/users/42/extra'));
        $this->assertNull($this->router->resolve('GET', '/prefix/users/42'));
    }

    public function testDotInRoutePatternMatchesLiterally(): void
    {
        // Regex metacharacter `.` in the static segment must be escaped
        // so that `/users.json/1` matches only a literal dot.
        $this->router->get('/users.json/{id}', 'UserController', 'findJson');

        $this->assertNotNull($this->router->resolve('GET', '/users.json/1'));
        $this->assertNull($this->router->resolve('GET', '/usersXjson/1'));
        $this->assertNull($this->router->resolve('GET', '/users_json/1'));
    }

    public function testRouteParameterAcceptsDotInValue(): void
    {
        // Parameter values are captured as `[^/]+`, which includes dots
        // (e.g. semantic versioning, email addresses).
        $this->router->get('/packages/{name}', 'PackageController', 'find');

        $result = $this->router->resolve('GET', '/packages/1.2.3');

        $this->assertNotNull($result);
        $this->assertSame(['name' => '1.2.3'], $result[1]);
    }

    public function testResourceWithOnlyAndExceptCombined(): void
    {
        // `only` narrows first, then `except` removes from the narrowed set.
        $group  = $this->router->resource('/users', 'UserController', only: ['index', 'find', 'create'], except: ['create']);
        $routes = $group->routes;

        $this->assertCount(2, $routes);
        $this->assertSame('index', $routes[0]->action);
        $this->assertSame('find', $routes[1]->action);
    }
}
