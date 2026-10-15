<?php

namespace Another\DatabaseAssistant;

class Assistant
{
    public function reply($user, array $history, string $message): array
    {
        $provider = match (config('assistant.provider')) {
            'openai' => app(OpenAiAssistant::class),
            'gemini' => app(GeminiAssistant::class),
            default => throw new \RuntimeException('Unsupported assistant provider.'),
        };
        return $provider->reply($user, $history, $message);
    }
}
