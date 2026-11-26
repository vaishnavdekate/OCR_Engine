<?php

namespace Tests\Feature;

use App\Receipt\ReceiptLock;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class ReceiptApiTest extends TestCase
{
    public function test_interface_and_schema_are_available(): void
    {
        $this->get('/')->assertOk()->assertSee('image/avif',false);
        $this->get('/docs')->assertOk();
        $this->get('/openapi.json')->assertOk();
        $this->getJson('/api/health')->assertOk()->assertJsonPath('busy',false);
    }

    public function test_missing_and_corrupt_uploads_are_rejected(): void
    {
        $this->postJson('/api/receipts')->assertStatus(422);
        $this->post('/api/receipts',['file'=>UploadedFile::fake()->createWithContent('bad.jpg','not an image')])->assertStatus(422);
    }

    public function test_cross_origin_and_host_validation(): void
    {
        $this->withHeader('Origin','https://attacker.example')->postJson('/api/receipts')->assertStatus(403);
        $this->get('http://attacker.example/api/health')->assertStatus(400);
    }

    public function test_optional_api_token(): void
    {
        config(['receipt.token'=>'test-token']);
        $this->postJson('/api/receipts')->assertStatus(401);
        $this->withHeader('Authorization','Bearer test-token')->postJson('/api/receipts')->assertStatus(422);
    }

    public function test_oversized_request_is_rejected(): void
    {
        $this->post('/api/receipts',['file'=>UploadedFile::fake()->create('large.jpg',20*1024+1)])->assertStatus(413);
    }

    public function test_busy_worker_applies_backpressure(): void
    {
        $lock=app(ReceiptLock::class); $handle=$lock->acquire(); self::assertNotNull($handle);
        try { $this->post('/api/receipts',['file'=>UploadedFile::fake()->createWithContent('x.png','x')])->assertStatus(503)->assertHeader('Retry-After','5'); }
        finally { $lock->release($handle); }
    }

    public function test_currency_validation(): void
    {
        $this->post('/api/receipts',['file'=>UploadedFile::fake()->createWithContent('x','x'),'currency'=>'<bad>'])->assertStatus(422);
    }
}
