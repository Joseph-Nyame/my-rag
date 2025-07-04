<?php

namespace App\Services;

use OpenAI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ItemChatService
{
    protected string $qdrantUrl;
    protected $client; 
    protected  $collectionName;

    public function __construct()
    {
        $this->collectionName = env('COLLECTION_NAME'); 
        $this->qdrantUrl = 'http://' . env('VECTORDB_HOST', 'localhost') . ':' . env('QDRANT_PORT', '6333');
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
    }

    /**
     * Chat with AI about items
     */
    public function chat(string $question, array $conversationHistory = []): array
    {
        // Step 1: Retrieve relevant items
        $context = $this->getRelevantContext($question);
        
        $messages = $this->buildMessages($question, $context, $conversationHistory);
        
        $response = $this->getAIResponse($messages);
        
        return [
            'answer' => $response['answer'],
            'context' => $context,
            'messages' => $messages
        ];
    }

    protected function getRelevantContext(string $question): array
    {
        $queryVector = $this->getEmbedding($question);
        
        $response = Http::post("{$this->qdrantUrl}/collections/{$this->collectionName}/points/search", [
            'vector' => $queryVector,
            'limit' => 5, // Get top 5 most relevant items
            'with_payload' => true,
            'with_vectors' => false
        ]);

        if ($response->failed()) {
            Log::error('Qdrant search failed', ['error' => $response->body()]);
            return [];
        }

        return array_map(function ($result) {
            return $result['payload'];
        }, $response->json()['result'] ?? []);
    }

    protected function buildMessages(string $question, array $context, array $history): array
    {
        $systemPrompt = $this->getSystemPrompt($context);
        
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];
        
        // Add conversation history
        foreach ($history as $message) {
            $messages[] = $message;
        }
        
        // Add current question
        $messages[] = ['role' => 'user', 'content' => $question];
        
        return $messages;
    }

    protected function getSystemPrompt(array $context): string
    {
        $contextText = json_encode($context, JSON_PRETTY_PRINT);
        
        return <<<PROMPT
You are an intelligent assistant that helps users find information about products in our inventory.
Use the following item data to answer questions accurately:

{$contextText}

Guidelines:
- Be concise but helpful
- If you don't know the answer, say so
- Highlight unique features when relevant
- Never make up information not in the provided data
PROMPT;
    }

    protected function getAIResponse(array $messages): array
    {
        $response = $this->client->chat()->create([ // Changed to use $this->client
            'model' => 'gpt-4',
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 500
        ]);

        return [
            'answer' => $response->choices[0]->message->content
        ];
    }

    protected function getEmbedding(string $text): array
    {
        $response = $this->client->embeddings()->create([ // Changed to use $this->client
            'model' => 'text-embedding-3-small',
            'input' => $text
        ]);

        return $response->embeddings[0]->embedding;
    }
}