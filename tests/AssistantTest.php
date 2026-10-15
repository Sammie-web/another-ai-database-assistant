<?php

namespace Another\DatabaseAssistant\Tests;

use Another\DatabaseAssistant\{AssistantServiceProvider, DatabaseTools, OpenAiAssistant};
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Gate, Http, Schema};
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AssistantTest extends TestCase
{
    protected function getPackageProviders($app): array { return [AssistantServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $mysql = getenv('TEST_MYSQL') === '1';
        $app['config']->set('database.default', $mysql ? 'mysql' : 'testing');
        $app['config']->set('database.connections.mysql', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int) (getenv('TEST_MYSQL_PORT') ?: 3306), 'database' => 'assistant_test', 'username' => 'root', 'password' => getenv('TEST_MYSQL_PASSWORD') !== false ? getenv('TEST_MYSQL_PASSWORD') : 'test', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true]);
        $app['config']->set('assistant.connection', $mysql ? 'mysql' : 'testing');
        $app['config']->set('assistant.key', 'test-key');
        $app['config']->set('assistant.provider', 'openai');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('projects');
        Schema::dropIfExists('assistant_proposals');
        Schema::dropIfExists('assistant_audits');
        (require __DIR__.'/../database/migrations/2026_10_13_000000_create_assistant_tables.php')->up();
        Schema::create('projects', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tenant_id'); $t->string('name'); $t->string('status'); $t->string('secret')->nullable(); $t->index(['tenant_id', 'id']);
        });
        DB::table('projects')->insert([
            ['id' => 1, 'tenant_id' => 10, 'name' => 'Local', 'status' => 'active', 'secret' => 'never disclose'],
            ['id' => 2, 'tenant_id' => 20, 'name' => 'Foreign', 'status' => 'active', 'secret' => 'other tenant'],
        ]);
        Gate::define('assistant.access', fn ($user) => true);
        Gate::define('assistant.read', fn ($user, $resource) => true);
        Gate::define('assistant.write', fn ($user, $resource) => $user->id === 1);
        Http::preventStrayRequests();
    }

    private function user(int $id = 1, int $tenant = 10): GenericUser { return new GenericUser(['id' => $id, 'tenant_id' => $tenant]); }
    private function args(): array { return ['resource' => 'projects', 'id' => null, 'after_id' => null, 'limit' => 10, 'count_only' => false]; }
    private function proposal(): array { return app(DatabaseTools::class)->propose($this->user(), ['resource' => 'projects', 'operation' => 'update', 'id' => 1, 'values' => ['name' => 'Revised', 'status' => 'completed']]); }

    public function test_reads_are_scoped_and_redacted(): void
    {
        $result = app(DatabaseTools::class)->read($this->user(), $this->args());
        $this->assertCount(1, $result['rows']);
        $this->assertSame('Local', $result['rows'][0]['name']);
        $this->assertArrayNotHasKey('secret', $result['rows'][0]);
        $count = app(DatabaseTools::class)->read($this->user(), array_replace($this->args(), ['count_only' => true]));
        $this->assertSame(1, $count['count']);
    }

    public function test_proposal_does_not_write_until_confirmation_and_is_idempotent(): void
    {
        $tools = app(DatabaseTools::class); $proposal = $this->proposal();
        $this->assertSame('Local', DB::table('projects')->where('id', 1)->value('name'));
        $this->assertSame('confirmed', $tools->confirm($this->user(), $proposal['proposal_id'])['status']);
        $this->assertSame('already_confirmed', $tools->confirm($this->user(), $proposal['proposal_id'])['status']);
        $this->assertSame('Revised', DB::table('projects')->where('id', 1)->value('name'));
        $this->assertSame(1, DB::table('assistant_audits')->where('action', 'confirmed_update')->count());
    }

    public function test_changed_record_cannot_be_confirmed(): void
    {
        $proposal = $this->proposal();
        DB::table('projects')->where('id', 1)->update(['name' => 'Concurrent edit']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(DatabaseTools::class)->confirm($this->user(), $proposal['proposal_id']);
    }

    public function test_foreign_proposal_is_hidden(): void
    {
        $proposal = $this->proposal();
        try { app(DatabaseTools::class)->confirm($this->user(1, 20), $proposal['proposal_id']); $this->fail('Must reject'); }
        catch (HttpExceptionInterface $e) { $this->assertSame(404, $e->getStatusCode()); }
    }

    public function test_expired_proposal_is_rejected(): void
    {
        $proposal = $this->proposal(); $this->travel(11)->minutes();
        try { app(DatabaseTools::class)->confirm($this->user(), $proposal['proposal_id']); $this->fail('Must reject'); }
        catch (HttpExceptionInterface $e) { $this->assertSame(410, $e->getStatusCode()); }
    }

    public function test_permissions_are_rechecked_at_confirmation(): void
    {
        $proposal = $this->proposal(); Gate::define('assistant.write', fn ($user, $resource) => false);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(DatabaseTools::class)->confirm($this->user(), $proposal['proposal_id']);
    }

    public function test_tenant_field_cannot_be_written(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(DatabaseTools::class)->propose($this->user(), ['resource' => 'projects', 'operation' => 'update', 'id' => 1, 'values' => ['tenant_id' => 20, 'name' => 'X', 'status' => 'active']]);
    }

    public function test_injection_in_resource_name_is_rejected(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(DatabaseTools::class)->read($this->user(), array_replace($this->args(), ['resource' => 'projects; DROP TABLE projects']));
    }

    public function test_create_and_delete_need_separate_confirmations(): void
    {
        $tools = app(DatabaseTools::class);
        $p = $tools->propose($this->user(), ['resource' => 'projects', 'operation' => 'create', 'id' => null, 'values' => ['name' => 'New', 'status' => 'planned']]);
        $tools->confirm($this->user(), $p['proposal_id']);
        $id = DB::table('projects')->where('name', 'New')->value('id');
        $this->assertSame(10, (int) DB::table('projects')->where('id', $id)->value('tenant_id'));
        $p = $tools->propose($this->user(), ['resource' => 'projects', 'operation' => 'delete', 'id' => $id, 'values' => []]);
        $this->assertTrue(DB::table('projects')->where('id', $id)->exists());
        $tools->confirm($this->user(), $p['proposal_id']);
        $this->assertFalse(DB::table('projects')->where('id', $id)->exists());
    }

    public function test_responses_tool_loop_returns_evidence_and_replays_reasoning(): void
    {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
                ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'read_records', 'arguments' => json_encode($this->args())],
            ]])
            ->push(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'One project.']]]]])]);
        $result = app(OpenAiAssistant::class)->reply($this->user(), [], 'List projects');
        $this->assertSame('One project.', $result['text']);
        $this->assertCount(1, $result['evidence']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['store'] === false && collect($request['input'])->contains(fn ($item) => ($item['type'] ?? '') === 'function_call_output'));
    }

    public function test_chat_route_uses_session_history(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hello']]]]])]);
        $this->actingAs($this->user())->postJson('/assistant/chat', ['message' => 'Hello'])->assertOk()->assertJsonPath('text', 'Hello');
    }

    public function test_chat_page_renders(): void
    {
        $this->actingAs($this->user())->get('/assistant')->assertOk()->assertSee('Database assistant');
    }

    public function test_audit_failure_rolls_back_confirmation_and_business_mutation(): void
    {
        $proposal = $this->proposal();
        Schema::drop('assistant_audits');
        try {
            app(DatabaseTools::class)->confirm($this->user(), $proposal['proposal_id']);
            $this->fail('Audit failure must abort confirmation');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('Local', DB::table('projects')->where('id', 1)->value('name'));
            $this->assertNull(DB::table('assistant_proposals')->where('id', $proposal['proposal_id'])->value('confirmed_at'));
        }
    }

    private function enableLiveAi(): void
    {
        if (getenv('LIVE_AI') !== '1') $this->markTestSkipped('Live API calls require explicit opt-in.');
        $provider = getenv('ASSISTANT_PROVIDER') ?: 'openai';
        $this->assertNotEmpty(getenv($provider === 'gemini' ? 'GEMINI_API_KEY' : 'OPENAI_API_KEY'), 'Live API key required.');
        config(['assistant.provider' => $provider, 'assistant.key' => getenv('OPENAI_API_KEY'), 'assistant.model' => getenv('OPENAI_MODEL') ?: 'gpt-5.4-mini',
            'assistant.gemini_key' => getenv('GEMINI_API_KEY'), 'assistant.gemini_model' => getenv('GEMINI_MODEL') ?: 'gemini-3.1-flash-lite']);
        Http::allowStrayRequests($provider === 'gemini' ? ['https://generativelanguage.googleapis.com/v1beta/models/*:generateContent'] : ['https://api.openai.com/v1/responses']);
    }

    public function test_live_ai_reads_scoped_mysql_records(): void
    {
        $this->enableLiveAi();
        $result = app(\Another\DatabaseAssistant\Assistant::class)->reply($this->user(), [], 'Use read_records to list my first 10 projects, with count_only false. Report their names.');
        $this->assertNotEmpty($result['evidence'], 'Live answer: '.$result['text']);
        $rows = collect($result['evidence'])->flatMap(fn ($entry) => $entry['result']['rows'] ?? []);
        $this->assertSame(['Local'], $rows->pluck('name')->unique()->values()->all());
        $this->assertStringNotContainsString('Foreign', json_encode($result));
        $this->assertStringNotContainsString('never disclose', json_encode($result));
        $this->assertStringContainsString('Local', $result['text']);
    }

    public function test_live_ai_previews_change_without_executing(): void
    {
        $this->enableLiveAi();
        $result = app(\Another\DatabaseAssistant\Assistant::class)->reply($this->user(), [], 'Prepare an update preview for projects record id 1: name Live test revised, status completed. Use propose_change. I will confirm separately in the application.');
        $this->assertNotEmpty($result['proposals']);
        $proposal = $result['proposals'][0];
        $this->assertSame('update', $proposal['operation']);
        $this->assertSame('Live test revised', $proposal['after']['name']);
        $this->assertSame('Local', DB::table('projects')->where('id', 1)->value('name'));
        $this->assertSame('confirmed', app(DatabaseTools::class)->confirm($this->user(), $proposal['proposal_id'])['status']);
        $this->assertSame('Live test revised', DB::table('projects')->where('id', 1)->value('name'));
        $this->assertSame('Foreign', DB::table('projects')->where('id', 2)->value('name'));
    }

    public function test_gemini_replays_signatures_and_returns_scoped_evidence(): void
    {
        config(['assistant.provider' => 'gemini', 'assistant.gemini_key' => 'fake-gemini-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [
                ['thoughtSignature' => 'signature-to-preserve', 'functionCall' => ['id' => 'call-1', 'name' => 'read_records', 'args' => $this->args()]],
            ]]]]])
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [['text' => 'Local is your project.']]]]]])]);
        $result = app(\Another\DatabaseAssistant\Assistant::class)->reply($this->user(), [], 'List projects');
        $this->assertSame('Local', $result['evidence'][0]['result']['rows'][0]['name']);
        $this->assertSame('Local is your project.', $result['text']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'fake-gemini-key')
            && data_get($request['contents'], '1.parts.0.thoughtSignature') === 'signature-to-preserve'
            && data_get($request['contents'], '2.parts.0.functionResponse.id') === 'call-1');
    }

    public function test_gemini_proposes_without_mutating(): void
    {
        config(['assistant.provider' => 'gemini', 'assistant.gemini_key' => 'fake-gemini-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => 'propose_change', 'args' => ['resource' => 'projects', 'operation' => 'update', 'id' => 1,
                    'fields' => [['name' => 'name', 'value' => 'Gemini revised'], ['name' => 'status', 'value' => 'completed']]]]],
            ]]]]])
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [['text' => 'Review the preview.']]]]]])]);
        $result = app(\Another\DatabaseAssistant\Assistant::class)->reply($this->user(), [], 'Prepare update');
        $this->assertSame('Gemini revised', $result['proposals'][0]['after']['name']);
        $this->assertSame('Local', DB::table('projects')->where('id', 1)->value('name'));
    }

    public function test_gemini_http_failure_returns_safe_route_error(): void
    {
        config(['assistant.provider' => 'gemini', 'assistant.gemini_key' => 'fake-gemini-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'private provider detail']], 400)]);
        $this->actingAs($this->user())->postJson('/assistant/chat', ['message' => 'Hello'])->assertStatus(503)
            ->assertJsonPath('message', 'Assistant unavailable. Please retry.')->assertDontSee('private provider detail');
        Http::assertSentCount(1);
    }

    public function test_gemini_incomplete_output_does_not_execute_tools(): void
    {
        config(['assistant.gemini_key' => 'fake-gemini-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'MAX_TOKENS']]])]);
        $this->expectException(\RuntimeException::class);
        app(\Another\DatabaseAssistant\GeminiAssistant::class)->reply($this->user(), [], 'Hello');
    }

    public function test_gemini_replays_empty_arguments_as_json_object(): void
    {
        config(['assistant.gemini_key' => 'fake-gemini-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => 'describe_schema', 'args' => (object) []]],
            ]]]]])
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [['text' => 'Projects available.']]]]]])]);
        $result = app(\Another\DatabaseAssistant\GeminiAssistant::class)->reply($this->user(), [], 'Show schema');
        $this->assertSame('Projects available.', $result['text']);
        Http::assertSent(fn ($request) => str_contains($request->body(), '"args":{}'));
    }

    public function test_gemini_reserves_last_round_for_final_answer(): void
    {
        config(['assistant.gemini_key' => 'fake-gemini-key']);
        $sequence = Http::sequence();
        for ($i = 0; $i < 3; $i++) $sequence->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [
            ['functionCall' => ['name' => 'describe_schema', 'args' => (object) []]],
        ]]]]]);
        $sequence->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [['text' => 'Projects available.']]]]]]);
        Http::fake(['generativelanguage.googleapis.com/*' => $sequence]);
        $result = app(\Another\DatabaseAssistant\GeminiAssistant::class)->reply($this->user(), [], 'Show schema');
        $this->assertSame('Projects available.', $result['text']);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request['toolConfig']['functionCallingConfig']['mode'] === 'NONE');
    }
}
