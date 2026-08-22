<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Support\Facades\Route;

class TestController extends ApiController
{
    public function success()
    {
        return $this->successResponse('Success message', ['foo' => 'bar']);
    }

    public function error()
    {
        return $this->errorResponse('Error message', 400);
    }
}

class StandardResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/v1')->group(function () {
            Route::get('/test-success', [TestController::class, 'success']);
            Route::get('/test-error', [TestController::class, 'error']);
        });
    }

    public function test_success_response_format()
    {
        $response = $this->getJson('/api/v1/test-success');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'Success message',
                     'data' => [
                         'foo' => 'bar'
                     ]
                 ]);
    }

    public function test_error_response_format()
    {
        $response = $this->getJson('/api/v1/test-error');

        $response->assertStatus(400)
                 ->assertJson([
                     'success' => false,
                     'message' => 'Error message',
                 ]);
    }
}
