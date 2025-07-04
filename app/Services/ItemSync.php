<?php

namespace App\Services;

use OpenAI;
use App\Models\Item;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ItemSync
{
    private $client;
    protected $qdrantUrl;
    protected  $collectionName;
    protected int $vectorSize = 1536;

    public function __construct(protected OpenAI $openai)
    {
        $this->collectionName = env('COLLECTION_NAME'); 
        $this->qdrantUrl = 'http://' . env('VECTORDB_HOST', 'localhost') . ':' . env('QDRANT_PORT', '6333');
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
        
        // Verify Qdrant connection
        try {
            $response = Http::get("{$this->qdrantUrl}/readyz");
            if (!$response->successful()) {
                throw new \Exception("Qdrant not available at {$this->qdrantUrl}");
            }
        } catch (\Exception $e) {
            Log::error("Qdrant connection failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Full sync of all items
     */
    public function fullSync(): int
    {
        try {
            $this->ensureCollectionExists();

            $items = Item::all();
            if ($items->isEmpty()) {
                Log::info("No items to sync.");
                return 0;
            }

            // Batch embed items for efficiency
            $texts = $items->map(fn($item) => implode(' ', array_filter([
                $item->name,
                $item->description,
            ])) ?: json_encode($item->toArray()))->toArray();

            $embeddings = $this->embedTextBatch($texts);

            // Prepare points with proper structure
            $points = [];
            foreach ($items as $index => $item) {
                if (!isset($embeddings[$index]) || count($embeddings[$index]) !== $this->vectorSize) {
                    Log::error("Invalid embedding for item {$item->id}");
                    continue;
                }

                $points[] = [
                    'id' => Str::uuid(), // Ensure ID is string
                    'vector' => $embeddings[$index],
                    'payload' => [
                        'id' => $item->id,
                        'name' => $item->name ?? '',
                        'description' => $item->description ?? '',
                        'created_at' => $item->created_at?->toDateTimeString() ?? now()->toDateTimeString(),
                        'updated_at' => $item->updated_at?->toDateTimeString() ?? now()->toDateTimeString(),
                    ],
                ];
            }

            if (empty($points)) {
                throw new \Exception('No valid points to sync.');
            }

            $payload = ['points' => $points];
            Log::debug("Qdrant fullSync payload: " . json_encode($payload, JSON_PRETTY_PRINT));

            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->put("{$this->qdrantUrl}/collections/{$this->collectionName}/points", $payload);

            if ($response->failed()) {
                Log::error("Qdrant fullSync failed: Status {$response->status()}, Body: {$response->body()}");
                throw new \Exception('Qdrant sync failed: ' . $response->body());
            }

            return count($points);
        } catch (\Exception $e) {
            Log::error("ItemSync fullSync failed: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Sync a single item
     */
    public function syncSingle(Item $item): bool
    {
        try {
            $this->ensureCollectionExists();

            $point = $this->preparePoint($item);
            $payload = ['points' => [$point]];
            
            Log::debug("Qdrant syncSingle payload: " . json_encode($payload, JSON_PRETTY_PRINT));

            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->put("{$this->qdrantUrl}/collections/{$this->collectionName}/points", $payload);

            if ($response->failed()) {
                Log::error("Qdrant syncSingle failed for item {$item->id}: Status {$response->status()}, Body: {$response->body()}");
                throw new \Exception('Qdrant upsert failed: ' . $response->body());
            }

            return true;
        } catch (\Exception $e) {
            Log::error("ItemSync syncSingle failed for item {$item->id}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Delete a single item
     */
    public function deleteSingle(int $itemId): bool
    {
        try {
            //  filter to find points with this item ID in their payload
            $filter = [
                'must' => [
                    [
                        'key' => 'original_id',  // This refers to payload.originalid
                        'match' => [
                            'value' => $itemId
                        ]
                    ]
                ]
            ];

            // First search for the point(s) to delete
            $searchResponse = Http::post(
                "{$this->qdrantUrl}/collections/{$this->collectionName}/points/scroll",
                [
                    'filter' => $filter,
                    'limit' => 1,       // only need one match
                    'with_payload' => false,
                    'with_vector' => false
                ]
            );

            if ($searchResponse->failed()) {
                throw new \Exception('Search failed: ' . $searchResponse->body());
            }

            $result = $searchResponse->json();
            $pointsToDelete = $result['result']['points'] ?? [];

            if (empty($pointsToDelete)) {
                Log::debug("No Qdrant point found for item ID {$itemId}");
                return true;
            }

            // Extract the Qdrant point IDs
            $pointIds = array_column($pointsToDelete, 'id');

           
            $deleteResponse = Http::post(
                "{$this->qdrantUrl}/collections/{$this->collectionName}/points/delete",
                ['points' => $pointIds]
            );

            if ($deleteResponse->failed()) {
                throw new \Exception('Delete failed: ' . $deleteResponse->body());
            }

            Log::debug("Deleted point(s) " . implode(', ', $pointIds) . " for item {$itemId}");
            return true;
        } catch (\Exception $e) {
            Log::error("Delete failed for item {$itemId}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Update a single item in Qdrant
     */
    public function updateSingle(Item $item): bool
    {
        try {
            $this->ensureCollectionExists();

            // Find the existing point by original_id
            $filter = [
                'must' => [
                    [
                        'key' => 'original_id',
                        'match' => [
                            'value' => $item->id,
                        ],
                    ],
                ],
            ];

            $searchResponse = Http::post(
                "{$this->qdrantUrl}/collections/{$this->collectionName}/points/scroll",
                [
                    'filter' => $filter,
                    'limit' => 1,
                    'with_payload' => true,
                    'with_vector' => false,
                ]
            );

            if ($searchResponse->failed()) {
                Log::error("Qdrant search failed for item {$item->id}: Status {$searchResponse->status()}, Body: {$searchResponse->body()}");
                throw new \Exception('Qdrant search failed: ' . $searchResponse->body());
            }

            $result = $searchResponse->json();
            $points = $result['result']['points'] ?? [];

            if (empty($points)) {
                Log::info("No existing Qdrant point found for item {$item->id}, performing sync instead");
                return $this->syncSingle($item); // If no point exists, create a new one
            }

            $existingPointId = $points[0]['id'];

            // Prepare updated point with the same UUID
            $point = $this->preparePoint($item);
            $point['id'] = $existingPointId; // Reuse the existing point ID

            $payload = ['points' => [$point]];
            Log::debug("Qdrant updateSingle payload: " . json_encode($payload, JSON_PRETTY_PRINT));

            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->put("{$this->qdrantUrl}/collections/{$this->collectionName}/points", $payload);

            if ($response->failed()) {
                Log::error("Qdrant updateSingle failed for item {$item->id}: Status {$response->status()}, Body: {$response->body()}");
                throw new \Exception('Qdrant update failed: ' . $response->body());
            }

            Log::info("Updated Qdrant point {$existingPointId} for item {$item->id}");
            return true;
        } catch (\Exception $e) {
            Log::error("ItemSync updateSingle failed for item {$item->id}: {$e->getMessage()}");
            throw $e;
        }
    }


    /**
     * Ensure the Qdrant collection exists
     */
    protected function ensureCollectionExists(): void
    {
        $response = Http::get("{$this->qdrantUrl}/collections/{$this->collectionName}");

        if ($response->status() === 404) {
            $response = Http::put("{$this->qdrantUrl}/collections/{$this->collectionName}", [
                'vectors' => [
                    'size' => $this->vectorSize,
                    'distance' => 'Cosine',
                ],
                'optimizers_config' => [
                    'default_segment_number' => 2,
                    'indexing_threshold' => 100,
                ],
            ]);

            if ($response->failed()) {
                Log::error("Collection creation failed: Status {$response->status()}, Body: {$response->body()}");
                throw new \Exception('Collection creation failed: ' . $response->body());
            }
            
            Log::info("Collection {$this->collectionName} created successfully");
        } elseif ($response->failed()) {
            Log::error("Collection check failed: Status {$response->status()}, Body: {$response->body()}");
            throw new \Exception('Collection check failed: ' . $response->body());
        }
    }

    /**
     * Prepare a single point for an item
     */
    protected function preparePoint(Item $item): array
    {
        $text = implode(' ', array_filter([
            $item->name,
            $item->description,
        ])) ?: json_encode($item->toArray());

        $vector = $this->embedText($text);

        return [
            'id' => (string) Str::uuid(), // Generate new UUID for each point
            'vector' => $vector,
            'payload' => [
                'original_id' => $item->id, 
                'name' => $item->name ?? '',
                'description' => $item->description ?? '',
                'created_at' => $item->created_at?->toDateTimeString() ?? now()->toDateTimeString(),
                'updated_at' => $item->updated_at?->toDateTimeString() ?? now()->toDateTimeString(),
            ],
        ];
    }

    /**
     * Embed a single text using OpenAI
     */
    protected function embedText(string $text): array
    {
        try {
            Log::debug("Generating embedding for text: " . substr($text, 0, 50) . "...");
            
            $response = $this->client->embeddings()->create([
                'model' => 'text-embedding-ada-002',
                'input' => $text,
            ]);

            $embedding = $response->embeddings[0]->embedding;
            
            Log::debug("Embedding generated. Length: " . count($embedding));

            if (count($embedding) !== $this->vectorSize) {
                throw new \Exception("Invalid embedding size: expected {$this->vectorSize}, got " . count($embedding));
            }

            return $embedding;
        } catch (\Exception $e) {
            Log::error("Failed to embed text: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Embed multiple texts in batch
     */
    protected function embedTextBatch(array $texts): array
    {
        try {
            $texts = array_filter($texts, 'strlen');
            if (empty($texts)) {
                throw new \Exception('No valid texts provided for embedding');
            }

            $response = $this->client->embeddings()->create([
                'model' => 'text-embedding-ada-002',
                'input' => $texts,
            ]);

            $embeddings = array_map(fn($embedding) => $embedding->embedding, $response->embeddings);
            
            foreach ($embeddings as $index => $embedding) {
                if (count($embedding) !== $this->vectorSize) {
                    Log::error("Invalid batch embedding size at index {$index}: expected {$this->vectorSize}, got " . count($embedding));
                    throw new \Exception("Invalid batch embedding size at index {$index}");
                }
            }

            Log::debug("Batch embeddings generated: " . count($embeddings) . " vectors");
            return $embeddings;
        } catch (\Exception $e) {
            Log::error("Failed to embed text batch: {$e->getMessage()}");
            throw $e;
        }
    }
}