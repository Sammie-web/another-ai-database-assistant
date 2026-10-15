<?php

namespace Another\DatabaseAssistant;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate, Log};
use Illuminate\Support\Str;

class AssistantController
{
    private function authorize(Request $request): void
    {
        Gate::forUser($request->user())->authorize('assistant.access');
        app(DatabaseTools::class)->tenant($request->user());
    }

    private function historyKey(Request $request): string
    {
        return 'assistant.history.'.hash('sha256', $request->user()->getAuthIdentifier().'|'.$request->user()->tenant_id.'|'.config('assistant.provider'));
    }

    public function index(Request $request)
    {
        $this->authorize($request);
        return view('assistant::chat');
    }

    public function reset(Request $request)
    {
        $this->authorize($request);
        $request->session()->forget($this->historyKey($request));
        return response()->json(['status' => 'cleared']);
    }

    public function chat(Request $request, Assistant $assistant)
    {
        $this->authorize($request);
        $data = $request->validate(['message' => 'required|string|max:4000']);
        $key = $this->historyKey($request);
        $history = $request->session()->get($key, []);
        try {
            $result = $assistant->reply($request->user(), $history, $data['message']);
        } catch (\Throwable $e) {
            $id = (string) Str::uuid();
            // Do not log exception bodies: provider errors may contain prompts/data.
            Log::warning('Assistant request failed', ['request_id' => $id, 'type' => get_class($e)]);
            return response()->json(['message' => 'Assistant unavailable. Please retry.', 'request_id' => $id], 503);
        }
        $history[] = ['role' => 'user', 'content' => $data['message']];
        $history[] = ['role' => 'assistant', 'content' => mb_substr($result['text'], 0, 8000)];
        $request->session()->put($key, array_slice($history, -12));
        return response()->json($result);
    }

    public function confirm(Request $request, string $id, DatabaseTools $tools)
    {
        $this->authorize($request);
        abort_unless(Str::isUuid($id), 404);
        $request->validate(['confirmed' => 'required|accepted']);
        return response()->json($tools->confirm($request->user(), $id));
    }
}
