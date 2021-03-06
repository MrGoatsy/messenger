<?php

namespace RTippin\Messenger\Tests\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Orchestra\Testbench\TestCase;
use RTippin\Messenger\Http\Middleware\MessengerApi;

class MessengerApiTest extends TestCase
{
    /** @test */
    public function wrapping_disabled_on_json_resource_and_json_header_is_set()
    {
        $request = new Request;

        (new MessengerApi())->handle($request, function (Request $request) {
            $this->assertNull(JsonResource::$wrap);
            $this->assertTrue($request->hasHeader('Accept'));
            $this->assertSame('application/json', $request->header('Accept'));
        });
    }
}
